<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Generator;
use RuntimeException;

/**
 * Streams rows from the Hugging Face Datasets Server REST API.
 *
 * For M0 we use the rows API instead of parquet because:
 *   - Zero external PHP deps (pure curl + json_decode)
 *   - Works without the omeka-cli container needing parquet libraries
 *   - 100 rows per call → ~125 calls for 12,287 articles (~30 s on warm CDN)
 *
 * Future optimization: use the /parquet endpoint to get direct file URLs and
 * stream-parse with flow-php/parquet. That'd be ~10× faster for cold reindex
 * but adds a non-trivial dep. Defer until reindex time becomes painful.
 *
 * The dataset is at https://huggingface.co/datasets/fmadore/islam-west-africa-collection
 * Subsets: articles | publications | documents | audiovisual | references | index
 */
final class HfDatasetLoader
{
    private const API_BASE = 'https://datasets-server.huggingface.co';
    private const DATASET  = 'fmadore/islam-west-africa-collection';
    private const PAGE_SIZE = 100; // HF cap per call

    /**
     * @param int $maxAttempts            Retry budget per page (HF
     *                                    sporadically 503s; rate limits
     *                                    surface as 429).
     * @param int $timeoutSeconds         Per-attempt cURL timeout.
     * @param int $interPageDelayMs       Sleep between successful pages
     *                                    to stay under HF's anonymous
     *                                    rate limit (~120 req/min on the
     *                                    /rows endpoint).
     */
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $timeoutSeconds = 30,
        private readonly int $interPageDelayMs = 250
    ) {
    }

    /**
     * Stream all rows of a subset. Yields one associative array per row.
     *
     * @param  string $subset One of: articles, publications, documents,
     *                        audiovisual, references, index
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $subset): Generator
    {
        $offset = 0;
        $total  = null;
        $count  = 0;

        while (true) {
            $page = $this->fetchPage($subset, $offset, self::PAGE_SIZE);
            $total ??= (int) ($page['num_rows_total'] ?? 0);

            $rows = $page['rows'] ?? [];
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                // The HF rows API wraps each row as {row_idx, row, truncated_cells}.
                // We only want the actual row payload.
                yield $row['row'] ?? [];
                $count++;
            }

            $offset += count($rows);
            if ($total !== null && $offset >= $total) {
                break;
            }

            // Throttle: stay under HF's anonymous /rows rate limit. At
            // 100 rows/page + 250ms/page we issue ~4 req/s ⇒ ~240/min,
            // still under HF's documented threshold but with headroom for
            // the cold-cache slowness on the first few pages of a subset.
            if ($this->interPageDelayMs > 0) {
                usleep($this->interPageDelayMs * 1000);
            }
        }

        if ($total !== null && $count !== $total) {
            // Fail loud — partial reads silently produce broken indexes
            throw new RuntimeException(sprintf(
                'HfDatasetLoader: streamed %d of %d rows from subset %s (incomplete)',
                $count,
                $total,
                $subset
            ));
        }
    }

    /**
     * Counts rows for a subset without streaming. Useful for progress bars.
     */
    public function count(string $subset): int
    {
        $page = $this->fetchPage($subset, 0, 1);
        return (int) ($page['num_rows_total'] ?? 0);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, num_rows_total?: int}
     */
    private function fetchPage(string $subset, int $offset, int $length): array
    {
        $url = sprintf(
            '%s/rows?dataset=%s&config=%s&split=train&offset=%d&length=%d',
            self::API_BASE,
            urlencode(self::DATASET),
            urlencode($subset),
            $offset,
            $length
        );

        $lastError = '';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $headers = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => 'IwacSearch-indexer/0.1',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                // Capture response headers — Retry-After is the canonical
                // signal for 429s on the HF Datasets Server.
                CURLOPT_HEADERFUNCTION => function ($ch, $rawHeader) use (&$headers): int {
                    $parts = explode(':', $rawHeader, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($rawHeader);
                },
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $code >= 200 && $code < 300) {
                $decoded = json_decode((string) $body, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                $lastError = 'malformed JSON response';
            } else {
                $lastError = sprintf('HTTP %d %s', $code, $err);
            }

            if ($attempt < $this->maxAttempts) {
                sleep($this->backoffSeconds($code, $headers, $attempt));
            }
        }

        throw new RuntimeException(sprintf(
            'HfDatasetLoader: failed to fetch %s offset=%d after %d attempts: %s',
            $subset,
            $offset,
            $this->maxAttempts,
            $lastError
        ));
    }

    /**
     * Decide how long to wait before the next retry.
     *
     * HF's anonymous /rows endpoint enforces a per-IP rate limit that
     * surfaces as HTTP 429 with a `Retry-After` header (seconds). Our
     * pre-fix policy of `1s, 2s, 4s` blew through three attempts in 7
     * seconds and left the rate limit untouched. Now:
     *
     *   429 — honour Retry-After if present (clamped to [30, 300] so a
     *         buggy server response can't stall the job for an hour),
     *         otherwise fall back to 30s, 60s, 120s, 240s by attempt.
     *   503 — HF transient overload. 5s, 10s, 20s, 40s.
     *   other — fast retry: 1s, 2s, 4s, 8s.
     *
     * @param array<string, string> $responseHeaders
     */
    private function backoffSeconds(int $code, array $responseHeaders, int $attempt): int
    {
        if ($code === 429) {
            $retryAfter = isset($responseHeaders['retry-after'])
                ? (int) $responseHeaders['retry-after']
                : 0;
            if ($retryAfter > 0) {
                return max(30, min(300, $retryAfter));
            }
            return 30 * (2 ** ($attempt - 1));
        }
        if ($code === 503) {
            return 5 * (2 ** ($attempt - 1));
        }
        return 2 ** ($attempt - 1);
    }
}

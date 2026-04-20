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
     * @param int $maxAttempts Retry budget per page (HF occasionally 503s)
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $timeoutSeconds = 30
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
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => 'IwacSearch-indexer/0.1',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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

            // Exponential backoff: 1s, 2s, 4s
            if ($attempt < $this->maxAttempts) {
                sleep(2 ** ($attempt - 1));
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
}

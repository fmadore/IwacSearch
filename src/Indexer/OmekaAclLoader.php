<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * HTTP-backed ACL loader: paginates the Omeka REST API anonymously.
 *
 * The trick: an unauthenticated /api/items request returns ONLY publicly
 * visible items. So "is this o:id in the result set?" IS the public-flag
 * answer — no need to fetch o:is_public explicitly.
 *
 * Why this matters:
 *   - HF dataset is monthly; Omeka ACL state may have changed since.
 *   - The IWAC-Hugging-Face pipeline pulls with admin auth and does NOT
 *     filter by `is_public`, so the HF parquet can contain currently-
 *     private items. Without the overlay, a bulk reindex would put
 *     those private items into Typesense as is_public=true.
 *   - Defaulting docs to is_public=false in the mapper means a missed
 *     overlay = invisible to public scoped keys (safe-closed).
 *   - Public scoped key also carries `filter_by: is_public:=true` so
 *     even an overlay miss can't leak data — this loader maximises
 *     recall on top of that hard guard.
 *
 * Cost: ~14K items at 100/page = 140 calls. ~30 s on warm CDN, called
 * once per reindex. Cached in-memory; no persistence.
 *
 * Used from cli/reindex.php where there is no Omeka service container
 * available. Inside an Omeka job, prefer {@see ApiOmekaAclLoader} —
 * docker containers can't reach their own public DNS reliably and the
 * internal ApiManager is faster anyway.
 */
final class OmekaAclLoader implements OmekaAclLoaderInterface
{
    /** @var array<int, true>|null  Set semantics: keyed by public o:id */
    private ?array $publicIds = null;

    public function __construct(
        private readonly string $omekaApiBase,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $perPage = 100,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxAttempts = 3
    ) {
        if (rtrim($omekaApiBase, '/') === '') {
            throw new RuntimeException('OmekaAclLoader: omekaApiBase must not be empty');
        }
    }

    /**
     * Fetch and cache the full set of public item IDs. Lazy — only runs
     * the first time isPublic() is called. Use prime() to force eagerly.
     */
    public function prime(): void
    {
        if ($this->publicIds !== null) {
            return;
        }

        $this->logger->info('Fetching public item IDs from Omeka API', ['base' => $this->omekaApiBase]);

        $ids = [];
        $page = 1;
        while (true) {
            $items = $this->fetchPage($page);
            if ($items === []) {
                break;
            }
            foreach ($items as $item) {
                $oid = (int) ($item['o:id'] ?? 0);
                if ($oid > 0) {
                    $ids[$oid] = true;
                }
            }
            // Last page detection: a partial page means we're done.
            if (count($items) < $this->perPage) {
                break;
            }
            $page++;
        }

        $this->publicIds = $ids;
        $this->logger->info('Public item IDs cached', ['count' => count($ids), 'pages' => $page]);
    }

    public function isPublic(int $omekaId): bool
    {
        if ($this->publicIds === null) {
            $this->prime();
        }
        /** @var array<int, true> $ids — primed above */
        $ids = $this->publicIds;
        return isset($ids[$omekaId]);
    }

    /**
     * Number of public item IDs known. 0 before prime() is called.
     */
    public function size(): int
    {
        return $this->publicIds === null ? 0 : count($this->publicIds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPage(int $page): array
    {
        $url = sprintf(
            '%s/items?page=%d&per_page=%d',
            rtrim($this->omekaApiBase, '/'),
            $page,
            $this->perPage
        );

        $lastError = '';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => 'IwacSearch-acl-loader/0.1',
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
            } elseif ($code === 404 && $page > 1) {
                // Some Omeka deployments 404 on past-end pages instead of
                // returning []. Treat as end-of-iteration.
                return [];
            } else {
                $lastError = sprintf('HTTP %d %s', $code, $err);
            }

            if ($attempt < $this->maxAttempts) {
                sleep(2 ** ($attempt - 1));
            }
        }

        throw new RuntimeException(sprintf(
            'OmekaAclLoader: failed to fetch page=%d after %d attempts: %s',
            $page,
            $this->maxAttempts,
            $lastError
        ));
    }
}

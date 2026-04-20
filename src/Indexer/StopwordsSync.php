<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent uploader for the French stopword set.
 *
 * Reads data/stopwords-fr.json and PUTs it as the "fr_default" stopword
 * set in Typesense. Public scoped keys carry `stopwords: fr_default` so
 * the public client cannot omit the filter.
 *
 * Idempotent: PUT /stopwords/{name} replaces the set. Safe to call on
 * every reindex; cheap enough to do so.
 *
 * Separate from Reindexer because:
 *   - Stopwords are a Typesense-wide concept, not per-collection
 *   - Useful to invoke standalone (e.g. when rotating the word list
 *     without rebuilding 14K docs)
 */
final class StopwordsSync
{
    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly string $stopwordsJsonPath,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @return array{set: string, locale: string, count: int}
     */
    public function sync(): array
    {
        if (!is_readable($this->stopwordsJsonPath)) {
            throw new RuntimeException("Stopwords file not readable: {$this->stopwordsJsonPath}");
        }

        $payload = json_decode((string) file_get_contents($this->stopwordsJsonPath), true);
        if (!is_array($payload) || !isset($payload['stopwords']) || !is_array($payload['stopwords'])) {
            throw new RuntimeException(
                "Stopwords file malformed (missing 'stopwords' array): {$this->stopwordsJsonPath}"
            );
        }

        $setName = 'fr_default';
        $body = [
            'stopwords' => $payload['stopwords'],
            'locale'    => $payload['locale'] ?? 'fr',
        ];

        $this->logger->info('Syncing stopwords set', [
            'set'    => $setName,
            'locale' => $body['locale'],
            'count'  => count($body['stopwords']),
        ]);

        // Try the resource path first (typesense-php >= 4.10), fall back
        // to a raw API call so we don't fight client version skew.
        try {
            // @phpstan-ignore-next-line  client member access varies by version
            $this->typesense->stopwords->upsert($setName, $body);
        } catch (Throwable $direct) {
            $this->logger->info('Falling back to raw API call for stopwords upsert', [
                'reason' => $direct->getMessage(),
            ]);
            // The Typesense client exposes the underlying API call object
            // through getApiCall() in all v4+ versions.
            // @phpstan-ignore-next-line
            $this->typesense->getApiCall()->put("/stopwords/{$setName}", $body);
        }

        return [
            'set'    => $setName,
            'locale' => $body['locale'],
            'count'  => count($body['stopwords']),
        ];
    }
}

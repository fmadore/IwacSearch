<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
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
    /**
     * Stopword set name. Public scoped keys and every search request
     * reference this set by name (`stopwords: fr_default`), so keep it in
     * sync with typesense.ts + InitialResponseRenderer.
     */
    public const SET_NAME = 'fr_default';

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

        $setName = self::SET_NAME;
        $locale  = $payload['locale'] ?? 'fr';

        $this->logger->info('Syncing stopwords set', [
            'set'    => $setName,
            'locale' => $locale,
            'count'  => count($payload['stopwords']),
        ]);

        // typesense-php v5+ (we pin ^6): $client->stopwords->put($stopwordSet)
        // is the single create-or-update method. The set name is part of the
        // payload (`name` key), not a separate first argument — the older
        // `upsert($name, $body)` / `getApiCall()` escape hatches were removed.
        $this->typesense->stopwords->put([
            'name'      => $setName,
            'stopwords' => $payload['stopwords'],
            'locale'    => $locale,
        ]);

        return [
            'set'    => $setName,
            'locale' => $locale,
            'count'  => count($payload['stopwords']),
        ];
    }
}

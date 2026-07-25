<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\StopwordsSync;
use Psr\Log\LoggerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Background job: sync the `fr_default` stopword set to Typesense.
 *
 * Same code path as cli/stopwords-sync.php: PUTs data/stopwords-fr.json
 * as the `fr_default` stopword set. Typically <1s, idempotent — runs
 * as a job for consistency with BulkReindex (visible in /admin/job/...
 * audit trail) and so the admin UI doesn't block on Typesense round
 * trips.
 *
 * Useful when:
 *   - The set is missing on a fresh Typesense volume.
 *   - You've edited data/stopwords-fr.json and want it live without
 *     reindexing the 14K-doc collection.
 */
class SyncStopwords extends AbstractTypesenseJob
{
    protected function label(): string
    {
        return 'French stopwords sync';
    }

    protected function operate(
        TypesenseClient $typesense,
        string $moduleRoot,
        LoggerInterface $logger
    ): array {
        return (new StopwordsSync($typesense, $moduleRoot . '/data/stopwords-fr.json', $logger))->sync();
    }
}

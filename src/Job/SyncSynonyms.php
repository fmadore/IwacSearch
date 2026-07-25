<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\SynonymsSync;
use Psr\Log\LoggerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Background job: sync the `iwac_synonyms` synonym set to Typesense.
 *
 * Same code path as cli/synonyms-sync.php: PUTs data/synonyms-fr.json as
 * the global iwac_synonyms set. Typically <1s, idempotent — runs as a job
 * for consistency with BulkReindex / SyncStopwords (visible in the
 * /admin/job/... audit trail).
 *
 * Useful when:
 *   - You've edited data/synonyms-fr.json (new transliteration variant)
 *     and want it live without reindexing the 14K-doc collection —
 *     synonym expansion is search-time, so no reindex is needed.
 *   - The set is missing on a fresh Typesense volume.
 */
class SyncSynonyms extends AbstractTypesenseJob
{
    protected function label(): string
    {
        return 'synonym set sync';
    }

    protected function operate(
        TypesenseClient $typesense,
        string $moduleRoot,
        LoggerInterface $logger
    ): array {
        return (new SynonymsSync($typesense, $moduleRoot . '/data/synonyms-fr.json', $logger))->sync();
    }
}

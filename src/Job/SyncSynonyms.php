<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\SynonymsSync;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Throwable;
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
class SyncSynonyms extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);

        // src/Job/SyncSynonyms.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        $sync = new SynonymsSync(
            $typesense,
            $moduleRoot . '/data/synonyms-fr.json',
            $logger
        );

        $logger->info('IwacSearch: syncing synonym set from Omeka job', [
            'job_id' => $this->job->getId(),
        ]);

        try {
            $stats = $sync->sync();
        } catch (Throwable $e) {
            $logger->error('IwacSearch: synonyms sync failed', [
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $logger->info('IwacSearch: synonyms synced', $stats);
    }
}

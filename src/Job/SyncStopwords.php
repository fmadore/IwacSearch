<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Throwable;
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
class SyncStopwords extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);

        // src/Job/SyncStopwords.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        $sync = new StopwordsSync(
            $typesense,
            $moduleRoot . '/data/stopwords-fr.json',
            $logger
        );

        $logger->info('IwacSearch: syncing French stopwords from Omeka job', [
            'job_id' => $this->job->getId(),
        ]);

        try {
            $stats = $sync->sync();
        } catch (Throwable $e) {
            $logger->error('IwacSearch: stopwords sync failed', [
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $logger->info('IwacSearch: stopwords synced', $stats);
    }
}

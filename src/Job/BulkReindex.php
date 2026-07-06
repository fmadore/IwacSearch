<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\ReindexOrchestrator;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Background job: bulk Typesense reindex from the Omeka MySQL database.
 *
 * Wraps Reindexer::run() + IndexReindexer::run() — same code path as
 * cli/reindex.php, dispatched from the admin "Run reindex" button.
 * Long-running (5–15 min on the IWAC corpus); status at /admin/job/{id}/log.
 *
 * Construction: the TypesenseClient and the DBAL Connection come from the
 * service container (the job runs inside Omeka, so 'Omeka\Connection' is the
 * live database connection). All indexer wiring lives in ReindexOrchestrator,
 * shared with cli/reindex.php so the two entry points can't drift.
 *
 * On any throw, AbstractJob marks the job ERROR — Reindexer::run() drops the
 * half-built collection on failure, so the live alias keeps pointing at the
 * previous good collection.
 */
class BulkReindex extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);
        /** @var Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // src/Job/BulkReindex.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        $logger->info('IwacSearch: starting bulk reindex from Omeka job', [
            'job_id'      => $this->job->getId(),
            'module_root' => $moduleRoot,
        ]);

        try {
            $stats = (new ReindexOrchestrator($typesense, $connection, $moduleRoot, $logger))->run();
        } catch (Throwable $e) {
            $logger->error('IwacSearch: reindex failed', [
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
            // Re-throw so AbstractJob marks the job as ERROR.
            throw $e;
        }

        $logger->info('IwacSearch: reindex complete', $stats);
    }
}

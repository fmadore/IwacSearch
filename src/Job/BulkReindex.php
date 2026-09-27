<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\ReindexOrchestrator;
use Psr\Log\LoggerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Background job: bulk Typesense reindex from the Omeka MySQL database.
 *
 * Wraps ReindexOrchestrator::run() — same code path as cli/reindex.php,
 * dispatched from the admin "Run reindex" button.
 * Long-running (5–15 min on the IWAC corpus); status at /admin/job/{id}/log.
 *
 * The DBAL Connection comes from the service container ('Omeka\Connection' is
 * the live database connection, since the job runs inside Omeka). All indexer
 * wiring lives in ReindexOrchestrator, shared with cli/reindex.php so the two
 * entry points can't drift.
 *
 * On any throw, AbstractTypesenseJob logs and rethrows so Omeka marks the job
 * ERROR. Promotion happens only after every check passes (and a failed alias
 * swap restores both aliases), so the live aliases still point at the
 * previous good generations. A build that fails after its import stays on
 * the server until retention cleanup removes it.
 */
class BulkReindex extends AbstractTypesenseJob
{
    protected function label(): string
    {
        return 'bulk reindex';
    }

    protected function operate(
        TypesenseClient $typesense,
        string $moduleRoot,
        LoggerInterface $logger
    ): array {
        /** @var Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        return (new ReindexOrchestrator($typesense, $connection, $moduleRoot, $logger, fn(): bool => $this->shouldStop()))->run();
    }
}

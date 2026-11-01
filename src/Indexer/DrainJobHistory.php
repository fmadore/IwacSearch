<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use IwacSearch\Job\DrainChanges;

/**
 * Housekeeping for the `job` rows drain workers leave in Omeka's job table.
 *
 * Whether a drain is running is no longer read from that table: the worker
 * lease decides dispatch ({@see WorkerLease}). The rows still accumulate —
 * one per worker, and saves start workers often — so retention trims them.
 */
final class DrainJobHistory
{
    /**
     * Delete completed drain jobs older than $days. Failed ones are kept for
     * diagnosis; other modules' jobs are never touched.
     */
    public static function prune(Connection $connection, int $days = 7): int
    {
        $before = (new DateTimeImmutable(sprintf('-%d days', max(1, $days))))->format('Y-m-d H:i:s');
        return (int) $connection->executeStatement(
            "DELETE FROM job WHERE class = ? AND status = 'completed' AND ended < ?",
            [DrainChanges::class, $before]
        );
    }
}

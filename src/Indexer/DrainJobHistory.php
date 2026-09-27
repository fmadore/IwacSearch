<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use IwacSearch\Job\DrainChanges;

/**
 * What Omeka's `job` table knows about drain jobs.
 *
 * Saves dispatch a drain at request shutdown, but only when none is already
 * queued or running: each dispatch costs a `job` row and a full Omeka
 * bootstrap in a new process, and a running drain consumes the rows appended
 * while it works. The per-minute `cli/maintenance.php drain` is the backstop
 * for anything that slips between the two.
 */
final class DrainJobHistory
{
    /**
     * A drain dispatched this recently that has not finished makes a new one
     * redundant. Longer than a drain's two-minute budget; bounded so a job
     * whose process died (left "starting"/"in_progress" forever) cannot
     * suppress dispatch indefinitely.
     */
    private const ACTIVE_WINDOW = '-5 minutes';

    /** Whether a drain job is queued or running (see ACTIVE_WINDOW). */
    public static function isActive(Connection $connection): bool
    {
        $since = (new DateTimeImmutable(self::ACTIVE_WINDOW))->format('Y-m-d H:i:s');
        return $connection->executeQuery(
            "SELECT 1 FROM job WHERE class = ? AND status IN ('starting', 'in_progress') AND started > ? LIMIT 1",
            [DrainChanges::class, $since]
        )->fetchOne() !== false;
    }

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

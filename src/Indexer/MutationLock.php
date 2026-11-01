<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;

/**
 * The mutation gate, as {@see self::STRIPES} named MySQL locks.
 *
 * The gate exists so that two readers see the catalog with no API write
 * between its pre and post events: the drain while it snapshots a batch, and
 * the rebuild while it reads its watermarks and runs its cutover. It never
 * had to keep writers from one another — but until 3.27 it was ONE exclusive
 * `GET_LOCK('mutation')`, so concurrent editors' saves queued behind each
 * other, a long batch write held every other editor's save for its whole
 * duration, and a save that waited out the 300-second timeout failed.
 *
 * MySQL has no shared user lock, but a session may hold several named locks.
 * So:
 *
 *   - a writer ({@see writer()}) takes ANY ONE free stripe — its preferred
 *     one first, then the others — and waits only when every stripe is busy:
 *     a drain snapshot or the cutover holds them all (or {@see STRIPES}
 *     saves are in flight at once);
 *   - an exclusive holder ({@see exclusive()}) takes EVERY stripe, always in
 *     the same order, so it excludes every writer exactly as the single lock
 *     did.
 *
 * Deadlock-free by construction: a writer holds at most one stripe and holds
 * nothing while it waits (nested writes in one process re-enter the stripe
 * they already hold), and every exclusive holder acquires in one fixed order
 * — the drain without waiting at all, the rebuild (one at a time, under the
 * rebuild lock) with a wait.
 *
 * Locks are per MySQL session and re-entrant there, as before: a process
 * whose own write holds a stripe can still take the exclusive gate on the
 * same connection.
 */
final class MutationLock
{
    /** Saves that can be in flight at once without any of them waiting. */
    public const STRIPES = 8;

    /** The gate an API write takes ({@see WriteGate}). */
    public static function writer(Connection $connection): AdvisoryLock
    {
        return new AnyStripe(self::stripes($connection));
    }

    /** The gate the drain snapshot and the rebuild's watermarks and cutover take. */
    public static function exclusive(Connection $connection): AdvisoryLock
    {
        return new AllStripes(self::stripes($connection));
    }

    /** @return list<AdvisoryLock> */
    private static function stripes(Connection $connection): array
    {
        $database = DatabaseLock::database($connection);
        $stripes = [];
        for ($i = 0; $i < self::STRIPES; $i++) {
            $stripes[] = new DatabaseLock($connection, 'mutation:' . $i, $database);
        }
        return $stripes;
    }
}

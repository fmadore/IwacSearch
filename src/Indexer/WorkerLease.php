<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * One coalesced drain worker, coordinated through a single
 * `iwac_search_worker` row instead of Omeka's job table.
 *
 * Until 3.26 a save dispatched a DrainChanges job unless the `job` table
 * showed one started in the last five minutes and not yet finished. That
 * window guessed at liveness (a dead job suppressed dispatch for five
 * minutes; a failed one did not, so a Typesense outage cost one failing job
 * per save), and it lost wake-ups: a drain that found nothing re-dispatched
 * only when it had processed something, so a row journaled while it ran
 * waited for the per-minute scheduled drain.
 *
 * Protocol — every step is one single-row UPDATE, hence atomic, and every
 * time is the database's own clock:
 *
 *  - A producer journals its changes, then {@see wake()}s: it records the
 *    request and dispatches a job only if it {@see claim()}s the lease, i.e.
 *    flips a NULL or stale heartbeat to now. Exactly one caller wins while
 *    no live worker holds the lease; everybody else relies on that worker.
 *  - The worker {@see begin()}s each pass (clearing the request), drains,
 *    and may exit only through {@see finish()}, which succeeds only while no
 *    request arrived since the pass began. A producer that journaled after
 *    the pass read the journal makes finish() fail, so the worker loops
 *    instead of stranding the row — the lost wake-up is closed.
 *  - A failed pass {@see backoff()}s: the heartbeat stays fresh for one
 *    stale window, so an outage costs one retry per window, not one job per
 *    save. A worker that dies stops beating; after the stale window the next
 *    producer, or the scheduled drain, takes the lease over.
 *
 * Correctness never rests on the lease: the publish lock still serialises
 * drainers, so a stale takeover that briefly yields two workers only costs
 * one of them a yield.
 */
final class WorkerLease
{
    public const TABLE = 'iwac_search_worker';
    public const NAME = 'drain';

    /** Seconds after which a silent worker is presumed dead (it beats after every batch). */
    public const STALE_SECONDS = 120;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $staleSeconds = self::STALE_SECONDS,
    ) {
    }

    /**
     * Producer side: ask for a worker, and start one through $dispatch only
     * when no live worker holds the lease. A failed dispatch gives the lease
     * back (the request stays recorded) and rethrows.
     *
     * @param Closure(): mixed $dispatch
     * @return bool whether this caller dispatched a worker
     */
    public function wake(Closure $dispatch): bool
    {
        $this->request();
        if (!$this->claim()) {
            return false;
        }
        try {
            $dispatch();
        } catch (Throwable $e) {
            try {
                $this->release();
            } catch (Throwable) {
                // The lease goes stale on its own.
            }
            throw $e;
        }
        return true;
    }

    /** Record that journaled work exists. */
    public function request(): void
    {
        $affected = (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET requested = 1, updated = NOW() WHERE name = ?',
            [self::NAME]
        );
        if ($affected === 0) {
            // Unchanged within the same second, or the row is missing.
            $this->ensureRow(1);
        }
    }

    /** True for exactly one caller while no live worker holds the lease. */
    public function claim(): bool
    {
        $claim = fn (): bool => (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET heartbeat = NOW(), updated = NOW()'
            . ' WHERE name = ? AND (heartbeat IS NULL OR heartbeat < NOW() - INTERVAL ? SECOND)',
            [self::NAME, max(1, $this->staleSeconds)]
        ) === 1;
        if ($claim()) {
            return true;
        }
        $this->ensureRow(0);
        return $claim();
    }

    /** Give the lease back (a failed dispatch, a stopped worker). */
    public function release(): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET heartbeat = NULL, updated = NOW() WHERE name = ?',
            [self::NAME]
        );
    }

    /** Start a pass: the worker holds the lease and has seen every earlier request. */
    public function begin(): void
    {
        $this->ensureRow(0);
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET requested = 0, heartbeat = NOW(), updated = NOW() WHERE name = ?',
            [self::NAME]
        );
    }

    /** The worker is alive (called after every batch). */
    public function beat(): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET heartbeat = NOW(), updated = NOW() WHERE name = ?',
            [self::NAME]
        );
    }

    /**
     * True when the worker may exit: nobody requested work since begin().
     * (A false negative — the row unchanged within one second — only costs
     * the worker one more, empty, pass.)
     */
    public function finish(): bool
    {
        return (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET heartbeat = NULL, updated = NOW() WHERE name = ? AND requested = 0',
            [self::NAME]
        ) === 1;
    }

    /** Failed pass: keep the work requested and hold the lease for one stale window. */
    public function backoff(): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET requested = 1, heartbeat = NOW(), updated = NOW() WHERE name = ?',
            [self::NAME]
        );
    }

    /** @return array{requested: bool, heartbeat: ?string, alive: bool} */
    public function status(): array
    {
        $row = $this->connection->executeQuery(
            'SELECT requested, heartbeat, heartbeat >= NOW() - INTERVAL ? SECOND AS alive FROM ' . self::TABLE . ' WHERE name = ?',
            [max(1, $this->staleSeconds), self::NAME]
        )->fetchAllAssociative()[0] ?? null;
        return [
            'requested' => $row !== null && (int) $row['requested'] === 1,
            'heartbeat' => $row !== null && $row['heartbeat'] !== null ? (string) $row['heartbeat'] : null,
            'alive' => $row !== null && (int) ($row['alive'] ?? 0) === 1,
        ];
    }

    private function ensureRow(int $requested): void
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO ' . self::TABLE . ' (name, requested) VALUES (?, ?)',
            [self::NAME, $requested]
        );
    }
}

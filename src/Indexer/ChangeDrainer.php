<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Closure;
use Throwable;

/**
 * Bounded, serialized consumer. Failed rows remain retryable, including
 * deletes; a document Typesense rejects is removed from search and its rows
 * acknowledged, so it cannot hold the journal (see IncrementalIndexer::applySnapshot()).
 *
 * {@see run()} is one pass over the journal; {@see work()} is the worker
 * body the DrainChanges job and the scheduled `cli/maintenance.php drain`
 * run, coordinated through the {@see WorkerLease}.
 */
final class ChangeDrainer
{
    /** How long one worker drains before it hands over to a successor job. */
    public const WORK_SECONDS = 300;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @param ?Closure(int): void $sleep test seam; defaults to sleep() */
    public function __construct(
        private readonly Connection $connection,
        private readonly IncrementalIndexer $indexer,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * One pass: batches of pending rows until the journal is empty, the time
     * is up, $stop says so, or a lock is busy (a save in flight, the rebuild
     * cutover, another drainer) — then it yields rather than wait.
     *
     * @param ?Closure(): bool $stop
     * @param ?Closure(): void $beat called after every applied batch
     * @return int journal rows applied
     */
    public function run(?Closure $stop = null, int $seconds = 120, ?Closure $beat = null): int
    {
        $journal = new ChangeJournal($this->connection);
        $lock = new DatabaseLock($this->connection, 'publish');
        $mutation = MutationLock::exclusive($this->connection);
        $deadline = microtime(true) + $seconds;
        $count = 0;
        do {
            if ($stop !== null && $stop()) {
                break;
            }
            // The snapshot needs every stripe of the mutation gate (no save
            // between its pre and post events), taken without waiting: a save
            // in flight makes this pass yield. Never hold the gate while
            // waiting for another worker's HTTP import either; that worker
            // (or the scheduled drain) consumes these rows.
            if (!$mutation->tryAcquire()) {
                return $count;
            }
            try {
                if (!$lock->tryAcquire()) {
                    return $count;
                }
                try {
                    $rows = $journal->pending(50);
                    $snapshot = $this->indexer->snapshot(array_column($rows, 'item_id'));
                } catch (\Throwable $e) {
                    $lock->release();
                    throw $e;
                }
            } finally {
                $mutation->release();
            }
            try {
                if ($rows === []) {
                    break;
                }
                $this->indexer->applySnapshot($snapshot, tolerateRejected: true);
                $journal->acknowledge(array_column($rows, 'id'));
                $count += count($rows);
            } finally {
                $lock->release();
            }
            if ($beat !== null) {
                $beat();
            }
        } while (microtime(true) < $deadline);
        return $count;
    }

    /**
     * The worker: drain passes until the journal is empty AND no producer
     * asked for work since the last pass began ({@see WorkerLease::finish()}),
     * so a row journaled while a pass ran is never left for the scheduled
     * drain. The caller holds the lease (it won {@see WorkerLease::claim()},
     * or is the job a winner dispatched).
     *
     * After $budgetSeconds with work left, the worker hands over: to
     * $successor (a fresh job, dispatched while this one still holds a fresh
     * heartbeat) when given, else by giving the lease back with the request
     * recorded, for the next save or the scheduled drain. A failed pass backs
     * off — the lease stays held for one stale window — and rethrows; a stop
     * request gives the lease back.
     *
     * @param ?Closure(): bool  $stop
     * @param ?Closure(): mixed $successor dispatches the next worker
     * @return int journal rows applied
     */
    public function work(WorkerLease $lease, ?Closure $stop = null, ?Closure $successor = null, int $budgetSeconds = self::WORK_SECONDS): int
    {
        $journal = new ChangeJournal($this->connection);
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $processed = 0;
        while (true) {
            $lease->begin();
            try {
                $pass = $this->run($stop, max(1, (int) ceil($deadline - microtime(true))), $lease->beat(...));
            } catch (Throwable $e) {
                $lease->backoff();
                throw $e;
            }
            $processed += $pass;
            if ($stop !== null && $stop()) {
                $lease->request();
                $lease->release();
                return $processed;
            }
            if (!$journal->hasPending() && $lease->finish()) {
                return $processed;
            }
            if (microtime(true) >= $deadline) {
                $lease->request();
                if ($successor === null) {
                    $lease->release();
                    return $processed;
                }
                try {
                    $lease->beat();
                    $successor();
                } catch (Throwable $e) {
                    $lease->release();
                    throw $e;
                }
                return $processed;
            }
            if ($pass === 0) {
                // Rows are pending but a lock was busy: a save in flight, the
                // rebuild cutover, or another drainer. Poll rather than spin.
                ($this->sleep)(1);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use WeakMap;

/**
 * Holds the mutation gate for the lifetime of each Omeka API write, keyed by
 * the write's request object.
 *
 * The gate is taken on `api.execute.pre` and given back on
 * `api.execute.post`. Omeka's `Api\Manager::execute()` fires post only when
 * the adapter returns: a validation error, a missing resource, a denied
 * entity, a throwing pre-listener from another module, or a caller passing
 * `finalize => false` all skip it. Counting holds alone would then keep the
 * lock for the rest of the process — in a long job that catches per-row
 * errors, that stalls indexing and makes every other editor's save wait out
 * the lock timeout.
 *
 * So each hold is tied to its request in a WeakMap. A request whose adapter
 * threw becomes unreachable once its caller has handled the exception, its
 * entry disappears, and {@see settle()} releases the hold it left behind.
 * settle() runs on every gate transition, and Module calls it on the API
 * events of reads too, so an abandoned hold survives only until the process
 * next talks to the API. (Production PHP runs with
 * `zend.exception_ignore_args=On`, so exception traces do not keep the
 * request alive; with it off, release waits until the caught exception
 * itself is discarded.)
 *
 * Nested writes nest naturally: the lock is re-entrant and every open request
 * keeps exactly one level of it.
 */
final class WriteGate
{
    /** @var WeakMap<object, list<int>> */
    private WeakMap $open;

    /** Levels of the lock this gate currently holds. */
    private int $held = 0;

    public function __construct(
        private readonly AdvisoryLock $lock,
        private readonly int $waitSeconds = 300,
    ) {
        $this->open = new WeakMap();
    }

    /** Take one level of the gate for $request (waits up to the configured timeout). */
    public function enter(object $request): void
    {
        $this->settle();
        $this->lock->acquire($this->waitSeconds);
        $this->held++;
        $this->open[$request] = [];
    }

    /**
     * Attach the IDs captured before the write, for the post event.
     *
     * @param list<int> $ids
     */
    public function remember(object $request, array $ids): void
    {
        if (isset($this->open[$request])) {
            $this->open[$request] = $ids;
        }
    }

    /**
     * IDs remembered for $request, or null when it never entered — its pre
     * event was skipped (`initialize => false`) or failed before entering.
     *
     * @return list<int>|null
     */
    public function pending(object $request): ?array
    {
        return $this->open[$request] ?? null;
    }

    /** Give back $request's level. A request that never entered releases nothing. */
    public function leave(object $request): void
    {
        if (isset($this->open[$request])) {
            unset($this->open[$request]);
            $this->held--;
            $this->lock->release();
        }
        $this->settle();
    }

    /** Release every level whose request is gone without a post event. */
    public function settle(): void
    {
        while ($this->held > count($this->open)) {
            $this->held--;
            $this->lock->release();
        }
    }

    /** Levels currently held (diagnostics and tests). */
    public function held(): int
    {
        return $this->held;
    }
}

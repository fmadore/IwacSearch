<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * A named, re-entrant advisory lock. {@see DatabaseLock} is the production
 * implementation (MySQL GET_LOCK, shared by web requests, jobs and the CLI);
 * the interface exists so the write-gate bookkeeping can be tested without a
 * database.
 */
interface AdvisoryLock
{
    /** Acquire or throw after waiting up to $seconds. */
    public function acquire(int $seconds = 0): void;

    /** Acquire, or return false after waiting up to $seconds. */
    public function tryAcquire(int $seconds = 0): bool;

    /** Release one level of a (possibly re-entrant) hold. */
    public function release(): void;
}

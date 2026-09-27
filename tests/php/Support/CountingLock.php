<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use IwacSearch\Indexer\AdvisoryLock;
use RuntimeException;

/** Re-entrant in-memory lock that records its depth, like DatabaseLock. */
final class CountingLock implements AdvisoryLock
{
    public int $depth = 0;
    public bool $busy = false;

    public function acquire(int $seconds = 0): void
    {
        if (!$this->tryAcquire($seconds)) {
            throw new RuntimeException('lock busy');
        }
    }

    public function tryAcquire(int $seconds = 0): bool
    {
        if ($this->busy && $this->depth === 0) {
            return false;
        }
        $this->depth++;
        return true;
    }

    public function release(): void
    {
        if ($this->depth === 0) {
            throw new RuntimeException('released more than acquired');
        }
        $this->depth--;
    }
}

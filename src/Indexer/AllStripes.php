<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use RuntimeException;

/**
 * Holds every one of several locks, acquired in their fixed order and
 * released in reverse: the exclusive side of {@see MutationLock}. A wait is
 * one budget for the whole set, and a failure releases whatever was taken,
 * so a refused attempt never leaves a stripe held. Re-entrant.
 */
final class AllStripes implements AdvisoryLock
{
    private int $depth = 0;

    /**
     * @param list<AdvisoryLock> $stripes
     * @param ?\Closure(): float $clock test seam; defaults to microtime(true)
     */
    public function __construct(private readonly array $stripes, private readonly ?\Closure $clock = null)
    {
    }

    public function acquire(int $seconds = 0): void
    {
        if (!$this->tryAcquire($seconds)) {
            throw new RuntimeException(sprintf(
                'IwacSearch mutation gate still held by a save in flight after %d s.',
                $seconds
            ));
        }
    }

    public function tryAcquire(int $seconds = 0): bool
    {
        if ($this->depth > 0) {
            $this->depth++;
            return true;
        }
        $now = $this->clock ?? static fn (): float => microtime(true);
        $deadline = $now() + max(0, $seconds);
        $taken = [];
        foreach ($this->stripes as $stripe) {
            if (!$stripe->tryAcquire(max(0, (int) ceil($deadline - $now())))) {
                foreach (array_reverse($taken) as $held) {
                    $held->release();
                }
                return false;
            }
            $taken[] = $stripe;
        }
        $this->depth = 1;
        return true;
    }

    public function release(): void
    {
        if ($this->depth > 0 && --$this->depth === 0) {
            foreach (array_reverse($this->stripes) as $stripe) {
                $stripe->release();
            }
        }
    }
}

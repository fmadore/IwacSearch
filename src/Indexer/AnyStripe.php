<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use RuntimeException;

/**
 * Holds any one of several locks: the writer's side of {@see MutationLock}.
 *
 * Re-entrant: nested acquisitions in one process reuse the stripe already
 * held, so a writer never holds two stripes (which is what keeps the
 * striped gate deadlock-free).
 */
final class AnyStripe implements AdvisoryLock
{
    private ?AdvisoryLock $held = null;
    private int $depth = 0;
    private readonly int $preferred;

    /**
     * @param list<AdvisoryLock> $stripes
     * @param ?int $preferred the stripe tried first, and waited on when all
     *   are busy; random by default, so concurrent processes spread out
     */
    public function __construct(private readonly array $stripes, ?int $preferred = null)
    {
        if ($stripes === []) {
            throw new RuntimeException('A striped lock needs at least one stripe.');
        }
        $this->preferred = ($preferred ?? random_int(0, count($stripes) - 1)) % count($stripes);
    }

    public function acquire(int $seconds = 0): void
    {
        if (!$this->tryAcquire($seconds)) {
            throw new RuntimeException(sprintf(
                'IwacSearch write gate busy for %d s: a rebuild cutover or a drain snapshot held it. The save was not made; retry it.',
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
        $count = count($this->stripes);
        for ($i = 0; $i < $count; $i++) {
            $stripe = $this->stripes[($this->preferred + $i) % $count];
            if ($stripe->tryAcquire(0)) {
                return $this->hold($stripe);
            }
        }
        // Every stripe is busy: an exclusive holder has them all. Wait on one;
        // it is released together with the others.
        $stripe = $this->stripes[$this->preferred];
        return $seconds > 0 && $stripe->tryAcquire($seconds) && $this->hold($stripe);
    }

    public function release(): void
    {
        if ($this->depth > 0 && --$this->depth === 0) {
            $held = $this->held;
            $this->held = null;
            $held?->release();
        }
    }

    private function hold(AdvisoryLock $stripe): bool
    {
        $this->held = $stripe;
        $this->depth = 1;
        return true;
    }
}

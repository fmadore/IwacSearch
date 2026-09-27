<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\WriteGate;
use IwacSearch\Tests\Support\CountingLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The gate every catalog save waits on. Omeka skips `api.execute.post` when
 * the adapter throws, so a gate that only counted holds would stay locked for
 * the rest of a job that catches per-row errors — stalling indexing and
 * making every other editor's save wait out the timeout.
 */
#[CoversClass(WriteGate::class)]
final class WriteGateTest extends TestCase
{
    private CountingLock $lock;
    private WriteGate $gate;

    protected function setUp(): void
    {
        $this->lock = new CountingLock();
        $this->gate = new WriteGate($this->lock);
    }

    public function testAPrePostPairTakesAndReturnsOneLevel(): void
    {
        $request = new stdClass();

        $this->gate->enter($request);
        self::assertSame(1, $this->lock->depth);

        $this->gate->leave($request);
        self::assertSame(0, $this->lock->depth);
        self::assertSame(0, $this->gate->held());
    }

    public function testNestedWritesUnwindInOrder(): void
    {
        $outer = new stdClass();
        $inner = new stdClass();

        $this->gate->enter($outer);
        $this->gate->enter($inner);
        self::assertSame(2, $this->lock->depth);

        $this->gate->leave($inner);
        self::assertSame(1, $this->lock->depth, 'the outer write still holds the gate');

        $this->gate->leave($outer);
        self::assertSame(0, $this->lock->depth);
    }

    public function testAWriteThatNeverReachesPostIsReleasedOnceItsRequestIsGone(): void
    {
        $failed = new stdClass();
        $this->gate->enter($failed);
        // The adapter threw; the caller handled the exception and moved on.
        unset($failed);

        self::assertSame(1, $this->lock->depth, 'nothing has run yet to notice');
        $this->gate->settle();
        self::assertSame(0, $this->lock->depth);
        self::assertSame(0, $this->gate->held());
    }

    public function testTheNextWriteSettlesAnAbandonedHoldBeforeWaiting(): void
    {
        $failed = new stdClass();
        $this->gate->enter($failed);
        unset($failed);

        $next = new stdClass();
        $this->gate->enter($next);
        self::assertSame(1, $this->lock->depth, 'only the live write holds the gate');

        $this->gate->leave($next);
        self::assertSame(0, $this->lock->depth);
    }

    public function testAFailedInnerWriteDoesNotReleaseTheOuterOne(): void
    {
        $outer = new stdClass();
        $this->gate->enter($outer);

        $inner = new stdClass();
        $this->gate->enter($inner);
        unset($inner); // inner adapter threw; outer caught it and carried on

        $this->gate->settle();
        self::assertSame(1, $this->lock->depth, 'the outer write is still in flight');

        $this->gate->leave($outer);
        self::assertSame(0, $this->lock->depth);
    }

    public function testAPostWithoutAPreReleasesNothing(): void
    {
        $outer = new stdClass();
        $this->gate->enter($outer);

        // A nested write issued with `initialize => false` fires only post.
        $unannounced = new stdClass();
        self::assertNull($this->gate->pending($unannounced));
        $this->gate->leave($unannounced);

        self::assertSame(1, $this->lock->depth, 'must not release the outer write early');
        $this->gate->leave($outer);
        self::assertSame(0, $this->lock->depth);
    }

    public function testRememberedIdsReachThePostEvent(): void
    {
        $request = new stdClass();
        $this->gate->enter($request);
        self::assertSame([], $this->gate->pending($request));

        $this->gate->remember($request, [7, 11]);
        self::assertSame([7, 11], $this->gate->pending($request));

        $this->gate->leave($request);
        self::assertNull($this->gate->pending($request));
    }

    public function testATimedOutAcquireLeavesNoPhantomHold(): void
    {
        $this->lock->busy = true;
        $request = new stdClass();

        try {
            $this->gate->enter($request);
            self::fail('enter() should propagate the lock timeout');
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->gate->held());
        self::assertNull($this->gate->pending($request));
        $this->gate->leave($request);
        self::assertSame(0, $this->lock->depth);
    }
}

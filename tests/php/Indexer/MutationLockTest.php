<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\AdvisoryLock;
use IwacSearch\Indexer\AllStripes;
use IwacSearch\Indexer\AnyStripe;
use IwacSearch\Indexer\WriteGate;
use IwacSearch\Tests\Support\CallLog;
use IwacSearch\Tests\Support\FakeLockServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * The striped mutation gate: saves must not wait for each other, while the
 * drain snapshot and the rebuild cutover must still exclude every save. Each
 * "session" below is one process's database connection, contending on the
 * same named locks the way MySQL's GET_LOCK does (see FakeLockServer).
 */
#[CoversClass(AnyStripe::class)]
#[CoversClass(AllStripes::class)]
final class MutationLockTest extends TestCase
{
    private FakeLockServer $server;

    protected function setUp(): void
    {
        $this->server = new FakeLockServer();
    }

    private function writer(string $session, int $preferred = 0): AnyStripe
    {
        return new AnyStripe($this->server->stripes($session), $preferred);
    }

    private function exclusive(string $session): AllStripes
    {
        return new AllStripes($this->server->stripes($session));
    }

    // ── Writers ──────────────────────────────────────────────────────────

    public function testConcurrentSavesDoNotWaitForEachOther(): void
    {
        // Same preferred stripe on purpose: the second save moves on to a free one.
        $editorA = $this->writer('A', preferred: 3);
        $editorB = $this->writer('B', preferred: 3);

        self::assertTrue($editorA->tryAcquire());
        self::assertTrue($editorB->tryAcquire());
        self::assertSame(['mutation:3'], $this->server->heldBy('A'));
        self::assertSame(['mutation:4'], $this->server->heldBy('B'));
    }

    public function testALongBatchHoldsOneStripeNotTheWholeGate(): void
    {
        $batch = $this->writer('batch');
        self::assertTrue($batch->tryAcquire());

        foreach (range(1, 7) as $n) {
            self::assertTrue($this->writer('editor' . $n, preferred: $n % 8)->tryAcquire(), "editor $n proceeds");
        }
        // Eight saves in flight: only then does a ninth have to wait.
        self::assertFalse($this->writer('editor8')->tryAcquire());
        self::assertSame([], $this->server->heldBy('editor8'));
    }

    public function testNestedWritesReuseTheirStripe(): void
    {
        $writer = $this->writer('A', preferred: 5);

        $writer->tryAcquire();
        $writer->tryAcquire();
        self::assertSame(['mutation:5'], $this->server->heldBy('A'), 'never a second stripe');
        self::assertSame(1, $this->server->owners['mutation:5']['count']);

        $writer->release();
        self::assertSame(['mutation:5'], $this->server->heldBy('A'), 'the outer write still holds it');
        $writer->release();
        self::assertSame([], $this->server->heldBy('A'));
    }

    public function testATimedOutSaveFailsLoudlyAndHoldsNothing(): void
    {
        self::assertTrue($this->exclusive('cutover')->tryAcquire());
        $gate = new WriteGate($this->writer('editor'), waitSeconds: 1);

        try {
            $gate->enter(new stdClass());
            self::fail('a save that cannot get the gate must not proceed unjournaled');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('write gate busy', $e->getMessage());
        }
        self::assertSame([], $this->server->heldBy('editor'));
        self::assertSame(0, $gate->held());
    }

    // ── The exclusive side ───────────────────────────────────────────────

    public function testASaveInFlightExcludesTheDrainSnapshotAndTheCutover(): void
    {
        self::assertTrue($this->writer('editor', preferred: 6)->tryAcquire());

        self::assertFalse($this->exclusive('drain')->tryAcquire());
        self::assertSame([], $this->server->heldBy('drain'), 'a refused attempt leaves no stripe held');
    }

    public function testTheExclusiveGateHoldsEverySave(): void
    {
        $drain = $this->exclusive('drain');
        self::assertTrue($drain->tryAcquire());
        self::assertCount(8, $this->server->heldBy('drain'));

        self::assertFalse($this->writer('editor', preferred: 2)->tryAcquire());

        $drain->release();
        self::assertSame([], $this->server->heldBy('drain'));
        self::assertTrue($this->writer('editor', preferred: 2)->tryAcquire());
    }

    public function testTheExclusiveGateIsReentrant(): void
    {
        $cutover = $this->exclusive('rebuild');
        $cutover->tryAcquire();
        $cutover->tryAcquire();
        $cutover->release();
        self::assertCount(8, $this->server->heldBy('rebuild'));
        $cutover->release();
        self::assertSame([], $this->server->heldBy('rebuild'));
    }

    public function testASessionsOwnSaveDoesNotBlockItsOwnExclusiveGate(): void
    {
        // As with MySQL: locks are per session and re-entrant there, so a
        // process holding a stripe for a write can still take the whole gate.
        self::assertTrue($this->writer('job', preferred: 1)->tryAcquire());
        self::assertTrue($this->exclusive('job')->tryAcquire());
    }

    public function testStripesAreTakenInOrderAndGivenBackInReverse(): void
    {
        $log = new CallLog();
        $stripes = [];
        foreach (['s0', 's1', 's2'] as $name) {
            $stripes[] = new class ($name, $log) implements AdvisoryLock {
                public function __construct(private string $name, private CallLog $log)
                {
                }

                public function acquire(int $seconds = 0): void
                {
                    $this->tryAcquire($seconds);
                }

                public function tryAcquire(int $seconds = 0): bool
                {
                    $this->log->record(['call' => '+' . $this->name]);
                    return true;
                }

                public function release(): void
                {
                    $this->log->record(['call' => '-' . $this->name]);
                }
            };
        }
        $gate = new AllStripes($stripes);
        $gate->acquire();
        $gate->release();

        self::assertSame(['+s0', '+s1', '+s2', '-s2', '-s1', '-s0'], array_column($log->entries, 'call'));
    }
}

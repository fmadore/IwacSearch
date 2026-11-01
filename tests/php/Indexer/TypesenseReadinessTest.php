<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\TypesenseReadiness;
use IwacSearch\Tests\Support\FakeTypesense;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A rebuild started while Typesense is still loading its collections used to
 * fail on its first request. It now waits — but boundedly, and it must still
 * start (and fail with the server's own error) when the server never comes.
 */
#[CoversClass(TypesenseReadiness::class)]
final class TypesenseReadinessTest extends TestCase
{
    private FakeTypesense $server;
    private int $now = 1_000_000;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->server = new FakeTypesense();
    }

    private function readiness(int $wait = 120): TypesenseReadiness
    {
        return new TypesenseReadiness(
            $this->server->client(),
            waitSeconds: $wait,
            sleep: function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            clock: fn (): int => $this->now,
        );
    }

    public function testAReadyServerIsNotWaitedFor(): void
    {
        self::assertTrue($this->readiness()->await());
        self::assertSame(1, $this->server->healthCalls);
        self::assertSame([], $this->slept);
    }

    public function testALoadingServerIsPolledUntilItIsReady(): void
    {
        $this->server->health = [
            new RuntimeException('Not Ready or Lagging'),
            ['ok' => false],
            ['ok' => true],
        ];

        self::assertTrue($this->readiness()->await());
        self::assertSame(3, $this->server->healthCalls);
        self::assertSame([3, 3], $this->slept);
    }

    public function testTheWaitIsBoundedAndTheRebuildProceeds(): void
    {
        $this->server->health = [new RuntimeException('connection refused')];

        self::assertFalse($this->readiness(wait: 10)->await());
        self::assertLessThanOrEqual(12, array_sum($this->slept), 'about the configured bound, not forever');
    }

    public function testAStoppedJobStopsWaiting(): void
    {
        $this->server->health = [['ok' => false]];

        self::assertFalse($this->readiness()->await(static fn (): bool => true));
        self::assertSame([], $this->slept);
    }
}

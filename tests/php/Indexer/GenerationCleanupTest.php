<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\CollectionOps;
use IwacSearch\Indexer\GenerationCleanup;
use IwacSearch\Tests\Support\FakeTypesense;
use IwacSearch\Tests\Support\MemoryGenerationLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Typesense is RAM-resident, so a leaked collection is a permanent memory
 * cost — but deleting the wrong one takes search down. The cleanup therefore
 * deletes only what the ledger says this module built and no longer needs,
 * and never a collection any alias targets, whatever the ledger says.
 */
#[CoversClass(GenerationCleanup::class)]
final class GenerationCleanupTest extends TestCase
{
    private FakeTypesense $server;
    private MemoryGenerationLedger $ledger;

    protected function setUp(): void
    {
        $this->server = new FakeTypesense();
        $this->ledger = new MemoryGenerationLedger();
    }

    private function cleanup(): GenerationCleanup
    {
        $client = $this->server->client();
        return new GenerationCleanup(
            new CollectionOps(static fn (): TypesenseClient => $client, ledger: $this->ledger),
            $this->ledger,
        );
    }

    /** A build recorded in the ledger whose collection exists on the server. */
    private function built(string $name, string $alias = 'iwac_current'): void
    {
        $this->server->seedCollection($name);
        $this->ledger->recordBuilding($name, $alias);
    }

    // ── Builds an interrupted rebuild left behind ───────────────────────

    public function testAnOrphanedBuildIsDroppedAndForgotten(): void
    {
        $this->built('iwac_v1_killed');

        self::assertSame(['iwac_v1_killed'], $this->cleanup()->recoverOrphans());
        self::assertSame(['iwac_v1_killed'], $this->server->dropped);
        self::assertSame([], $this->ledger->rows);
    }

    public function testAnOrphanAnAliasServesIsRecordedAsLiveNotDropped(): void
    {
        // A cutover whose alias rollback failed leaves search on the new build.
        $this->built('iwac_v1_half_promoted');
        $this->server->aliases['iwac_current'] = 'iwac_v1_half_promoted';

        self::assertSame([], $this->cleanup()->recoverOrphans());
        self::assertSame([], $this->server->dropped);
        self::assertSame(['iwac_v1_half_promoted' => 'live'], $this->ledger->statuses());
    }

    public function testRecoveryLeavesPromotedAndUnrecordedCollectionsAlone(): void
    {
        // Only `building` rows are orphans. Unrecorded collections — builds
        // from before the ledger, the analytics collections, another schema
        // version, a sibling prefix — are not the ledger's to delete.
        $this->built('iwac_v1_live');
        $this->built('iwac_v1_previous');
        $this->ledger->recordPromotion('iwac_current', 'iwac_v1_live', 'iwac_v1_previous');
        foreach (['iwac_popular_queries', 'iwac_v2_20260101_000000', 'iwac_v10_20260101_000000', 'iwac_index_v3_20260101_000000'] as $name) {
            $this->server->seedCollection($name);
        }

        self::assertSame([], $this->cleanup()->recoverOrphans());
        self::assertSame([], $this->server->dropped);
    }

    public function testNothingIsDroppedWhenTheAliasTableCannotBeRead(): void
    {
        $this->built('iwac_v1_killed');
        $this->server->aliasListFailure = new RuntimeException('connection refused');

        self::assertSame([], $this->cleanup()->recoverOrphans());
        self::assertSame([], $this->server->dropped);
        self::assertSame(['iwac_v1_killed' => 'building'], $this->ledger->statuses(), 'kept for the next attempt');
    }

    public function testAnOrphanThatCannotBeDroppedIsRetriedLater(): void
    {
        $this->built('iwac_v1_killed');
        $this->server->dropFailure = new RuntimeException('connection refused');

        self::assertSame([], $this->cleanup()->recoverOrphans());
        self::assertSame(['iwac_v1_killed' => 'building'], $this->ledger->statuses());

        $this->server->dropFailure = null;
        self::assertSame(['iwac_v1_killed'], $this->cleanup()->recoverOrphans());
    }

    // ── Retention after promotion ────────────────────────────────────────

    public function testEachAliasKeepsItsLiveGenerationAndOneRollbackTarget(): void
    {
        $promote = function (string $alias, string $name): void {
            $previous = $this->server->aliases[$alias] ?? null;
            $this->built($name, $alias);
            $this->server->aliases[$alias] = $name;
            $this->ledger->recordPromotion($alias, $name, $previous);
            $this->cleanup()->removeRetired();
        };
        foreach (['a', 'b', 'c', 'd'] as $generation) {
            $promote('iwac_current', 'iwac_v1_' . $generation);
            $promote('iwac_index_current', 'iwac_index_v1_' . $generation);
        }

        self::assertSame([
            'iwac_index_v1_c' => 'previous',
            'iwac_index_v1_d' => 'live',
            'iwac_v1_c' => 'previous',
            'iwac_v1_d' => 'live',
        ], $this->ledger->statuses());
        self::assertEqualsCanonicalizing(
            ['iwac_v1_a', 'iwac_v1_b', 'iwac_index_v1_a', 'iwac_index_v1_b'],
            $this->server->dropped
        );
    }

    public function testAFirstPromotionRetiresNothing(): void
    {
        $this->built('iwac_v1_first');
        $this->ledger->recordPromotion('iwac_current', 'iwac_v1_first', null);

        self::assertSame([], $this->cleanup()->removeRetired());
        self::assertSame(['iwac_v1_first' => 'live'], $this->ledger->statuses());
    }

    public function testARetiredGenerationAnotherAliasStillServesIsKept(): void
    {
        $this->built('iwac_v1_shared');
        $this->ledger->mark('iwac_v1_shared', 'retired');
        $this->server->aliases['someone_elses_alias'] = 'iwac_v1_shared';

        self::assertSame([], $this->cleanup()->removeRetired());
        self::assertSame([], $this->server->dropped);
        self::assertSame(['iwac_v1_shared' => 'retired'], $this->ledger->statuses());
    }

    public function testRetentionNeverTouchesABuildInProgressOrAnUnrecordedCollection(): void
    {
        $this->built('iwac_v1_building');
        $this->server->seedCollection('iwac_v1_20200101_000000');
        $this->built('iwac_v1_old');
        $this->ledger->mark('iwac_v1_old', 'retired');

        self::assertSame(['iwac_v1_old'], $this->cleanup()->removeRetired());
        self::assertArrayHasKey('iwac_v1_building', $this->server->collections);
        self::assertArrayHasKey('iwac_v1_20200101_000000', $this->server->collections);
    }

    public function testRetentionDeletesNothingWhenTheAliasTableCannotBeRead(): void
    {
        $this->built('iwac_v1_old');
        $this->ledger->mark('iwac_v1_old', 'retired');
        $this->server->aliasListFailure = new RuntimeException('connection refused');

        self::assertSame([], $this->cleanup()->removeRetired());
        self::assertSame([], $this->server->dropped);
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\CollectionOps;
use IwacSearch\Tests\Support\FakeTypesense;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * `promote()` is the code that protects live search: it decides whether a
 * freshly-built collection is healthy enough to swap the public alias onto.
 * Get it wrong and a systemic import failure (a schema/field-type mismatch
 * rejects every document) puts an EMPTY collection live and drops the last
 * good one — the corpus disappears from the public site until someone
 * notices and reindexes.
 *
 * Nothing here talks to a server; {@see FakeTypesense} stands in.
 */
#[CoversClass(CollectionOps::class)]
final class CollectionOpsTest extends TestCase
{
    private FakeTypesense $server;

    protected function setUp(): void
    {
        $this->server = new FakeTypesense();
    }

    private function ops(): CollectionOps
    {
        $client = $this->server->client();
        return new CollectionOps(static fn(): TypesenseClient => $client);
    }

    // ── The health guard ─────────────────────────────────────────────────

    public function testPromoteSwapsTheAliasOnAHealthyImport(): void
    {
        $this->server->seedCollection('iwac_v1_old');
        $this->server->aliases['iwac_current'] = 'iwac_v1_old';
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', 'iwac_v1_old', 14000, 0);

        self::assertSame('iwac_v1_new', $this->server->aliases['iwac_current']);
        self::assertSame(['iwac_v1_old'], $this->server->dropped);
    }

    public function testPromoteRefusesWhenNothingWasIndexed(): void
    {
        $this->server->aliases['iwac_current'] = 'iwac_v1_old';
        $this->server->seedCollection('iwac_v1_old');
        $this->server->seedCollection('iwac_v1_new');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the previous collection stays live');

        try {
            $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', 'iwac_v1_old', 0, 0);
        } finally {
            // The whole point: live search is untouched, and the half-built
            // collection is cleaned up rather than left leaking RAM.
            self::assertSame('iwac_v1_old', $this->server->aliases['iwac_current']);
            self::assertArrayHasKey('iwac_v1_old', $this->server->collections);
            self::assertSame(['iwac_v1_new'], $this->server->dropped);
        }
    }

    public function testPromoteRefusesWhenTooManyDocumentsFailed(): void
    {
        // 11 % > the 10 % ceiling.
        $this->server->aliases['iwac_current'] = 'iwac_v1_old';
        $this->server->seedCollection('iwac_v1_new');

        $this->expectException(RuntimeException::class);
        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', 'iwac_v1_old', 890, 110);
    }

    public function testPromoteAcceptsAnErrorRatioAtTheCeiling(): void
    {
        // Exactly 10 % — the guard rejects ABOVE the ratio, not at it, so a
        // corpus with a known bad tail still ships.
        $this->server->aliases['iwac_current'] = 'iwac_v1_old';
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', 'iwac_v1_old', 900, 100);

        self::assertSame('iwac_v1_new', $this->server->aliases['iwac_current']);
    }

    public function testPromoteHandlesAFirstEverBuildWithNoPreviousCollection(): void
    {
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', null, 10, 0);

        self::assertSame('iwac_v1_new', $this->server->aliases['iwac_current']);
        self::assertSame([], $this->server->dropped);
    }

    public function testPromoteDoesNotDropThePreviousCollectionWhenItIsAlsoTheNewOne(): void
    {
        // Defensive: a re-promote of the live collection must not delete it.
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', 'iwac_v1_new', 10, 0);

        self::assertSame([], $this->server->dropped);
        self::assertArrayHasKey('iwac_v1_new', $this->server->collections);
    }

    // ── The orphan sweep ─────────────────────────────────────────────────

    public function testPromoteSweepsOrphansLeftByCrashedRuns(): void
    {
        // Typesense is RAM-resident, so a leaked collection is a permanent
        // memory cost until someone drops it by hand.
        $this->server->seedCollection('iwac_v1_20260101_000000');
        $this->server->seedCollection('iwac_v1_20260201_000000');
        $this->server->seedCollection('iwac_v1_new');
        $this->server->aliases['iwac_current'] = 'iwac_v1_20260101_000000';

        $this->ops()->promote(
            'iwac_current',
            'iwac_v1_new',
            'iwac_v1',
            'iwac_v1_20260101_000000',
            10,
            0
        );

        self::assertSame(
            ['iwac_v1_20260101_000000', 'iwac_v1_20260201_000000'],
            $this->server->dropped
        );
        self::assertArrayHasKey('iwac_v1_new', $this->server->collections);
    }

    public function testTheSweepLeavesOtherSchemaVersionsAndUnrelatedCollectionsAlone(): void
    {
        // iwac_index_* belongs to the entity pass; the analytics collections
        // accumulate history across reindexes and must never be swept.
        $this->server->seedCollection('iwac_index_v3_20260101_000000');
        $this->server->seedCollection('iwac_popular_queries');
        $this->server->seedCollection('iwac_v2_20260101_000000');
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', null, 10, 0);

        self::assertSame([], $this->server->dropped);
    }

    public function testTheSweepMatchesOnTheSeparatorSoASiblingPrefixSurvives(): void
    {
        // `iwac_v10_…` must not be swept by a build of `iwac_v1`.
        $this->server->seedCollection('iwac_v10_20260101_000000');
        $this->server->seedCollection('iwac_v1_20260101_000000');
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', null, 10, 0);

        self::assertSame(['iwac_v1_20260101_000000'], $this->server->dropped);
    }

    public function testTheSweepIsSkippedRatherThanFatalWhenListingFails(): void
    {
        $this->server->listFailure = new RuntimeException('connection refused');
        $this->server->seedCollection('iwac_v1_new');

        $this->ops()->promote('iwac_current', 'iwac_v1_new', 'iwac_v1', null, 10, 0);

        // The swap is what matters; a failed cleanup must not undo it.
        self::assertSame('iwac_v1_new', $this->server->aliases['iwac_current']);
    }

    public function testSafelyDropCollectionSwallowsFailures(): void
    {
        $this->server->dropFailure = new RuntimeException('connection refused');

        $this->ops()->safelyDropCollection('iwac_v1_old');

        self::assertSame([], $this->server->dropped);
    }

    // ── Import + alias plumbing ──────────────────────────────────────────

    public function testImportAllBatchesAndTalliesResults(): void
    {
        $this->server->seedCollection('c');
        $docs = [];
        for ($i = 1; $i <= 25; $i++) {
            $docs[] = ['id' => (string) $i];
        }

        [$indexed, $errors] = $this->ops()->importAll('c', $docs, 10);

        self::assertSame([25, 0], [$indexed, $errors]);
        self::assertCount(25, $this->server->collections['c']);
    }

    public function testImportAllCountsRejectedDocumentsSeparately(): void
    {
        $this->server->seedCollection('c');
        $this->server->importDecision = static fn(array $doc): bool => ($doc['id'] ?? '') !== '2';

        [$indexed, $errors] = $this->ops()->importAll('c', [
            ['id' => '1'],
            ['id' => '2'],
            ['id' => '3'],
        ], 2);

        self::assertSame([2, 1], [$indexed, $errors]);
    }

    public function testImportAllHandlesAnEmptyStreamWithoutCallingTheServer(): void
    {
        self::assertSame([0, 0], $this->ops()->importAll('c', [], 10));
        self::assertSame([], $this->server->collections);
    }

    public function testImportAllPreservesUnicodeAndSlashesUnescaped(): void
    {
        $this->server->seedCollection('c');

        $this->ops()->importAll('c', [[
            'id' => '1',
            'title' => "Côte d'Ivoire / الإسلام",
            'omeka_url' => 'https://islam.zmo.de/s/afrique_ouest/item/1',
        ]], 10);

        self::assertSame(
            "Côte d'Ivoire / الإسلام",
            $this->server->collections['c'][0]['title']
        );
    }

    public function testResolveAliasTargetReturnsNullWhenTheAliasIsAbsent(): void
    {
        // A first-ever build: the alias doesn't exist yet, and that must not
        // be an error.
        self::assertNull($this->ops()->resolveAliasTarget('iwac_current'));

        $this->server->aliases['iwac_current'] = 'iwac_v1_x';
        self::assertSame('iwac_v1_x', $this->ops()->resolveAliasTarget('iwac_current'));
    }

    public function testCreateVersionedStripsTheLoaderPrivateKeys(): void
    {
        $this->ops()->createVersioned([
            'name' => 'iwac_v1_20260101_000000',
            'fields' => [['name' => 'id', 'type' => 'string']],
            '_alias_target' => 'iwac_current',
            '_base_name' => 'iwac_v1',
        ]);

        // Typesense rejects unknown top-level schema keys, so the loader's
        // bookkeeping must not reach it.
        self::assertArrayHasKey('iwac_v1_20260101_000000', $this->server->collections);
    }

    public function testDeleteDocumentReportsWhetherTheDocumentExisted(): void
    {
        $this->server->collections['c'] = [['id' => '42']];

        self::assertTrue($this->ops()->deleteDocument('c', '42'));
        self::assertFalse($this->ops()->deleteDocument('c', '42'));
        self::assertFalse($this->ops()->deleteDocument('c', '999'));
    }
}

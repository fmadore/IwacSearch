<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\CollectionOps;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\OmekaSourceReader;
use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Tests\Support\FakeTypesense;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * The write half of incremental indexing: applying an already-mapped snapshot.
 *
 * An authority edit fans out to every document that links it, so the apply
 * step used to cost one HTTP request per document (a DELETE for each ID
 * absent from a collection, a GET for each entity). These tests pin the
 * batched contract — a constant number of requests per collection — and the
 * outcome report the rebuild cutover reconciles its counts from.
 *
 * @phpstan-import-type AuthorityEntity from EntityAuthority
 */
#[CoversClass(IncrementalIndexer::class)]
#[CoversClass(CollectionOps::class)]
final class IncrementalApplyTest extends TestCase
{
    private FakeTypesense $server;

    protected function setUp(): void
    {
        $this->server = new FakeTypesense();
        $this->server->seedCollection('content');
        $this->server->seedCollection('index');
    }

    private function ops(): CollectionOps
    {
        $client = $this->server->client();
        return new CollectionOps(static fn(): TypesenseClient => $client);
    }

    private function indexer(?string $index = 'index'): IncrementalIndexer
    {
        // applySnapshot() never reads the catalog; the reader only needs to
        // exist (constructing it for real requires Doctrine DBAL, which Omeka
        // ships and this module's test suite does not).
        $reader = (new ReflectionClass(OmekaSourceReader::class))->newInstanceWithoutConstructor();
        $authority = new EntityAuthority();
        $registry = MapperRegistry::default($authority, new CountryResolver(dirname(__DIR__, 3) . '/data/newspaper-countries.json'));
        return new IncrementalIndexer($this->ops(), $reader, $registry, $authority, 'content', indexAlias: $index);
    }

    /** @return AuthorityEntity */
    private static function entity(int $id, string $title = 'Tidjaniya'): array
    {
        return [
            'id' => $id, 'type' => 'Sujets', 'title' => $title, 'aliases' => [], 'description' => '',
            'coordinates' => '', 'identifier' => '', 'wikidata_id' => '', 'is_part_of' => [], 'thumbnail' => null, 'is_public' => true,
        ];
    }

    public function testAFanOutCostsOneDeletePerCollectionNotOnePerDocument(): void
    {
        $snapshot = [];
        for ($id = 1; $id <= 40; $id++) {
            $snapshot[] = ['id' => $id, 'content' => ['id' => (string) $id, 'type_s' => 'article', 'is_public' => true], 'entity' => null];
        }
        $snapshot[] = ['id' => 500, 'content' => null, 'entity' => self::entity(500)];

        $this->indexer()->applySnapshot($snapshot);

        self::assertCount(2, $this->server->filterDeletes, 'one delete per collection');
        self::assertCount(1, $this->server->exports, 'one aggregate read for all entities');
        self::assertSame([], $this->server->singleDeletes);
    }

    public function testEntityAggregatesSurviveAMetadataUpdate(): void
    {
        $this->server->collections['index'][] = [
            'id' => '500', 'title' => 'Old name', 'frequency' => 42, 'authored_count' => 3,
            'country_ss' => ['Bénin'], 'first_year' => 1990, 'last_year' => 2010,
            'mentions_by_year_s' => '1990:2;2010:40',
        ];

        $this->indexer()->applySnapshot([
            ['id' => 500, 'content' => null, 'entity' => self::entity(500, 'New name')],
        ]);

        $doc = $this->server->collections['index'][0];
        self::assertSame('New name', $doc['title']);
        self::assertSame(42, $doc['frequency']);
        self::assertSame(3, $doc['authored_count']);
        self::assertSame(['Bénin'], $doc['country_ss']);
        self::assertSame(2010, $doc['last_year']);
        self::assertSame('1990:2;2010:40', $doc['mentions_by_year_s']);
    }

    public function testTheOutcomeReportsWhatEachCollectionNowHolds(): void
    {
        $this->server->collections['content'][] = ['id' => '7', 'type_s' => 'article'];
        $this->server->collections['index'][] = ['id' => '8', 'title' => 'Gone'];

        $outcome = $this->indexer()->applySnapshot([
            ['id' => 6, 'content' => ['id' => '6', 'type_s' => 'document', 'is_public' => true], 'entity' => null],
            ['id' => 7, 'content' => null, 'entity' => null],
            ['id' => 8, 'content' => null, 'entity' => null],
            ['id' => 9, 'content' => null, 'entity' => self::entity(9)],
        ]);

        self::assertSame([6 => 'document', 7 => null, 8 => null, 9 => null], $outcome['content']);
        self::assertSame([6 => false, 7 => false, 8 => false, 9 => true], $outcome['entity']);
        self::assertSame(['6'], array_column($this->server->collections['content'], 'id'));
        self::assertSame(['9'], array_column($this->server->collections['index'], 'id'));
    }

    public function testAContentOnlyIndexerNeverTouchesAnEntityCollection(): void
    {
        $outcome = $this->indexer(null)->applySnapshot([
            ['id' => 9, 'content' => null, 'entity' => self::entity(9)],
        ]);

        self::assertSame([], $outcome['entity']);
        self::assertSame([['content', 'id:[9]']], $this->server->filterDeletes);
        self::assertSame([], $this->server->exports);
    }

    public function testNothingToWriteSendsNoImports(): void
    {
        $this->indexer()->applySnapshot([['id' => 3, 'content' => null, 'entity' => null]]);

        self::assertSame([], $this->server->collections['content']);
        self::assertSame([], $this->server->exports, 'no entity to read aggregates for');
    }

    // ── Rejected documents in the live drain ─────────────────────────────

    /** @return list<array{id:int, content:?array<string,mixed>, entity:AuthorityEntity|null}> */
    private static function badAndGoodArticles(): array
    {
        return [
            ['id' => 5, 'content' => ['id' => '5', 'type_s' => 'article', 'is_public' => true, 'bad' => true], 'entity' => null],
            ['id' => 6, 'content' => ['id' => '6', 'type_s' => 'article', 'is_public' => true], 'entity' => null],
        ];
    }

    public function testTheDrainRemovesARejectedDocumentAndKeepsTheRest(): void
    {
        // The stale copy of 5 may show metadata that has since changed — a
        // withdrawal included — so it leaves search rather than lingering.
        $this->server->collections['content'][] = ['id' => '5', 'type_s' => 'article', 'title_txt' => 'stale'];
        $this->server->importDecision = static fn(array $doc): bool => !isset($doc['bad']);

        $outcome = $this->indexer()->applySnapshot(self::badAndGoodArticles(), tolerateRejected: true);

        self::assertSame(['6'], array_column($this->server->collections['content'], 'id'));
        self::assertSame([5], $outcome['rejected']);
        self::assertSame([5 => null, 6 => 'article'], $outcome['content']);
    }

    public function testTheRebuildCutoverStillFailsOnARejection(): void
    {
        $this->server->importDecision = static fn(array $doc): bool => !isset($doc['bad']);

        $this->expectException(RuntimeException::class);
        $this->indexer()->applySnapshot(self::badAndGoodArticles());
    }

    public function testABatchWithNothingAcceptedIsAServerFailureNotARejection(): void
    {
        // The embedding model not loaded rejects every document: removing
        // them would empty the index, so the rows stay pending instead.
        $this->server->collections['content'][] = ['id' => '5', 'type_s' => 'article', 'title_txt' => 'live'];
        $this->server->importDecision = static fn(array $doc): bool => false;

        try {
            $this->indexer()->applySnapshot(self::badAndGoodArticles(), tolerateRejected: true);
            self::fail('A batch with nothing accepted must throw.');
        } catch (RuntimeException) {
        }

        self::assertSame(['5'], array_column($this->server->collections['content'], 'id'));
    }

    public function testAnAcceptedEntityDoesNotLetAWhollyRejectedContentBatchEmptyTheIndex(): void
    {
        // An authority edit journals the entity and every item linking it:
        // the entity imports, the embedding model refuses all the content.
        // Summing both collections' accepted counts let that through and
        // deleted the content documents (#131).
        $this->server->collections['content'][] = ['id' => '5', 'type_s' => 'article', 'title_txt' => 'live'];
        $this->server->collections['content'][] = ['id' => '6', 'type_s' => 'article', 'title_txt' => 'live'];
        $this->server->collections['index'][] = ['id' => '500', 'title' => 'Old name'];
        $this->server->importDecision = static fn(array $doc): bool => !isset($doc['type_s']);
        $snapshot = [...self::badAndGoodArticles(), ['id' => 500, 'content' => null, 'entity' => self::entity(500, 'New name')]];

        try {
            $this->indexer()->applySnapshot($snapshot, tolerateRejected: true);
            self::fail('A collection with nothing accepted must throw.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('content', $e->getMessage());
        }

        self::assertSame(['5', '6'], array_column($this->server->collections['content'], 'id'));
        self::assertSame(['500'], array_column($this->server->collections['index'], 'id'));
    }

    public function testAcceptedContentDoesNotLetAWhollyRejectedEntityBatchEmptyTheIndex(): void
    {
        $this->server->collections['content'][] = ['id' => '6', 'type_s' => 'article', 'title_txt' => 'old'];
        $this->server->collections['index'][] = ['id' => '500', 'title' => 'Old name'];
        $this->server->importDecision = static fn(array $doc): bool => isset($doc['type_s']);
        $snapshot = [
            ['id' => 6, 'content' => ['id' => '6', 'type_s' => 'article', 'is_public' => true], 'entity' => null],
            ['id' => 500, 'content' => null, 'entity' => self::entity(500, 'New name')],
        ];

        try {
            $this->indexer()->applySnapshot($snapshot, tolerateRejected: true);
            self::fail('A collection with nothing accepted must throw.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('index', $e->getMessage());
        }

        self::assertSame(['500'], array_column($this->server->collections['index'], 'id'));
        self::assertSame(['6'], array_column($this->server->collections['content'], 'id'));
    }

    public function testAPartialRejectionBesideAnAcceptedEntityRemovesOnlyTheRejected(): void
    {
        $this->server->collections['content'][] = ['id' => '5', 'type_s' => 'article', 'title_txt' => 'stale'];
        $this->server->importDecision = static fn(array $doc): bool => !isset($doc['bad']);
        $snapshot = [...self::badAndGoodArticles(), ['id' => 500, 'content' => null, 'entity' => self::entity(500)]];

        $outcome = $this->indexer()->applySnapshot($snapshot, tolerateRejected: true);

        self::assertSame(['6'], array_column($this->server->collections['content'], 'id'));
        self::assertSame(['500'], array_column($this->server->collections['index'], 'id'));
        self::assertSame([5], $outcome['rejected']);
        self::assertSame([5 => null, 6 => 'article', 500 => null], $outcome['content']);
        self::assertTrue($outcome['entity'][500]);
    }

    public function testIdFiltersRefuseAnythingButDigits(): void
    {
        $this->expectException(RuntimeException::class);
        $this->ops()->deleteDocuments('content', ['1 || is_public:true']);
    }

    public function testLargeBatchesAreSplitIntoBoundedRequests(): void
    {
        $ids = array_map('strval', range(1, 600));

        self::assertSame(0, $this->ops()->deleteDocuments('content', $ids));

        self::assertCount(3, $this->server->filterDeletes, '250 IDs per request');
    }

    public function testFacetCountsReadOneFieldInOneRequest(): void
    {
        $this->server->searchResponse = FakeTypesense::facetResponse(['type_s' => ['article' => 12, 'reference' => 3]]);

        self::assertSame(['article' => 12, 'reference' => 3], $this->ops()->facetCounts('content', 'type_s'));
        self::assertCount(1, $this->server->searches);
        self::assertSame(0, $this->server->searches[0]['per_page']);
        self::assertFalse($this->server->searches[0]['enable_analytics']);
    }
}

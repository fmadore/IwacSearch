<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\SearchDefaults;
use IwacSearch\Search\SnapshotCache;
use IwacSearch\Search\SnapshotCacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;
use Typesense\MultiSearch;

/**
 * The SSR path runs with the ADMIN key, which bypasses every constraint the
 * public scoped key bakes in. So the two things worth pinning hardest are
 * that it re-imposes them itself (`is_public:=true`, no `ocr_text` in the
 * payload) and that it degrades to null — letting the client fetch — rather
 * than failing the page, whatever Typesense does.
 */
#[CoversClass(InitialResponseRenderer::class)]
final class InitialResponseRendererTest extends TestCase
{
    /** @var list<array<string, mixed>> Bodies passed to multiSearch->perform(). */
    private array $sent = [];

    /**
     * @param  list<mixed>|\Throwable $responses One perform() result per call,
     *   or a throwable to raise on every call.
     */
    private function renderer(
        array|\Throwable $responses,
        ?SnapshotCacheInterface $cache = null
    ): InitialResponseRenderer {
        $sent = &$this->sent;
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->multiSearch = new class ($responses, $sent) extends MultiSearch {
            private int $call = 0;

            /** @param list<mixed>|\Throwable $responses */
            public function __construct(private array|\Throwable $responses, private array &$sent)
            {
            }

            public function perform(array $searches, array $queryParameters = []): array
            {
                $this->sent[] = $searches;
                if ($this->responses instanceof \Throwable) {
                    throw $this->responses;
                }
                return $this->responses[min($this->call++, count($this->responses) - 1)];
            }
        };

        return new InitialResponseRenderer(
            clientFactory: static fn(): TypesenseClient => $client,
            cache: $cache,
        );
    }

    /** @return array<string, mixed> */
    private static function bootstrap(array $overrides = []): array
    {
        return $overrides + [
            'collection_alias' => 'iwac_current',
            'prominent_facets' => ['country_ss'],
            'default_sort' => 'date:desc',
            'results_per_page' => 10,
        ];
    }

    /** @return array<string, mixed> */
    private static function page(int $found = 3): array
    {
        return ['hits' => [], 'found' => $found, 'page' => 1];
    }

    // ── The security constraints the admin key would otherwise bypass ────

    public function testThePublicVisibilityFilterIsAlwaysImposed(): void
    {
        $this->renderer([['results' => [self::page()]]])->render(self::bootstrap());

        self::assertSame('is_public:=true', $this->sent[0]['searches'][0]['filter_by']);
    }

    public function testLockedFiltersAreAndedAfterThePublicGuard(): void
    {
        $this->renderer([['results' => [self::page()]]])
            ->render(self::bootstrap(['locked_filters' => 'country_ss:=`Bénin`']));

        self::assertSame(
            'is_public:=true && country_ss:=`Bénin`',
            $this->sent[0]['searches'][0]['filter_by']
        );
    }

    public function testOcrTextNeverReachesTheInlinedPayload(): void
    {
        // The SSR response is inlined into the HTML; OCR fulltext must not be.
        $this->renderer([['results' => [self::page()]]])->render(self::bootstrap());

        self::assertStringContainsString('ocr_text', $this->sent[0]['searches'][0]['exclude_fields']);
    }

    // ── Request shaping ─────────────────────────────────────────────────

    public function testRelevanceSortBecomesDateSortInBrowseMode(): void
    {
        // The snapshot is always the empty-query first page, where text-match
        // scoring is meaningless — mirrors the client's own browse behaviour.
        $this->renderer([['results' => [self::page()]]])
            ->render(self::bootstrap(['default_sort' => '_text_match:desc']));

        self::assertSame('*', $this->sent[0]['searches'][0]['q']);
        self::assertSame('date:desc', $this->sent[0]['searches'][0]['sort_by']);
    }

    public function testTheOptionalAuthorSortGetsItsMissingValuesRule(): void
    {
        // Typesense errors on an optional sort field with no explicit rule,
        // which would cost an author-sorted block its snapshot entirely.
        $this->renderer([['results' => [self::page()]]])
            ->render(self::bootstrap(['default_sort' => 'creator_sort:asc']));

        self::assertSame(
            'creator_sort(missing_values:last):asc',
            $this->sent[0]['searches'][0]['sort_by']
        );
    }

    public function testUnknownFacetFieldsAreDroppedRatherThanFailingTheRequest(): void
    {
        // Typesense 400s the WHOLE request over one bad facet name, so a
        // stale bootstrap must not take the snapshot down with it.
        $this->renderer([['results' => [self::page()]]])
            ->render(self::bootstrap(['prominent_facets' => ['country_ss', 'not_a_field']]));

        self::assertSame('country_ss', $this->sent[0]['searches'][0]['facet_by']);
    }

    public function testTheEntityCollectionKeepsItsOwnQueryBy(): void
    {
        // The entity schema has no ocr_text / abstract / embedding — passing
        // content's field list at it makes Typesense reject the search.
        $this->renderer([['results' => [self::page()]]])
            ->render(self::bootstrap(['query_by' => SearchDefaults::ENTITY_QUERY_BY]));

        self::assertSame(SearchDefaults::ENTITY_QUERY_BY, $this->sent[0]['searches'][0]['query_by']);
    }

    // ── renderMany ──────────────────────────────────────────────────────

    public function testRenderManyIssuesOneRequestForEverySurface(): void
    {
        $renderer = $this->renderer([['results' => [self::page(1), self::page(2)]]]);

        $out = $renderer->renderMany([
            self::bootstrap(),
            self::bootstrap(['collection_alias' => 'iwac_index_current']),
        ]);

        self::assertCount(1, $this->sent, 'both surfaces must share one round trip');
        self::assertCount(2, $this->sent[0]['searches']);
        self::assertSame([1, 2], [$out[0]['found'], $out[1]['found']]);
    }

    public function testResultsComeBackInInputOrder(): void
    {
        $renderer = $this->renderer([['results' => [self::page(1), self::page(2)]]]);

        $out = $renderer->renderMany([
            self::bootstrap(['collection_alias' => 'a']),
            self::bootstrap(['collection_alias' => 'b']),
        ]);

        self::assertSame('a', $this->sent[0]['searches'][0]['collection']);
        self::assertSame('b', $this->sent[0]['searches'][1]['collection']);
        self::assertSame(2, $out[1]['found']);
    }

    public function testOneFailingSurfaceDoesNotCostTheOtherItsSnapshot(): void
    {
        // A federated tab that can't pre-render should fall back on its own,
        // not blank its sibling.
        $renderer = $this->renderer([[
            'results' => [self::page(1), ['error' => 'Field `nope` not found in schema.']],
        ]]);

        $out = $renderer->renderMany([self::bootstrap(), self::bootstrap()]);

        self::assertSame(1, $out[0]['found']);
        self::assertNull($out[1]);
    }

    public function testAResultMissingHitsIsRejected(): void
    {
        // A half-baked initial_response crashes the Svelte client mid-render.
        $renderer = $this->renderer([['results' => [['found' => 3]]]]);

        self::assertNull($renderer->render(self::bootstrap()));
    }

    public function testAnEmptyInputListShortCircuits(): void
    {
        self::assertSame([], $this->renderer([])->renderMany([]));
        self::assertSame([], $this->sent);
    }

    // ── Degradation ─────────────────────────────────────────────────────

    public function testAnUnreachableTypesenseYieldsNullRatherThanFailingThePage(): void
    {
        $renderer = $this->renderer(new RuntimeException('connection refused'));

        self::assertNull($renderer->render(self::bootstrap()));
        self::assertSame([null, null], $renderer->renderMany([self::bootstrap(), self::bootstrap()]));
    }

    public function testAMissingStopwordSetRetriesOnceWithoutStopwords(): void
    {
        // A fresh Typesense volume has no fr_default set until a reindex
        // provisions it. Stopwords are an enhancement, so the snapshot should
        // still render rather than silently disappearing.
        $renderer = $this->renderer([
            ['results' => [['error' => 'Could not find a stopword set named `fr_default`.']]],
            ['results' => [self::page()]],
        ]);

        $out = $renderer->render(self::bootstrap());

        self::assertNotNull($out);
        self::assertCount(2, $this->sent);
        self::assertArrayHasKey('stopwords', $this->sent[0]['searches'][0]);
        self::assertArrayNotHasKey('stopwords', $this->sent[1]['searches'][0]);
    }

    public function testTheStopwordRetryDropsThemFromEverySubSearch(): void
    {
        // They all name the same set, so they would all fail the same way.
        $renderer = $this->renderer([
            ['results' => [self::page(), ['error' => 'unknown stopword set `fr_default`']]],
            ['results' => [self::page(1), self::page(2)]],
        ]);

        $out = $renderer->renderMany([self::bootstrap(), self::bootstrap()]);

        self::assertCount(2, $this->sent);
        foreach ($this->sent[1]['searches'] as $search) {
            self::assertArrayNotHasKey('stopwords', $search);
        }
        self::assertSame([1, 2], [$out[0]['found'], $out[1]['found']]);
    }

    // ── Snapshot cache ──────────────────────────────────────────────────

    /**
     * An in-memory stand-in for the APCu-backed cache — the real one is a
     * no-op without the extension, which CI does not have.
     */
    private function memoryCache(): SnapshotCacheInterface
    {
        return new class implements SnapshotCacheInterface {
            /** @var array<string, list<array<string, mixed>|null>> */
            public array $store = [];

            public function key(array $body): string
            {
                return hash('xxh128', json_encode($body) ?: '');
            }

            public function get(string $key): ?array
            {
                return $this->store[$key] ?? null;
            }

            public function set(string $key, array $value): void
            {
                $this->store[$key] = $value;
            }
        };
    }

    public function testAnIdenticalRenderIsServedFromCache(): void
    {
        // Every anonymous visitor of a landing page produces the same request.
        $cache = $this->memoryCache();
        $renderer = $this->renderer([['results' => [self::page(7)]]], $cache);

        $first = $renderer->render(self::bootstrap());
        $second = $renderer->render(self::bootstrap());

        self::assertSame(7, $first['found']);
        self::assertSame(7, $second['found']);
        self::assertCount(1, $this->sent, 'the second render must not hit Typesense');
    }

    public function testSurfacesWithDifferentParamsDoNotShareAnEntry(): void
    {
        $cache = $this->memoryCache();
        $renderer = $this->renderer([['results' => [self::page(1)]], ['results' => [self::page(2)]]], $cache);

        $a = $renderer->render(self::bootstrap(['locked_filters' => 'country_ss:=`Niger`']));
        $b = $renderer->render(self::bootstrap(['locked_filters' => 'country_ss:=`Bénin`']));

        self::assertSame([1, 2], [$a['found'], $b['found']]);
        self::assertCount(2, $this->sent);
    }

    public function testAFailedRenderIsNotCached(): void
    {
        // Otherwise a transient Typesense blip would be pinned in front of
        // every visitor for the whole TTL, turning a hiccup into an outage.
        $cache = $this->memoryCache();
        $renderer = $this->renderer(new RuntimeException('connection refused'), $cache);

        self::assertNull($renderer->render(self::bootstrap()));
        self::assertSame([], $cache->store);
    }

    public function testAPartiallyFailedRenderIsNotCachedEither(): void
    {
        $cache = $this->memoryCache();
        $renderer = $this->renderer([[
            'results' => [self::page(1), ['error' => 'Field `nope` not found in schema.']],
        ]], $cache);

        $renderer->renderMany([self::bootstrap(), self::bootstrap()]);

        self::assertSame([], $cache->store);
    }

    public function testTheRealCacheIsANoOpRatherThanAnErrorWithoutApcu(): void
    {
        // The module must work on a PHP build with no APCu — it just pays the
        // round trip it pays today.
        $renderer = $this->renderer(
            [['results' => [self::page()]], ['results' => [self::page()]]],
            new SnapshotCache(30)
        );

        self::assertNotNull($renderer->render(self::bootstrap()));
        self::assertNotNull($renderer->render(self::bootstrap()));
    }

    public function testItGivesUpAfterOneRetryRatherThanLooping(): void
    {
        $renderer = $this->renderer([
            ['results' => [['error' => 'no stopword set `fr_default`']]],
        ]);

        self::assertNull($renderer->render(self::bootstrap()));
        self::assertCount(2, $this->sent);
    }
}

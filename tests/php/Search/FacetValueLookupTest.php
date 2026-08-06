<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\ScopeFilters;
use IwacSearch\Tests\Support\FakeTypesense;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * The live facet-value reader behind the page block's value pickers.
 *
 * What's asserted here is almost entirely about NOT breaking the admin: this
 * runs while Omeka renders the page-edit screen, so every failure mode has to
 * end in a null the form can explain, never an exception. The one non-defensive
 * assertion — that the search carries `is_public:=true` — is what keeps the
 * picker honest about what the block will actually show.
 */
#[CoversClass(FacetValueLookup::class)]
final class FacetValueLookupTest extends TestCase
{
    private function lookup(FakeTypesense $server, string $alias = 'iwac_current'): FacetValueLookup
    {
        return new FacetValueLookup(
            clientFactory: static fn(): TypesenseClient => $server->client(),
            contentAlias:  $alias,
        );
    }

    public function testItReturnsFacetValuesWithTheirCounts(): void
    {
        $server = new FakeTypesense();
        $server->searchResponse = FakeTypesense::facetResponse([
            'newspaper_ss' => ['Sidwaya' => 1200, 'Le Pays' => 340],
            'language_ss'  => ['Français' => 1500],
        ]);

        $counts = $this->lookup($server)->counts(['newspaper_ss', 'language_ss']);

        self::assertSame([
            'newspaper_ss' => [
                ['value' => 'Sidwaya', 'count' => 1200],
                ['value' => 'Le Pays', 'count' => 340],
            ],
            'language_ss' => [
                ['value' => 'Français', 'count' => 1500],
            ],
        ], $counts);
    }

    public function testItAsksOnlyForPublicDocuments(): void
    {
        // The admin key sees everything; the block being configured will not.
        // Without this guard the picker could offer a newspaper whose only
        // documents are non-public — an editor would build a scope that
        // renders empty and have nothing to explain why.
        $server = new FakeTypesense();
        $server->searchResponse = FakeTypesense::facetResponse(['newspaper_ss' => []]);

        $this->lookup($server)->counts(['newspaper_ss', 'type_s']);

        self::assertCount(1, $server->searches);
        self::assertSame('is_public:=true', $server->searches[0]['filter_by']);
        self::assertSame('newspaper_ss,type_s', $server->searches[0]['facet_by']);
        self::assertSame(FacetValueLookup::MAX_VALUES, $server->searches[0]['max_facet_values']);
    }

    public function testItHitsTypesenseOncePerRequestEvenAcrossManyBlocks(): void
    {
        // Omeka calls BlockLayout::form() once per block on the page plus once
        // for the "add block" template; each call asks for the same values.
        $server = new FakeTypesense();
        $server->searchResponse = FakeTypesense::facetResponse(['newspaper_ss' => ['Sidwaya' => 1]]);
        $lookup = $this->lookup($server);

        $first  = $lookup->counts(ScopeFilters::lookupFields());
        $second = $lookup->counts(ScopeFilters::lookupFields());
        $third  = $lookup->counts(ScopeFilters::lookupFields());

        self::assertCount(1, $server->searches);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function testAnUnreachableIndexDegradesToNullInsteadOfThrowing(): void
    {
        $server = new FakeTypesense();
        $server->searchFailure = new RuntimeException('Connection refused');

        self::assertNull($this->lookup($server)->counts(['newspaper_ss']));
    }

    public function testAFailureIsAlsoMemoisedSoTheFormDoesNotRetryPerBlock(): void
    {
        // A down Typesense usually means a connect TIMEOUT, not a fast refusal.
        // Retrying per block would multiply that wait by the number of blocks
        // on the page, turning a degraded form into an unusable one.
        $server = new FakeTypesense();
        $server->searchFailure = new RuntimeException('Connection timed out');
        $lookup = $this->lookup($server);

        self::assertNull($lookup->counts(['newspaper_ss']));

        // Even if the server recovers mid-request, the answer stays null for
        // this request — the point is the bounded number of attempts.
        $server->searchFailure = null;
        $server->searchResponse = FakeTypesense::facetResponse(['newspaper_ss' => ['Sidwaya' => 1]]);
        self::assertNull($lookup->counts(['newspaper_ss']));
        self::assertSame([], $server->searches);
    }

    public function testAnUnexpectedResponseShapeDegradesToNull(): void
    {
        // A typesense-php upgrade that changes the contract should cost the
        // pickers their live values, not raise a TypeError in the admin.
        $server = new FakeTypesense();
        $server->searchResponse = ['found' => 0, 'hits' => []]; // no facet_counts

        self::assertNull($this->lookup($server)->counts(['newspaper_ss']));
    }

    public function testMalformedFacetEntriesAreSkippedNotTrusted(): void
    {
        $server = new FakeTypesense();
        $server->searchResponse = [
            'facet_counts' => [
                'not-an-array',
                ['counts' => [['value' => 'x', 'count' => 1]]],          // no field_name
                ['field_name' => 'language_ss'],                          // no counts
                ['field_name' => 'newspaper_ss', 'counts' => [
                    'nope',
                    ['count' => 3],                                       // no value
                    ['value' => '', 'count' => 9],                        // empty value
                    ['value' => 'Sidwaya'],                               // no count
                    ['value' => 'Le Pays', 'count' => 7],
                ]],
            ],
        ];

        self::assertSame(
            ['newspaper_ss' => [
                ['value' => 'Sidwaya', 'count' => 0],
                ['value' => 'Le Pays', 'count' => 7],
            ]],
            $this->lookup($server)->counts(['newspaper_ss'])
        );
    }

    public function testNoClientOrNoFieldsMeansNoRequest(): void
    {
        $server = new FakeTypesense();

        self::assertNull((new FacetValueLookup())->counts(['newspaper_ss']));
        self::assertNull($this->lookup($server)->counts([]));
        self::assertSame([], $server->searches);
    }

    public function testTruncationIsDetectableSoTheFormCanSaySo(): void
    {
        // A silently truncated list reads as exhaustive. The form says
        // "showing the N most common values" instead.
        $full = array_combine(
            array_map(static fn(int $i): string => "Paper {$i}", range(1, FacetValueLookup::MAX_VALUES)),
            array_fill(0, FacetValueLookup::MAX_VALUES, 1)
        );

        $server = new FakeTypesense();
        $server->searchResponse = FakeTypesense::facetResponse([
            'newspaper_ss' => $full,
            'language_ss'  => ['Français' => 1],
        ]);

        $counts = $this->lookup($server)->counts(['newspaper_ss', 'language_ss']);

        self::assertTrue(FacetValueLookup::isTruncated($counts, 'newspaper_ss'));
        self::assertFalse(FacetValueLookup::isTruncated($counts, 'language_ss'));
        // An unreachable index isn't a truncated one.
        self::assertFalse(FacetValueLookup::isTruncated(null, 'newspaper_ss'));
    }

    public function testItReadsTheCollectionItWasConfiguredWith(): void
    {
        $server = new FakeTypesense();
        $server->searchResponse = FakeTypesense::facetResponse(['type_s' => ['article' => 5]]);

        $counts = $this->lookup($server, 'iwac_v4_20260806_120000')->counts(['type_s']);

        self::assertSame(['type_s' => [['value' => 'article', 'count' => 5]]], $counts);
    }
}

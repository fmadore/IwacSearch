<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\EntityOccurrences;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entity (index) collection is built from two halves: the occurrence
 * aggregates accumulated during the content pass, and the per-entity
 * document the mapper builds from them. Both are pure.
 */
#[CoversClass(EntityOccurrences::class)]
#[CoversClass(IndexEntityMapper::class)]
final class EntityPipelineTest extends TestCase
{
    // ── EntityOccurrences ────────────────────────────────────────────────

    public function testFrequencyCountsEveryPublicDocumentReferencingTheEntity(): void
    {
        $occ = new EntityOccurrences();
        $occ->record(['is_public' => true, 'entity_ids' => [1, 2], 'pub_year' => 1994]);
        $occ->record(['is_public' => true, 'entity_ids' => [1], 'pub_year' => 1999]);

        self::assertSame(2, $occ->aggregate(1)['frequency']);
        self::assertSame(1, $occ->aggregate(2)['frequency']);
    }

    public function testNonPublicDocumentsDoNotCountTowardFrequency(): void
    {
        // The public entity index must not leak the existence of private
        // material through inflated counts.
        $occ = new EntityOccurrences();
        $occ->record(['is_public' => false, 'entity_ids' => [1], 'pub_year' => 1994]);
        $occ->record(['entity_ids' => [1], 'pub_year' => 1994]); // is_public absent

        self::assertSame(0, $occ->aggregate(1)['frequency']);
    }

    public function testFirstAndLastYearSpanTheDatedOccurrences(): void
    {
        $occ = new EntityOccurrences();
        foreach ([1999, 1980, 1994] as $year) {
            $occ->record(['is_public' => true, 'entity_ids' => [1], 'pub_year' => $year]);
        }

        $agg = $occ->aggregate(1);
        self::assertSame(1980, $agg['first_year']);
        self::assertSame(1999, $agg['last_year']);
    }

    public function testUndatedOccurrencesStillCountButLeaveTheSpanEmpty(): void
    {
        $occ = new EntityOccurrences();
        $occ->record(['is_public' => true, 'entity_ids' => [1]]);

        $agg = $occ->aggregate(1);
        self::assertSame(1, $agg['frequency']);
        self::assertNull($agg['first_year']);
        self::assertNull($agg['last_year']);
        self::assertSame([], $agg['mentions_by_year']);
    }

    public function testTheYearHistogramIsAscendingAndCounted(): void
    {
        $occ = new EntityOccurrences();
        foreach ([1994, 1980, 1994] as $year) {
            $occ->record(['is_public' => true, 'entity_ids' => [1], 'pub_year' => $year]);
        }

        self::assertSame([1980 => 1, 1994 => 2], $occ->aggregate(1)['mentions_by_year']);
    }

    public function testCountriesAreTheUnionAcrossOccurrences(): void
    {
        $occ = new EntityOccurrences();
        $occ->record(['is_public' => true, 'entity_ids' => [1], 'country_ss' => ['Bénin']]);
        $occ->record(['is_public' => true, 'entity_ids' => [1], 'country_ss' => ['Bénin', 'Niger']]);

        $countries = $occ->aggregate(1)['countries'];
        sort($countries);
        self::assertSame(['Bénin', 'Niger'], $countries);
    }

    public function testAnUnseenEntityAggregatesToAnEmptyRecord(): void
    {
        // IndexReindexer asks for every authority record, including ones no
        // content references — they must still be browsable.
        $agg = (new EntityOccurrences())->aggregate(999);

        self::assertSame(0, $agg['frequency']);
        self::assertSame([], $agg['countries']);
        self::assertNull($agg['first_year']);
    }

    public function testDocumentsWithNoEntityLinksAreIgnored(): void
    {
        $occ = new EntityOccurrences();
        $occ->record(['is_public' => true, 'entity_ids' => [], 'pub_year' => 1994]);

        self::assertSame(0, $occ->aggregate(1)['frequency']);
    }

    // ── IndexEntityMapper ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function entity(array $overrides = []): array
    {
        return $overrides + [
            'id' => 42,
            'type' => 'Lieux',
            'title' => 'Cotonou',
            'aliases' => [],
            'description' => '',
            'coordinates' => '',
            'identifier' => '',
            'is_part_of' => [],
            'thumbnail' => null,
            'is_public' => true,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function aggregate(array $overrides = []): array
    {
        return $overrides + [
            'frequency' => 0,
            'countries' => [],
            'first_year' => null,
            'last_year' => null,
            'mentions_by_year' => [],
        ];
    }

    public function testAnEntityWithoutATitleOrTypeIsSkipped(): void
    {
        $mapper = new IndexEntityMapper();

        self::assertNull($mapper->map(self::entity(['title' => '  ']), self::aggregate()));
        self::assertNull($mapper->map(self::entity(['type' => '']), self::aggregate()));
    }

    public function testTheBaseDocumentCarriesIdentityAndFrequency(): void
    {
        $doc = (new IndexEntityMapper())->map(
            self::entity(['identifier' => 'Q1234']),
            self::aggregate(['frequency' => 17])
        );

        self::assertSame('42', $doc['id']);
        self::assertSame('Cotonou', $doc['title']);
        self::assertSame('Lieux', $doc['entity_type_s']);
        self::assertSame(17, $doc['frequency']);
        self::assertSame('Q1234', $doc['identifier']);
        self::assertSame('https://islam.zmo.de/s/afrique_ouest/item/42', $doc['omeka_url']);
    }

    /**
     * @return list<array{0:string,1:?array{0:float,1:float}}>
     */
    public static function coordinateCases(): array
    {
        return [
            ['6.3703, 2.3912', [6.3703, 2.3912]],
            ['  6.3703 ,2.3912 ', [6.3703, 2.3912]],
            ['-6.5, -2.5', [-6.5, -2.5]],
            ['90, 180', [90.0, 180.0]],       // bounds are inclusive
            ['91, 0', null],                   // latitude out of range
            ['0, 181', null],                  // longitude out of range
            ['6.3703', null],                  // one component
            ['6.3703, 2.3912, 5', null],       // three components
            ['north, east', null],
            ['', null],
        ];
    }

    #[DataProvider('coordinateCases')]
    public function testCoordinatesParseToAGeopointOrAreSkippedSilently(
        string $raw,
        ?array $expected
    ): void {
        // Typesense expects [lat, lng] — the OPPOSITE of GeoJSON/MapLibre.
        // Malformed values keep the raw string visible for cleanup rather
        // than failing the import.
        $doc = (new IndexEntityMapper())->map(
            self::entity(['coordinates' => $raw]),
            self::aggregate()
        );

        if ($expected === null) {
            self::assertArrayNotHasKey('geo', $doc);
            self::assertArrayNotHasKey('has_coords', $doc);
            return;
        }
        self::assertSame($expected, $doc['geo']);
        // has_coords is the filterable presence flag: Typesense can't filter
        // on an optional field merely being set (typesense#798).
        self::assertTrue($doc['has_coords']);
    }

    public function testTheRawCoordinateStringSurvivesEvenWhenUnparseable(): void
    {
        $doc = (new IndexEntityMapper())->map(
            self::entity(['coordinates' => 'somewhere near Cotonou']),
            self::aggregate()
        );

        self::assertSame('somewhere near Cotonou', $doc['coordinates']);
    }

    public function testTheLastMentionYearDrivesBothPubYearAndTheSortableDate(): void
    {
        $doc = (new IndexEntityMapper())->map(
            self::entity(),
            self::aggregate(['first_year' => 1980, 'last_year' => 1999])
        );

        self::assertSame(1980, $doc['first_year']);
        self::assertSame(1999, $doc['last_year']);
        self::assertSame(1999, $doc['pub_year']);
        self::assertSame(gmmktime(0, 0, 0, 1, 1, 1999), $doc['date']);
    }

    public function testTheMentionHistogramIsSerialisedForTheSparkline(): void
    {
        $doc = (new IndexEntityMapper())->map(
            self::entity(),
            self::aggregate(['mentions_by_year' => [1980 => 1, 1994 => 2]])
        );

        self::assertSame('1980:1;1994:2', $doc['mentions_by_year_s']);
    }

    public function testAnEntityWithNoDatedMentionsGetsNoSparklineField(): void
    {
        $doc = (new IndexEntityMapper())->map(self::entity(), self::aggregate());

        self::assertArrayNotHasKey('mentions_by_year_s', $doc);
        self::assertArrayNotHasKey('pub_year', $doc);
    }

    public function testOptionalFieldsAreOmittedRatherThanEmptied(): void
    {
        // Typesense optional fields are ABSENT, not empty strings.
        $doc = (new IndexEntityMapper())->map(self::entity(), self::aggregate());

        foreach (['identifier', 'description', 'coordinates', 'thumbnail_url', 'country_ss', 'is_part_of_ss', 'entity_aliases_txt'] as $field) {
            self::assertArrayNotHasKey($field, $doc, $field);
        }
    }

    public function testAliasesAndCategoriesAreCarriedThrough(): void
    {
        $doc = (new IndexEntityMapper())->map(
            self::entity([
                'aliases' => ['RCI', 'Radio Côte d\'Ivoire'],
                'is_part_of' => ['Organisation islamique'],
            ]),
            self::aggregate(['countries' => ['Bénin', 'Bénin', 'Niger']])
        );

        self::assertSame(['RCI', 'Radio Côte d\'Ivoire'], $doc['entity_aliases_txt']);
        self::assertSame(['Organisation islamique'], $doc['is_part_of_ss']);
        self::assertSame(['Bénin', 'Niger'], $doc['country_ss']);
    }
}

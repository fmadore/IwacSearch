<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\PropertyValues;
use IwacSearch\IwacInstance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The mappers turn one Omeka item into one Typesense document. Every
 * derivation here is invisible until it's wrong in production — a bad
 * `has_fulltext` mislabels the "Full text available" filter, a bad
 * `creator_sort` scrambles the bibliography, a missing `country_ss` drops
 * an item out of a country scope entirely.
 *
 * No database: the mappers take plain arrays and a {@see PropertyValues},
 * which is exactly why they were worth extracting.
 */
#[CoversClass(MapperRegistry::class)]
final class MapperTest extends TestCase
{
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = MapperRegistry::default(
            new EntityAuthority(),
            new CountryResolver(dirname(__DIR__, 3) . '/data/newspaper-countries.json')
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $terms
     */
    private static function values(array $terms): PropertyValues
    {
        $rows = [];
        foreach ($terms as $term => $entries) {
            foreach ($entries as $entry) {
                $rows[$term][] = $entry + [
                    'vrid' => null, 'value' => null, 'uri' => null,
                    'title' => null, 'vpub' => true,
                ];
            }
        }
        return PropertyValues::fromRows($rows);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{id:int,title:string,is_public:bool,class:int,item_sets:list<int>}
     */
    private static function item(array $overrides = []): array
    {
        return $overrides + [
            'id' => 123,
            'title' => 'Le ramadan à Cotonou',
            'is_public' => true,
            'class' => IwacInstance::CLASS_ARTICLE,
            'item_sets' => [],
        ];
    }

    // ── Registry ─────────────────────────────────────────────────────────

    public function testEveryContentClassResolvesToExactlyOneMapper(): void
    {
        $expected = [
            IwacInstance::CLASS_ARTICLE => 'articles',
            IwacInstance::CLASS_PUBLICATION => 'publications',
            IwacInstance::CLASS_DOCUMENT => 'documents',
            IwacInstance::CLASS_AUDIOVISUAL => 'audiovisual',
            IwacInstance::CLASS_PHOTOGRAPH => 'photographs',
            IwacInstance::CLASS_CHAPTER => 'references',
        ];
        foreach ($expected as $classId => $subset) {
            self::assertSame($subset, $this->registry->forClass($classId)?->subsetName());
        }
    }

    public function testAuthorityClassesResolveToNoMapper(): void
    {
        // An authority record is not content; the incremental indexer relies
        // on this null to skip (and to delete a stale doc).
        foreach (IwacInstance::ENTITY_CLASSES as $classId) {
            self::assertNull($this->registry->forClass($classId));
        }
        self::assertNull($this->registry->forClass(999999));
    }

    public function testRegistryRejectsTwoMappersClaimingTheSameClass(): void
    {
        $authority = new EntityAuthority();
        $countries = new CountryResolver(dirname(__DIR__, 3) . '/data/newspaper-countries.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('claimed by both');

        // Two DIFFERENT subsets claiming one resource class: whichever
        // registered first used to silently win, so an item could be mapped
        // by the wrong subset with no signal at all.
        new MapperRegistry([
            new \IwacSearch\Indexer\Mapper\ArticleMapper($authority, $countries),
            new class ($authority, $countries) extends \IwacSearch\Indexer\Mapper\AbstractMapper {
                public function subsetName(): string
                {
                    return 'articles-clone';
                }

                public function classIds(): array
                {
                    return [IwacInstance::CLASS_ARTICLE];
                }

                public function readTerms(): array
                {
                    return [];
                }

                protected function typeTag(): string
                {
                    return 'clone';
                }

                public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): array
                {
                    return $this->buildBase($item, $values, $thumbnailUrl);
                }
            },
        ]);
    }

    public function testAllReadTermsIsTheUnionOfEverySubset(): void
    {
        $terms = $this->registry->allReadTerms();

        self::assertSame($terms, array_values(array_unique($terms)), 'union must be deduplicated');
        // A term only references declare, and one only the primary sources do.
        self::assertContains('bibo:authorList', $terms);
        self::assertContains('bibo:content', $terms);
    }

    // ── Identity / base fields ───────────────────────────────────────────

    public function testBaseFieldsAreDerivedFromTheItem(): void
    {
        $doc = $this->registry->get('articles')->map(self::item(), self::values([]), null);

        self::assertSame('123', $doc['id']);
        self::assertSame('article', $doc['type_s']);
        self::assertSame('Le ramadan à Cotonou', $doc['title']);
        self::assertTrue($doc['is_public']);
        self::assertSame('https://islam.zmo.de/s/afrique_ouest/item/123', $doc['omeka_url']);
    }

    public function testAnUntitledItemGetsAnIdentifiableDisplayTitle(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(['title' => '   ']),
            self::values([]),
            null
        );

        self::assertSame('[Untitled #123]', $doc['title']);
        // …but the FTS field stays empty rather than indexing the placeholder.
        self::assertSame('', $doc['title_txt']);
    }

    public function testTheIiifManifestIsEmittedOnlyAlongsideAThumbnail(): void
    {
        // A thumbnailed media is the proxy for "has primary media", which is
        // the precondition for a manifest existing at all.
        $withMedia = $this->registry->get('articles')
            ->map(self::item(), self::values([]), '/files/medium/abc.jpg');
        self::assertSame('/files/medium/abc.jpg', $withMedia['thumbnail_url']);
        self::assertSame('https://islam.zmo.de/iiif/3/123/manifest', $withMedia['iiif_manifest']);

        $without = $this->registry->get('articles')->map(self::item(), self::values([]), null);
        self::assertArrayNotHasKey('thumbnail_url', $without);
        self::assertArrayNotHasKey('iiif_manifest', $without);
    }

    public function testItemSetIdsAreOmittedWhenTheItemBelongsToNoSet(): void
    {
        $doc = $this->registry->get('articles')->map(self::item(), self::values([]), null);
        self::assertArrayNotHasKey('item_set_ids', $doc);

        $doc = $this->registry->get('articles')
            ->map(self::item(['item_sets' => [2193, 7]]), self::values([]), null);
        self::assertSame([2193, 7], $doc['item_set_ids']);
    }

    // ── Dates ────────────────────────────────────────────────────────────

    /**
     * @return list<array{0:string,1:?int,2:?string}>
     */
    public static function dateCases(): array
    {
        return [
            ['1994-03-15', 1994, '1990s'],
            ['1994-03', 1994, '1990s'],   // year-month → period start
            ['1994', 1994, '1990s'],      // year only → 1 January
            ['2000-01-01', 2000, '2000s'],
            ['1999-12-31', 1999, '1990s'], // decade boundary
            ['not a date', null, null],
            ['', null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dateCases')]
    public function testPartialIsoDatesResolveToAYearAndDecade(
        string $iso,
        ?int $year,
        ?string $decade
    ): void {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:date' => [['value' => $iso]]]),
            null
        );

        if ($year === null) {
            self::assertArrayNotHasKey('pub_year', $doc);
            self::assertArrayNotHasKey('date_decade_ss', $doc);
            return;
        }
        self::assertSame($year, $doc['pub_year']);
        self::assertSame([$decade], $doc['date_decade_ss']);
    }

    public function testTheEpochIsTheUtcStartOfThePeriod(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:date' => [['value' => '1994-03']]]),
            null
        );

        self::assertSame(gmmktime(0, 0, 0, 3, 1, 1994), $doc['date']);
    }

    // ── has_fulltext / OCR ───────────────────────────────────────────────

    public function testPublicOcrSetsHasFulltextAndCountsWordsUnicodeAware(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['bibo:content' => [['value' => "Café des élus à Cotonou", 'vpub' => true]]]),
            null
        );

        self::assertTrue($doc['has_fulltext']);
        // Accented words count as ONE word each, not split on the accent.
        self::assertSame(5, $doc['nb_words']);
        self::assertSame('Café des élus à Cotonou', $doc['ocr_text']);
    }

    public function testRestrictedOcrIsStillIndexedButFlaggedNotPubliclyReadable(): void
    {
        // Licensing-restricted OCR keeps snippet highlighting working while
        // the "Full text available" filter tells the truth about what a
        // visitor can actually open.
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['bibo:content' => [['value' => 'restricted text', 'vpub' => false]]]),
            null
        );

        self::assertFalse($doc['has_fulltext']);
        self::assertSame('restricted text', $doc['ocr_text']);
    }

    public function testOnePublicValueAmongRestrictedOnesIsEnough(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['bibo:content' => [
                ['value' => 'restricted', 'vpub' => false],
                ['value' => 'public', 'vpub' => true],
            ]]),
            null
        );

        self::assertTrue($doc['has_fulltext']);
        // The FIRST non-empty value is the indexed body, regardless.
        self::assertSame('restricted', $doc['ocr_text']);
    }

    public function testSubsetsWithoutOcrGetAnHonestFalseRatherThanAMissingField(): void
    {
        // Keeps the "Full text available" facet counts complete across the
        // primary-source subsets.
        foreach (['audiovisual', 'photographs'] as $subset) {
            $mapper = $this->registry->get($subset);
            $doc = $mapper->map(
                self::item(['class' => $mapper->classIds()[0]]),
                self::values([]),
                null
            );
            self::assertFalse($doc['has_fulltext'], $subset);
            self::assertArrayNotHasKey('ocr_text', $doc);
        }
    }

    public function testReferencesCarryNoHasFulltextFieldAtAll(): void
    {
        // References have no OCR concept, so the facet stays hidden for them
        // instead of showing a meaningless "no".
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values([]),
            null
        );

        self::assertArrayNotHasKey('has_fulltext', $doc);
    }

    // ── country_ss ───────────────────────────────────────────────────────

    public function testCountryIsDerivedFromTheNewspaperName(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:publisher' => [['value' => 'La Nation']]]),
            null
        );

        self::assertSame(['La Nation'], $doc['newspaper_ss']);
        self::assertSame(['Bénin'], $doc['country_ss']);
    }

    public function testAnUnknownNewspaperYieldsNoCountryRatherThanABogusOne(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:publisher' => [['value' => 'Some Unknown Paper']]]),
            null
        );

        self::assertArrayNotHasKey('country_ss', $doc);
    }

    public function testDocumentsFallBackToTheirCountryItemSet(): void
    {
        // Documents rarely carry a publisher; membership of the per-country
        // "Documents divers" set is the fallback.
        $doc = $this->registry->get('documents')->map(
            self::item(['class' => IwacInstance::CLASS_DOCUMENT, 'item_sets' => [23453]]),
            self::values([]),
            null
        );

        self::assertSame(['Burkina Faso'], $doc['country_ss']);
    }

    public function testAudiovisualFallsBackToItsPlaceHeading(): void
    {
        // The regression this guards: recordings carry a producer, not a
        // newspaper, and sit in topical sets, so both the publisher and the
        // item-set paths resolve nothing. Without the place fallback every
        // Nigerian recording indexed with no country_ss and /browse/nigeria
        // came back empty (country presets exclude references, which were
        // the only other Nigerian material).
        $doc = $this->registry->get('audiovisual')->map(
            self::item(['class' => IwacInstance::CLASS_AUDIOVISUAL]),
            self::values([
                'dcterms:publisher' => [['value' => 'Daarul Hadeethis Salafiyyah']],
                'dcterms:spatial' => [['title' => 'Zaria'], ['title' => 'Nigéria']],
            ]),
            null
        );

        self::assertSame(['Nigeria'], $doc['country_ss']);
    }

    public function testAudiovisualWithNoCountryPlaceStaysUncountried(): void
    {
        $doc = $this->registry->get('audiovisual')->map(
            self::item(['class' => IwacInstance::CLASS_AUDIOVISUAL]),
            self::values(['dcterms:spatial' => [['title' => 'Zaria']]]),
            null
        );

        self::assertArrayNotHasKey('country_ss', $doc);
    }

    public function testThePlaceFallbackIsAudiovisualOnly(): void
    {
        // An article merely MENTIONING a neighbouring country must not
        // acquire its flag — the press subsets keep the newspaper as their
        // single country signal.
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:spatial' => [['title' => 'Nigéria']]]),
            null
        );

        self::assertArrayNotHasKey('country_ss', $doc);
    }

    public function testTheNewspaperDerivationWinsOverThePlaceFallback(): void
    {
        // Radio Oméga is Burkinabè; if a recording's producer IS in the map,
        // that beats a place heading naming somewhere else.
        $doc = $this->registry->get('audiovisual')->map(
            self::item(['class' => IwacInstance::CLASS_AUDIOVISUAL]),
            self::values([
                'dcterms:publisher' => [['value' => 'La Nation']], // Bénin
                'dcterms:spatial' => [['title' => 'Nigéria']],
            ]),
            null
        );

        self::assertSame(['Bénin'], $doc['country_ss']);
    }

    public function testTheNewspaperDerivationWinsOverTheItemSetFallback(): void
    {
        $doc = $this->registry->get('documents')->map(
            self::item([
                'class' => IwacInstance::CLASS_DOCUMENT,
                'item_sets' => [23453], // Burkina Faso set
            ]),
            self::values(['dcterms:publisher' => [['value' => 'La Nation']]]), // Bénin
            null
        );

        self::assertSame(['Bénin'], $doc['country_ss']);
    }

    // ── creator_sort ─────────────────────────────────────────────────────

    /**
     * @return list<array{0:string,1:string}>
     */
    public static function authorSortCases(): array
    {
        return [
            ['Awa Traoré', 'traore awa'],
            ['Jean-Pierre Dupont', 'dupont jean-pierre'],
            ["Marie N'Diaye", "n'diaye marie"],
            ['Cheikh Ahmadou Bamba', 'bamba cheikh ahmadou'],
            ['Plato', 'plato'],
            ['  Élise  Müller  ', 'muller elise'],
            ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authorSortCases')]
    public function testAuthorSortKeyIsSurnameFirstFoldedAndLowercased(
        string $author,
        string $expected
    ): void {
        // "Sort by author" has to read like a bibliography and collate A–Z
        // regardless of the diacritics French bylines are full of.
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:creator' => [['value' => $author]]]),
            null
        );

        if ($expected === '') {
            self::assertArrayNotHasKey('creator_sort', $doc);
            return;
        }
        self::assertSame($expected, $doc['creator_sort']);
    }

    public function testTheSortKeyComesFromTheFirstAuthorOnly(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['dcterms:creator' => [
                ['value' => 'Awa Traoré'],
                ['value' => 'Jean Dupont'],
            ]]),
            null
        );

        self::assertSame(['Awa Traoré', 'Jean Dupont'], $doc['creator_ss']);
        self::assertSame('traore awa', $doc['creator_sort']);
    }

    // ── AI sentiment ─────────────────────────────────────────────────────

    public function testSubjectivityLabelsAreConvertedToTheirNumericScore(): void
    {
        $labels = [
            'Très objectif' => 1.0,
            'Plutôt objectif' => 2.0,
            'Mixte' => 3.0,
            'Plutôt subjectif' => 4.0,
            'Très subjectif' => 5.0,
        ];
        foreach ($labels as $label => $score) {
            $doc = $this->registry->get('articles')->map(
                self::item(),
                // Catalogued as a LINKED category, so the title carries it.
                self::values(['iwac:geminiSubjectiviteScore' => [['vrid' => 5, 'title' => $label]]]),
                null
            );
            self::assertSame($score, $doc['gemini_subjectivite'], $label);
        }
    }

    public function testANumericSubjectivityValueIsAcceptedDirectly(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['iwac:mistralSubjectiviteScore' => [['value' => '4']]]),
            null
        );

        self::assertSame(4.0, $doc['mistral_subjectivite']);
    }

    public function testAnUnrecognisedSubjectivityLabelIsDroppedRatherThanGuessed(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values(['iwac:geminiSubjectiviteScore' => [['value' => 'Assez objectif']]]),
            null
        );

        self::assertArrayNotHasKey('gemini_subjectivite', $doc);
    }

    public function testAllThreeSentimentModelsAreMappedIndependently(): void
    {
        $doc = $this->registry->get('articles')->map(
            self::item(),
            self::values([
                'iwac:geminiPolarite' => [['value' => 'Neutre']],
                'iwac:chatgptCentralite' => [['vrid' => 3, 'title' => 'Centrale']],
            ]),
            null
        );

        self::assertSame(['Neutre'], $doc['gemini_polarite_ss']);
        self::assertSame(['Centrale'], $doc['chatgpt_centralite_ss']);
        self::assertArrayNotHasKey('mistral_polarite_ss', $doc);
    }

    public function testPublicationsCarryNoSentimentFields(): void
    {
        // Sentiment is computed per article upstream, not per issue.
        $doc = $this->registry->get('publications')->map(
            self::item(['class' => IwacInstance::CLASS_PUBLICATION]),
            self::values(['iwac:geminiPolarite' => [['value' => 'Neutre']]]),
            null
        );

        self::assertArrayNotHasKey('gemini_polarite_ss', $doc);
    }

    // ── References ───────────────────────────────────────────────────────

    public function testReferencesReadAuthorsFromBiboAuthorListNotDctermsCreator(): void
    {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values([
                'bibo:authorList' => [['value' => 'Awa Traoré']],
                'dcterms:creator' => [['value' => 'Should Be Ignored']],
            ]),
            null
        );

        self::assertSame(['Awa Traoré'], $doc['creator_ss']);
    }

    public function testTheReferenceTypeComesFromTheResourceClass(): void
    {
        foreach (IwacInstance::REFERENCE_CLASS_LABELS as $classId => $label) {
            $doc = $this->registry->get('references')->map(
                self::item(['class' => $classId]),
                self::values([]),
                null
            );
            self::assertSame('reference', $doc['type_s']);
            self::assertSame([$label], $doc['reference_type_ss']);
        }
    }

    public function testAChapterTakesItsBookTitleFromDctermsAlternative(): void
    {
        // IWAC's convention (verified live); isPartOf is the fallback for
        // records catalogued the other way.
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values([
                'dcterms:alternative' => [['value' => 'Le grand livre']],
                'dcterms:isPartOf' => [['value' => 'Ignored when alternative is set']],
            ]),
            null
        );

        self::assertSame('Le grand livre', $doc['book_title_s']);
    }

    public function testAChapterFallsBackToIsPartOfWhenAlternativeIsAbsent(): void
    {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values(['dcterms:isPartOf' => [['value' => 'Le grand livre']]]),
            null
        );

        self::assertSame('Le grand livre', $doc['book_title_s']);
    }

    public function testNonChapterReferencesUseIsPartOfForTheContainerTitle(): void
    {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => 40]), // Livre
            self::values([
                'dcterms:alternative' => [['value' => 'A variant title, not a container']],
                'dcterms:isPartOf' => [['value' => 'The series']],
            ]),
            null
        );

        self::assertSame('The series', $doc['book_title_s']);
    }

    /**
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function pageRangeCases(): array
    {
        return [
            ['185', '209', '185–209'],  // en dash, not hyphen
            ['185', '185', '185'],      // single page, not "185–185"
            ['185', '', '185'],
            ['', '209', '209'],
            ['', '', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageRangeCases')]
    public function testPageRangesAreFormattedForDisplay(
        string $start,
        string $end,
        string $expected
    ): void {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values([
                'bibo:pageStart' => [['value' => $start]],
                'bibo:pageEnd' => [['value' => $end]],
            ]),
            null
        );

        if ($expected === '') {
            self::assertArrayNotHasKey('pages_s', $doc);
            return;
        }
        self::assertSame($expected, $doc['pages_s']);
    }

    public function testReferencesTakeTheirCountryFromTheReferencesItemSet(): void
    {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER, 'item_sets' => [2217]]),
            self::values([]),
            null
        );

        self::assertSame(["Côte d'Ivoire"], $doc['country_ss']);
    }

    public function testReferencesUseTheRealAbstractNotAnAiSummary(): void
    {
        $doc = $this->registry->get('references')->map(
            self::item(['class' => IwacInstance::CLASS_CHAPTER]),
            self::values([
                'dcterms:abstract' => [['value' => 'A human-written abstract']],
                'bibo:shortDescription' => [['value' => 'An AI summary']],
            ]),
            null
        );

        self::assertSame('A human-written abstract', $doc['abstract']);
    }
}

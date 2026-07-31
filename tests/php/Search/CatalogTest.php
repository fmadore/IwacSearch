<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\SearchDefaults;
use IwacSearch\Search\SurfaceBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pure-data catalogues and the bootstrap builder they feed.
 *
 * `scripts/check-schema-drift.js` already guards these against the schema
 * YAMLs and the client's label/sort tables; what's asserted here is the
 * INTERNAL consistency the drift script can't see — that every preset is
 * self-consistent, and that the bootstrap a surface emits matches the
 * collection it targets.
 */
#[CoversClass(FacetCatalog::class)]
#[CoversClass(PresetCatalog::class)]
#[CoversClass(SurfaceBootstrap::class)]
final class CatalogTest extends TestCase
{
    // ── FacetCatalog ─────────────────────────────────────────────────────

    public function testNormaliseFacetsKeepsOnlyKnownFieldsInSubmittedOrder(): void
    {
        // Admin-submitted; the order is drag-and-drop and must persist.
        self::assertSame(
            ['topics_ss', 'country_ss'],
            FacetCatalog::normaliseFacets(['topics_ss', 'not_a_field', 'country_ss'])
        );
    }

    public function testNormaliseFacetsDedupesAndDropsNonStrings(): void
    {
        self::assertSame(
            ['country_ss'],
            FacetCatalog::normaliseFacets(['country_ss', 'country_ss', 42, null, ['x']])
        );
        self::assertSame([], FacetCatalog::normaliseFacets([]));
    }

    public function testNormaliseFacetsUpgradesRetiredSentimentFieldNames(): void
    {
        // A page block saved before the v4 rename still holds the vendor-slot
        // names in site_block.data. Dropping them would strip a curated
        // block's sentiment facets on upgrade with nothing to explain it.
        self::assertSame(
            ['country_ss', 'gemini_3_flash_preview_polarite_ss'],
            FacetCatalog::normaliseFacets(['country_ss', 'gemini_polarite_ss'])
        );
    }

    public function testNormaliseFacetsDedupesAcrossTheLegacyAliasHop(): void
    {
        // Old and new spelling of one field in the same config must not
        // produce a duplicate facet_by entry.
        self::assertSame(
            ['gemini_3_flash_preview_polarite_ss'],
            FacetCatalog::normaliseFacets(['gemini_polarite_ss', 'gemini_3_flash_preview_polarite_ss'])
        );
    }

    public function testEveryLegacyAliasResolvesToACurrentSchemaField(): void
    {
        // An alias pointing at a field that no longer exists would be worse
        // than no alias: normaliseFacets drops it either way, but the map
        // would read as covering a case it doesn't. Aliases for fields not
        // offered in the picker (the two non-surfaced models) are expected —
        // they still matter to the URL codec's twin map.
        foreach (FacetCatalog::LEGACY_FIELD_ALIASES as $old => $new) {
            self::assertArrayNotHasKey($old, FacetCatalog::FACETABLE_FIELDS, "$old is retired");
            self::assertNotSame($old, $new);
            self::assertSame($new, FacetCatalog::canonicalField($old));
        }

        // Unknown names pass through untouched — canonicalField is a rename
        // hop, not a validator.
        self::assertSame('country_ss', FacetCatalog::canonicalField('country_ss'));
    }

    public function testSortOptionsAreCollectionSpecific(): void
    {
        // The entity index has no relevance or author sort; offering one
        // would produce a Typesense error on an unknown sort field.
        self::assertArrayHasKey('_text_match:desc', FacetCatalog::sortOptionsFor('content'));
        self::assertArrayNotHasKey('_text_match:desc', FacetCatalog::sortOptionsFor('entity'));
        self::assertArrayHasKey('frequency:desc', FacetCatalog::sortOptionsFor('entity'));

        // Anything that isn't the entity card is treated as content.
        self::assertSame(FacetCatalog::sortOptionsFor('content'), FacetCatalog::sortOptionsFor('anything'));
    }

    public function testIsValidSortForMatchesTheOptionSets(): void
    {
        self::assertTrue(FacetCatalog::isValidSortFor('content', 'creator_sort:asc'));
        self::assertFalse(FacetCatalog::isValidSortFor('entity', 'creator_sort:asc'));
        self::assertTrue(FacetCatalog::isValidSortFor('entity', 'frequency:desc'));
        self::assertFalse(FacetCatalog::isValidSortFor('content', 'nonsense:desc'));
    }

    // ── PresetCatalog ────────────────────────────────────────────────────

    public function testEveryPresetIsInternallyConsistent(): void
    {
        foreach (PresetCatalog::all() as $key => $preset) {
            self::assertSame($key, $preset->key, 'the map key must be the preset key');
            self::assertNotSame('', $preset->label, "{$key} needs a label");

            // A scope whose default sort its own collection can't perform
            // would silently fall back — and the SortSelect would show a
            // value it has no option for.
            self::assertTrue(
                FacetCatalog::isValidSortFor($preset->card, $preset->defaultSort),
                "{$key}: default sort {$preset->defaultSort} is invalid for card {$preset->card}"
            );

            foreach ($preset->facets as $field) {
                self::assertArrayHasKey(
                    $field,
                    FacetCatalog::FACETABLE_FIELDS,
                    "{$key}: facet {$field} is not in the catalog"
                );
            }
        }
    }

    public function testTheDefaultAndCustomKeysBehaveAsDocumented(): void
    {
        self::assertNotNull(PresetCatalog::get(PresetCatalog::DEFAULT_KEY));
        // `custom` is a sentinel, not a preset — the block form appends it.
        self::assertNull(PresetCatalog::get(PresetCatalog::CUSTOM));
        self::assertNull(PresetCatalog::get('no-such-preset'));
    }

    public function testCountryPresetsLockTheirCountryAndExcludeReferences(): void
    {
        $benin = PresetCatalog::get('benin');

        self::assertNotNull($benin);
        // Backticks escape the space + apostrophe in country names.
        self::assertSame('country_ss:=`Bénin` && type_s:!=reference', $benin->lockedFilters);
        // The scope IS this country, so repeating it on every card is noise.
        self::assertTrue($benin->hideCountry);
        self::assertNotContains('country_ss', $benin->facets);
    }

    public function testTheApostropheCountryIsQuotedCorrectly(): void
    {
        $ci = PresetCatalog::get('cote-divoire');

        self::assertNotNull($ci);
        self::assertStringContainsString("country_ss:=`Côte d'Ivoire`", $ci->lockedFilters);
        self::assertSame(["Côte d'Ivoire"], array_values($ci->redirectQuery));
    }

    public function testEveryLegacySlugResolvesToItsSuccessorScope(): void
    {
        // These back the /browse/{slug} redirect shim, which exists purely so
        // old bookmarks and external links keep working.
        foreach (['all', 'benin', 'burkina-faso', 'cote-divoire', 'niger', 'nigeria', 'togo', 'references', 'index'] as $slug) {
            self::assertNotNull(
                PresetCatalog::findByLegacySlug($slug),
                "legacy slug {$slug} no longer resolves"
            );
        }
        self::assertNull(PresetCatalog::findByLegacySlug('not-a-slug'));
    }

    public function testRedirectQueriesAreDeclaredNotReverseParsed(): void
    {
        // The redirect appends these to /search rather than trying to
        // reverse-parse the filter string the catalog itself built.
        self::assertSame(['f.country_ss' => 'Niger'], PresetCatalog::get('niger')?->redirectQuery);
        self::assertSame(['f.type_s' => 'reference'], PresetCatalog::get('references')?->redirectQuery);
        // The whole-corpus and entity scopes need no query params.
        self::assertSame([], PresetCatalog::get('all')?->redirectQuery);
        self::assertSame([], PresetCatalog::get('index')?->redirectQuery);
    }

    public function testOnlyTheIndexPresetTargetsTheEntityCollection(): void
    {
        foreach (PresetCatalog::all() as $key => $preset) {
            self::assertSame($key === 'index', $preset->isEntity(), $key);
        }
    }

    public function testOptionsListMirrorsTheCatalogInOrder(): void
    {
        $options = PresetCatalog::optionsList();

        self::assertSame(array_keys(PresetCatalog::all()), array_column($options, 'value'));
        self::assertNotContains(PresetCatalog::CUSTOM, array_column($options, 'value'));
    }

    // ── SurfaceBootstrap ─────────────────────────────────────────────────

    public function testTheCardChoosesBothTheCollectionAndItsFieldSets(): void
    {
        // The entity collection has no ocr_text / abstract / embedding, so
        // passing content's query_by at it makes Typesense reject the search.
        $content = SurfaceBootstrap::build(
            blockId: 'standalone',
            card: PresetCatalog::CARD_CONTENT,
            contentAlias: 'iwac_current',
            indexAlias: 'iwac_index_current',
            prominentFacets: [],
            defaultSort: '_text_match:desc',
        );
        self::assertSame('iwac_current', $content['collection_alias']);
        self::assertSame(SearchDefaults::CONTENT_QUERY_BY, $content['query_by']);
        self::assertSame(SearchDefaults::CONTENT_HIGHLIGHT_FIELDS, $content['highlight_fields']);

        $entity = SurfaceBootstrap::build(
            blockId: 1,
            card: PresetCatalog::CARD_ENTITY,
            contentAlias: 'iwac_current',
            indexAlias: 'iwac_index_current',
            prominentFacets: [],
            defaultSort: 'frequency:desc',
        );
        self::assertSame('iwac_index_current', $entity['collection_alias']);
        self::assertSame(SearchDefaults::ENTITY_QUERY_BY, $entity['query_by']);
    }

    public function testTheEntityIndexAliasIsAdvertisedOnEverySurface(): void
    {
        // Even content surfaces need it: the autocomplete federates to the
        // entity index to reconcile aliases ("RCI" → "Radio Côte d'Ivoire").
        $content = SurfaceBootstrap::build(
            blockId: 'standalone',
            card: PresetCatalog::CARD_CONTENT,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
        );

        self::assertSame('i', $content['index_collection_alias']);
    }

    public function testDiversificationIsOptInAndNeverAppliedToTheEntityIndex(): void
    {
        $off = SurfaceBootstrap::build(
            blockId: 1,
            card: PresetCatalog::CARD_CONTENT,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
        );
        self::assertArrayNotHasKey('diversify_tag', $off);

        $on = SurfaceBootstrap::build(
            blockId: 1,
            card: PresetCatalog::CARD_CONTENT,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
            diversifyTag: 'diversify',
        );
        self::assertSame('diversify', $on['diversify_tag']);
        self::assertSame(0.7, $on['diversity_lambda']);

        // MMR measures similarity on `embedding`, which the entity schema
        // doesn't have.
        $entity = SurfaceBootstrap::build(
            blockId: 1,
            card: PresetCatalog::CARD_ENTITY,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
            diversifyTag: 'diversify',
        );
        self::assertArrayNotHasKey('diversify_tag', $entity);
    }

    public function testEndpointsAreRawStemsForTheViewToResolve(): void
    {
        // basePath() is applied by the mount partial, which is why these must
        // stay unprefixed here — resolving twice double-prefixes them under a
        // subdirectory install.
        $bootstrap = SurfaceBootstrap::build(
            blockId: 1,
            card: PresetCatalog::CARD_CONTENT,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
        );

        self::assertSame(SurfaceBootstrap::ENDPOINT_STEMS, $bootstrap['endpoints']);
        self::assertStringStartsWith('/', $bootstrap['endpoints']['token']);
    }

    public function testEverySurfaceEmitsTheSameKeySet(): void
    {
        // The client mounts one component for all three surfaces, so a key
        // present on one and missing on another is how they drift.
        $keys = static fn(string $card): array => array_keys(SurfaceBootstrap::build(
            blockId: 1,
            card: $card,
            contentAlias: 'c',
            indexAlias: 'i',
            prominentFacets: [],
            defaultSort: '',
        ));

        self::assertSame($keys(PresetCatalog::CARD_CONTENT), $keys(PresetCatalog::CARD_ENTITY));
    }

    public function testEveryPresetProducesABootstrapThatKeepsItsSort(): void
    {
        foreach (PresetCatalog::all() as $key => $preset) {
            $bootstrap = SurfaceBootstrap::build(
                blockId: 1,
                card: $preset->card,
                contentAlias: 'iwac_current',
                indexAlias: 'iwac_index_current',
                prominentFacets: $preset->facets,
                defaultSort: $preset->defaultSort,
                lockedFilters: $preset->lockedFilters,
                hideCountry: $preset->hideCountry,
            );

            self::assertSame($preset->defaultSort, $bootstrap['default_sort'], $key);
            self::assertSame($preset->lockedFilters, $bootstrap['locked_filters'], $key);
            self::assertSame($preset->hideCountry, $bootstrap['hide_country'], $key);
        }
    }
}

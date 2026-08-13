<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * The catalogue of search scopes a page block can target.
 *
 * Single source of truth for the {@see Preset} list, the successor to the
 * old seeder + `iwac_browse_config` machinery. The facet stacks below were
 * lifted verbatim from the four seeders (CountrySeeder, AllCountriesSeeder,
 * ReferencesSeeder, IndexSeeder) and the country list from the former
 * Browse\Countries, so retiring those classes (Phase C) loses nothing.
 *
 * Pure data, no services — mirrors {@see \IwacSearch\Browse\FacetCatalog}.
 * The block form reads {@see optionsList()} to build its Scope dropdown and
 * {@see get()} to resolve the chosen scope into a bootstrap at render time.
 */
final class PresetCatalog
{
    public const CARD_CONTENT = 'content';
    public const CARD_ENTITY  = 'entity';

    /** The default scope a fresh block starts on. */
    public const DEFAULT_KEY = 'all';

    /**
     * Sentinel for "no preset" — the block exposes the raw locked_filters /
     * facets / sort fields and queries the content collection directly.
     * Not a real {@see Preset}; handled by the block form/render.
     */
    public const CUSTOM = 'custom';

    /**
     * Content facet stack for the whole corpus (Country first — the natural
     * slicer when nothing is locked). From AllCountriesSeeder::DEFAULT_FACETS.
     *
     * @var list<string>
     */
    private const CONTENT_ALL_FACETS = [
        'country_ss',
        'type_s',
        'has_fulltext',
        'newspaper_ss',
        // The corpus-wide and per-country scopes mix press with audiovisual,
        // and since the 2026 YouTube ingest the video material is no longer a
        // rounding error (1,099 of the 1,146 class-38 records, all Burkinabè
        // so far). Without this the only publisher facet on those scopes is
        // newspaper_ss, which audiovisual deliberately no longer populates —
        // so a visitor could see the videos but not slice them by channel.
        // Empty (and self-hiding) on a result set with no audiovisual in it,
        // exactly as newspaper_ss already is on an audiovisual-only one.
        'channel_ss',
        'language_ss',
        'places_ss',
        'persons_ss',
        'organisations_ss',
        'topics_ss',
        'gpt_5_6_luna_polarite_ss',
        'gpt_5_6_luna_centralite_ss',
        'gpt_5_6_luna_subjectivite',
    ];

    /**
     * Content facet stack for a single-country scope — drops country_ss
     * (it's locked). From CountrySeeder::DEFAULT_FACETS.
     *
     * @var list<string>
     */
    private const CONTENT_COUNTRY_FACETS = [
        'type_s',
        'has_fulltext',
        'newspaper_ss',
        'channel_ss',
        'language_ss',
        'places_ss',
        'persons_ss',
        'organisations_ss',
        'topics_ss',
        'gpt_5_6_luna_polarite_ss',
        'gpt_5_6_luna_centralite_ss',
        'gpt_5_6_luna_subjectivite',
    ];

    /**
     * Audiovisual scope: the carrier/platform split leads (it is the coarsest
     * cut through the class — web video vs deposited DVD/CD), then the
     * channel, then the ordinary geography / authority stack.
     *
     * No sentiment trio: the annotators run over press articles, so on this
     * scope those three facets would render empty on every result set.
     * `has_fulltext` stays — for a video it means a transcript exists.
     *
     * @var list<string>
     */
    private const AUDIOVISUAL_FACETS = [
        'media_platform_s',
        'media_kind_s',
        'channel_ss',
        'country_ss',
        'language_ss',
        'has_fulltext',
        'places_ss',
        'persons_ss',
        'organisations_ss',
        'topics_ss',
        'date_decade_ss',
    ];

    /**
     * Bibliography facet stack tuned for academic literature: journal /
     * publisher (publisher_s) joins the stack, the split entity facets
     * (topics / persons / organisations) are replaced by the MERGED
     * subjects_ss, and spatial coverage (places_ss) is dropped — on
     * citations it read as noise next to country_ss.
     *
     * @var list<string>
     */
    private const REFERENCES_FACETS = [
        'country_ss',
        'reference_type_ss',
        'creator_ss',
        'publisher_s',
        'subjects_ss',
        'language_ss',
        'date_decade_ss',
    ];

    /**
     * Entity-collection facet stack. is_part_of_ss slices organisations by
     * category ("Organisation islamique", …) via dcterms:isPartOf.
     *
     * @var list<string>
     */
    private const ENTITY_FACETS = [
        'entity_type_s',
        'is_part_of_ss',
        'country_ss',
    ];

    /**
     * IWAC country corpus boundary. `name` must match the `country_ss`
     * facet value EXACTLY (Typesense filter_by is accent + case sensitive);
     * `slug` is the legacy /browse/{slug} segment. Absorbed from the former
     * Browse\Countries so this catalogue has no Browse\ dependency.
     *
     * @var list<array{name: string, slug: string}>
     */
    private const COUNTRIES = [
        ['name' => 'Bénin',         'slug' => 'benin'],
        ['name' => 'Burkina Faso',  'slug' => 'burkina-faso'],
        ['name' => "Côte d'Ivoire", 'slug' => 'cote-divoire'],
        ['name' => 'Niger',         'slug' => 'niger'],
        ['name' => 'Nigeria',       'slug' => 'nigeria'],
        ['name' => 'Togo',          'slug' => 'togo'],
    ];

    /** @var ?array<string, Preset> Memoized preset map — get()/optionsList()/findByLegacySlug() all call all(). */
    private static ?array $cache = null;

    /**
     * Build the ordered preset map: All · 6 countries · Audiovisual ·
     * References · Index.
     *
     * @return array<string, Preset>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $presets = [];

        // Whole corpus — no lock.
        $presets['all'] = new Preset(
            key:           'all',
            label:         'All content', // @translate
            card:          self::CARD_CONTENT,
            lockedFilters: '',
            facets:        self::CONTENT_ALL_FACETS,
            defaultSort:   'date:desc',
            legacySlug:    'all',
        );

        // One scope per country — content collection, country locked.
        // References are EXCLUDED: a country page block surfaces the primary
        // sources (press, publications, documents, audiovisual); the academic
        // bibliography has its own References scope, and per-country
        // references were drowning the archival material on country pages.
        foreach (self::COUNTRIES as $country) {
            $presets[$country['slug']] = new Preset(
                key:           $country['slug'],
                label:         $country['name'], // @translate
                card:          self::CARD_CONTENT,
                // Backticks escape spaces + the apostrophe in Côte d'Ivoire.
                lockedFilters: sprintf('country_ss:=`%s` && type_s:!=reference', $country['name']),
                facets:        self::CONTENT_COUNTRY_FACETS,
                defaultSort:   'date:desc',
                legacySlug:    $country['slug'],
                // The scope IS this country — don't repeat it on every card.
                hideCountry:   true,
                redirectQuery: ['f.country_ss' => $country['name']],
            );
        }

        // Audiovisual — content collection, type locked. A scope of its own
        // because the class doubled in size by an order of magnitude in 2026
        // (47 deposited recordings → 1,146 records, the rest YouTube), and
        // none of its distinguishing facets (platform, media kind, channel)
        // belong in the press-oriented stacks above.
        $presets['audiovisual'] = new Preset(
            key:           'audiovisual',
            label:         'Audiovisual (radio, TV, YouTube)', // @translate
            card:          self::CARD_CONTENT,
            lockedFilters: 'type_s:=audiovisual',
            facets:        self::AUDIOVISUAL_FACETS,
            defaultSort:   'date:desc',
            // No legacySlug: /browse/ never had an audiovisual page, so there
            // is no old bookmark to redirect.
        );

        // Bibliographic references — content collection, type locked.
        $presets['references'] = new Preset(
            key:           'references',
            label:         'References (bibliography)', // @translate
            card:          self::CARD_CONTENT,
            lockedFilters: 'type_s:=reference',
            facets:        self::REFERENCES_FACETS,
            defaultSort:   'date:desc',
            legacySlug:    'references',
            redirectQuery: ['f.type_s' => 'reference'],
        );

        // Entity index — the SEPARATE authority collection.
        $presets['index'] = new Preset(
            key:           'index',
            label:         'Entity index (people, places, topics…)', // @translate
            card:          self::CARD_ENTITY,
            lockedFilters: '',
            facets:        self::ENTITY_FACETS,
            defaultSort:   'frequency:desc',
            legacySlug:    'index',
        );

        return self::$cache = $presets;
    }

    public static function get(string $key): ?Preset
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The IWAC country names, exactly as `country_ss` stores them (accents
     * included — Typesense filter_by is accent- and case-sensitive). The
     * country scopes above filter on these strings, so they double as the
     * option list for the page block's Country picker
     * ({@see ScopeFilters::staticOptions()}) rather than that picker keeping
     * a second, drift-prone copy.
     *
     * @return list<string>
     */
    public static function countryNames(): array
    {
        return array_column(self::COUNTRIES, 'name');
    }

    /**
     * Resolve a legacy /browse/{slug} segment to the preset that replaced it.
     * Used by the Phase-C redirect shim.
     */
    public static function findByLegacySlug(string $slug): ?Preset
    {
        foreach (self::all() as $preset) {
            if ($preset->legacySlug === $slug) {
                return $preset;
            }
        }
        return null;
    }

    /**
     * The Scope dropdown options for the block form. The `custom` escape
     * hatch is appended by the form itself, not listed here.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function optionsList(): array
    {
        $out = [];
        foreach (self::all() as $preset) {
            $out[] = ['value' => $preset->key, 'label' => $preset->label];
        }
        return $out;
    }
}

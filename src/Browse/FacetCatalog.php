<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

/**
 * Single source of truth for the UI controls that surface browse-config
 * choices:
 *
 *   - FACETABLE_FIELDS — the schema fields an admin can mark as a
 *                        prominent (above-the-fold) facet, with their
 *                        display labels.
 *   - SORT_OPTIONS / SORT_OPTIONS_ENTITY — the content and entity-index sort
 *                        orders the block can default to. Both mirror the
 *                        client sort sets in src/svelte/lib/i18n.ts so the
 *                        block-config picker and the live SortSelect agree.
 *   - RENDER_MODES     — shared by the page block today; kept here so
 *                        later surfaces (embed snippets, external
 *                        integrations) can pull from the same list
 *                        without re-copying.
 *
 * The page-block form (IwacSearchBlock::form) reads from these arrays, so
 * adding a new facetable field or a new sort option is a one-line change
 * here rather than a sweep across the places that must stay in sync.
 *
 * Keep this class pure data — no services, no I/O — so it stays trivially
 * unit-testable and safe to reference from scripts/check-schema-drift.js.
 */
final class FacetCatalog
{
    /**
     * Schema field name => human-readable label.
     *
     * Order here is the default display order in admin pickers; the
     * stored `prominent_facets` array preserves whatever order the
     * admin saved, which wins at render time.
     */
    public const FACETABLE_FIELDS = [
        'type_s'             => 'Type',
        'entity_type_s'      => 'Entity type',
        'reference_type_ss'  => 'Reference type',
        // Boolean: full text (bibo:content) exists AND is publicly visible.
        // Only the primary-source subsets carry it (references never do).
        'has_fulltext'       => 'Full text available',
        // Journal title (journal articles) or publisher (books/chapters/…).
        'publisher_s'        => 'Journal / Publisher',
        // MERGED dcterms:subject facet (persons + organisations + topics in
        // one list) — used on the references scope.
        'subjects_ss'        => 'Subjects (combined)',
        // dcterms:isPartOf on entity items — organisation category on the
        // entity index.
        'is_part_of_ss'      => 'Part of (entity category)',
        // Authorship — mapped from Omeka dcterms:creator to creator_ss by
        // AbstractMapper; facet:true in schema.yaml and populated in the live
        // index, but it was never in this catalog, so it couldn't be picked in
        // the admin or survive a save (normaliseFacets dropped it). Used on the
        // references browse page.
        'creator_ss'         => 'Author',
        'country_ss'         => 'Country',
        'newspaper_ss'       => 'Newspaper',
        // Audiovisual: the producing channel / broadcaster (dcterms:publisher
        // on class 38). Separate from newspaper_ss on purpose — see
        // AudiovisualMapper. media_kind_s / media_platform_s are the
        // normalised dcterms:type / dcterms:medium headings, so the filter
        // values are stable enum keys rather than French display strings.
        'channel_ss'         => 'Channel / Producer',
        'media_kind_s'       => 'Media kind',
        'media_platform_s'   => 'Format / Platform',
        'rights_s'           => 'Rights',
        'language_ss'        => 'Language',
        'topics_ss'          => 'Topics',
        'persons_ss'         => 'Persons',
        'places_ss'          => 'Places',
        'organisations_ss'   => 'Organisations',
        'events_ss'          => 'Events',
        'date_decade_ss'     => 'Decade',
        // Sentiment trio — rendered together under one collapsible
        // "Sentiment" group in the client. subjectivite is a 1–5 numeric
        // facet. The other two annotating models (mistral_small_2603_*,
        // deepseek_v4_flash_0731_*) are indexed and facetable in schema.yaml
        // but deliberately NOT offered here: three parallel sentiment trios
        // in the admin picker read as noise, and the panel only has room for
        // one. Cross-model comparison is a dataset job, not a search-sidebar
        // one.
        //
        // GPT-5.6 Luna holds the surfaced slot because it is the only
        // generation-2 annotator complete on all three properties (12,305
        // articles); DeepSeek is ~489 subjectivity values short, and on
        // centralité the Mistral family is a documented systematic outlier.
        'gpt_5_6_luna_polarite_ss'   => 'Polarity (GPT-5.6 Luna)',
        'gpt_5_6_luna_centralite_ss' => 'Centrality (GPT-5.6 Luna)',
        'gpt_5_6_luna_subjectivite'  => 'Subjectivity (GPT-5.6 Luna)',
    ];

    /**
     * Retired field name => current one.
     *
     * Page-block configs are JSON in `site_block.data` and hold field names
     * verbatim, so a block saved before a rename still names the old field.
     * Without an entry here normaliseFacets() silently DROPS it — a curated
     * block comes back from the upgrade missing that facet, with nothing in
     * the UI to explain why. Mapping on read (rather than migrating
     * `site_block.data` in Module::upgrade) keeps the module's "owns no
     * tables, writes no migrations" lifecycle intact; a block rewrites itself
     * to the new names the next time an admin saves it.
     *
     * EMPTY on purpose since v6. The map's only entries were the v4 sentiment
     * rename (`gemini_polarite_ss` → `gemini_3_flash_preview_polarite_ss`, …),
     * and v6 dropped the generation-1 sentiment fields from the index
     * entirely. There is no honest target left: aliasing them onto a
     * generation-2 model would make a saved block — or a bookmarked share
     * link — quietly return a DIFFERENT model's judgement under the name it
     * was saved with. Retired sentiment facets are dropped instead, and an
     * admin re-picks them.
     *
     * Keep the constant (and its urlState.ts twin) rather than deleting it:
     * both are wired into normaliseFacets, the URL decoder and the drift
     * guard, so the next rename is one line here plus one there.
     *
     * @var array<string, string>
     */
    public const LEGACY_FIELD_ALIASES = [
    ];

    public const SORT_OPTIONS = [
        '_text_match:desc' => 'Relevance',
        'date:desc'        => 'Newest first',
        'date:asc'         => 'Oldest first',
        // Optional scalar creator_sort field — author-less docs sort last
        // (missing_values rule applied in typesense.ts + InitialResponseRenderer).
        // Mirrors the client content sort set in src/svelte/lib/i18n.ts
        // (sortOptions, card='content').
        'creator_sort:asc' => 'Author (A–Z)',
    ];

    /**
     * Sort orders for the entity index (the authority collection). Its
     * sortable fields differ from content's — frequency (occurrence count)
     * and title, no relevance/author — so it carries its own list. Mirrors
     * the client entity sort set in src/svelte/lib/i18n.ts (card='entity').
     */
    public const SORT_OPTIONS_ENTITY = [
        'frequency:desc' => 'Most mentioned',
        'frequency:asc'  => 'Least mentioned',
        // frequency counts every role at once, so it cannot answer "who wrote
        // the most" — authored_count can. Entities that signed nothing carry
        // a 0 and sort to the bottom.
        'authored_count:desc' => 'Most authored',
        'title:asc'      => 'Title (A–Z)',
        'date:desc'      => 'Newest first',
    ];

    public const RENDER_MODES = [
        'full'         => 'Full (search box + facets + results)',
        'compact'      => 'Compact (search box only — links to /search)',
        'results-only' => 'Results only (curated grid, no search box)',
    ];

    /** Resolve a possibly-retired field name to the one the schema declares. */
    public static function canonicalField(string $field): string
    {
        // Via a local, so the lookup keeps type-checking while the map is
        // empty: PHPStan constant-folds the literal to `array{}` and would
        // otherwise call every offset on it a guaranteed miss.
        /** @var array<string, string> $aliases */
        $aliases = self::LEGACY_FIELD_ALIASES;

        return $aliases[$field] ?? $field;
    }

    /**
     * Filter an arbitrary admin-submitted list of field names down to
     * the ones that actually exist in FACETABLE_FIELDS. Preserves the
     * submitted order so an admin can reorder via drag-and-drop in
     * the picker and have the new order persist. Applied on save by
     * IwacSearchBlock::onHydrate().
     *
     * Retired names are upgraded via LEGACY_FIELD_ALIASES first, so a block
     * saved before a rename keeps its facets. Deduplication happens AFTER
     * that hop — a config naming both the old and the new spelling collapses
     * to one entry rather than emitting a duplicate facet_by field.
     *
     * @param iterable<mixed> $submitted
     * @return list<string>
     */
    public static function normaliseFacets(iterable $submitted): array
    {
        $seen = [];
        $out = [];
        foreach ($submitted as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = self::canonicalField($field);
            if (!isset(self::FACETABLE_FIELDS[$field])) {
                continue;
            }
            if (isset($seen[$field])) {
                continue;
            }
            $seen[$field] = true;
            $out[] = $field;
        }
        return $out;
    }

    /**
     * The sort orders valid for a given card. Content surfaces use
     * SORT_OPTIONS; the entity index has its own sortable fields
     * (SORT_OPTIONS_ENTITY).
     *
     * @return array<string, string> sort value => label
     */
    public static function sortOptionsFor(string $card): array
    {
        return $card === 'entity' ? self::SORT_OPTIONS_ENTITY : self::SORT_OPTIONS;
    }

    /** Whether $sort is a valid order for the given card's collection. */
    public static function isValidSortFor(string $card, string $sort): bool
    {
        return isset(self::sortOptionsFor($card)[$sort]);
    }
}

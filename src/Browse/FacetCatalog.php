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
 * Keep this class pure data — no services, no I/O. Tests rely on it
 * being instantiation-free.
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
        // Authorship — mapped from the HF `author` field to creator_ss by
        // AbstractMapper; facet:true in schema.yaml and populated in the live
        // index, but it was never in this catalog, so it couldn't be picked in
        // the admin or survive a save (normaliseFacets dropped it). Used on the
        // references browse page.
        'creator_ss'         => 'Author',
        'country_ss'         => 'Country',
        'newspaper_ss'       => 'Newspaper',
        'language_ss'        => 'Language',
        'topics_ss'          => 'Topics',
        'persons_ss'         => 'Persons',
        'places_ss'          => 'Places',
        'organisations_ss'   => 'Organisations',
        'events_ss'          => 'Events',
        'date_decade_ss'     => 'Decade',
        // Sentiment trio — rendered together under one collapsible
        // "Sentiment" group in the client. All three are already indexed
        // (Gemini model); centrality + subjectivity were surfaced in
        // 0.2.22. subjectivite is a 1–5 numeric facet.
        'gemini_polarite_ss'    => 'Polarity (Gemini)',
        'gemini_centralite_ss'  => 'Centrality (Gemini)',
        'gemini_subjectivite'   => 'Subjectivity (Gemini)',
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
        'title:asc'      => 'Title (A–Z)',
        'date:desc'      => 'Newest first',
    ];

    public const RENDER_MODES = [
        'full'         => 'Full (search box + facets + results)',
        'compact'      => 'Compact (search box only — links to /search)',
        'results-only' => 'Results only (curated grid, no search box)',
    ];

    /**
     * Return the facetable fields shaped for the Svelte admin picker:
     * a stable list of {name, label} pairs rather than an associative
     * array. PHP associative arrays serialise to JSON objects, which
     * lose their insertion order under some consumers — lists never do.
     *
     * @return list<array{name: string, label: string}>
     */
    public static function facetableFieldsList(): array
    {
        $out = [];
        foreach (self::FACETABLE_FIELDS as $name => $label) {
            $out[] = ['name' => $name, 'label' => $label];
        }
        return $out;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function sortOptionsList(): array
    {
        $out = [];
        foreach (self::SORT_OPTIONS as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }

    /**
     * Filter an arbitrary admin-submitted list of field names down to
     * the ones that actually exist in FACETABLE_FIELDS. Preserves the
     * submitted order so an admin can reorder via drag-and-drop in
     * the picker and have the new order persist.
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

    public static function isValidSort(string $sort): bool
    {
        return isset(self::SORT_OPTIONS[$sort]);
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

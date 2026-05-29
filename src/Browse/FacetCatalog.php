<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

/**
 * Single source of truth for the UI controls that surface browse-config
 * choices:
 *
 *   - FACETABLE_FIELDS — the 12 schema fields an admin can mark as a
 *                        prominent (above-the-fold) facet, with their
 *                        display labels.
 *   - SORT_OPTIONS     — the three sort orders the block / browse page
 *                        can default to.
 *   - RENDER_MODES     — shared by the page block today; kept here so
 *                        later surfaces (embed snippets, external
 *                        integrations) can pull from the same list
 *                        without re-copying.
 *
 * Both the page-block form (IwacSearchBlock::form) and the admin Svelte
 * app read from these arrays, so adding a new facetable field or a new
 * sort option is a one-line change here rather than a sweep across
 * three places that must stay in sync.
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
        'reference_type_ss'  => 'Reference type',
        'country_ss'         => 'Country',
        'newspaper_ss'       => 'Newspaper',
        'language_ss'        => 'Language',
        'topics_ss'          => 'Topics',
        'persons_ss'         => 'Persons',
        'places_ss'          => 'Places',
        'organisations_ss'   => 'Organisations',
        'events_ss'          => 'Events',
        'date_decade_ss'     => 'Decade',
        'lda_topic_label'    => 'LDA topic',
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
}

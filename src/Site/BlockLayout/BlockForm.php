<?php

declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\ScopeFilters;
use IwacSearch\Search\SearchDefaults;

/**
 * Everything the page block's admin form shows, worked out before any markup.
 *
 * The form used to be ~340 lines of HTML inside IwacSearchBlock::form(),
 * interleaved with the decisions it renders: which scope a block with no
 * `preset` key falls back to, which saved values the index no longer offers,
 * when a picker is truncated. The markup now lives in a view partial
 * ({@see TEMPLATE}) and the decisions here, which — unlike the block, which
 * extends Omeka's AbstractBlockLayout — loads without Omeka and so can be
 * unit-tested (tests/php/Site/BlockFormTest.php).
 *
 * The partial receives only what this returns: plain strings, ints, bools and
 * lists, English source strings it translates itself, and no services.
 */
final class BlockForm
{
    /** The partial that renders the form ({@see variables()}). */
    public const TEMPLATE = 'common/block-layout/iwac-search-block-form';

    /**
     * Omeka's block form contract: every field posts under this prefix, and
     * the page editor substitutes the block's index for the placeholder.
     */
    public const NAME_PREFIX = 'o:block[__blockIndex__][o:data]';

    /**
     * The partial's variables.
     *
     * @param array<string, mixed> $data   Stored block data; [] for a new block.
     * @param bool                 $saved  Whether the block already exists. A new
     *                                     block defaults to the whole-corpus scope;
     *                                     a saved one with no `preset` key predates
     *                                     the Scope dropdown and stays Custom, so
     *                                     its stored locked_filters keep driving it.
     * @param string               $uid    Suffix that keeps each field's id unique
     *                                     when several blocks are edited on one page.
     * @param array<string, list<array{value: string, count: int}>>|null $counts
     *                                     Live facet values ({@see FacetValueLookup});
     *                                     null when the index is unreachable.
     * @return array<string, mixed>
     */
    public static function variables(array $data, bool $saved, string $uid, ?array $counts): array
    {
        $prominent = $data['prominent_facets'] ?? SearchDefaults::CONTENT_PROMINENT_FACETS;

        return [
            'name_prefix'      => self::NAME_PREFIX,
            'uid'              => $uid,
            'preset'           => (string) ($data['preset']
                ?? ($saved ? PresetCatalog::CUSTOM : PresetCatalog::DEFAULT_KEY)),
            'preset_options'   => PresetCatalog::optionsList(),
            'custom_preset'    => PresetCatalog::CUSTOM,
            'mode'             => (string) ($data['mode'] ?? 'full'),
            'render_modes'     => FacetCatalog::RENDER_MODES,
            'title'            => (string) ($data['title'] ?? ''),
            'intro_html'       => (string) ($data['intro_html'] ?? ''),
            'locked_filters'   => (string) ($data['locked_filters'] ?? ''),
            // Default mirrors what the standalone /search route shows — the
            // SAME constant, so the two can't drift.
            'prominent_facets' => is_array($prominent)
                ? array_values(array_filter($prominent, 'is_string'))
                : [],
            'facetable_fields' => FacetCatalog::FACETABLE_FIELDS,
            // Empty = "use the scope's own default sort". An explicit choice
            // overrides it when valid for the scope's collection (see render()).
            'default_sort'     => (string) ($data['default_sort'] ?? ''),
            'content_sorts'    => FacetCatalog::sortOptionsFor(PresetCatalog::CARD_CONTENT),
            'entity_sorts'     => FacetCatalog::sortOptionsFor(PresetCatalog::CARD_ENTITY),
            'results_per_page' => (int) ($data['results_per_page'] ?? 10),
            'pickers'          => self::pickers(ScopeFilters::normalise($data['filter_values'] ?? null), $counts),
            'index_unreachable' => $counts === null,
            'max_values'       => FacetValueLookup::MAX_VALUES,
        ];
    }

    /**
     * The multi-select value pickers — one checkbox list per
     * {@see ScopeFilters::FIELDS} entry.
     *
     * Options come from the live index where the vocabulary is open data
     * (newspaper titles, languages, the categorical sentiment labels) and from
     * the closed enums this codebase already declares otherwise (types,
     * countries, the 1–5 subjectivity scale). The index is also asked for
     * document counts on the closed enums, since "Photographie (0)" is what
     * tells an editor not to bother scoping to it.
     *
     * Nothing here is required for the form to work: an unreachable index
     * costs the open pickers their options and every picker its counts, and
     * the partial says so — the page-edit screen still renders, and the raw
     * locked_filters field is still a complete escape hatch.
     *
     * @param  array<string, list<string>> $selected Normalised current selection.
     * @param  array<string, list<array{value: string, count: int}>>|null $counts
     * @return list<array{field: string, label: string, truncated: bool, options: list<array{value: string, label: string, translatable: bool, count: ?int, checked: bool, stale: bool}>}>
     */
    public static function pickers(array $selected, ?array $counts): array
    {
        $pickers = [];
        foreach (ScopeFilters::FIELDS as $field) {
            $chosen = $selected[$field] ?? [];
            $options = [];
            foreach (self::pickerOptions($field, $counts, $chosen) as $option) {
                $label = ScopeFilters::valueLabel($field, $option['value']);
                $options[] = $option + [
                    'label'        => $label,
                    // Only the closed-enum labels are translatable source
                    // strings; a newspaper title is data and goes through
                    // verbatim.
                    'translatable' => $label !== $option['value'],
                    'checked'      => in_array($option['value'], $chosen, true),
                ];
            }
            $pickers[] = [
                'field'     => $field,
                'label'     => ScopeFilters::label($field),
                'truncated' => FacetValueLookup::isTruncated($counts, $field),
                'options'   => $options,
            ];
        }
        return $pickers;
    }

    /**
     * The option list for one picker: the field's own values, plus any
     * already-saved value the index doesn't (or can't) offer.
     *
     * That last part is the whole reason this isn't inline. A saved value
     * missing from the rendered list posts back as unchecked, so re-saving the
     * block would silently drop it — losing a curated scope to a newspaper
     * leaving the corpus, or to Typesense being down at the wrong moment.
     * Rendering it checked and flagged keeps the save round-trip lossless.
     *
     * @param  array<string, list<array{value: string, count: int}>>|null $counts
     * @param  list<string> $selected
     * @return list<array{value: string, count: ?int, stale: bool}>
     */
    private static function pickerOptions(string $field, ?array $counts, array $selected): array
    {
        $live = $counts[$field] ?? [];

        $liveCounts = [];
        foreach ($live as $entry) {
            $liveCounts[$entry['value']] = $entry['count'];
        }

        // Closed enums keep their declared order (which is meaningful — the
        // subjectivity scale runs 1→5); open vocabularies keep Typesense's
        // count-descending order.
        $static = ScopeFilters::staticOptions($field);
        $values = $static !== [] ? $static : array_column($live, 'value');

        $out  = [];
        $seen = [];
        foreach ($values as $value) {
            $seen[$value] = true;
            $out[] = [
                'value' => $value,
                'count' => $liveCounts[$value] ?? null,
                'stale' => false,
            ];
        }
        foreach ($selected as $value) {
            if (!isset($seen[$value])) {
                $out[] = ['value' => $value, 'count' => null, 'stale' => true];
            }
        }

        return $out;
    }
}

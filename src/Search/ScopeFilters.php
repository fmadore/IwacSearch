<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use IwacSearch\Browse\FacetCatalog;

/**
 * The value pickers a page block narrows its scope with, and the compiler
 * that turns an admin's selection into a Typesense `filter_by` clause.
 *
 * Before this existed, a block could lock its scope two ways: pick ONE of
 * the {@see PresetCatalog} scopes (which meant one country at a time), or
 * hand-type raw Typesense syntax into `locked_filters`. Neither let an
 * editor build "Bénin + Togo, articles and documents only" without knowing
 * the query language — and hand-typing is exactly where the accented
 * country values (`Bénin`, `Côte d'Ivoire`) go wrong, since Typesense
 * filter_by matching is accent- and case-sensitive.
 *
 * So the block form now also stores:
 *
 *     "filter_values": {
 *       "country_ss": ["Bénin", "Togo"],
 *       "type_s":     ["article", "document"]
 *     }
 *
 * compiled here to `country_ss:=[`Bénin`,`Togo`] && type_s:=[`article`,`document`]`
 * — values within a field OR, fields AND. Exactly the semantics the public
 * facet panel already has, which is deliberate: this mirrors `buildFilterBy`
 * in src/svelte/lib/queryBuilders.ts, so an admin-locked scope and a visitor's
 * own facet selection compose into one predictable filter string.
 *
 * The result is ANDed onto whatever the block's Scope already locks (see
 * {@see combine}), so these pickers apply to preset scopes too, not just
 * Custom. A block with no `filter_values` compiles to '' and behaves exactly
 * as it did before the key existed.
 *
 * Like {@see FacetCatalog} and {@see PresetCatalog}, pure data + pure
 * functions — no services, no I/O. The LIVE values an editor picks from
 * (newspaper titles, languages, sentiment labels) come from
 * {@see FacetValueLookup}, which asks the index; everything here is the part
 * that must keep working when the index is unreachable.
 */
final class ScopeFilters
{
    /**
     * Fields offered as multi-select value pickers in the block form, in
     * display order.
     *
     * Every entry MUST also be a {@see FacetCatalog::FACETABLE_FIELDS} key
     * (asserted in CatalogTest): the field label is read from there, so the
     * picker and the facet checkbox list above it can't drift into naming
     * the same field two different things.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'type_s',
        'country_ss',
        'newspaper_ss',
        'language_ss',
        // Only the gpt_5_6_luna_* trio, matching the facet picker and the
        // public panel — see the FacetCatalog note on why the other two
        // annotating models stay out of the UI.
        'gpt_5_6_luna_polarite_ss',
        'gpt_5_6_luna_centralite_ss',
        'gpt_5_6_luna_subjectivite',
    ];

    /**
     * Fields whose filter_by values must be emitted BARE, not backtick-quoted.
     * Typesense rejects a backticked number on a numeric field ("Numerical
     * field has an invalid comparator"). Mirrors NUMERIC_FACET_FIELDS in
     * src/svelte/lib/i18n.ts.
     *
     * @var list<string>
     */
    public const NUMERIC_FIELDS = [
        'gpt_5_6_luna_subjectivite',
    ];

    /**
     * Values known WITHOUT asking the index, because they're a closed enum
     * this codebase already defines elsewhere. Fields absent from this map
     * (newspaper titles, languages, the categorical sentiment labels) are
     * open data vocabularies and get their options from the live index.
     *
     * `country_ss` is in {@see staticOptions()} rather than here because it
     * reads from PresetCatalog, which is the existing single source for the
     * exact, accented country strings.
     *
     * @var array<string, list<string>>
     */
    private const STATIC_OPTIONS = [
        // The type_s enum — data/schema.yaml declares it inline on the field.
        'type_s' => ['article', 'publication', 'document', 'audiovisual', 'photograph', 'reference'],
        // The 1–5 subjectivity scale (docs/data-sources.md: "Très objectif"→1
        // … "Très subjectif"→5). Static rather than live because Typesense
        // returns float facet values in a format we'd then have to match
        // against ("1" vs "1.0"), and the scale is fixed by definition.
        'gpt_5_6_luna_subjectivite' => ['1', '2', '3', '4', '5'],
    ];

    /**
     * Display labels for the values of the closed-enum fields. Raw data
     * values (newspaper titles, languages, sentiment labels) are shown
     * verbatim — they're already human-readable French.
     *
     * Mirrors TYPE_LABELS / SUBJECTIVITY_LABELS in src/svelte/lib/i18n.ts.
     * English source strings, marked for Omeka's translator like the rest of
     * the admin catalogs.
     *
     * Keys are `array-key`, not `string`, because PHP coerces the numeric
     * subjectivity keys ('1'…'5') to ints on the way in. Reading them back
     * with the string form still resolves — array access coerces the same way
     * — so {@see valueLabel()} needs no special case.
     *
     * @var array<string, array<array-key, string>>
     */
    public const VALUE_LABELS = [
        'type_s' => [
            'article'     => 'News article',        // @translate
            'publication' => 'Islamic publication', // @translate
            'document'    => 'Document',            // @translate
            'audiovisual' => 'Audiovisual',         // @translate
            'photograph'  => 'Photograph',          // @translate
            'reference'   => 'Reference',           // @translate
        ],
        'gpt_5_6_luna_subjectivite' => [
            '1' => 'Very objective',    // @translate
            '2' => 'Rather objective',  // @translate
            '3' => 'Mixed',             // @translate
            '4' => 'Rather subjective', // @translate
            '5' => 'Very subjective',   // @translate
        ],
    ];

    /** The admin label for a picker, borrowed from the facet catalog. */
    public static function label(string $field): string
    {
        return FacetCatalog::FACETABLE_FIELDS[$field] ?? $field;
    }

    /**
     * The closed-enum options for a field, or [] when its vocabulary is open
     * and must be read from the index instead.
     *
     * @return list<string>
     */
    public static function staticOptions(string $field): array
    {
        if ($field === 'country_ss') {
            // PresetCatalog already guarantees these match the country_ss
            // facet values exactly, accents included — the country scopes
            // filter on them.
            return PresetCatalog::countryNames();
        }
        return self::STATIC_OPTIONS[$field] ?? [];
    }

    /**
     * The fields worth asking the index about — every picker except the
     * numeric ones.
     *
     * Open vocabularies (newspapers, languages, sentiment labels) need this
     * for their option list at all; the closed enums only use it to annotate
     * their fixed options with document counts, which is what tells an editor
     * whether "Photographie" is worth offering as a scope. The numeric
     * subjectivity scale is excluded because Typesense returns float facet
     * values in a format we would then have to match back ("1" vs "1.0") for
     * no gain — the 1–5 scale is fixed by definition.
     *
     * @return list<string>
     */
    public static function lookupFields(): array
    {
        return array_values(array_filter(
            self::FIELDS,
            static fn(string $field): bool => !self::isNumeric($field)
        ));
    }

    /** Whether a field's vocabulary is open data rather than a closed enum. */
    public static function isOpenVocabulary(string $field): bool
    {
        return self::staticOptions($field) === [];
    }

    /** Display label for one value of a picker; raw value when unmapped. */
    public static function valueLabel(string $field, string $value): string
    {
        return self::VALUE_LABELS[$field][$value] ?? $value;
    }

    public static function isNumeric(string $field): bool
    {
        return in_array($field, self::NUMERIC_FIELDS, true);
    }

    /**
     * Filter admin-submitted selections down to well-formed state, so
     * nothing invalid can reach `site_block.data`. Applied on save by
     * IwacSearchBlock::onHydrate().
     *
     * Structural only — it does NOT check values against the live index.
     * A newspaper that leaves the corpus, or a save made while Typesense is
     * down, must not silently strip a curated block's scope; the block form
     * re-renders unknown-but-saved values as checked so they stay visible.
     *
     * Fields keep {@see FIELDS} order (not submission order) so the compiled
     * filter string is stable across saves. Empty selections drop their key
     * entirely rather than persisting `"country_ss": []`.
     *
     * @return array<string, list<string>>
     */
    public static function normalise(mixed $submitted): array
    {
        if (!is_iterable($submitted)) {
            return [];
        }

        /** @var array<string, list<string>> $collected */
        $collected = [];
        foreach ($submitted as $field => $values) {
            if (!is_string($field) || !is_iterable($values)) {
                continue;
            }
            // Same rename hop the facet list gets. The alias map is empty
            // today (see FacetCatalog::LEGACY_FIELD_ALIASES), so this is a
            // no-op — but a future rename would otherwise silently drop a
            // locked scope. Two spellings of one field merge instead of
            // colliding.
            $field = FacetCatalog::canonicalField($field);
            if (!in_array($field, self::FIELDS, true)) {
                continue;
            }

            $clean = $collected[$field] ?? [];
            foreach ($values as $value) {
                if (is_int($value) || is_float($value)) {
                    $value = (string) $value;
                }
                if (!is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                // A non-numeric value on a numeric field makes Typesense
                // reject the whole search, taking the block down with it.
                if (self::isNumeric($field) && !is_numeric($value)) {
                    continue;
                }
                if (!in_array($value, $clean, true)) {
                    $clean[] = $value;
                }
            }

            if ($clean !== []) {
                $collected[$field] = $clean;
            }
        }

        $out = [];
        foreach (self::FIELDS as $field) {
            if (isset($collected[$field])) {
                $out[$field] = $collected[$field];
            }
        }
        return $out;
    }

    /**
     * Compile a normalised selection into a Typesense `filter_by` clause.
     * Returns '' for an empty selection, which {@see combine} drops.
     *
     *   ['country_ss' => ['Bénin', 'Togo'], 'type_s' => ['article']]
     *     →  country_ss:=[`Bénin`,`Togo`] && type_s:=[`article`]
     *
     * Values are backtick-quoted so spaces and punctuation survive (the
     * apostrophe in `Côte d'Ivoire`, the space in `Burkina Faso`), and any
     * backtick IN a value is stripped first — a backtick is the only
     * character that could otherwise close the quote early and inject
     * filter syntax. Numeric fields are emitted bare instead. Identical
     * rules to buildFilterBy() in src/svelte/lib/queryBuilders.ts.
     *
     * @param array<string, mixed> $filterValues
     */
    public static function compile(array $filterValues): string
    {
        $parts = [];
        // Iterate FIELDS, not the input, so output order is stable no matter
        // how the array was stored or hand-edited.
        foreach (self::FIELDS as $field) {
            $values = $filterValues[$field] ?? null;
            if (!is_array($values) || $values === []) {
                continue;
            }

            if (self::isNumeric($field)) {
                $nums = [];
                foreach ($values as $value) {
                    if ((is_string($value) || is_int($value) || is_float($value)) && is_numeric($value)) {
                        $nums[] = (string) $value;
                    }
                }
                if ($nums === []) {
                    continue;
                }
                $parts[] = $field . ':=[' . implode(',', $nums) . ']';
                continue;
            }

            $quoted = [];
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $quoted[] = '`' . str_replace('`', '', $value) . '`';
            }
            if ($quoted === []) {
                continue;
            }
            $parts[] = $field . ':=[' . implode(',', $quoted) . ']';
        }

        return implode(' && ', $parts);
    }

    /**
     * AND-join filter clauses, dropping the empty ones. The PHP twin of
     * combineFilters() in src/svelte/lib/typesense.ts.
     *
     * A clause containing `||` is parenthesised first. Typesense binds `&&`
     * tighter than `||`, so ANDing a picker selection onto a hand-written
     * `a:=1 || b:=2` would otherwise silently regroup it as
     * `a:=1 || (b:=2 && …)` — the OR branch escaping the lock. Clauses
     * without `||` are passed through untouched, so the output for every
     * preset scope is byte-identical to what it was before this existed.
     */
    public static function combine(string ...$parts): string
    {
        $kept = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $kept[] = $part;
            }
        }

        // A single clause needs no grouping — and leaving it bare keeps the
        // common case (one preset lock, no pickers) exactly as it was.
        if (count($kept) < 2) {
            return $kept[0] ?? '';
        }

        return implode(' && ', array_map(
            static fn(string $part): string => str_contains($part, '||') ? '(' . $part . ')' : $part,
            $kept
        ));
    }
}

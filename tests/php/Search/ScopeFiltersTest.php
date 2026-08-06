<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\ScopeFilters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The page block's value pickers: what an admin's tick-boxes become.
 *
 * Three things here are worth a test rather than a careful read, because
 * each fails in a way the admin form itself would never show:
 *
 *   - **Quoting is the injection guard.** Values are backtick-quoted so
 *     `Côte d'Ivoire` survives; a backtick INSIDE a value would close the
 *     quote early and let the rest be read as filter syntax.
 *   - **Numeric fields must be emitted bare.** A backticked number makes
 *     Typesense reject the whole search ("Numerical field has an invalid
 *     comparator") — the block renders empty, not wrong-but-visible.
 *   - **`&&` binds tighter than `||`.** ANDing a picker selection onto a
 *     hand-written OR silently regroups it, letting the OR branch escape
 *     the lock.
 */
#[CoversClass(ScopeFilters::class)]
final class ScopeFiltersTest extends TestCase
{
    // ── The catalogue itself ─────────────────────────────────────────────

    public function testEveryPickerFieldIsAlsoAFacetCatalogField(): void
    {
        // The picker borrows its label from FacetCatalog. A field missing
        // there would render as its raw schema name in the admin.
        foreach (ScopeFilters::FIELDS as $field) {
            self::assertArrayHasKey($field, FacetCatalog::FACETABLE_FIELDS, $field);
            self::assertNotSame($field, ScopeFilters::label($field), "{$field} has no label");
        }
    }

    public function testNumericFieldsAreASubsetOfTheOfferedFields(): void
    {
        foreach (ScopeFilters::NUMERIC_FIELDS as $field) {
            self::assertContains($field, ScopeFilters::FIELDS, $field);
        }
    }

    public function testCountryOptionsComeFromThePresetCatalogVerbatim(): void
    {
        // Typesense filter_by is accent- and case-sensitive, and the country
        // presets already filter on these exact strings. A second, hand-typed
        // copy in the picker is precisely the drift this avoids.
        self::assertSame(PresetCatalog::countryNames(), ScopeFilters::staticOptions('country_ss'));
        self::assertContains('Bénin', ScopeFilters::staticOptions('country_ss'));
        self::assertContains("Côte d'Ivoire", ScopeFilters::staticOptions('country_ss'));
    }

    public function testOpenVocabulariesHaveNoStaticOptionsAndClosedOnesDo(): void
    {
        // Newspapers and languages are corpus data that grows; types,
        // countries and the 1–5 scale are enums this codebase declares.
        self::assertTrue(ScopeFilters::isOpenVocabulary('newspaper_ss'));
        self::assertTrue(ScopeFilters::isOpenVocabulary('language_ss'));
        self::assertTrue(ScopeFilters::isOpenVocabulary('gemini_3_flash_preview_polarite_ss'));

        self::assertFalse(ScopeFilters::isOpenVocabulary('type_s'));
        self::assertFalse(ScopeFilters::isOpenVocabulary('country_ss'));
        self::assertSame(['1', '2', '3', '4', '5'], ScopeFilters::staticOptions('gemini_3_flash_preview_subjectivite'));
    }

    public function testLookupFieldsAskTheIndexForEverythingExceptTheNumericScale(): void
    {
        // The closed enums are still looked up — for their document counts,
        // which is what tells an editor a value is worth scoping to.
        self::assertContains('type_s', ScopeFilters::lookupFields());
        self::assertContains('newspaper_ss', ScopeFilters::lookupFields());
        // Float facet values come back as "1" or "1.0"; matching that back
        // against the fixed scale buys nothing.
        self::assertNotContains('gemini_3_flash_preview_subjectivite', ScopeFilters::lookupFields());
    }

    public function testClosedEnumValuesHaveLabelsAndOpenOnesPassThrough(): void
    {
        self::assertSame('News article', ScopeFilters::valueLabel('type_s', 'article'));
        self::assertSame('Very subjective', ScopeFilters::valueLabel('gemini_3_flash_preview_subjectivite', '5'));
        // A newspaper title is data, not a translatable source string.
        self::assertSame('Sidwaya', ScopeFilters::valueLabel('newspaper_ss', 'Sidwaya'));

        foreach (ScopeFilters::VALUE_LABELS as $field => $labels) {
            foreach (array_keys($labels) as $value) {
                self::assertContains(
                    (string) $value,
                    ScopeFilters::staticOptions($field),
                    "{$field}: labelled value {$value} is not an option"
                );
            }
        }
    }

    // ── normalise() ──────────────────────────────────────────────────────

    public function testNormaliseKeepsKnownFieldsInCatalogOrderNotSubmissionOrder(): void
    {
        // Stable output order means the compiled filter string — and so the
        // SSR cache key — doesn't churn between saves.
        $out = ScopeFilters::normalise([
            'language_ss' => ['Français'],
            'type_s'      => ['article'],
        ]);

        self::assertSame(['type_s', 'language_ss'], array_keys($out));
    }

    public function testNormaliseDropsUnknownFieldsEmptyValuesAndNonStrings(): void
    {
        $out = ScopeFilters::normalise([
            'country_ss'   => ['Togo', '', '  ', 'Togo', null, ['x']],
            'not_a_field'  => ['whatever'],
            // A facet field that isn't offered as a picker.
            'topics_ss'    => ['Islam'],
            'newspaper_ss' => [],
            42             => ['nope'],
        ]);

        self::assertSame(['country_ss' => ['Togo']], $out);
    }

    public function testNormaliseTrimsAndDedupes(): void
    {
        self::assertSame(
            ['country_ss' => ['Bénin', 'Togo']],
            ScopeFilters::normalise(['country_ss' => [' Bénin ', 'Togo', 'Bénin']])
        );
    }

    public function testNormaliseDropsNonNumericValuesOnTheNumericField(): void
    {
        // A non-numeric value here makes Typesense reject the entire search,
        // taking the block's results down with it.
        self::assertSame(
            ['gemini_3_flash_preview_subjectivite' => ['1', '5']],
            ScopeFilters::normalise([
                'gemini_3_flash_preview_subjectivite' => ['1', 'très objectif', '5'],
            ])
        );

        // Numbers arriving as ints/floats (hand-edited JSON) still count.
        self::assertSame(
            ['gemini_3_flash_preview_subjectivite' => ['3']],
            ScopeFilters::normalise(['gemini_3_flash_preview_subjectivite' => [3]])
        );
    }

    public function testNormaliseMergesARetiredFieldNameIntoItsCurrentOne(): void
    {
        // No stored block can carry a retired name in this key yet — it is
        // newer than the v4 sentiment rename — but a FUTURE rename must not
        // silently drop a curated scope, and two spellings of one field must
        // merge rather than one overwriting the other.
        self::assertSame(
            ['gemini_3_flash_preview_polarite_ss' => ['Neutre', 'Négative']],
            ScopeFilters::normalise([
                'gemini_polarite_ss'                 => ['Neutre'],
                'gemini_3_flash_preview_polarite_ss' => ['Négative', 'Neutre'],
            ])
        );
    }

    public function testNormaliseHandlesAbsentAndMalformedInput(): void
    {
        self::assertSame([], ScopeFilters::normalise(null));
        self::assertSame([], ScopeFilters::normalise('country_ss:=Togo'));
        self::assertSame([], ScopeFilters::normalise([]));
        // A field whose value isn't a list at all.
        self::assertSame([], ScopeFilters::normalise(['country_ss' => 'Togo']));
    }

    // ── compile() ────────────────────────────────────────────────────────

    public function testCompileOrsWithinAFieldAndAndsAcrossFields(): void
    {
        self::assertSame(
            'type_s:=[`article`,`document`] && country_ss:=[`Bénin`,`Togo`]',
            ScopeFilters::compile([
                'country_ss' => ['Bénin', 'Togo'],
                'type_s'     => ['article', 'document'],
            ])
        );
    }

    public function testCompileBacktickQuotesValuesWithSpacesAndApostrophes(): void
    {
        self::assertSame(
            "country_ss:=[`Côte d'Ivoire`,`Burkina Faso`]",
            ScopeFilters::compile(['country_ss' => ["Côte d'Ivoire", 'Burkina Faso']])
        );
    }

    public function testCompileStripsBackticksOutOfValues(): void
    {
        // The backtick is the one character that could close the quote early
        // and have the remainder read as filter syntax.
        self::assertSame(
            'newspaper_ss:=[`Sidwaya || is_public:=false`]',
            ScopeFilters::compile(['newspaper_ss' => ['Sid`waya` || is_public:=false']])
        );
    }

    public function testCompileEmitsNumericValuesBare(): void
    {
        // Backticked numbers → "Numerical field has an invalid comparator".
        self::assertSame(
            'gemini_3_flash_preview_subjectivite:=[1,2]',
            ScopeFilters::compile(['gemini_3_flash_preview_subjectivite' => ['1', '2']])
        );
    }

    public function testCompileIgnoresEmptyUnknownAndMalformedEntries(): void
    {
        self::assertSame('', ScopeFilters::compile([]));
        self::assertSame('', ScopeFilters::compile(['country_ss' => []]));
        self::assertSame('', ScopeFilters::compile(['topics_ss' => ['Islam']]));
        self::assertSame('', ScopeFilters::compile(['country_ss' => 'Togo']));
        self::assertSame('', ScopeFilters::compile(['gemini_3_flash_preview_subjectivite' => ['x']]));
    }

    public function testCompileOutputOrderFollowsTheCatalogNotTheInput(): void
    {
        $a = ScopeFilters::compile(['language_ss' => ['Français'], 'type_s' => ['article']]);
        $b = ScopeFilters::compile(['type_s' => ['article'], 'language_ss' => ['Français']]);

        self::assertSame($a, $b);
        self::assertSame('type_s:=[`article`] && language_ss:=[`Français`]', $a);
    }

    public function testANormalisedSelectionRoundTripsThroughCompile(): void
    {
        $compiled = ScopeFilters::compile(ScopeFilters::normalise([
            'country_ss' => ['Bénin', 'Bénin', ''],
            'bogus'      => ['x'],
        ]));

        self::assertSame('country_ss:=[`Bénin`]', $compiled);
    }

    // ── combine() ────────────────────────────────────────────────────────

    public function testCombineDropsEmptyClauses(): void
    {
        self::assertSame('', ScopeFilters::combine('', '   '));
        self::assertSame('type_s:=reference', ScopeFilters::combine('', 'type_s:=reference', ''));
    }

    public function testCombineLeavesASingleClauseByteIdentical(): void
    {
        // Every preset scope with no pickers ticked goes down this path, so
        // its filter string must not change shape at all.
        foreach (PresetCatalog::all() as $key => $preset) {
            self::assertSame(
                $preset->lockedFilters,
                ScopeFilters::combine($preset->lockedFilters, ''),
                $key
            );
        }
    }

    public function testCombineAndJoinsAScopeLockWithAPickerSelection(): void
    {
        self::assertSame(
            'country_ss:=`Bénin` && type_s:!=reference && type_s:=[`article`]',
            ScopeFilters::combine(
                'country_ss:=`Bénin` && type_s:!=reference',
                'type_s:=[`article`]'
            )
        );
    }

    public function testCombineParenthesisesAnOrClauseSoItCannotEscapeTheAnd(): void
    {
        // Typesense binds && tighter than ||. Without the parentheses this
        // reads as `a:=1 || (b:=2 && country_ss:=[…])` — documents matching
        // a:=1 would ignore the country lock entirely.
        self::assertSame(
            '(a:=1 || b:=2) && country_ss:=[`Togo`]',
            ScopeFilters::combine('a:=1 || b:=2', 'country_ss:=[`Togo`]')
        );

        // …but a lone OR clause is left exactly as the editor wrote it.
        self::assertSame('a:=1 || b:=2', ScopeFilters::combine('a:=1 || b:=2', ''));
    }
}

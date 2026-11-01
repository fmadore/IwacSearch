<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Site;

use Dom\HTMLDocument;
use IwacSearch\Search\FacetValueLookup;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\ScopeFilters;
use IwacSearch\Search\SearchDefaults;
use IwacSearch\Site\BlockLayout\BlockForm;
use IwacSearch\Tests\Support\TemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The page block's admin form: what it decides (BlockForm) and how the
 * partial posts it back.
 *
 * The round trip is the contract. Omeka posts every field under
 * o:block[__blockIndex__][o:data][…] and hands the result to onHydrate(), so
 * a renamed field silently drops a setting on the next save, and a saved
 * value the form fails to render as checked is deleted by an editor who only
 * changed the title.
 */
#[CoversClass(BlockForm::class)]
final class BlockFormTest extends TestCase
{
    /** @return array<string, list<array{value: string, count: int}>> */
    private static function counts(): array
    {
        return [
            'type_s'       => [['value' => 'reference', 'count' => 900], ['value' => 'article', 'count' => 12000]],
            'newspaper_ss' => [['value' => 'Sidwaya', 'count' => 1200], ['value' => 'Le Pays', 'count' => 340]],
        ];
    }

    /**
     * @param  list<array<string, mixed>> $pickers
     * @return array<string, mixed>
     */
    private static function picker(array $pickers, string $field): array
    {
        foreach ($pickers as $picker) {
            if ($picker['field'] === $field) {
                return $picker;
            }
        }
        self::fail("No picker for $field");
    }

    public function testANewBlockStartsOnTheWholeCorpusWithTheSearchPageFacets(): void
    {
        $vars = BlockForm::variables([], false, 'abc', null);

        self::assertSame(PresetCatalog::DEFAULT_KEY, $vars['preset']);
        self::assertSame(SearchDefaults::CONTENT_PROMINENT_FACETS, $vars['prominent_facets']);
        self::assertSame('full', $vars['mode']);
        self::assertSame('', $vars['default_sort']);
        self::assertSame(10, $vars['results_per_page']);
        self::assertSame(BlockForm::NAME_PREFIX, $vars['name_prefix']);
    }

    public function testASavedBlockFromBeforeTheScopeDropdownStaysCustom(): void
    {
        // No `preset` key: its stored locked_filters must keep driving it.
        $vars = BlockForm::variables(['locked_filters' => 'date:>=1'], true, '7', null);

        self::assertSame(PresetCatalog::CUSTOM, $vars['preset']);
        self::assertSame('date:>=1', $vars['locked_filters']);
    }

    public function testASavedValueTheIndexNoLongerOffersStaysCheckedAndFlagged(): void
    {
        $pickers = BlockForm::pickers(['newspaper_ss' => ['Sidwaya', 'Gone paper']], self::counts());
        $options = self::picker($pickers, 'newspaper_ss')['options'];

        self::assertSame(['Sidwaya', 'Le Pays', 'Gone paper'], array_column($options, 'value'));
        self::assertSame([true, false, true], array_column($options, 'checked'));
        self::assertSame([false, false, true], array_column($options, 'stale'));
        self::assertSame([1200, 340, null], array_column($options, 'count'));
    }

    public function testClosedEnumsKeepTheirDeclaredOrderAndBorrowTheLiveCounts(): void
    {
        $options = self::picker(BlockForm::pickers([], self::counts()), 'type_s')['options'];

        self::assertSame(ScopeFilters::staticOptions('type_s'), array_column($options, 'value'));
        $counts = array_combine(array_column($options, 'value'), array_column($options, 'count'));
        self::assertSame(12000, $counts['article']);
        self::assertSame(900, $counts['reference']);
        self::assertNull($counts['photograph']);
    }

    public function testOnlyClosedEnumLabelsAreTranslatable(): void
    {
        $pickers = BlockForm::pickers([], self::counts());

        $type = self::picker($pickers, 'type_s')['options'][0];
        self::assertSame(['article', 'News article', true], [$type['value'], $type['label'], $type['translatable']]);
        $paper = self::picker($pickers, 'newspaper_ss')['options'][0];
        self::assertSame(['Sidwaya', 'Sidwaya', false], [$paper['value'], $paper['label'], $paper['translatable']]);
    }

    public function testAnUnreachableIndexEmptiesOnlyTheOpenPickers(): void
    {
        $vars = BlockForm::variables(['filter_values' => ['newspaper_ss' => ['Sidwaya']]], true, '1', null);

        self::assertTrue($vars['index_unreachable']);
        // The saved value is still offered, so a save cannot drop it.
        self::assertSame(['Sidwaya'], array_column(self::picker($vars['pickers'], 'newspaper_ss')['options'], 'value'));
        self::assertSame([], self::picker($vars['pickers'], 'language_ss')['options']);
        $types = self::picker($vars['pickers'], 'type_s')['options'];
        self::assertCount(count(ScopeFilters::staticOptions('type_s')), $types);
        self::assertSame([null], array_values(array_unique(array_column($types, 'count'))));
    }

    public function testAFullPickerSaysTheListIsTruncated(): void
    {
        $values = [];
        for ($i = 0; $i < FacetValueLookup::MAX_VALUES; $i++) {
            $values[] = ['value' => 'Paper ' . $i, 'count' => 1000 - $i];
        }
        $pickers = BlockForm::pickers([], ['newspaper_ss' => $values]);

        self::assertTrue(self::picker($pickers, 'newspaper_ss')['truncated']);
        self::assertFalse(self::picker($pickers, 'language_ss')['truncated']);
    }

    public function testEveryFieldPostsUnderTheBlockDataPrefix(): void
    {
        $doc = $this->render([
            'preset' => 'benin', 'mode' => 'compact', 'title' => 'Latest', 'intro_html' => '<p>Hi</p>',
            'locked_filters' => 'date:>=1', 'filter_values' => ['type_s' => ['article']],
            'prominent_facets' => ['country_ss'], 'default_sort' => 'date:desc', 'results_per_page' => 25,
        ]);

        $fields = [];
        foreach ($doc->querySelectorAll('input, select, textarea') as $control) {
            $name = (string) $control->getAttribute('name');
            self::assertStringStartsWith(BlockForm::NAME_PREFIX . '[', $name);
            $fields[] = (string) preg_replace('/\].*$/', '', substr($name, strlen(BlockForm::NAME_PREFIX) + 1));
        }
        self::assertSame(
            ['default_sort', 'filter_values', 'intro_html', 'locked_filters', 'mode', 'preset', 'prominent_facets', 'results_per_page', 'title'],
            array_values(array_unique(self::sorted($fields)))
        );
    }

    public function testTheStoredValuesComeBackSelected(): void
    {
        $doc = $this->render([
            'preset' => 'benin', 'mode' => 'compact', 'title' => 'Latest', 'intro_html' => '<p>Hi</p>',
            'filter_values' => ['type_s' => ['article'], 'newspaper_ss' => ['Gone paper']],
            'prominent_facets' => ['country_ss'], 'default_sort' => 'date:desc', 'results_per_page' => 25,
        ]);
        $prefix = BlockForm::NAME_PREFIX;

        self::assertSame('benin', $this->selected($doc, $prefix . '[preset]'));
        self::assertSame('compact', $this->selected($doc, $prefix . '[mode]'));
        self::assertSame('date:desc', $this->selected($doc, $prefix . '[default_sort]'));
        self::assertSame('Latest', $this->value($doc, 'input', $prefix . '[title]'));
        self::assertSame('25', $this->value($doc, 'input', $prefix . '[results_per_page]'));
        self::assertSame('<p>Hi</p>', $this->control($doc, 'textarea', $prefix . '[intro_html]')->textContent);
        self::assertSame(['country_ss'], $this->checked($doc, $prefix . '[prominent_facets][]'));
        self::assertSame(['article'], $this->checked($doc, $prefix . '[filter_values][type_s][]'));
        self::assertSame(['Gone paper'], $this->checked($doc, $prefix . '[filter_values][newspaper_ss][]'));
    }

    public function testEveryLabelPointsAtAControlOnThePage(): void
    {
        $doc = $this->render([]);

        $ids = [];
        foreach ($doc->querySelectorAll('[id]') as $element) {
            $ids[] = (string) $element->getAttribute('id');
        }
        self::assertSame($ids, array_values(array_unique($ids)), 'ids are unique');
        foreach ($doc->querySelectorAll('label[for]') as $label) {
            self::assertContains((string) $label->getAttribute('for'), $ids);
        }
        foreach ($doc->querySelectorAll('[aria-labelledby]') as $group) {
            self::assertContains((string) $group->getAttribute('aria-labelledby'), $ids);
        }
    }

    public function testTheIndexNoticesAreTranslatedSourceStrings(): void
    {
        $html = (new TemplateRenderer(markTranslations: true))->renderTemplate(
            BlockForm::TEMPLATE,
            BlockForm::variables([], false, 'u', null)
        );

        self::assertStringContainsString('⟦The search index is unreachable', $html);
        // Data is printed verbatim; closed-enum labels go through translate().
        self::assertStringContainsString('⟦News article⟧', $html);
        self::assertStringContainsString('⟦Custom…⟧', $html);
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): HTMLDocument
    {
        $html = (new TemplateRenderer())->renderTemplate(
            BlockForm::TEMPLATE,
            BlockForm::variables($data, $data !== [], 'u1', self::counts())
        );
        return HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);
    }

    private function control(HTMLDocument $doc, string $tag, string $name): \Dom\Element
    {
        $element = $doc->querySelector($tag . '[name="' . addcslashes($name, '"\\') . '"]');
        self::assertNotNull($element, "no $tag named $name");
        return $element;
    }

    private function value(HTMLDocument $doc, string $tag, string $name): string
    {
        return (string) $this->control($doc, $tag, $name)->getAttribute('value');
    }

    /**
     * The value a select posts back. Several options may be marked: the sort
     * select lists `date:desc` under both the content and the entity group,
     * and marks both — harmless, since either posts the same value.
     */
    private function selected(HTMLDocument $doc, string $name): string
    {
        $values = [];
        foreach ($this->control($doc, 'select', $name)->querySelectorAll('option[selected]') as $option) {
            $values[] = (string) $option->getAttribute('value');
        }
        $values = array_values(array_unique($values));
        self::assertCount(1, $values, "$name posts exactly one value");
        return $values[0];
    }

    /** @return list<string> */
    private function checked(HTMLDocument $doc, string $name): array
    {
        $values = [];
        foreach ($doc->querySelectorAll('input[name="' . addcslashes($name, '"\\') . '"][checked]') as $box) {
            $values[] = (string) $box->getAttribute('value');
        }
        return $values;
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}

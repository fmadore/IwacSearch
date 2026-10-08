import { describe, expect, it } from 'vitest';
import {
  SORT_VALUES,
  createI18n,
  formatNumber,
  pluralKey,
  sortOptions,
  translate,
  translateSuggest,
} from '../../src/svelte/lib/i18n';

/**
 * The header typeahead's strings live in their own table so the site-wide
 * header bundle does not carry the app's ~270-key STRINGS table (it did, for
 * three strings). These cases pin both halves of that split: the header's
 * translator reads the small table, and the app's `translate()` still resolves
 * the same keys by falling back to it — so no component changed behaviour.
 */
describe('translateSuggest / translate', () => {
  it('renders the header strings in both locales, with interpolation', () => {
    expect(translateSuggest('fr', 'search_for', { q: 'Touba' })).toBe('Rechercher « Touba »');
    expect(translateSuggest('en', 'search_for', { q: 'Touba' })).toBe('Search for “Touba”');
    expect(translateSuggest('en', 'no_matches')).toBe('No matches.');
    expect(translateSuggest('fr', 'suggestions')).toBe('Suggestions');
  });

  it('the app translator still resolves the moved keys', () => {
    for (const locale of ['fr', 'en'] as const) {
      for (const key of ['no_matches', 'suggestions']) {
        expect(translate(locale, key)).toBe(translateSuggest(locale, key));
      }
      expect(translate(locale, 'search_for', { q: 'x' })).toBe(
        translateSuggest(locale, 'search_for', { q: 'x' }),
      );
    }
  });

  it('keeps its own keys first and falls back to the key itself', () => {
    expect(translate('en', 'clear_all')).toBe('Clear all');
    expect(translate('en', 'no_such_key_anywhere')).toBe('no_such_key_anywhere');
    expect(translateSuggest('en', 'clear_all')).toBe('clear_all');
  });
});

describe('SORT_VALUES', () => {
  it('is every value any surface offers (it is the ?sort= allowlist)', () => {
    const offered = [...sortOptions('fr', 'content'), ...sortOptions('fr', 'entity')].map(
      (o) => o.value,
    );
    expect([...SORT_VALUES].sort()).toEqual([...new Set(offered)].sort());
    expect(SORT_VALUES.has('_text_match:desc')).toBe(true);
    expect(SORT_VALUES.has('title:desc')).toBe(false);
  });
});

const NNBSP = ' ';

/**
 * The stack's number rule (IWAC-theme docs/DESIGN-SYSTEM.md): U+202F groups
 * thousands in EVERY locale, the decimal mark follows the page, and a French
 * percent keeps its narrow space. Formatted with the page locale — the
 * browser's never gets a say.
 */
describe('formatNumber', () => {
  it('groups thousands with U+202F in both locales', () => {
    expect(formatNumber(20944, 'en')).toBe(`20${NNBSP}944`);
    expect(formatNumber(20944, 'fr')).toBe(`20${NNBSP}944`);
    expect(formatNumber(1234567, 'en')).toBe(`1${NNBSP}234${NNBSP}567`);
  });

  it('leaves small numbers alone', () => {
    expect(formatNumber(999, 'en')).toBe('999');
    expect(formatNumber(0, 'fr')).toBe('0');
  });

  it('takes the decimal mark from the page locale', () => {
    expect(formatNumber(12.5, 'en')).toBe('12.5');
    expect(formatNumber(12.5, 'fr')).toBe('12,5');
  });

  it('spaces a French percent with U+202F and an English one not at all', () => {
    const pct = { style: 'percent', maximumFractionDigits: 1 } as const;
    expect(formatNumber(0.125, 'fr', pct)).toBe(`12,5${NNBSP}%`);
    expect(formatNumber(0.125, 'en', pct)).toBe('12.5%');
  });
});

/**
 * Plural categories come from Intl.PluralRules, never from `=== 1`: French
 * files 0 with 1 ("0 résultat"), English files 0 with the plural.
 */
describe('plurals', () => {
  it("follows each locale's rules for 0 and 1", () => {
    expect(pluralKey('fr', 'result', 0)).toBe('result_one');
    expect(pluralKey('fr', 'result', 1)).toBe('result_one');
    expect(pluralKey('fr', 'result', 2)).toBe('result_other');
    expect(pluralKey('en', 'result', 0)).toBe('result_other');
    expect(pluralKey('en', 'result', 1)).toBe('result_one');
  });

  it('falls back to _other for a category the table does not spell out', () => {
    // French has a "many" category (1 000 000); the tables only carry one/other.
    expect(pluralKey('fr', 'result', 1_000_000)).toBe('result_other');
  });

  it('fills {n} with the formatted count', () => {
    expect(createI18n('fr').tp('facet_search_count', 0)).toBe('0 résultat');
    expect(createI18n('fr').tp('facet_search_count', 1234)).toBe(`1${NNBSP}234 résultats`);
    expect(createI18n('en').tp('facet_search_count', 0)).toBe('0 results');
    expect(createI18n('en').tp('mention', 1)).toBe('1 mention');
  });

  it('lets an explicit n win over the formatted count', () => {
    expect(createI18n('en').tp('mention', 4, { n: '' }).trim()).toBe('mentions');
  });

  it('carries no parenthesised "(s)" plural in any string', () => {
    for (const locale of ['fr', 'en'] as const) {
      for (const key of ['facet_search_count_one', 'facet_search_count_other', 'n_active_one']) {
        expect(translate(locale, key)).not.toMatch(/\(s\)/);
      }
    }
  });
});

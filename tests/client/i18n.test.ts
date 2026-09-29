import { describe, expect, it } from 'vitest';
import { SORT_VALUES, sortOptions, translate, translateSuggest } from '../../src/svelte/lib/i18n';

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

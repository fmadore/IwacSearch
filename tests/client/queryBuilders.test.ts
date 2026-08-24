import { describe, expect, it } from 'vitest';
import {
  CONTENT_HIGHLIGHT_FALLBACK,
  CONTENT_QUERY_BY_FALLBACK,
  buildFilterBy,
  buildYearRangeFilter,
  combineFilters,
  effectiveSortValue,
  isExactQuery,
  resolveSortBy,
  withoutField,
} from '../../src/svelte/lib/queryBuilders';

/**
 * These builders produce the `filter_by` / `sort_by` strings sent to
 * Typesense. Getting them subtly wrong doesn't throw — it silently returns
 * the wrong documents, or errors server-side in a way that surfaces as
 * "Search unavailable", so they are worth pinning precisely.
 */

describe('buildFilterBy', () => {
  it('backtick-quotes string facet values', () => {
    expect(buildFilterBy({ country_ss: ['Burkina Faso', 'Niger'] })).toBe(
      'country_ss:=[`Burkina Faso`,`Niger`]',
    );
  });

  it('AND-joins several fields', () => {
    expect(buildFilterBy({ country_ss: ['Niger'], newspaper_ss: ['Sidwaya'] })).toBe(
      'country_ss:=[`Niger`] && newspaper_ss:=[`Sidwaya`]',
    );
  });

  it('keeps an apostrophe intact but strips backticks from values', () => {
    // Côte d'Ivoire is a real facet value; a backtick would break out of the
    // quoting Typesense relies on.
    expect(buildFilterBy({ country_ss: ["Côte d'Ivoire"] })).toBe("country_ss:=[`Côte d'Ivoire`]");
    expect(buildFilterBy({ topics_ss: ['a`b'] })).toBe('topics_ss:=[`ab`]');
  });

  it('emits numeric facets bare — a backticked number is a Typesense error', () => {
    expect(buildFilterBy({ gpt_5_6_luna_subjectivite: ['1', '2'] })).toBe(
      'gpt_5_6_luna_subjectivite:=[1,2]',
    );
  });

  it('emits boolean facets bare and drops non-boolean tokens', () => {
    expect(buildFilterBy({ has_fulltext: ['true'] })).toBe('has_fulltext:=[true]');
    expect(buildFilterBy({ has_fulltext: ['yes'] })).toBe('');
  });

  it('drops numeric facets whose values are not numbers', () => {
    expect(buildFilterBy({ gpt_5_6_luna_subjectivite: ['high', ''] })).toBe('');
  });

  it('ignores empty selections rather than emitting an empty clause', () => {
    expect(buildFilterBy({})).toBe('');
    expect(buildFilterBy({ country_ss: [] })).toBe('');
  });
});

describe('buildYearRangeFilter', () => {
  it('emits both bounds, one bound, or nothing', () => {
    expect(buildYearRangeFilter({ from: 1990, to: 2010 })).toBe(
      'pub_year:>=1990 && pub_year:<=2010',
    );
    expect(buildYearRangeFilter({ from: 1990 })).toBe('pub_year:>=1990');
    expect(buildYearRangeFilter({ to: 2010 })).toBe('pub_year:<=2010');
    expect(buildYearRangeFilter(null)).toBe('');
    expect(buildYearRangeFilter({})).toBe('');
  });

  it('truncates fractional bounds and ignores non-finite ones', () => {
    expect(buildYearRangeFilter({ from: 1990.9 })).toBe('pub_year:>=1990');
    expect(buildYearRangeFilter({ from: Number.NaN, to: 2010 })).toBe('pub_year:<=2010');
  });
});

describe('combineFilters', () => {
  it('AND-joins only the non-empty parts', () => {
    expect(combineFilters('a:=1', '', undefined, null, '  ', 'b:=2')).toBe('a:=1 && b:=2');
    expect(combineFilters(undefined, '')).toBe('');
  });
});

describe('isExactQuery', () => {
  it.each([
    ['"radicalisation en Côte d\'Ivoire"', true],
    ['ramadan -tabaski', true],
    ['-tabaski', true],
    ['ramadan', false],
    ['Faso-Dan Fani', false],
    ['', false],
  ])('%s → %s', (q, expected) => {
    expect(isExactQuery(q)).toBe(expected);
  });
});

describe('withoutField', () => {
  it('drops one entry and normalises whitespace', () => {
    expect(withoutField('a, b , c', 'b')).toBe('a,c');
  });

  it('leaves the list alone when the field is absent', () => {
    expect(withoutField('a,b', 'z')).toBe('a,b');
  });

  it('strips embedding from the real content query_by', () => {
    const exact = withoutField(CONTENT_QUERY_BY_FALLBACK, 'embedding');
    expect(exact).not.toContain('embedding');
    // Exact mode drops ONLY the vector field; everything the highlighter
    // needs must survive.
    expect(exact).toBe(CONTENT_HIGHLIGHT_FALLBACK);
  });
});

describe('resolveSortBy', () => {
  it('falls back to date:desc in browse mode, where relevance is meaningless', () => {
    expect(resolveSortBy('_text_match:desc', true, undefined)).toBe('date:desc');
    expect(resolveSortBy(undefined, true, undefined)).toBe('date:desc');
  });

  it('honours an explicit non-relevance sort in browse mode', () => {
    expect(resolveSortBy('frequency:desc', true, 'frequency:desc')).toBe('frequency:desc');
  });

  it("uses the surface's default for a text query with no explicit sort", () => {
    expect(resolveSortBy(undefined, false, 'date:desc')).toBe('date:desc');
    expect(resolveSortBy(undefined, false, undefined)).toBe('_text_match:desc');
  });

  it('adds the missing-values rule for the optional creator_sort field', () => {
    // Typesense errors on an optional sort field with no explicit rule;
    // author-less docs must land last, not break the request.
    expect(resolveSortBy('creator_sort:asc', false, undefined)).toBe(
      'creator_sort(missing_values:last):asc',
    );
  });
});

/**
 * The UI half of the same resolution. The summary strip and the sort dropdown
 * used to label the UNRESOLVED state value, so the resting state of
 * /references and FR /parcourir — both configured `_text_match:desc`, both
 * silently served date:desc because relevance is meaningless without a query —
 * read "sorted by Relevance" over strictly date-ordered results (Phase-1
 * critique P2).
 */
describe('effectiveSortValue', () => {
  it('agrees with resolveSortBy on every case that needs no decoration', () => {
    const cases: Array<[string | undefined, boolean, string | undefined]> = [
      ['_text_match:desc', true, undefined],
      [undefined, true, undefined],
      ['frequency:desc', true, 'frequency:desc'],
      [undefined, false, 'date:desc'],
      [undefined, false, undefined],
      ['date:asc', false, 'date:desc'],
    ];
    for (const [requested, isBrowse, def] of cases) {
      expect(effectiveSortValue(requested, isBrowse, def)).toBe(
        resolveSortBy(requested, isBrowse, def),
      );
    }
  });

  it('reports the substitution the browse surfaces actually apply', () => {
    // /references and FR /parcourir at rest: configured relevance, served date.
    expect(effectiveSortValue('_text_match:desc', true, '_text_match:desc')).toBe('date:desc');
  });

  it('stays a plain sort-option value for creator_sort — no Typesense decoration', () => {
    // resolveSortBy() wraps this for the wire; the dropdown must still be able
    // to find the value in its own option list.
    expect(effectiveSortValue('creator_sort:asc', false, undefined)).toBe('creator_sort:asc');
  });
});

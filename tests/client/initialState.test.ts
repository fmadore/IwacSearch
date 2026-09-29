import { describe, expect, it } from 'vitest';
import { adoptableSnapshot, initialSearchState } from '../../src/svelte/lib/initialState';
import type { IwacBootstrap, IwacSearchResponse, SearchState } from '../../src/svelte/lib/types';

/**
 * Where a surface starts, and when the server's first page may stand in for
 * the first fetch. The snapshot is always the surface's DEFAULT first page, so
 * adopting it for any other state shows the reader something they did not
 * ask for — the whole corpus under a deep link's query, for one frame or for
 * good.
 */

const SNAPSHOT = {
  found: 9,
  page: 1,
  hits: [],
  request_params: {},
  search_time_ms: 1,
} as IwacSearchResponse;

function bootstrap(over: Partial<IwacBootstrap> = {}): IwacBootstrap {
  return {
    block_id: 'standalone',
    mode: 'full',
    locked_filters: '',
    prominent_facets: [],
    default_sort: 'date:desc',
    results_per_page: 20,
    endpoints: { token: '/t', search: '/s' },
    initial_response: SNAPSHOT,
    ...over,
  };
}

const PRISTINE: SearchState = {
  q: '',
  page: 1,
  sort: 'date:desc',
  filters: {},
  yearRange: null,
  perPage: null,
  view: null,
};

describe('initialSearchState', () => {
  it('hydrates a syncing surface from its URL, under its own prefix', () => {
    const s = initialSearchState(bootstrap(), {
      syncUrl: true,
      urlPrefix: 'b7.',
      defaultSort: 'date:desc',
      href: 'https://x.test/s/p?b7.q=coran&b7.page=3&q=ignored',
    });
    expect(s.q).toBe('coran');
    expect(s.page).toBe(3);
    expect(s.sort).toBe('date:desc');
  });

  it('starts a non-syncing surface from the parent query and handed-off filters', () => {
    const s = initialSearchState(bootstrap({ initial_filters: { country_ss: ['Niger'] } }), {
      syncUrl: false,
      urlPrefix: '',
      defaultSort: 'date:desc',
      sharedQuery: 'islam',
      href: 'https://x.test/?q=ignored',
    });
    expect(s).toEqual({ ...PRISTINE, q: 'islam', filters: { country_ss: ['Niger'] } });
  });

  it('starts a plain page block empty', () => {
    const s = initialSearchState(bootstrap(), {
      syncUrl: false,
      urlPrefix: '',
      defaultSort: 'date:desc',
    });
    expect(s).toEqual(PRISTINE);
  });
});

describe('adoptableSnapshot', () => {
  it('adopts the snapshot for the pristine state', () => {
    expect(adoptableSnapshot(bootstrap(), PRISTINE, 'date:desc')).toBe(SNAPSHOT);
  });

  it.each<[string, Partial<SearchState>]>([
    ['a query', { q: 'ramadan' }],
    ['a later page', { page: 2 }],
    ['a non-default sort', { sort: 'date:asc' }],
    ['a filter', { filters: { country_ss: ['Niger'] } }],
    ['a year range', { yearRange: { from: 1990, to: 2000 } }],
    ['a page size', { perPage: 50 }],
  ])('refuses it for %s', (_label, over) => {
    expect(adoptableSnapshot(bootstrap(), { ...PRISTINE, ...over }, 'date:desc')).toBeNull();
  });

  it('refuses it when a filter was handed off, even before the state carries it', () => {
    const bs = bootstrap({ initial_filters: { country_ss: ['Niger'] } });
    expect(adoptableSnapshot(bs, PRISTINE, 'date:desc')).toBeNull();
  });

  it('compares against the surface default sort, not the global fallback', () => {
    const state = { ...PRISTINE, sort: '_text_match:desc' };
    expect(adoptableSnapshot(bootstrap(), state, '_text_match:desc')).toBe(SNAPSHOT);
    expect(adoptableSnapshot(bootstrap(), state, 'date:desc')).toBeNull();
  });

  it('keeps the view out of it: a view is presentation, not a different page', () => {
    expect(adoptableSnapshot(bootstrap(), { ...PRISTINE, view: 'gallery' }, 'date:desc')).toBe(
      SNAPSHOT,
    );
  });

  it('rejects a malformed snapshot without hits', () => {
    const bs = bootstrap({ initial_response: { found: 1 } as unknown as IwacSearchResponse });
    expect(adoptableSnapshot(bs, PRISTINE, 'date:desc')).toBeNull();
    expect(
      adoptableSnapshot(bootstrap({ initial_response: undefined }), PRISTINE, 'date:desc'),
    ).toBeNull();
  });
});

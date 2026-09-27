import { afterEach, describe, expect, it, vi } from 'vitest';
import { MULTI_SEARCH_LIMIT, postJson } from '../../src/svelte/lib/transport';
import { runSuggest } from '../../src/svelte/lib/suggestQuery';
import type { IwacBootstrap } from '../../src/svelte/lib/types';

/**
 * Every public scoped key embeds `limit_multi_searches`
 * (TypesenseSearchKeyProvider::MAX_MULTI_SEARCHES). A request carrying more
 * searches than that fails on the server for every visitor, so the client
 * refuses to send one, and its largest real request — the typeahead — must
 * stay under the cap however many facets a surface configures.
 */

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('multi_search limit', () => {
  it('refuses a request above the limit without sending it', async () => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    const searches = Array.from({ length: MULTI_SEARCH_LIMIT + 1 }, () => ({ q: '*' }));

    await expect(postJson('/search-api/multi_search', 'k', { searches }, 'Test')).rejects.toThrow(
      /exceed the per-request limit/,
    );
    expect(fetch).not.toHaveBeenCalled();
  });

  it('sends a request at the limit', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ results: [] }), { status: 200 })),
    );
    const searches = Array.from({ length: MULTI_SEARCH_LIMIT }, () => ({ q: '*' }));

    await expect(postJson('/search-api/multi_search', 'k', { searches }, 'Test')).resolves.toEqual({
      results: [],
    });
  });

  it('keeps the typeahead under the limit on a surface with every suggestable facet', async () => {
    const sent: Array<{ searches: unknown[] }> = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string, init?: RequestInit) => {
        if (String(url).endsWith('/token')) {
          return new Response(
            JSON.stringify({ key: 'k', expires_at: 2 ** 31, host: '', collection: 'iwac_current' }),
            { status: 200 },
          );
        }
        const body = JSON.parse(String(init?.body ?? '{}')) as { searches: unknown[] };
        sent.push(body);
        return new Response(
          JSON.stringify({ results: body.searches.map(() => ({ hits: [], found: 0 })) }),
          { status: 200 },
        );
      }),
    );
    const bootstrap = {
      block_id: 'limit-test',
      mode: 'full',
      locked_filters: '',
      prominent_facets: [
        'places_ss',
        'topics_ss',
        'persons_ss',
        'organisations_ss',
        'events_ss',
        'subjects_ss',
        'creator_ss',
        'publisher_s',
        'newspaper_ss',
        'channel_ss',
      ],
      default_sort: '_text_match:desc',
      results_per_page: 10,
      collection_alias: 'iwac_current',
      index_collection_alias: 'iwac_index_current',
      endpoints: { token: '/limit-test/token', search: '/search-api/multi_search' },
    } as IwacBootstrap;

    await runSuggest(bootstrap, 'ramadan');

    expect(sent).toHaveLength(1);
    expect(sent[0].searches.length).toBeLessThanOrEqual(MULTI_SEARCH_LIMIT);
  });
});

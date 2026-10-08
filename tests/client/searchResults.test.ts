import { describe, expect, it, vi } from 'vitest';
import {
  createMapResults,
  createSearchResults,
  type SearchRequest,
  type SearchResultsClient,
  type SearchResultsOptions,
} from '../../src/svelte/lib/searchResults.svelte';
import { facetUnion } from '../../src/svelte/lib/queryBuilders';
import type { HistogramOutcome, SearchOutcome } from '../../src/svelte/lib/typesense';
import type { IwacDoc, IwacSearchResponse, YearBucket } from '../../src/svelte/lib/types';

/**
 * The fetch rules that used to live in App.svelte's search `$effect`, where no
 * test could reach them. Each of these regressed, or was the fix for one:
 *
 *   - the adopted SSR snapshot skips the first search but not the histogram;
 *   - the histogram rides along only when query + categorical filters changed
 *     (never for a page, a sort or the year range);
 *   - an aborted (superseded) request settles nothing;
 *   - a semantic-only response is a dead query: out of the history, into the
 *     did-you-mean path.
 */

function res(found: number, over: Partial<IwacSearchResponse> = {}): IwacSearchResponse {
  return {
    found,
    page: 1,
    request_params: { per_page: 10 },
    search_time_ms: 3,
    hits: Array.from({ length: Math.min(found, 3) }, (_, i) => ({
      document: { id: String(i), title: `hit ${i}` } as IwacDoc,
    })),
    ...over,
  } as IwacSearchResponse;
}

const YEARS: YearBucket[] = [
  { year: 1990, count: 2 },
  { year: 1991, count: 5 },
];

/** A settled-by-hand promise, so ordering between calls is under the test's control. */
function deferred<T>() {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((a, b) => {
    resolve = a;
    reject = b;
  });
  return { promise, resolve, reject };
}

function fakeClient(outcome: () => Promise<SearchOutcome> = async () => ({ response: res(3) })) {
  const client = {
    search: vi.fn((args: Parameters<SearchResultsClient['search']>[0]) => {
      void args;
      return outcome();
    }),
    yearDistribution: vi.fn(async (): Promise<HistogramOutcome> => ({ years: YEARS })),
    suggest: vi.fn(async () => ({
      articles: [],
      entities: [1, 2, 3, 4, 5].map((i) => ({ field: 'topics_ss', value: `T${i}`, count: i })),
    })),
  };
  return client as typeof client & SearchResultsClient;
}

function make(client: SearchResultsClient, over: Partial<SearchResultsOptions> = {}) {
  const onOutcome = vi.fn();
  const onFruitfulQuery = vi.fn();
  const results = createSearchResults(client, {
    withHistogram: true,
    prominentFacets: ['country_ss', 'type_s'],
    collection: 'iwac_current',
    initialResponse: null,
    onOutcome,
    onFruitfulQuery,
    ...over,
  });
  return { results, onOutcome, onFruitfulQuery };
}

function req(over: Partial<SearchRequest> = {}): SearchRequest {
  return {
    q: '',
    page: 1,
    sort: 'date:desc',
    filters: {},
    yearRange: null,
    perPage: null,
    ...over,
  };
}

/** Let every queued .then/.catch run. */
const flush = () => new Promise((r) => setTimeout(r, 0));

describe('facetUnion', () => {
  it('requests the prominent facets plus every selected field, once each', () => {
    expect(
      facetUnion(['country_ss', 'type_s'], { type_s: ['article'], subject_ss: ['Islam'] }),
    ).toEqual(['country_ss', 'type_s', 'subject_ss']);
  });
});

describe('createSearchResults', () => {
  it('fetches on the first run when no snapshot was adopted', async () => {
    const client = fakeClient();
    const { results } = make(client);
    results.run(req({ page: 2, perPage: 50, filters: { country_ss: ['Niger'] } }));
    expect(results.isLoading).toBe(true);
    expect(client.search).toHaveBeenCalledWith(
      expect.objectContaining({
        page: 2,
        perPage: 50,
        sortBy: 'date:desc',
        facetBy: ['country_ss', 'type_s'],
        withYearDistribution: true,
      }),
    );
    await flush();
    expect(results.isLoading).toBe(false);
    expect(results.response?.found).toBe(3);
  });

  it('passes a null page size through as the surface default', () => {
    const client = fakeClient();
    make(client).results.run(req());
    expect(client.search.mock.calls[0][0].perPage).toBeUndefined();
  });

  it('adopts the snapshot: no search on the first run, but the histogram is still fetched', async () => {
    const client = fakeClient();
    const snapshot = res(40);
    const { results } = make(client, { initialResponse: snapshot });
    // Equal, not identical: $state hands back a proxy of what it was given.
    expect(results.response).toEqual(snapshot);
    results.run(req());
    expect(client.search).not.toHaveBeenCalled();
    expect(client.yearDistribution).toHaveBeenCalledWith('', true);
    await flush();
    expect(results.yearDistribution).toEqual(YEARS);
    // The next change searches — and the histogram it fetched is reused.
    results.run(req({ page: 2 }));
    expect(client.search).toHaveBeenCalledTimes(1);
    expect(client.search.mock.calls[0][0].withYearDistribution).toBe(false);
  });

  it('adopts the snapshot on a compact surface without asking for a histogram', () => {
    const client = fakeClient();
    make(client, { initialResponse: res(4), withHistogram: false }).results.run(req());
    expect(client.search).not.toHaveBeenCalled();
    expect(client.yearDistribution).not.toHaveBeenCalled();
  });

  it('drops a histogram that lands after the effect was torn down', async () => {
    const client = fakeClient();
    const d = deferred<HistogramOutcome>();
    client.yearDistribution.mockReturnValueOnce(d.promise);
    const { results } = make(client, { initialResponse: res(4) });
    const cleanup = results.run(req());
    cleanup?.();
    d.resolve({ years: YEARS });
    await flush();
    expect(results.yearDistribution).toEqual([]);
  });

  it('flags the histogram unavailable when the snapshot path fails', async () => {
    const client = fakeClient();
    client.yearDistribution.mockRejectedValueOnce(new Error('down'));
    const { results } = make(client, { initialResponse: res(4) });
    results.run(req());
    await flush();
    expect(results.yearsUnavailable).toBe(true);
  });

  it('asks for the histogram only when the query or the categorical filters change', async () => {
    const client = fakeClient(async () => ({ response: res(3), years: YEARS }));
    const { results } = make(client);
    const asked = () => client.search.mock.calls.at(-1)![0].withYearDistribution;

    results.run(req({ q: 'islam' }));
    expect(asked()).toBe(true);
    await flush();
    results.run(req({ q: 'islam', page: 2 }));
    expect(asked()).toBe(false);
    results.run(req({ q: 'islam', sort: 'date:asc' }));
    expect(asked()).toBe(false);
    results.run(req({ q: 'islam', yearRange: { from: 1990, to: 2000 } }));
    expect(asked()).toBe(false);
    results.run(req({ q: 'islam', filters: { country_ss: ['Niger'] } }));
    expect(asked()).toBe(true);
    await flush();
    results.run(req({ q: 'coran', filters: { country_ss: ['Niger'] } }));
    expect(asked()).toBe(true);
  });

  /**
   * The slider's bounds come from the data (S-02): the span rides with the
   * first request that carries a histogram, and stops being asked for once
   * it has arrived — it depends on the locked scope alone.
   */
  it('asks for the year span until one arrives, then never again', async () => {
    const client = fakeClient(async () => ({ response: res(3), years: YEARS }));
    const { results } = make(client);
    const askedSpan = () => client.search.mock.calls.at(-1)![0].withYearSpan;

    results.run(req({ q: 'islam' }));
    expect(askedSpan()).toBe(true);
    await flush();
    // No span in that answer (a failed sub-search): ask again next time.
    expect(results.yearSpan).toBeNull();
    client.search.mockImplementationOnce(async () => ({
      response: res(3),
      span: { min: 1912, max: 2026 },
    }));
    results.run(req({ q: 'islam', page: 2 }));
    expect(askedSpan()).toBe(true);
    await flush();
    expect(results.yearSpan).toEqual({ min: 1912, max: 2026 });
    results.run(req({ q: 'coran' }));
    expect(askedSpan()).toBe(false);
  });

  it('takes the span from the histogram request when the snapshot was adopted', async () => {
    const client = fakeClient();
    client.yearDistribution.mockResolvedValueOnce({ years: YEARS, span: { min: 1961, max: 2026 } });
    const { results } = make(client, { initialResponse: res(4) });
    results.run(req());
    await flush();
    expect(results.yearSpan).toEqual({ min: 1961, max: 2026 });
    results.run(req({ page: 2 }));
    expect(client.search.mock.calls[0][0].withYearSpan).toBe(false);
  });

  it('never asks for a span on a surface without a slider', () => {
    const client = fakeClient();
    make(client, { withHistogram: false }).results.run(req({ q: 'islam' }));
    expect(client.search.mock.calls[0][0].withYearSpan).toBe(false);
  });

  it('never asks for a histogram on a surface without one', () => {
    const client = fakeClient();
    make(client, { withHistogram: false }).results.run(req({ q: 'islam' }));
    expect(client.search.mock.calls[0][0].withYearDistribution).toBe(false);
  });

  it('asks again after the histogram sub-search failed, and clears the stale bars', async () => {
    const client = fakeClient(async () => ({ response: res(3), years: YEARS }));
    const { results } = make(client);
    results.run(req({ q: 'islam' }));
    await flush();
    expect(results.yearDistribution).toEqual(YEARS);

    client.search.mockImplementationOnce(async () => ({
      response: res(3),
      yearsUnavailable: true,
    }));
    results.run(req({ q: 'coran' }));
    await flush();
    expect(results.yearsUnavailable).toBe(true);
    expect(results.yearDistribution).toEqual([]);

    // Same query again (the retry button): the key never advanced, so it asks.
    results.run(req({ q: 'coran' }));
    expect(client.search.mock.calls.at(-1)![0].withYearDistribution).toBe(true);
    await flush();
    expect(results.yearsUnavailable).toBe(false);
  });

  it('reports a settled query and records it when it found something', async () => {
    const client = fakeClient(async () => ({ response: res(3, { keyword_found: 3 }) }));
    const { results, onOutcome, onFruitfulQuery } = make(client);
    results.run(req({ q: 'islam' }));
    await flush();
    expect(onOutcome).toHaveBeenCalledWith({
      query: 'islam',
      collection: 'iwac_current',
      found: 3,
      keywordFound: 3,
      semanticOnly: false,
    });
    expect(onFruitfulQuery).toHaveBeenCalledWith('islam');
    expect(client.suggest).not.toHaveBeenCalled();
  });

  it('reports nothing for browse mode', async () => {
    const client = fakeClient();
    const { results, onOutcome, onFruitfulQuery } = make(client);
    results.run(req({ q: '  ' }));
    await flush();
    expect(onOutcome).not.toHaveBeenCalled();
    expect(onFruitfulQuery).not.toHaveBeenCalled();
  });

  it('treats a zero-hit query as a dead end: no history, up to four did-you-mean entities', async () => {
    const client = fakeClient(async () => ({ response: res(0) }));
    const { results, onFruitfulQuery } = make(client);
    results.run(req({ q: 'tidjaniya' }));
    await flush();
    await flush();
    expect(onFruitfulQuery).not.toHaveBeenCalled();
    expect(client.suggest).toHaveBeenCalledWith('tidjaniya', 3);
    expect(results.didYouMean.map((e) => e.value)).toEqual(['T1', 'T2', 'T3', 'T4']);
  });

  it('treats a semantic-only response as a dead end too', async () => {
    const client = fakeClient(async () => ({ response: res(100, { keyword_found: 0 }) }));
    const { results, onOutcome, onFruitfulQuery } = make(client);
    results.run(req({ q: 'zzkw' }));
    await flush();
    expect(onOutcome).toHaveBeenCalledWith(
      expect.objectContaining({ semanticOnly: true, found: 100 }),
    );
    expect(onFruitfulQuery).not.toHaveBeenCalled();
    expect(client.suggest).toHaveBeenCalledWith('zzkw', 3);
  });

  it('offers no spelling help for a query under three characters', async () => {
    const client = fakeClient(async () => ({ response: res(0) }));
    make(client).results.run(req({ q: 'ab' }));
    await flush();
    expect(client.suggest).not.toHaveBeenCalled();
  });

  it('clears the did-you-mean row when the next search starts', async () => {
    const client = fakeClient(async () => ({ response: res(0) }));
    const { results } = make(client);
    results.run(req({ q: 'tidjaniya' }));
    await flush();
    await flush();
    expect(results.didYouMean).toHaveLength(4);
    results.run(req({ q: 'tijaniyya' }));
    expect(results.didYouMean).toEqual([]);
  });

  it('shows the error — not the previous results — when a search fails', async () => {
    const client = fakeClient();
    const { results } = make(client);
    results.run(req({ q: 'islam' }));
    await flush();
    expect(results.response).not.toBeNull();

    vi.spyOn(console, 'error').mockImplementation(() => {});
    client.search.mockImplementationOnce(async () => {
      throw new Error('Search HTTP 500');
    });
    results.run(req({ q: 'coran' }));
    await flush();
    expect(results.error).toBe('Search HTTP 500');
    expect(results.response).toBeNull();
    expect(results.isLoading).toBe(false);

    // The next search clears the error when it starts.
    results.run(req({ q: 'coran', page: 2 }));
    expect(results.error).toBeNull();
  });

  it('lets an aborted request settle nothing: the newer one owns the loading flag', async () => {
    const client = fakeClient();
    const first = deferred<SearchOutcome>();
    const second = deferred<SearchOutcome>();
    client.search.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const { results } = make(client);

    results.run(req({ q: 'isl' }));
    results.run(req({ q: 'islam' }));
    first.reject(new DOMException('superseded', 'AbortError'));
    await flush();
    expect(results.isLoading).toBe(true);
    expect(results.error).toBeNull();

    second.resolve({ response: res(3) });
    await flush();
    expect(results.isLoading).toBe(false);
    expect(results.response?.found).toBe(3);
  });
});

describe('createMapResults', () => {
  const doc = (id: string) => ({ id, title: id }) as IwacDoc;

  it('fetches the geo set with the year window and settles it', async () => {
    const fetchForMap = vi.fn(async () => [doc('a')]);
    const map = createMapResults({ fetchForMap });
    map.run({ q: 'x', filters: { country_ss: ['Niger'] }, yearRange: { from: 1990, to: 2000 } });
    expect(map.loading).toBe(true);
    expect(fetchForMap).toHaveBeenCalledWith(
      expect.objectContaining({
        q: 'x',
        activeFilters: { country_ss: ['Niger'] },
        yearRange: { from: 1990, to: 2000 },
      }),
    );
    await flush();
    expect(map.loading).toBe(false);
    expect(map.docs.map((d) => d.id)).toEqual(['a']);
  });

  it('aborts the running fetch on cleanup and ignores what it returns', async () => {
    const pending = deferred<IwacDoc[]>();
    let signal: AbortSignal | undefined;
    const fetchForMap = vi.fn((args: { signal?: AbortSignal }) => {
      signal = args.signal;
      return pending.promise;
    });
    const map = createMapResults({ fetchForMap });
    const cleanup = map.run({ q: 'x', filters: {}, yearRange: null });
    cleanup();
    expect(signal?.aborted).toBe(true);
    pending.resolve([doc('stale')]);
    await flush();
    expect(map.docs).toEqual([]);
  });

  it('never lets an older fetch overwrite a newer one', async () => {
    const older = deferred<IwacDoc[]>();
    const newer = deferred<IwacDoc[]>();
    const fetchForMap = vi
      .fn()
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);
    const map = createMapResults({ fetchForMap });
    map.run({ q: 'a', filters: {}, yearRange: null });
    map.run({ q: 'ab', filters: {}, yearRange: null });
    newer.resolve([doc('new')]);
    await flush();
    older.resolve([doc('old')]);
    await flush();
    expect(map.docs.map((d) => d.id)).toEqual(['new']);
  });

  it('empties the map on a failure', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const fetchForMap = vi.fn(async () => {
      throw new Error('down');
    });
    const map = createMapResults({ fetchForMap });
    map.run({ q: 'x', filters: {}, yearRange: null });
    await flush();
    expect(map.docs).toEqual([]);
    expect(map.loading).toBe(false);
  });
});

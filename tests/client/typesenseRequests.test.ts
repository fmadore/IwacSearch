import { afterEach, describe, expect, it, vi } from 'vitest';
import { TypesenseClient } from '../../src/svelte/lib/typesense';
import type { IwacBootstrap } from '../../src/svelte/lib/types';

/**
 * Request shapes and ordering for the client's multi-search calls — the parts
 * no other test pinned: which sub-searches a search carries (the year span,
 * S-02), what the federated union and count calls send (S-14), and that a
 * newer search always beats an older one still waiting on its key (S-09).
 */

const TOKEN = { key: 'k', expires_at: 2 ** 31, host: '', collection: 'iwac_current' };

let tokenSeq = 0;

function bootstrap(overrides: Partial<IwacBootstrap> = {}): IwacBootstrap {
  return {
    block_id: 'test',
    mode: 'full',
    locked_filters: '',
    prominent_facets: ['country_ss'],
    default_sort: '_text_match:desc',
    results_per_page: 10,
    collection_alias: 'iwac_current',
    // A fresh token endpoint per client: the key cache is module-scoped and
    // keyed by endpoint, so tests never share (or wait on) each other's mint.
    endpoints: { token: `/token-${++tokenSeq}`, search: '/search-api/multi_search' },
    ...overrides,
  } as IwacBootstrap;
}

type Body = { searches?: Array<Record<string, unknown>>; union?: boolean };

function serve(
  answer: (body: Body, url: string) => unknown,
  tokenGate?: Promise<void>,
): { bodies: Body[]; urls: string[] } {
  const bodies: Body[] = [];
  const urls: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      if (String(url).includes('/token-')) {
        await tokenGate;
        return new Response(JSON.stringify(TOKEN), { status: 200 });
      }
      const body = JSON.parse(String(init?.body ?? '{}')) as Body;
      bodies.push(body);
      urls.push(String(url));
      return new Response(JSON.stringify(answer(body, String(url))), { status: 200 });
    }),
  );
  return { bodies, urls };
}

const page = (found: number, extra: object = {}) => ({ hits: [], found, page: 1, ...extra });

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('search()', () => {
  it('appends the year-span sub-search: browse, locked filters only, stats only', async () => {
    const { bodies } = serve(() => ({
      results: [
        page(3),
        page(0, { facet_counts: [{ field_name: 'pub_year', counts: [] }] }),
        page(3),
        page(0, {
          facet_counts: [{ field_name: 'pub_year', counts: [], stats: { min: 1912, max: 2026 } }],
        }),
      ],
    }));
    const client = new TypesenseClient(bootstrap({ locked_filters: 'type_s:=reference' }));
    const out = await client.search({
      q: 'islam',
      activeFilters: { country_ss: ['Niger'] },
      yearRange: { from: 1990 },
      withYearDistribution: true,
      withYearSpan: true,
    });

    const searches = bodies[0].searches!;
    expect(searches).toHaveLength(4); // main, histogram, keyword count, span
    const span = searches[3];
    expect(span).toMatchObject({ q: '*', facet_by: 'pub_year', max_facet_values: 1, per_page: 0 });
    expect(span.filter_by).toBe('type_s:=reference');
    expect(out.span).toEqual({ min: 1912, max: 2026 });
    expect(out.response.keyword_found).toBe(3);
  });

  it('lets the newer search win when an older one is still waiting for its key', async () => {
    let release!: () => void;
    const gate = new Promise<void>((resolve) => (release = resolve));
    serve((body) => {
      const q = body.searches?.[0]?.q;
      return { results: [page(q === 'islam' ? 7 : 1), page(1), page(1)] };
    }, gate);
    const client = new TypesenseClient(bootstrap());
    // The older search has one more await (the histogram's filter), so it
    // reaches its POST after the newer one — and used to abort it there.
    const older = client.search({ q: 'isl', withYearDistribution: true });
    const newer = client.search({ q: 'islam' });
    release();
    await expect(older).rejects.toMatchObject({ name: 'AbortError' });
    expect((await newer).response.found).toBe(7);
  });
});

describe('unionSearch()', () => {
  it('pages in the URL, sorts every leg alike, then counts the keyword legs', async () => {
    const { bodies, urls } = serve((body) =>
      body.union ? page(12) : { results: [page(4), page(2)] },
    );
    const client = new TypesenseClient(bootstrap());
    const out = await client.unionSearch({
      q: 'tabaski',
      page: 3,
      perPage: 20,
      searches: [
        { collection: 'iwac_current', queryBy: 'title_txt,embedding' },
        { collection: 'iwac_index_current', queryBy: 'title', filterBy: 'entity_type_s:=Lieux' },
      ],
    });

    const union = bodies[0];
    expect(union.union).toBe(true);
    expect(new URL(urls[0], 'http://x').searchParams.get('page')).toBe('3');
    expect(new URL(urls[0], 'http://x').searchParams.get('per_page')).toBe('20');
    expect(union.searches!.map((s) => s.sort_by)).toEqual(['_text_match:desc', '_text_match:desc']);
    expect(union.searches![1].filter_by).toBe('entity_type_s:=Lieux');
    // The keyword-only legs drop the embedding.
    expect(bodies[1].searches![0].query_by).toBe('title_txt');
    expect(out.keyword_found).toBe(6);
  });

  it('sorts browse mode by date and asks for no keyword counts', async () => {
    const { bodies } = serve(() => page(40));
    await new TypesenseClient(bootstrap()).unionSearch({
      q: '  ',
      searches: [{ collection: 'iwac_current', queryBy: 'title_txt' }],
    });
    expect(bodies).toHaveLength(1);
    expect(bodies[0].searches![0]).toMatchObject({ q: '*', sort_by: 'date:desc' });
  });
});

describe('countAcross()', () => {
  it('sends a hybrid and a keyword count per collection, and zeroes a keyword miss', async () => {
    const { bodies } = serve(() => ({
      results: [page(100), page(0), page(30), page(12)],
    }));
    const counts = await new TypesenseClient(bootstrap()).countAcross('zzkw', [
      { collection: 'iwac_current', queryBy: 'title_txt,embedding' },
      { collection: 'iwac_index_current', queryBy: 'title' },
    ]);

    const searches = bodies[0].searches!;
    expect(searches).toHaveLength(4);
    expect(searches.map((s) => s.per_page)).toEqual([0, 0, 0, 0]);
    expect(searches[0].query_by).toBe('title_txt,embedding');
    expect(searches[1].query_by).toBe('title_txt');
    // 100 hybrid hits over zero keyword hits is the vector leg's top-k, not a count.
    expect(counts).toEqual([0, 30]);
  });

  it('asks nothing for no collections', async () => {
    const { bodies } = serve(() => ({ results: [] }));
    expect(await new TypesenseClient(bootstrap()).countAcross('x', [])).toEqual([]);
    expect(bodies).toHaveLength(0);
  });
});

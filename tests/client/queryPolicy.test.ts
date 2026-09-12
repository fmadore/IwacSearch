import { afterEach, describe, expect, it, vi } from 'vitest';
import { TypesenseClient } from '../../src/svelte/lib/typesense';
import { queryPolicy } from '../../src/svelte/lib/queryPolicy';
import { authenticatedSearch } from '../../src/svelte/lib/authenticatedSearch';
import type { IwacBootstrap } from '../../src/svelte/lib/types';

const bootstrap: IwacBootstrap = {
  block_id: 'policy-test',
  mode: 'full',
  locked_filters: '',
  prominent_facets: [],
  default_sort: '_text_match:desc',
  results_per_page: 10,
  endpoints: { token: '/policy-token', search: '/multi_search' },
};
const token = { key: 'test-key', collection: 'iwac_current', expires_at: 2 ** 31 };
const page = {
  found: 100,
  hits: [{ document: { id: '1', title: 'Vector match' }, text_match: 0 }],
};
type Search = Record<string, unknown>;

function server(reply: (searches: Search[]) => unknown) {
  const sent: Search[][] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      if (String(url) === '/policy-token') return Response.json(token);
      const searches = JSON.parse(String(init?.body)).searches as Search[];
      sent.push(searches);
      return Response.json(reply(searches));
    }),
  );
  return sent;
}
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('shared population policy', () => {
  it.each(['"imam de Lomé"', 'imam -politique'])(
    'keeps exact syntax strict everywhere: %s',
    (q) => {
      const p = queryPolicy(q, 'title_txt,embedding');
      expect(p.query_by).toBe('title_txt');
      expect(p).not.toHaveProperty('stopwords');
      expect(p).toMatchObject({ num_typos: 0, prefix: false, drop_tokens_threshold: 0 });
    },
  );
  it('keeps stopwords on main, histogram and keyword probes, including retry', async () => {
    const sent = server((searches) => ({
      results: searches.map((s, i) =>
        i === 0 ? page : { found: 1, hits: [], facet_counts: s.facet_by ? [] : undefined },
      ),
    }));
    const outcome = await new TypesenseClient(bootstrap).search({
      q: 'imam de Lomé',
      sortBy: 'date:desc',
      withYearDistribution: true,
    });
    expect(outcome.response.keyword_found).toBe(1);
    expect(sent[0]).toHaveLength(3);
    expect(sent[0].every((s) => s.stopwords === 'fr_default')).toBe(true);
    expect(sent[0].every((s) => s.exclude_fields === 'ocr_text,toc_txt,embedding')).toBe(true);
    expect(sent[0][1].enable_analytics).toBe(false);
    expect(sent[0][2].query_by).not.toContain('embedding');
  });
  it('preserves main results when the histogram fails, without claiming empty data', async () => {
    server(() => ({ results: [page, { code: 500, error: 'histogram failed' }, { found: 1 }] }));
    const outcome = await new TypesenseClient(bootstrap).search({
      q: 'imam',
      withYearDistribution: true,
    });
    expect(outcome.response.found).toBe(100);
    expect(outcome.years).toBeUndefined();
    expect(outcome.yearsUnavailable).toBe(true);
  });
  it('keeps facet and export requests in the exact population', async () => {
    const sent = server((searches) => ({
      results: searches.map(() => ({ found: 0, hits: [], facet_counts: [] })),
    }));
    const client = new TypesenseClient(bootstrap);
    await client.searchFacetValues({ q: '"imam"', query: 'Lomé', field: 'places_ss' });
    await client.fetchForExport({ q: '"imam"' });
    for (const request of sent.flat()) {
      expect(request.query_by).not.toContain('embedding');
      expect(request).not.toHaveProperty('stopwords');
      expect(request).toMatchObject({ num_typos: 0, enable_analytics: false });
    }
  });
  it('does not fetch another map page after cancellation', async () => {
    const controller = new AbortController();
    const sent = server(() => {
      controller.abort();
      return { results: [page] };
    });
    await expect(
      new TypesenseClient(bootstrap).fetchForMap({ q: 'imam', signal: controller.signal }),
    ).rejects.toMatchObject({ name: 'AbortError' });
    expect(sent).toHaveLength(1);
  });
  it('uses query-level counts for federated badges', async () => {
    server(() => ({ results: [{ found: 100 }, { found: 1 }, { found: 100 }, { found: 0 }] }));
    expect(
      await new TypesenseClient(bootstrap).countAcross('imam', [
        { collection: 'a', queryBy: 'title_txt,embedding' },
        { collection: 'b', queryBy: 'title_txt,embedding' },
      ]),
    ).toEqual([100, 0]);
  });
});

it('refreshes authorization once and never loops on a rejected replacement', async () => {
  let posts = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      if (url === '/refresh-test-token') return Response.json({ ...token, key: 'replacement' });
      posts += 1;
      return new Response('unauthorized', { status: 401 });
    }),
  );
  await expect(
    authenticatedSearch('/refresh-test-token', '/search', 'rejected', {}, 'Test'),
  ).rejects.toThrow(/401/);
  expect(posts).toBe(2);
});

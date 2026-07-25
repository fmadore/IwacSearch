import { afterEach, describe, expect, it, vi } from 'vitest';
import { TypesenseClient } from '../../src/svelte/lib/typesense';
import type { IwacBootstrap } from '../../src/svelte/lib/types';

/**
 * The stopword-recovery retry and the shared request preamble.
 *
 * A missing `fr_default` stopword set on the server is a REAL operational
 * state — a fresh Typesense volume has none until a reindex or
 * cli/stopwords-sync.php provisions it. Stopwords are an enhancement, so the
 * client degrades instead of failing; if that path breaks, every search on a
 * freshly-provisioned instance returns "Search unavailable".
 *
 * The loop used to be written out three times. These pin the behaviour now
 * that one helper serves search() and unionSearch().
 */

function bootstrap(overrides: Partial<IwacBootstrap> = {}): IwacBootstrap {
  return {
    block_id: 'test',
    mode: 'full',
    locked_filters: '',
    prominent_facets: [],
    default_sort: '_text_match:desc',
    results_per_page: 10,
    collection_alias: 'iwac_current',
    endpoints: { token: '/discovery/token', search: '/search-api/multi_search' },
    ...overrides,
  } as IwacBootstrap;
}

const TOKEN = {
  key: 'scoped-key',
  expires_at: 2 ** 31,
  host: '/search-api',
  collection: 'iwac_current',
};
const HIT_PAGE = { hits: [], found: 0, page: 1 };
const STOPWORD_ERROR = { code: 404, error: 'Could not find a stopword set named `fr_default`.' };

/** Bodies POSTed to the search endpoint, in order. */
type Sent = Record<string, unknown>;

function mockServer(responses: unknown[]): { sent: Sent[]; urls: string[] } {
  const sent: Sent[] = [];
  const urls: string[] = [];
  let call = 0;
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      // The token endpoint is hit once and cached module-side.
      if (String(url).includes('/discovery/token')) {
        return new Response(JSON.stringify(TOKEN), { status: 200 });
      }
      urls.push(String(url));
      sent.push(JSON.parse(String(init?.body ?? '{}')));
      const body = responses[Math.min(call, responses.length - 1)];
      call += 1;
      return new Response(JSON.stringify(body), { status: 200 });
    }),
  );
  return { sent, urls };
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('stopword recovery', () => {
  it('retries once WITHOUT stopwords when the set is missing, and succeeds', async () => {
    const { sent } = mockServer([{ results: [STOPWORD_ERROR] }, { results: [HIT_PAGE] }]);
    vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = await new TypesenseClient(bootstrap()).search({ q: 'ramadan' });

    expect(result.found).toBe(0);
    expect(sent).toHaveLength(2);
    // First attempt asks for stopwords; the retry drops the field entirely
    // (not just empties it — Typesense 404s on an unknown set name).
    expect(sent[0].searches).toMatchObject([{ stopwords: 'fr_default' }]);
    expect(sent[1].searches[0]).not.toHaveProperty('stopwords');
  });

  it('warns the operator exactly once, pointing at the fix', async () => {
    mockServer([{ results: [STOPWORD_ERROR] }, { results: [HIT_PAGE] }]);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    await new TypesenseClient(bootstrap()).search({ q: 'x' });

    expect(warn).toHaveBeenCalledTimes(1);
    expect(String(warn.mock.calls[0][0])).toMatch(/stopwords-sync|discovery:reindex/);
  });

  it('gives up after ONE retry rather than looping', async () => {
    // The retry drops `stopwords`, so a second stopword error can only mean
    // something else is wrong — it must surface, not spin.
    const { sent } = mockServer([{ results: [STOPWORD_ERROR] }]);
    vi.spyOn(console, 'warn').mockImplementation(() => {});

    await expect(new TypesenseClient(bootstrap()).search({ q: 'x' })).rejects.toThrow();
    expect(sent).toHaveLength(2);
  });

  it('does NOT retry on an unrelated per-search error', async () => {
    const { sent } = mockServer([
      { results: [{ code: 400, error: 'Field `nope` not found in schema.' }] },
    ]);

    await expect(new TypesenseClient(bootstrap()).search({ q: 'x' })).rejects.toThrow(/nope/);
    expect(sent).toHaveLength(1);
  });

  it('applies the same recovery to the federated union search', async () => {
    const { sent, urls } = mockServer([STOPWORD_ERROR, HIT_PAGE]);
    vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = await new TypesenseClient(bootstrap()).unionSearch({
      q: 'ramadan',
      searches: [
        { collection: 'iwac_current', queryBy: 'title_txt' },
        { collection: 'iwac_index_current', queryBy: 'title_txt' },
      ],
    });

    expect(result.found).toBe(0);
    expect(sent).toHaveLength(2);
    // Union mode returns ONE merged object rather than {results: [...]}, and
    // pages via the URL — both must survive the retry.
    expect(sent[0].union).toBe(true);
    expect(urls[1]).toContain('page=1');
  });
});

describe('exact-query handling', () => {
  it('drops embedding and stopwords, and adds the strict params', async () => {
    // A quoted phrase must not be softened by semantic blending or typo
    // tolerance — that is the whole point of typing the quotes.
    const { sent } = mockServer([{ results: [HIT_PAGE] }]);

    await new TypesenseClient(bootstrap({ query_by: 'title_txt,ocr_text,embedding' })).search({
      q: '"radicalisation en Côte d\'Ivoire"',
    });

    const search = (sent[0].searches as Sent[])[0];
    expect(search.query_by).toBe('title_txt,ocr_text');
    expect(search).not.toHaveProperty('stopwords');
    expect(search).toMatchObject({ num_typos: 0, drop_tokens_threshold: 0 });
  });

  it('leaves an ordinary query fuzzy, with embedding and stopwords intact', async () => {
    const { sent } = mockServer([{ results: [HIT_PAGE] }]);

    await new TypesenseClient(bootstrap({ query_by: 'title_txt,ocr_text,embedding' })).search({
      q: 'ramadan',
    });

    const search = (sent[0].searches as Sent[])[0];
    expect(search.query_by).toBe('title_txt,ocr_text,embedding');
    expect(search.stopwords).toBe('fr_default');
    expect(search).not.toHaveProperty('num_typos');
  });

  it('never treats browse mode as exact', async () => {
    const { sent } = mockServer([{ results: [HIT_PAGE] }]);

    await new TypesenseClient(bootstrap()).search({ q: '   ' });

    const search = (sent[0].searches as Sent[])[0];
    expect(search.q).toBe('*');
    expect(search).not.toHaveProperty('num_typos');
  });
});

describe('shared request preamble', () => {
  it('combines locked filters, facet selections and the year range', async () => {
    const { sent } = mockServer([{ results: [HIT_PAGE] }]);

    await new TypesenseClient(bootstrap({ locked_filters: 'type_s:=article' })).search({
      q: 'x',
      activeFilters: { country_ss: ['Niger'] },
      yearRange: { from: 1990, to: 1999 },
    });

    expect((sent[0].searches as Sent[])[0].filter_by).toBe(
      'type_s:=article && country_ss:=[`Niger`] && pub_year:>=1990 && pub_year:<=1999',
    );
  });

  it('omits the year range from the histogram so the bars keep the full span', async () => {
    // Dragging the slider must repaint which bars are highlighted, not
    // collapse the chart to the selected window.
    const { sent } = mockServer([{ results: [{ ...HIT_PAGE, facet_counts: [] }] }]);

    await new TypesenseClient(bootstrap({ locked_filters: 'type_s:=article' })).yearDistribution({
      q: 'x',
      activeFilters: { country_ss: ['Niger'] },
    });

    const search = (sent[0].searches as Sent[])[0];
    expect(search.filter_by).toBe('type_s:=article && country_ss:=[`Niger`]');
    expect(search.filter_by).not.toContain('pub_year');
    expect(search.facet_by).toBe('pub_year');
    expect(search.per_page).toBe(0);
  });
});

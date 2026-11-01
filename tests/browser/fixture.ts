import { readFileSync } from 'node:fs';
import { extname, join, normalize, sep } from 'node:path';
import type { Page, Route } from '@playwright/test';

/**
 * A static stand-in for an IWAC page carrying an IwacSearch surface, for the
 * browser suite. Everything is served through page.route() under a made-up
 * origin, so no Omeka, PHP or Typesense is involved:
 *
 *   - the page shell, written to the mount contract of
 *     view/common/iwac-search-mount.phtml and iwac-federated-mount.phtml (the
 *     root div, its data attributes, the sibling JSON state script) — the
 *     same markup tests/lighthouse/fixtures captures from the live site;
 *   - the BUILT bundle and stylesheets from asset/, plus the theme's tokens
 *     from tokens.json (light on :root, dark under the body[data-theme] the
 *     theme's pre-paint script writes);
 *   - the two live endpoints the browser talks to: /discovery/token (the
 *     scoped key) and /search-api/multi_search (Typesense), answered by a
 *     small in-memory Typesense that reads the same request shapes as the
 *     real one: q, filter_by, facet_by, facet_query, sort_by, paging and
 *     union mode — see {@link answerSearch};
 *   - MapLibre, as a fake `window.maplibregl` (the loader uses a global it
 *     finds before reaching for the CDN), so the Map view mounts offline.
 *
 * Documents follow src/svelte/lib/types.ts (IwacDoc). Tests flip the
 * {@link ApiStub} counters to make the next request of a kind fail.
 */

const ROOT = join(import.meta.dirname, '..', '..');

const MIME: Record<string, string> = {
  '.js': 'text/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
};

type Doc = { id: string; title: string } & Record<string, unknown>;

const CONTENT_ALIAS = 'iwac_current';
const INDEX_ALIAS = 'iwac_index_current';

/** Midnight UTC, as the indexer stores publication dates. */
const day = (iso: string): number => Date.parse(`${iso}T00:00:00Z`) / 1000;

export const CONTENT: Doc[] = [
  {
    id: '101',
    title: 'Le Ramadan à Ouagadougou',
    type_s: 'article',
    date: day('1995-02-03'),
    pub_year: 1995,
    country_ss: ['Burkina Faso'],
    newspaper_ss: ['Sidwaya'],
    places_ss: ['Ouagadougou'],
    topics_ss: ['Ramadan'],
    creator_ss: ['Ouédraogo, Issa'],
    abstract: 'Le jeûne du mois de Ramadan dans la capitale.',
    omeka_url: '/s/afrique_ouest/item/101',
  },
  {
    id: '102',
    title: 'La Tabaski à Niamey',
    type_s: 'article',
    date: day('2004-01-31'),
    pub_year: 2004,
    country_ss: ['Niger'],
    newspaper_ss: ['Le Sahel'],
    places_ss: ['Niamey'],
    topics_ss: ['Tabaski'],
    abstract: 'La fête du mouton dans les quartiers de Niamey.',
    omeka_url: '/s/afrique_ouest/item/102',
  },
  {
    id: '103',
    title: 'Les confréries soufies au Niger',
    type_s: 'publication',
    date: day('2012-06-01'),
    pub_year: 2012,
    country_ss: ['Niger'],
    places_ss: ['Niamey'],
    topics_ss: ['Tidjaniyya'],
    abstract: 'Une revue des confréries et de leurs zaouïas.',
    omeka_url: '/s/afrique_ouest/item/103',
  },
];

export const ENTITIES: Doc[] = [
  {
    id: '201',
    title: 'Ouagadougou',
    entity_type_s: 'Lieux',
    frequency: 120,
    first_year: 1962,
    last_year: 2024,
    country_ss: ['Burkina Faso'],
    geo: [12.37, -1.52],
    has_coords: true,
    omeka_url: '/s/afrique_ouest/item/201',
  },
  {
    id: '202',
    title: 'Niamey',
    entity_type_s: 'Lieux',
    frequency: 80,
    first_year: 1970,
    last_year: 2023,
    country_ss: ['Niger'],
    geo: [13.51, 2.11],
    has_coords: true,
    omeka_url: '/s/afrique_ouest/item/202',
  },
  {
    id: '203',
    title: 'Tidjaniyya',
    entity_type_s: 'Sujets',
    frequency: 40,
    first_year: 1980,
    last_year: 2020,
    has_coords: false,
    omeka_url: '/s/afrique_ouest/item/203',
  },
];

const ENDPOINTS = { token: '/discovery/token', search: '/search-api/multi_search' };

/** A content surface's bootstrap, shaped as SurfaceBootstrap.php emits it. */
function contentBootstrap(blockId: string | number, withSsr: boolean): Record<string, unknown> {
  return {
    block_id: blockId,
    mode: 'full',
    card: 'content',
    locale: 'en',
    locked_filters: '',
    prominent_facets: ['type_s', 'country_ss', 'newspaper_ss', 'places_ss', 'topics_ss'],
    hide_country: false,
    default_sort: '_text_match:desc',
    results_per_page: 10,
    collection_alias: CONTENT_ALIAS,
    index_collection_alias: INDEX_ALIAS,
    query_by: 'title_txt,abstract,entity_aliases_txt,embedding',
    highlight_fields: 'title_txt,abstract,entity_aliases_txt',
    endpoints: ENDPOINTS,
    // The server's first page: the browse request the client would make.
    ...(withSsr
      ? {
          initial_response: answerSearch({
            collection: CONTENT_ALIAS,
            q: '*',
            sort_by: 'date:desc',
            per_page: 10,
            facet_by: 'type_s,country_ss,newspaper_ss,places_ss,topics_ss',
          }),
        }
      : {}),
  };
}

function entityBootstrap(blockId: string): Record<string, unknown> {
  return {
    block_id: blockId,
    mode: 'full',
    card: 'entity',
    locale: 'en',
    locked_filters: '',
    prominent_facets: ['entity_type_s', 'country_ss'],
    hide_country: false,
    default_sort: 'frequency:desc',
    results_per_page: 20,
    collection_alias: INDEX_ALIAS,
    index_collection_alias: INDEX_ALIAS,
    query_by: 'title_txt,entity_aliases_txt',
    highlight_fields: 'title_txt',
    endpoints: ENDPOINTS,
  };
}

/** The root + state script of view/common/iwac-search-mount.phtml. */
function searchMount(id: string | number, bootstrap: object, headingLevel: 2 | 3): string {
  return `<div data-iwac-search-root data-iwac-block-id="${id}" data-iwac-heading-level="${headingLevel}"
     class="iwac-search-block__root" id="iwac-search-root-${id}">
  <div class="iwac-search-block__skeleton" aria-hidden="true">
    <div class="iwac-search-block__skeleton-input"></div>
  </div>
</div>
<script type="application/json" id="iwac-search-state-${id}">${JSON.stringify(bootstrap)}</script>`;
}

/** The root + state script of view/common/iwac-federated-mount.phtml. */
function federatedMount(): string {
  const bootstrap = {
    variant: 'federated',
    locale: 'en',
    initial_query: '',
    default_tab: 'content',
    tabs: [
      { id: 'content', bootstrap: contentBootstrap('everything-content', false) },
      { id: 'entities', bootstrap: entityBootstrap('everything-entities') },
    ],
    endpoints: ENDPOINTS,
  };
  return `<div data-iwac-federated-root data-iwac-block-id="everything"
     class="iwac-search-block__root" id="iwac-federated-root-everything"></div>
<script type="application/json" id="iwac-federated-state-everything">${JSON.stringify(bootstrap)}</script>`;
}

/**
 * view/common/block-layout/iwac-search-block.phtml, titled or not. The titled
 * one also carries the server's first page (a block with locked filters gets
 * one), so the block mount is exercised with and without it.
 */
function block(title: string): string {
  const id = 42;
  const open = title
    ? `<section class="iwac-search-block iwac-search-block--full" aria-labelledby="iwac-search-heading-${id}">
  <h2 id="iwac-search-heading-${id}" class="iwac-search-block__title">${title}</h2>`
    : `<section class="iwac-search-block iwac-search-block--full" aria-label="Search and filter the collection">`;
  return `${open}
  ${searchMount(id, contentBootstrap(id, title !== ''), title ? 3 : 2)}
</section>`;
}

/**
 * The theme's tokens as CSS, from tokens.json (synced from IWAC-theme): light
 * values on :root, dark ones under body[data-theme="dark"], which is where
 * the theme declares them and where the module's own dark rules look.
 */
function tokenCss(): string {
  const tokens = JSON.parse(readFileSync(join(ROOT, 'tokens.json'), 'utf8')) as {
    light: Record<string, string>;
    dark: Record<string, string>;
    values: { light: Record<string, string>; dark: Record<string, string> };
  };
  const decls = (...tables: Array<Record<string, string>>): string =>
    tables
      .flatMap((t) => Object.entries(t))
      .map(([name, value]) => `${name}: ${value};`)
      .join('\n');
  return `:root { ${decls(tokens.values.light, tokens.light)} }
body[data-theme="dark"] { ${decls(tokens.values.dark, tokens.dark)} color-scheme: dark; }
body { margin: 0; padding: 1rem; background: var(--background); color: var(--ink);
  font-family: var(--font-body); }
h1 { font-family: var(--font-headings); }`;
}

type Surface = 'search' | 'search-ssr' | 'block' | 'titled-block' | 'federated' | 'entities';

function pageHtml(surface: Surface): string {
  const [h1, body] = ((): [string, string] => {
    switch (surface) {
      case 'search':
        return [
          'Search the IWAC archive',
          searchMount('standalone', contentBootstrap('standalone', false), 2),
        ];
      case 'search-ssr':
        return [
          'Search the IWAC archive',
          searchMount('standalone', contentBootstrap('standalone', true), 2),
        ];
      case 'block':
        return ['Collection', block('')];
      case 'titled-block':
        return ['Collection', block('Find articles')];
      case 'federated':
        return ['Search everything', federatedMount()];
      case 'entities':
        return ['Index', searchMount('standalone', entityBootstrap('standalone'), 2)];
    }
  })();
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${h1}</title>
  <link rel="stylesheet" href="/tokens.css">
  <link rel="stylesheet" href="/asset/css/iwac-search.css">
  <link rel="stylesheet" href="/asset/dist/iwac-search.css">
  <script defer src="/asset/dist/iwac-search.js"></script>
</head>
<body data-theme="light">
  <main>
    <h1 class="title">${h1}</h1>
    ${body}
  </main>
</body>
</html>`;
}

// ── The Typesense stand-in ───────────────────────────────────────────────

type Search = Record<string, unknown>;

interface Hit {
  document: Doc;
  highlights: never[];
}

/** Lower-cased, accents folded — Typesense matches "Tidjaniyya" for "tidjaniyya". */
function fold(text: unknown): string {
  return String(text ?? '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();
}

/** Every query token appears in the title or the abstract (all of them, like `drop_tokens_threshold: 0`). */
function matchesQuery(doc: Doc, q: string): boolean {
  if (q.trim() === '' || q === '*') return true;
  const hay = fold(`${doc.title} ${String(doc.abstract ?? '')}`);
  return fold(q.replace(/["-]/g, ' '))
    .split(/\s+/)
    .filter(Boolean)
    .every((token) => hay.includes(token));
}

/** The filter_by clauses the client writes (queryBuilders.ts), joined by `&&`. */
function matchesFilter(doc: Doc, filterBy: unknown): boolean {
  if (typeof filterBy !== 'string' || filterBy.trim() === '') return true;
  return filterBy.split(' && ').every((raw) => {
    const clause = raw.trim().replace(/^\((.*)\)$/, '$1');
    const range = /^(\w+):(>=|<=)(-?\d+)$/.exec(clause);
    if (range) {
      const value = Number(doc[range[1] ?? '']);
      const bound = Number(range[3]);
      return range[2] === '>=' ? value >= bound : value <= bound;
    }
    const eq = /^(\w+):=(.*)$/.exec(clause);
    if (!eq) throw new Error(`fixture cannot read filter clause: ${clause}`);
    const field = eq[1] ?? '';
    const list = (eq[2] ?? '').replace(/^\[(.*)\]$/, '$1');
    const wanted = list.split(',').map((v) => v.trim().replace(/^`(.*)`$/, '$1'));
    const have = ([] as unknown[]).concat(doc[field] ?? []).map(String);
    return wanted.some((v) => have.includes(v));
  });
}

function sortDocs(docs: Doc[], sortBy: unknown): Doc[] {
  const primary = String(sortBy ?? '_text_match:desc').split(',')[0] ?? '';
  const [field, order] = primary.split(':');
  if (!field || field === '_text_match') return docs;
  const dir = order === 'asc' ? 1 : -1;
  return [...docs].sort((a, b) => (Number(a[field] ?? 0) - Number(b[field] ?? 0)) * dir);
}

function facetCounts(docs: Doc[], facetBy: unknown, facetQuery: unknown, maxValues: unknown) {
  if (typeof facetBy !== 'string' || facetBy === '') return undefined;
  const [queryField, queryText] =
    typeof facetQuery === 'string' ? facetQuery.split(/:(.*)/s) : [undefined, undefined];
  return facetBy.split(',').map((field) => {
    const tally = new Map<string, number>();
    for (const doc of docs) {
      for (const value of ([] as unknown[]).concat(doc[field] ?? [])) {
        tally.set(String(value), (tally.get(String(value)) ?? 0) + 1);
      }
    }
    let counts = [...tally]
      .map(([value, count]) => ({ value, count, highlighted: value }))
      .sort((a, b) => b.count - a.count);
    if (field === queryField && queryText) {
      counts = counts.filter((c) => fold(c.value).includes(fold(queryText)));
    }
    const numbers = [...tally.keys()].map(Number).filter(Number.isFinite);
    return {
      field_name: field,
      counts: counts.slice(0, Number(maxValues ?? 10)),
      stats: {
        total_values: counts.length,
        ...(field === 'pub_year' && numbers.length > 0
          ? { min: Math.min(...numbers), max: Math.max(...numbers) }
          : {}),
      },
    };
  });
}

/** One sub-search, answered over the in-memory collections. */
export function answerSearch(search: Search, paging?: { page: number; perPage: number }) {
  const pool = search.collection === INDEX_ALIAS ? ENTITIES : CONTENT;
  const q = String(search.q ?? '*');
  const matched = sortDocs(
    pool.filter((doc) => matchesQuery(doc, q) && matchesFilter(doc, search.filter_by)),
    search.sort_by,
  );
  const page = paging?.page ?? Number(search.page ?? 1);
  const perPage = paging?.perPage ?? Number(search.per_page ?? 10);
  const hits: Hit[] = matched
    .slice((page - 1) * perPage, page * perPage)
    .map((document) => ({ document, highlights: [] }));
  return {
    found: matched.length,
    out_of: pool.length,
    page,
    request_params: { q, per_page: perPage },
    search_time_ms: 1,
    hits,
    facet_counts: facetCounts(
      matched,
      search.facet_by,
      search.facet_query,
      search.max_facet_values,
    ),
  };
}

/** What a POST to multi_search was for, read off its first sub-search. */
export type RequestKind =
  'search' | 'aux' | 'suggest' | 'did-you-mean' | 'facet' | 'map' | 'union' | 'export';

function kindOf(body: { union?: boolean; searches?: Search[] }): RequestKind {
  const first = body.searches?.[0] ?? {};
  if (body.union) return 'union';
  if (first.prefix === true) return first.per_page === 3 ? 'did-you-mean' : 'suggest';
  if (String(first.filter_by ?? '').includes('has_coords:=true')) return 'map';
  if (first.facet_query) return 'facet';
  if (first.per_page === 0) return 'aux';
  if (first.include_fields) return 'export';
  return 'search';
}

/** What the stand-in should do; tests flip these between steps. */
export interface ApiStub {
  /** Answer this many result searches (and union searches) with HTTP 500. */
  failSearches: number;
  /** Answer this many typeahead requests with HTTP 500. */
  failSuggests: number;
  /** Answer this many Map-view fetches with HTTP 500. */
  failMaps: number;
  /** Every multi_search body, with what it was for, in order. */
  requests: Array<{ kind: RequestKind; body: { searches?: Search[]; union?: boolean } }>;
  /** The main sub-search of every result search, in order. */
  searches(): Search[];
}

function json(route: Route, body: unknown, status = 200): Promise<void> {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}

/** A stand-in for MapLibre's global: enough of Map for MapView to mount and draw. */
function fakeMapLibre(): void {
  class FakeMap {
    private readonly handlers = new Map<string, Array<() => void>>();
    private readonly sources = new Map<string, { setData(data: unknown): void }>();
    private readonly canvas = document.createElement('canvas');
    constructor(opts: { container: HTMLElement }) {
      opts.container.appendChild(this.canvas);
      setTimeout(() => this.handlers.get('load')?.forEach((fn) => fn()), 0);
    }
    on(event: string, layerOrFn: unknown, fn?: () => void): void {
      if (typeof layerOrFn === 'function') {
        this.handlers.set(event, [...(this.handlers.get(event) ?? []), layerOrFn as () => void]);
      } else if (fn) {
        this.handlers.set(`${event}:${String(layerOrFn)}`, [fn]);
      }
    }
    addSource(id: string, source: { data: unknown }): void {
      const draw = (data: unknown): void => {
        const count = (data as { features?: unknown[] }).features?.length ?? 0;
        this.canvas.dataset.points = String(count);
      };
      draw(source.data);
      this.sources.set(id, { setData: draw });
    }
    getSource(id: string): unknown {
      return this.sources.get(id) ?? null;
    }
    addLayer(): void {}
    addControl(): void {}
    fitBounds(): void {}
    easeTo(): void {}
    getZoom(): number {
      return 3;
    }
    getCanvas(): HTMLCanvasElement {
      return this.canvas;
    }
    remove(): void {
      this.canvas.remove();
    }
  }
  class FakePopup {
    setLngLat(): this {
      return this;
    }
    setHTML(): this {
      return this;
    }
    addTo(): this {
      return this;
    }
  }
  (window as unknown as Record<string, unknown>).maplibregl = {
    Map: FakeMap,
    Popup: FakePopup,
    NavigationControl: class {},
    FullscreenControl: class {},
  };
}

/**
 * Serve `surface` on `page` at the fixture origin and return the API stub.
 * The page is answered at any path, so a test may navigate with its own query
 * string (a shared link).
 */
export async function serve(page: Page, surface: Surface = 'search'): Promise<ApiStub> {
  const stub: ApiStub = {
    failSearches: 0,
    failSuggests: 0,
    failMaps: 0,
    requests: [],
    searches() {
      return this.requests
        .filter((r) => r.kind === 'search')
        .map((r) => r.body.searches?.[0] ?? {});
    },
  };

  await page.addInitScript(fakeMapLibre);

  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;

    if (url.origin !== 'http://iwac.test') return route.abort();
    if (path === '/tokens.css') {
      return route.fulfill({ contentType: 'text/css', body: tokenCss() });
    }
    if (path.startsWith('/asset/')) {
      const file = normalize(join(ROOT, path));
      if (!file.startsWith(join(ROOT, 'asset') + sep)) return route.abort();
      return route.fulfill({
        contentType: MIME[extname(file)] ?? 'application/octet-stream',
        body: readFileSync(file),
      });
    }
    if (/^\/s\/[^/]+\/item\/\d+$/.test(path)) {
      return route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><title>Item</title>',
      });
    }
    if (path === ENDPOINTS.token) {
      return json(route, {
        key: 'fixture-key',
        expires_at: Math.floor(Date.now() / 1000) + 3600,
        expires_in: 3600,
        host: '',
        collection: CONTENT_ALIAS,
      });
    }
    if (path === ENDPOINTS.search) {
      const body = (request.postDataJSON() ?? {}) as { searches?: Search[]; union?: boolean };
      const kind = kindOf(body);
      stub.requests.push({ kind, body });
      const failure =
        ((kind === 'search' || kind === 'union') && stub.failSearches-- > 0) ||
        (kind === 'suggest' && stub.failSuggests-- > 0) ||
        (kind === 'map' && stub.failMaps-- > 0);
      if (failure) {
        return json(route, { message: 'Typesense timed out (fixture)' }, 500);
      }
      if (kind === 'union') {
        // One merged ranking; pagination comes from the URL in union mode.
        const pageNo = Number(url.searchParams.get('page') ?? 1);
        const perPage = Number(url.searchParams.get('per_page') ?? 10);
        const merged = (body.searches ?? []).flatMap(
          (s) => answerSearch({ ...s, per_page: 250, page: 1 }).hits,
        );
        return json(route, {
          found: merged.length,
          page: pageNo,
          request_params: { per_page: perPage },
          search_time_ms: 1,
          hits: merged.slice((pageNo - 1) * perPage, pageNo * perPage),
        });
      }
      return json(route, { results: (body.searches ?? []).map((s) => answerSearch(s)) });
    }
    if (request.resourceType() === 'document') {
      return route.fulfill({ contentType: 'text/html', body: pageHtml(surface) });
    }
    return route.fulfill({ status: 404, body: '' });
  });

  return stub;
}

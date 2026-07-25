import type {
  ActiveFilters,
  IwacBootstrap,
  IwacDoc,
  IwacFacetCount,
  IwacSearchResponse,
  ScopedKeyResponse,
  SuggestResult,
  YearBucket,
  YearRange,
} from './types';
import {
  CONTENT_HIGHLIGHT_FALLBACK,
  CONTENT_QUERY_BY_FALLBACK,
  EXACT_MODE_PARAMS,
  buildFilterBy,
  buildYearRangeFilter,
  combineFilters,
  isExactQuery,
  resolveSortBy,
  withoutField,
} from './queryBuilders';
import {
  AbortSlot,
  type MultiSearchEnvelope,
  type TypesensePerSearchError,
  perSearchError,
  postJson,
  validateSearchResult,
} from './transport';
import { getScopedKey } from './scopedKey';
import { runSuggest } from './suggestQuery';

/**
 * Hard cap on exported hits (Typesense pages at 250/request, so 4 pages).
 * Exports follow the CURRENT sort, so a truncated export keeps the most
 * relevant / newest results.
 */
export const EXPORT_MAX_HITS = 1000;

/**
 * Citation metadata only — no OCR, no embedding, no sentiment. Field
 * conventions follow the IWAC-SEO CitationMeta service: the container
 * (journal / newspaper / publisher) lives in publisher_s / newspaper_ss;
 * a chapter's containing book in book_title_s.
 */
const EXPORT_INCLUDE_FIELDS = [
  'id',
  'identifier',
  'title',
  'type_s',
  'reference_type_ss',
  'creator_ss',
  'editor_ss',
  'date',
  'pub_year',
  'publisher_s',
  'book_title_s',
  'volume_s',
  'issue_s',
  'pages_s',
  'edition_s',
  'doi',
  'newspaper_ss',
  'country_ss',
  'language_ss',
  'subjects_ss',
  'places_ss',
  'abstract',
  'omeka_url',
  'source_url',
].join(',');

/** Cap on geo-tagged entities the map view fetches (Typesense pages at 250). */
export const MAP_MAX_HITS = 2000;

/**
 * Facet values requested per field on the main search — enough for "show
 * more" inside a facet group without paging the facet API. Exported so
 * FacetGroup can infer truncation ("are there more values server-side?")
 * from the same number instead of hardcoding its own copy.
 */
export const MAX_FACET_VALUES = 50;

/** Fields the map view needs per entity marker. */
const MAP_INCLUDE_FIELDS = [
  'id',
  'title',
  'entity_type_s',
  'frequency',
  'coordinates',
  'geo',
  'omeka_url',
  'country_ss',
].join(',');

/**
 * Everything a request needs to know about "what is being searched", derived
 * once from the surface bootstrap + the caller's arguments.
 *
 * Five methods used to repeat this key→collection→filter→browse-mode→query_by
 * sequence verbatim, and had already drifted: `yearDistribution` deliberately
 * omits the year range from filter_by (the histogram must show the full span),
 * while `searchFacetValues` and `fetchForExport` do not apply exact mode. Both
 * variations are now explicit parameters rather than accidents of copying.
 */
/**
 * Does this message mean the server has no `fr_default` stopword set?
 * Typesense phrases it the same way at the HTTP layer and inside a
 * per-search error, which is why one predicate covers both.
 */
function isStopwordError(message: string): boolean {
  return /stopword set/i.test(message);
}

interface SearchContext {
  key: ScopedKeyResponse;
  collection: string;
  filterBy: string;
  /** Empty query → Typesense's `*` wildcard. */
  isBrowse: boolean;
  q: string;
  /** Already exact-adjusted when the caller asked for it. */
  queryBy: string;
  highlightFields: string;
  sortBy: string;
  exact: boolean;
}

/**
 * Thin wrapper over the Typesense REST API for the public client.
 *
 * Why not the official typesense-js package: it bundles ~50 KB of
 * cluster-management code (admin keys, collection CRUD, alias swap)
 * the browser will never use. We make exactly two kinds of HTTP calls —
 * fetch scoped key, run multi_search — so a thin wrapper is the right
 * size. The pure query-building helpers live in queryBuilders.ts and the
 * fetch/validation plumbing in transport.ts; this class owns the scoped
 * key lifecycle and the per-surface request composition.
 */
export class TypesenseClient {
  /**
   * Per-channel abort slots: a new search/suggest/histogram/union call
   * aborts its still-in-flight predecessor, so a fast typist can't get
   * out-of-order responses (or pay for their bandwidth). Callers swallow
   * the resulting AbortError via transport.isAbortError().
   */
  private readonly searchAbort = new AbortSlot();
  private readonly suggestAbort = new AbortSlot();
  private readonly yearAbort = new AbortSlot();
  private readonly unionAbort = new AbortSlot();

  constructor(private readonly bootstrap: IwacBootstrap) {}

  /**
   * Run a search.
   *
   * Empty query issues a browse request (Typesense `q=*` wildcard), so
   * curated browse surfaces, page blocks with locked_filters, and the
   * standalone /search route all show results + facet counts immediately
   * on mount. The public scoped key still carries `filter_by:is_public:=true`,
   * so this never leaks private docs. `exclude_fields: ocr_text` is also
   * baked in at mint time, so browse responses stay lean.
   *
   * `activeFilters` are the in-memory selections from the facet panel,
   * one entry per facet field. They're combined with the block's
   * locked_filters using `&&`. Selections within a single field use OR
   * semantics — `country_ss:=[\`Burkina Faso\`,\`Niger\`]` matches docs
   * in either country.
   */
  async search(args: {
    q: string;
    page?: number;
    perPage?: number;
    sortBy?: string;
    activeFilters?: ActiveFilters;
    /** Numeric year range. Either bound may be omitted. */
    yearRange?: YearRange | null;
    /**
     * Names of facet fields to request counts for. We always request the
     * UNION of (prominent_facets ∪ currently selected) so a selection
     * on a non-prominent facet still shows its current values in the UI.
     */
    facetBy?: string[];
  }): Promise<IwacSearchResponse> {
    const ctx = await this.resolveContext(args);
    const { collection, q, filterBy, isBrowse, exact } = ctx;

    const facets = args.facetBy ?? this.bootstrap.prominent_facets;

    // The body is built as a function so the stopword-recovery retry can
    // re-issue the request without the `stopwords` field.
    const buildBody = (includeStopwords: boolean) => ({
      searches: [
        {
          collection,
          q,
          // query_by is surface-specific (see queryBy above): content uses
          // title + ocr + abstract + aliases + embedding; the entity
          // collection uses only title + aliases. Typesense ignores
          // query_by when q=* so browse mode drops straight through. Exact
          // queries drop `embedding` (queryByEffective) for literal matching.
          query_by: ctx.queryBy,
          // Stopwords keep "le", "la", "des" etc. from polluting matches.
          // Conditionally included so the recovery retry can drop it — and
          // never applied to an exact query, so a quoted phrase keeps its
          // stopwords ("radicalisation en Côte d'Ivoire" stays intact).
          ...(includeStopwords && !exact ? { stopwords: 'fr_default' } : {}),
          // Strict matching for an exact query (see `exact` above).
          ...(exact ? EXACT_MODE_PARAMS : {}),
          filter_by: filterBy || undefined,
          sort_by: ctx.sortBy,
          page: args.page ?? 1,
          per_page: args.perPage ?? this.bootstrap.results_per_page,
          highlight_fields: ctx.highlightFields,
          highlight_full_fields: 'title_txt',
          snippet_threshold: 30,
          highlight_affix_num_tokens: 8,
          // No limit_hits: Typesense's default is no cap, so users can page
          // through every match (not just the first 250). per_page stays ≤ 50,
          // and Pagination windows the page bar, so deep result sets are fine.
          facet_by: facets.length > 0 ? facets.join(',') : undefined,
          max_facet_values: MAX_FACET_VALUES,
          // Result diversification (Typesense 30.2 MMR). Only on a real
          // query: browse mode (q=*) is date-sorted and must not be
          // reshuffled, and the clustering of near-identical syndicated
          // articles this fixes only happens under text-match ranking.
          // `curation_tags` activates the iwac_diversity curation set
          // linked on the collection (see CurationSync.php); the server
          // ignores it on collections without that link. diversity_lambda
          // tunes the relevance↔diversity balance (1 = relevance, 0 = max
          // variety).
          ...(!isBrowse && this.bootstrap.diversify_tag
            ? {
                curation_tags: this.bootstrap.diversify_tag,
                diversity_lambda: this.bootstrap.diversity_lambda ?? 0.7,
              }
            : {}),
        },
      ],
    });

    return this.withStopwordRetry({
      label: 'Search',
      url: this.bootstrap.endpoints.search,
      key: ctx.key.key,
      // A newer search supersedes any in-flight one.
      signal: this.searchAbort.next(),
      buildBody,
      pick: (raw) => (raw as MultiSearchEnvelope).results?.[0],
    });
  }

  /**
   * Document count per `pub_year` for the year-distribution histogram drawn
   * under the date slider.
   *
   * Scoped to the CURRENT query + categorical filters, but deliberately NOT
   * the year range itself — so the bars show the full span and reveal where
   * results cluster, instead of collapsing to the selected window. Dragging
   * the slider therefore needs no refetch (the caller re-runs this only when
   * the query or a non-year filter changes); the bars just repaint which
   * years fall inside the handles.
   *
   * Mirrors search()'s exact-mode handling so the distribution matches the
   * set a quoted / -excluded query would return. Stopwords are omitted (like
   * countAcross) so a missing `fr_default` set can't 404 the histogram — it's
   * an approximate visual, not a tallied count. Counts only (per_page:0).
   */
  async yearDistribution(args: {
    q: string;
    activeFilters?: ActiveFilters;
  }): Promise<YearBucket[]> {
    // includeYearRange:false is the whole point — see the docblock.
    const { key, collection, q, filterBy, queryBy, exact } = await this.resolveContext({
      ...args,
      includeYearRange: false,
    });

    const body = {
      searches: [
        {
          collection,
          q,
          query_by: queryBy,
          ...(exact ? EXACT_MODE_PARAMS : {}),
          filter_by: filterBy || undefined,
          facet_by: 'pub_year',
          // pub_year spans the whole corpus; 200 buckets is comfortably above
          // the distinct-year count, so no year is dropped from the histogram.
          max_facet_values: 200,
          per_page: 0,
        },
      ],
    };

    const json = await postJson<MultiSearchEnvelope>(
      this.bootstrap.endpoints.search,
      key.key,
      body,
      'Year histogram',
      this.yearAbort.next(),
    );
    const first = json.results?.[0];
    if (!first) {
      throw new Error('Year histogram response missing results[0]');
    }
    const err = perSearchError(first);
    if (err) {
      throw new Error(`Year histogram HTTP ${err.code}: ${err.error}`);
    }
    const fc = (first as IwacSearchResponse).facet_counts?.find((f) => f.field_name === 'pub_year');
    return (fc?.counts ?? [])
      .map((c) => ({ year: Number(c.value), count: c.count }))
      .filter((b) => Number.isFinite(b.year) && b.count > 0)
      .sort((a, b) => a.year - b.year);
  }

  /**
   * Search the VALUES of a single facet field server-side, so a user can find
   * and filter on a value that isn't in the top `max_facet_values` the main
   * search returns (e.g. an author beyond the first 50 on the references
   * surface). Uses Typesense `facet_query` — the same mechanism suggest() uses
   * for entities — scoped to the same locked_filters + active filters + year
   * range + current query as the live results, so the counts shown match what
   * selecting the value would yield.
   *
   * Returns the matching facet counts (value + count). Blank query → [].
   */
  async searchFacetValues(args: {
    field: string;
    /** Text typed in the facet's search box. */
    query: string;
    /** The surface's current main query, for contextual counts. */
    q: string;
    activeFilters?: ActiveFilters;
    yearRange?: YearRange | null;
    maxValues?: number;
  }): Promise<IwacFacetCount[]> {
    const text = args.query.trim();
    if (!text) return [];

    // applyExact:false — a facet lookup is a value-name search inside the
    // current scope, and narrowing the scope's own query to strict keyword
    // mode would change which values are offered. Kept as it has always
    // behaved; see the note in docs/engineering-roadmap.md.
    const { key, collection, q, filterBy, queryBy } = await this.resolveContext({
      ...args,
      applyExact: false,
    });

    const body = {
      searches: [
        {
          collection,
          q,
          query_by: queryBy,
          filter_by: filterBy || undefined,
          facet_by: args.field,
          // Typo-tolerant prefix/substring match of facet values vs the typed
          // text. Highlight is returned too, but we render the plain value.
          facet_query: `${args.field}:${text}`,
          max_facet_values: Math.max(1, Math.min(250, args.maxValues ?? 100)),
          // Counts only — a facet lookup needs no hits.
          per_page: 0,
        },
      ],
    };

    const json = await postJson<MultiSearchEnvelope>(
      this.bootstrap.endpoints.search,
      key.key,
      body,
      'Facet',
    );
    const first = json.results?.[0];
    if (!first) {
      throw new Error('Facet response missing results[0]');
    }
    // per_page:0 responses carry no hits[], so we read facet_counts directly
    // (like suggest()) rather than validateSearchResult, which requires hits[].
    const err = perSearchError(first);
    if (err) {
      throw new Error(`Facet HTTP ${err.code}: ${err.error}`);
    }
    const fc = (first as IwacSearchResponse).facet_counts?.find((f) => f.field_name === args.field);
    return fc?.counts ?? [];
  }

  /**
   * Run a typeahead/suggest query for a short prefix string.
   *
   * Tuned differently from the main search:
   *   - `prefix=true` so each query token does prefix matching (Typesense
   *     default, but explicit here in case future versions change).
   *   - `query_by` is narrower (title + entity_aliases) — OCR fulltext
   *     prefix-matching produces too much noise for a dropdown.
   *   - per_page is small (default 6) and we ignore facets — the whole
   *     point is one cheap call per keystroke.
   *   - The same scoped key + same locked_filters apply, so suggestions
   *     respect the surface's curatorial scope (a /browse/benin
   *     suggestion never leaks docs from another country).
   *
   * Returns up to `perPage` hits with `title_txt` highlighting, ready to
   * render in a dropdown. Empty / very short prefixes resolve to an empty
   * response without hitting the network — saves the cheapest fetch.
   *
   * Errors are translated to a thrown Error like search() — caller
   * decides whether to surface in the UI or swallow. A superseded call
   * rejects with an AbortError (see transport.isAbortError).
   */
  /**
   * Typeahead/suggest for a short prefix. The request shape lives in
   * suggestQuery.ts so the site-wide header bundle can run the identical
   * query without importing this class (see that module's header). This
   * wrapper adds only the per-instance abort channel: a keystroke-driven
   * call must not let a slow response for "ram" paint over "ramadan".
   */
  async suggest(prefix: string, perPage = 6): Promise<SuggestResult> {
    return runSuggest(this.bootstrap, prefix, perPage, this.suggestAbort.next());
  }

  /**
   * Fetch the documents of the CURRENT result set (same query, filters,
   * year range, sort and scope as the visible results) for a client-side
   * export — capped at {@link EXPORT_MAX_HITS}, paging at Typesense's
   * 250/request maximum. Only the citation metadata fields ship
   * (include_fields), no highlights, no facets.
   */
  async fetchForExport(args: {
    q: string;
    sortBy?: string;
    activeFilters?: ActiveFilters;
    yearRange?: YearRange | null;
  }): Promise<{ docs: IwacDoc[]; found: number }> {
    // applyExact:false preserves the behaviour this has always had — see the
    // note in docs/engineering-roadmap.md, which flags it as a real (separate)
    // question rather than something to change inside a refactor.
    const { key, collection, q, filterBy, queryBy, sortBy } = await this.resolveContext({
      ...args,
      applyExact: false,
    });

    const docs: IwacDoc[] = [];
    let found = 0;
    // Stopwords mirror the live search so the export matches what the user
    // sees; dropped after the first stopword-set-missing error (same
    // degradation path as search()).
    let useStopwords = true;
    const pages = Math.ceil(EXPORT_MAX_HITS / 250);

    for (let page = 1; page <= pages; page++) {
      const body = {
        searches: [
          {
            collection,
            q,
            query_by: queryBy,
            ...(useStopwords ? { stopwords: 'fr_default' } : {}),
            filter_by: filterBy || undefined,
            sort_by: sortBy,
            page,
            per_page: 250,
            include_fields: EXPORT_INCLUDE_FIELDS,
            highlight_fields: 'none',
          },
        ],
      };
      const json = await postJson<MultiSearchEnvelope>(
        this.bootstrap.endpoints.search,
        key.key,
        body,
        'Export',
      );
      const first = json.results?.[0];
      const err = perSearchError(first);
      if (err && useStopwords && isStopwordError(err.error)) {
        useStopwords = false;
        page--; // retry this page without stopwords
        continue;
      }
      const result = validateSearchResult('Export', first);
      found = result.found;
      docs.push(...result.hits.map((h) => h.document));
      if (docs.length >= found || docs.length >= EXPORT_MAX_HITS) {
        break;
      }
    }

    return { docs: docs.slice(0, EXPORT_MAX_HITS), found };
  }

  /**
   * Fetch every geo-tagged entity matching the current query/filters for
   * the map view — hits with a `geo` point only (`has_coords:=true`),
   * paged at Typesense's 250/request, capped at {@link MAP_MAX_HITS}.
   * Marker fields only; no highlights, no facets.
   */
  async fetchForMap(args: {
    q: string;
    activeFilters?: ActiveFilters;
    yearRange?: YearRange | null;
  }): Promise<IwacDoc[]> {
    // applyExact:false — same as the export path (see the roadmap note).
    const ctx = await this.resolveContext({ ...args, applyExact: false });
    const { key, collection, q, queryBy, isBrowse } = ctx;
    // Markers only exist for entities that parsed a geopoint.
    const filterBy = combineFilters('has_coords:=true', ctx.filterBy);

    const docs: IwacDoc[] = [];
    const pages = Math.ceil(MAP_MAX_HITS / 250);
    for (let page = 1; page <= pages; page++) {
      const body = {
        searches: [
          {
            collection,
            q,
            query_by: queryBy,
            filter_by: filterBy,
            // Most-mentioned first, so the cap keeps the important markers.
            sort_by: isBrowse ? 'frequency:desc' : '_text_match:desc',
            page,
            per_page: 250,
            include_fields: MAP_INCLUDE_FIELDS,
            highlight_fields: 'none',
          },
        ],
      };
      const json = await postJson<MultiSearchEnvelope>(
        this.bootstrap.endpoints.search,
        key.key,
        body,
        'Map',
      );
      const result = validateSearchResult('Map', json.results?.[0]);
      docs.push(...result.hits.map((h) => h.document));
      if (docs.length >= result.found || docs.length >= MAP_MAX_HITS) {
        break;
      }
    }
    return docs.slice(0, MAP_MAX_HITS);
  }

  /**
   * UNION search (Typesense v30): one merged, relevance-ranked result list
   * across several collections — powers the "All results" view on the
   * federated /search/everything page. Union responses have no per-hit
   * source marker, so callers dispatch on document shape instead
   * (entity_type_s present → entity card, else content card).
   *
   * Constraints honoured here (per the v30.2 docs):
   *   - pagination (`page` / `per_page`) goes in the URL query string —
   *     per-search pagination params are ignored in union mode;
   *   - every sub-search must sort by the same type/count/order of fields,
   *     so ALL sub-searches share one sort_by (relevance for a query,
   *     date:desc for browse — both collections carry `date`);
   *   - union responses carry no facet_counts, so this view offers no
   *     facet panel (the per-tab views do).
   *
   * Same one-shot stopword-recovery as search(). Aborts a superseded call.
   */
  async unionSearch(args: {
    q: string;
    page?: number;
    perPage?: number;
    searches: Array<{ collection: string; queryBy: string; filterBy?: string }>;
  }): Promise<IwacSearchResponse> {
    const key = await this.getKey();
    const isBrowse = !args.q.trim();
    const q = isBrowse ? '*' : args.q;
    const exact = !isBrowse && isExactQuery(q);
    const sortBy = isBrowse ? 'date:desc' : '_text_match:desc';

    const buildBody = (includeStopwords: boolean) => ({
      union: true,
      searches: args.searches.map((s) => ({
        collection: s.collection,
        q,
        query_by: exact ? withoutField(s.queryBy, 'embedding') : s.queryBy,
        ...(includeStopwords && !exact ? { stopwords: 'fr_default' } : {}),
        ...(exact ? EXACT_MODE_PARAMS : {}),
        filter_by: s.filterBy?.trim() || undefined,
        sort_by: sortBy,
        highlight_fields: 'title_txt',
        highlight_full_fields: 'title_txt',
        exclude_fields: 'ocr_text,embedding',
      })),
    });

    // Union pagination lives in the URL, not the body.
    const url = new URL(this.bootstrap.endpoints.search, window.location.origin);
    url.searchParams.set('page', String(args.page ?? 1));
    url.searchParams.set('per_page', String(args.perPage ?? this.bootstrap.results_per_page));

    // Union mode returns ONE merged result object, not {results: [...]},
    // so the payload IS the response — no `pick` needed.
    return this.withStopwordRetry({
      label: 'Everything',
      url: url.toString(),
      key: key.key,
      signal: this.unionAbort.next(),
      buildBody,
    });
  }

  /**
   * Run a counts-only multi_search across several collections for the same
   * query, returning the `found` total per collection in input order.
   *
   * Powers the federated /search/everything tab badges. One scoped key
   * covers every collection (the search-only parent key spans all of them)
   * and already bakes in `is_public:=true`. Stopwords are deliberately
   * omitted: a tab badge is an approximate count, and dropping the set keeps
   * this resilient when the server lacks `fr_default` (the per-tab App still
   * applies stopwords to the actual results). A collection that errors
   * resolves to `null` (blank badge) rather than throwing — one bad
   * collection must not blank the whole page.
   */
  async countAcross(
    q: string,
    collections: Array<{ collection: string; queryBy: string; filterBy?: string }>,
  ): Promise<Array<number | null>> {
    if (collections.length === 0) {
      return [];
    }
    const key = await this.getKey();
    const qParam = q.trim() ? q : '*';
    const searches = collections.map((c) => ({
      collection: c.collection,
      q: qParam,
      query_by: c.queryBy,
      filter_by: c.filterBy?.trim() || undefined,
      // Only `found` is read — no hits, facets, or highlights.
      per_page: 0,
    }));

    const json = await postJson<{ results: Array<{ found?: number; error?: string }> }>(
      this.bootstrap.endpoints.search,
      key.key,
      { searches },
      'Counts',
    );
    return collections.map((_, i) => {
      const r = json.results?.[i];
      if (!r || r.error || typeof r.found !== 'number') {
        return null;
      }
      return r.found;
    });
  }

  private warnStopwords(): void {
    console.warn(
      '[iwac-search] Typesense stopword set missing; retrying without stopwords. Run discovery:reindex (or cli/stopwords-sync.php) to provision.',
    );
  }

  /**
   * Resolve the shared request context. See {@link SearchContext}.
   *
   * @param includeYearRange  false only for the year histogram, which must
   *   show the full span regardless of the selected window.
   * @param applyExact  whether a quoted / -excluded query switches to strict
   *   keyword matching (drop `embedding`, no typo tolerance, no stopwords).
   */
  private async resolveContext(args: {
    q: string;
    activeFilters?: ActiveFilters;
    yearRange?: YearRange | null;
    sortBy?: string;
    includeYearRange?: boolean;
    applyExact?: boolean;
  }): Promise<SearchContext> {
    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;

    const filterBy = combineFilters(
      this.bootstrap.locked_filters,
      buildFilterBy(args.activeFilters ?? {}),
      (args.includeYearRange ?? true) ? buildYearRangeFilter(args.yearRange ?? null) : '',
    );

    // Browse mode: empty query becomes Typesense's wildcard `*`. Text-match
    // scoring is meaningless without a query, so resolveSortBy falls back to
    // date:desc unless the surface configured its own default.
    const isBrowse = !args.q.trim();
    const q = isBrowse ? '*' : args.q;

    // Per-surface field sets. The entity collection lacks ocr_text / abstract
    // / embedding, so the index surfaces pass their own query_by and
    // highlight_fields; content surfaces fall back to the full set.
    const baseQueryBy = this.bootstrap.query_by ?? CONTENT_QUERY_BY_FALLBACK;

    // Exact mode — the user typed a "quoted phrase" or a -excluded term, so
    // they mean it literally. Drop `embedding` so no semantically-similar
    // (but non-matching) document is blended in by hybrid rank-fusion; the
    // caller adds EXACT_MODE_PARAMS and skips stopwords. Browse mode is
    // never exact. See isExactQuery().
    const exact = (args.applyExact ?? true) && !isBrowse && isExactQuery(q);

    return {
      key,
      collection,
      filterBy,
      isBrowse,
      q,
      queryBy: exact ? withoutField(baseQueryBy, 'embedding') : baseQueryBy,
      highlightFields: this.bootstrap.highlight_fields ?? CONTENT_HIGHLIGHT_FALLBACK,
      sortBy: resolveSortBy(args.sortBy, isBrowse, this.bootstrap.default_sort),
      exact,
    };
  }

  /**
   * POST a search body, retrying ONCE without the `stopwords` field when the
   * server reports the set missing.
   *
   * Typesense surfaces that failure two ways — an HTTP 404 at the wrapper, or
   * HTTP 200 with `{code: 404, error}` in the payload — so both paths funnel
   * through the same retry. Stopwords are an enhancement (filtering "le",
   * "la", "des" out of matches), never a correctness requirement, so degrading
   * beats failing the search. The operator should run `discovery:reindex` (or
   * cli/stopwords-sync.php) to restore the set.
   *
   * `pick` pulls the per-search payload out of whatever shape the endpoint
   * returned: plain multi_search nests it under `results[0]`, union mode
   * returns ONE merged object.
   */
  private async withStopwordRetry(opts: {
    label: string;
    url: string;
    /** The scoped key value (context.key.key). */
    key: string;
    signal?: AbortSignal;
    buildBody: (includeStopwords: boolean) => object;
    pick?: (raw: unknown) => IwacSearchResponse | TypesensePerSearchError | undefined;
  }): Promise<IwacSearchResponse> {
    const pick =
      opts.pick ??
      ((raw: unknown) => raw as IwacSearchResponse | TypesensePerSearchError | undefined);
    let lastMessage = `${opts.label} failed`;

    // Two attempts max: the retry drops `stopwords`, so a stopword error
    // cannot recur on the second pass.
    for (let attempt = 0; attempt < 2; attempt++) {
      const includeStopwords = attempt === 0;
      let raw: unknown;
      try {
        raw = await postJson<unknown>(
          opts.url,
          opts.key,
          opts.buildBody(includeStopwords),
          opts.label,
          opts.signal,
        );
      } catch (e) {
        const message = e instanceof Error ? e.message : String(e);
        if (includeStopwords && isStopwordError(message)) {
          this.warnStopwords();
          lastMessage = message;
          continue;
        }
        throw e;
      }

      const payload = pick(raw);
      const err = perSearchError(payload);
      if (err && includeStopwords && isStopwordError(err.error)) {
        this.warnStopwords();
        lastMessage = `${opts.label} HTTP ${err.code}: ${err.error}`;
        continue;
      }
      return validateSearchResult(opts.label, payload);
    }
    throw new Error(lastMessage);
  }

  /**
   * Get a valid scoped key. The cache lives in scopedKey.ts and is
   * MODULE-scoped (keyed by token endpoint), not per-instance: several
   * client instances on one page (multiple blocks, the federated page's
   * per-tab App remounts, the header box) share one key and one refresh
   * cycle, and in-flight requests are coalesced.
   */
  private getKey(): Promise<ScopedKeyResponse> {
    return getScopedKey(this.bootstrap.endpoints.token);
  }
}

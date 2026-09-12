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
  validateSearchResult,
} from './transport';
import { queryPolicy } from './queryPolicy';
import { authenticatedSearch } from './authenticatedSearch';
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
  // Audiovisual provenance: the producing channel stands in for the
  // newspaper on those records, and the platform + running time are what
  // makes an exported video row intelligible.
  'channel_ss',
  'media_kind_s',
  'media_platform_s',
  'duration_seconds',
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
 * Does this message mean the server has no `fr_default` stopword set?
 * Typesense phrases it the same way at the HTTP layer and inside a
 * per-search error, which is why one predicate covers both.
 */
function readCount(
  result: IwacSearchResponse | TypesensePerSearchError | undefined,
): number | undefined {
  return result && 'found' in result && typeof result.found === 'number' ? result.found : undefined;
}

function isStopwordError(message: string): boolean {
  return /stopword set/i.test(message);
}

/** What search() returns: the page of results, plus the histogram if asked. */
export interface SearchOutcome {
  response: IwacSearchResponse;
  /** Present only when `withYearDistribution` was requested. */
  years?: YearBucket[];
  yearsUnavailable?: boolean;
}

/**
 * Pull the `pub_year` facet out of a counts-only sub-search into ascending
 * year buckets. Empty (not an error) when the sub-search is absent or the
 * facet came back empty — the slider still works without bars.
 */
function readYearBuckets(result: unknown): YearBucket[] {
  const error = perSearchError(result as IwacSearchResponse | TypesensePerSearchError | undefined);
  if (!result || error) throw new Error(error?.error ?? 'Histogram response missing');
  const counts = (result as IwacSearchResponse | undefined)?.facet_counts?.find(
    (f) => f.field_name === 'pub_year',
  );
  return (counts?.counts ?? [])
    .map((c) => ({ year: Number(c.value), count: c.count }))
    .filter((b) => Number.isFinite(b.year) && b.count > 0)
    .sort((a, b) => a.year - b.year);
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
   * Per-channel abort slots: a new search/suggest/union call aborts its
   * still-in-flight predecessor, so a fast typist can't get
   * out-of-order responses (or pay for their bandwidth). Callers swallow
   * the resulting AbortError via transport.isAbortError().
   */
  private readonly searchAbort = new AbortSlot();
  private readonly suggestAbort = new AbortSlot();
  private readonly unionAbort = new AbortSlot();

  constructor(private readonly bootstrap: IwacBootstrap) {}

  /**
   * Run a search.
   *
   * Empty query issues a browse request (Typesense `q=*` wildcard), so
   * curated browse surfaces, page blocks with locked_filters, and the
   * standalone /search route all show results + facet counts immediately
   * on mount. The public scoped key still carries `filter_by:is_public:=true`,
   * so this never leaks private docs. `exclude_fields: ocr_text,toc_txt` is
   * also baked in at mint time, so browse responses stay lean.
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
    /**
     * Also compute the year histogram, as a SECOND sub-search in the same
     * multi_search body — one POST instead of two.
     *
     * The caller opts in only when the histogram is actually stale, because
     * the bars depend on the query + categorical filters ONLY (never page,
     * sort, or the year range itself — the chart must show the full span so
     * dragging the slider just repaints which bars are highlighted). Passing
     * it on a mere page change would make Typesense recompute a facet nobody
     * is going to look at.
     */
    withYearDistribution?: boolean;
  }): Promise<SearchOutcome> {
    const ctx = await this.resolveContext(args);
    const { collection, q, filterBy, isBrowse, exact } = ctx;

    let lastEnvelope: MultiSearchEnvelope | undefined;

    // The histogram sub-search needs the same filter MINUS the year range.
    const filterByWithoutYears = args.withYearDistribution
      ? (await this.resolveContext({ ...args, includeYearRange: false })).filterBy
      : '';

    const facets = args.facetBy ?? this.bootstrap.prominent_facets;

    // The body is built as a function so the stopword-recovery retry can
    // re-issue the request without the `stopwords` field.
    const buildBody = (includeStopwords: boolean) => ({
      searches: [
        {
          collection,
          // query_by is surface-specific (see queryBy above): content uses
          // title + ocr + abstract + aliases + embedding; the entity
          // collection uses only title + aliases. Typesense ignores
          // query_by when q=* so browse mode drops straight through. Exact
          // queries drop `embedding` (queryByEffective) for literal matching.
          ...queryPolicy(q, ctx.queryBy, includeStopwords),
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
        // The histogram, when asked for: same query + categorical filters,
        // but WITHOUT the year range (see withYearDistribution above).
        ...(args.withYearDistribution
          ? [
              {
                collection,
                ...queryPolicy(q, ctx.queryBy, includeStopwords),
                ...(exact ? EXACT_MODE_PARAMS : {}),
                filter_by: filterByWithoutYears || undefined,
                enable_analytics: false,
                facet_by: 'pub_year',
                // pub_year spans the whole corpus; 200 buckets is comfortably
                // above the distinct-year count, so no year is dropped.
                max_facet_values: 200,
                per_page: 0,
              },
            ]
          : []),
        ...(!isBrowse
          ? [
              {
                collection,
                ...queryPolicy(q, ctx.queryBy, includeStopwords, true),
                filter_by: filterBy || undefined,
                per_page: 0,
                enable_analytics: false,
              },
            ]
          : []),
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
      // Keep the raw envelope so the histogram sub-search can be read off it.
      onRaw: (raw) => {
        lastEnvelope = raw as MultiSearchEnvelope;
      },
    }).then((response) => {
      if (!isBrowse)
        response.keyword_found = readCount(
          lastEnvelope?.results?.[args.withYearDistribution ? 2 : 1],
        );
      if (args.withYearDistribution) {
        try {
          return { response, years: readYearBuckets(lastEnvelope?.results?.[1]) };
        } catch {
          return { response, yearsUnavailable: true };
        }
      }
      return { response };
    });
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
  async yearDistribution(q: string): Promise<YearBucket[]> {
    const ctx = await this.resolveContext({ q, includeYearRange: false });
    const json = await this.auxiliary(
      ctx.key.key,
      (stopwords) => ({
        searches: [
          {
            collection: ctx.collection,
            ...queryPolicy(q, ctx.queryBy, stopwords),
            filter_by: ctx.filterBy || undefined,
            facet_by: 'pub_year',
            max_facet_values: 200,
            per_page: 0,
            enable_analytics: false,
          },
        ],
      }),
      'Histogram',
    );
    return readYearBuckets(json.results?.[0]);
  }

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

    const { key, collection, q, filterBy, queryBy } = await this.resolveContext(args);

    const buildBody = (stopwords: boolean) => ({
      searches: [
        {
          collection,
          ...queryPolicy(q, queryBy, stopwords),
          enable_analytics: false,
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
    });

    const json = await this.auxiliary(key.key, buildBody, 'Facet');
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
    const { key, collection, q, filterBy, queryBy, sortBy, exact } =
      await this.resolveContext(args);

    const docs: IwacDoc[] = [];
    let found = 0;
    // Stopwords mirror the live search so the export matches what the user
    // sees; dropped after the first stopword-set-missing error (same
    // degradation path as search()).
    let useStopwords = !exact;
    const pages = Math.ceil(EXPORT_MAX_HITS / 250);

    for (let page = 1; page <= pages; page++) {
      const body = {
        searches: [
          {
            collection,
            ...queryPolicy(q, queryBy, useStopwords),
            enable_analytics: false,
            ...(useStopwords ? { stopwords: 'fr_default' } : {}),
            ...(exact ? EXACT_MODE_PARAMS : {}),
            filter_by: filterBy || undefined,
            sort_by: sortBy,
            page,
            per_page: 250,
            include_fields: EXPORT_INCLUDE_FIELDS,
            highlight_fields: 'none',
          },
        ],
      };
      let json: MultiSearchEnvelope;
      try {
        json = await this.post<MultiSearchEnvelope>(
          this.bootstrap.endpoints.search,
          key.key,
          body,
          'Export',
        );
      } catch (e) {
        const message = e instanceof Error ? e.message : String(e);
        if (useStopwords && isStopwordError(message)) {
          this.warnStopwords();
          useStopwords = false;
          page--; // retry this page without stopwords
          continue;
        }
        throw e;
      }
      const first = json.results?.[0];
      const err = perSearchError(first);
      if (err && useStopwords && isStopwordError(err.error)) {
        this.warnStopwords();
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
    signal?: AbortSignal;
  }): Promise<IwacDoc[]> {
    const ctx = await this.resolveContext(args);
    const { key, collection, q, queryBy, isBrowse } = ctx;
    // Markers only exist for entities that parsed a geopoint.
    const filterBy = combineFilters('has_coords:=true', ctx.filterBy);

    const docs: IwacDoc[] = [];
    const pages = Math.ceil(MAP_MAX_HITS / 250);
    for (let page = 1; page <= pages; page++) {
      args.signal?.throwIfAborted();
      const buildBody = (stopwords: boolean) => ({
        searches: [
          {
            collection,
            ...queryPolicy(q, queryBy, stopwords),
            enable_analytics: false,
            filter_by: filterBy,
            // Most-mentioned first, so the cap keeps the important markers.
            sort_by: isBrowse ? 'frequency:desc' : '_text_match:desc',
            page,
            per_page: 250,
            include_fields: MAP_INCLUDE_FIELDS,
            highlight_fields: 'none',
          },
        ],
      });
      const json = await this.auxiliary(key.key, buildBody, 'Map', args.signal);
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
        exclude_fields: 'ocr_text,toc_txt,embedding',
      })),
    });

    // Union pagination lives in the URL, not the body.
    const url = new URL(this.bootstrap.endpoints.search, window.location.origin);
    url.searchParams.set('page', String(args.page ?? 1));
    url.searchParams.set('per_page', String(args.perPage ?? this.bootstrap.results_per_page));

    // Union mode returns ONE merged result object, not {results: [...]},
    // so the payload IS the response — no `pick` needed.
    const signal = this.unionAbort.next();
    const response = await this.withStopwordRetry({
      label: 'Everything',
      url: url.toString(),
      key: key.key,
      signal,
      buildBody,
    });
    if (!isBrowse) {
      const counts = await this.auxiliary(
        key.key,
        (stopwords) => ({
          searches: args.searches.map((s) => ({
            collection: s.collection,
            ...queryPolicy(q, s.queryBy, stopwords, true),
            filter_by: s.filterBy || undefined,
            per_page: 0,
            enable_analytics: false,
          })),
        }),
        'Keyword counts',
        signal,
      );
      const values = counts.results?.map(readCount);
      if (
        values?.length === args.searches.length &&
        values.every((n): n is number => n !== undefined)
      )
        response.keyword_found = values.reduce((a, b) => a + b, 0);
    }
    return response;
  }

  /** Hybrid totals paired with explicit keyword counts. Failed counts become blank badges. */
  async countAcross(
    q: string,
    collections: Array<{ collection: string; queryBy: string; filterBy?: string }>,
  ): Promise<Array<number | null>> {
    if (collections.length === 0) {
      return [];
    }
    const key = await this.getKey();
    const json = await this.auxiliary(
      key.key,
      (stopwords) => ({
        searches: collections.flatMap((c) => [
          {
            collection: c.collection,
            ...queryPolicy(q, c.queryBy, stopwords),
            filter_by: c.filterBy || undefined,
            per_page: 0,
            enable_analytics: false,
          },
          {
            collection: c.collection,
            ...queryPolicy(q, c.queryBy, stopwords, true),
            filter_by: c.filterBy || undefined,
            per_page: 0,
            enable_analytics: false,
          },
        ]),
      }),
      'Counts',
    );
    return collections.map((_, i) => {
      const total = readCount(json.results?.[i * 2]);
      const keyword = readCount(json.results?.[i * 2 + 1]);
      return total === undefined || keyword === undefined ? null : keyword === 0 ? 0 : total;
    });
  }

  /** Counts/facets share stopword degradation and validate each envelope. */
  private async auxiliary(
    key: string,
    build: (stopwords: boolean) => object,
    label: string,
    signal?: AbortSignal,
  ): Promise<MultiSearchEnvelope> {
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const json = await this.post<MultiSearchEnvelope>(
          this.bootstrap.endpoints.search,
          key,
          build(attempt === 0),
          label,
          signal,
        );
        if (
          attempt === 0 &&
          json.results?.some((r) => isStopwordError(perSearchError(r)?.error ?? ''))
        ) {
          this.warnStopwords();
          continue;
        }
        return json;
      } catch (error) {
        if (attempt === 0 && error instanceof Error && isStopwordError(error.message)) {
          this.warnStopwords();
          continue;
        }
        throw error;
      }
    }
    throw new Error(label + ' failed');
  }

  private post<T>(
    url: string,
    key: string,
    body: unknown,
    label: string,
    signal?: AbortSignal,
  ): Promise<T> {
    return authenticatedSearch<T>(this.bootstrap.endpoints.token, url, key, body, label, signal);
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
   */
  private async resolveContext(args: {
    q: string;
    activeFilters?: ActiveFilters;
    yearRange?: YearRange | null;
    sortBy?: string;
    includeYearRange?: boolean;
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
    const exact = !isBrowse && isExactQuery(q);

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
    /** Called with the full envelope of the attempt that succeeded. */
    onRaw?: (raw: unknown) => void;
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
        raw = await this.post<unknown>(
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

      opts.onRaw?.(raw);
      const auxiliaryError = (raw as MultiSearchEnvelope).results
        ?.map(perSearchError)
        .find((e) => e && isStopwordError(e.error));
      if (includeStopwords && auxiliaryError) {
        this.warnStopwords();
        continue;
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

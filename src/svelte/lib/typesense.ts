import type {
  ActiveFilters,
  EntitySuggestion,
  IwacBootstrap,
  IwacDoc,
  IwacFacetCount,
  IwacSearchResponse,
  ScopedKeyResponse,
  SuggestResult,
  YearRange,
} from './types';
import { BOOLEAN_FACET_FIELDS, NUMERIC_FACET_FIELDS } from './i18n';

/**
 * Facet fields the typeahead may prefix-match via `facet_query`. The list a
 * surface actually queries is its prominent_facets ∩ this set, so the
 * suggestions follow the scope: a country block suggests places / topics /
 * persons / organisations, while the references scope suggests authors,
 * journals/publishers and merged subjects — without any extra config.
 */
const SUGGESTABLE_FACET_FIELDS: ReadonlySet<string> = new Set([
  'places_ss',
  'topics_ss',
  'persons_ss',
  'organisations_ss',
  'events_ss',
  'subjects_ss',
  'creator_ss',
  'publisher_s',
  'book_title_s',
  'newspaper_ss',
]);

/** Fallback for surfaces without prominent facets (e.g. the header box). */
const DEFAULT_SUGGEST_FACET_FIELDS = [
  'places_ss',
  'topics_ss',
  'persons_ss',
  'organisations_ss',
] as const;

/** Cap on facet_query sub-searches per keystroke. */
const MAX_SUGGEST_FACET_FIELDS = 5;

/**
 * Entity-index `entity_type_s` → the content facet field a suggestion
 * picked from the index collection should toggle. "Notices d'autorité"
 * are deliberately absent (they never feed content facets).
 */
const ENTITY_TYPE_FACET: Record<string, string> = {
  Personnes: 'persons_ss',
  Lieux: 'places_ss',
  Organisations: 'organisations_ss',
  Événements: 'events_ss',
  Sujets: 'topics_ss',
};

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

/** Default content query_by — mirrors SearchDefaults::CONTENT_QUERY_BY. */
const CONTENT_QUERY_BY_FALLBACK =
  'title_txt,alt_title_txt,ocr_text,abstract,' +
  'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt,embedding';
const CONTENT_HIGHLIGHT_FALLBACK =
  'title_txt,alt_title_txt,ocr_text,abstract,' +
  'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt';

/**
 * Thin wrapper over the Typesense REST API for the public client.
 *
 * Why not the official typesense-js package: it bundles ~50 KB of
 * cluster-management code (admin keys, collection CRUD, alias swap)
 * the browser will never use. We make exactly two HTTP calls — fetch
 * scoped key, run multi_search — so a 30-line wrapper is the right size.
 */
export class TypesenseClient {
  /**
   * Cached scoped key. Held in memory only — never persisted to
   * localStorage / sessionStorage — so it dies with the tab. Refreshed
   * 60 s before its `expires_at` to avoid mid-search expiry.
   */
  private cachedKey: ScopedKeyResponse | null = null;
  private inflight: Promise<ScopedKeyResponse> | null = null;

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
    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;

    const filterBy = combineFilters(
      this.bootstrap.locked_filters,
      buildFilterBy(args.activeFilters ?? {}),
      buildYearRangeFilter(args.yearRange ?? null),
    );

    const facets = args.facetBy ?? this.bootstrap.prominent_facets;

    // Browse mode: empty query becomes Typesense's wildcard `*`. Text-match
    // scoring is meaningless without a query, so fall back to date:desc
    // (newest first) unless the caller explicitly set a sort.
    const isBrowse = !args.q.trim();
    const q = isBrowse ? '*' : args.q;
    // Per-surface field sets. The entity collection lacks ocr_text/abstract/
    // embedding, so the index browse page passes its own query_by /
    // highlight_fields; content surfaces fall back to the full set.
    const queryBy = this.bootstrap.query_by ?? CONTENT_QUERY_BY_FALLBACK;
    const highlightFields = this.bootstrap.highlight_fields ?? CONTENT_HIGHLIGHT_FALLBACK;
    const sortBy =
      args.sortBy && args.sortBy !== '_text_match:desc'
        ? args.sortBy
        : isBrowse
          ? 'date:desc'
          : (this.bootstrap.default_sort ?? '_text_match:desc');
    // creator_sort is an optional field (only docs with an author have it).
    // Typesense needs an explicit missing-values rule for optional sort
    // fields, or it errors; push author-less docs (e.g. unsigned press) to
    // the end. Done here, not in the sort-option value, so the URL/dropdown
    // stay clean (`creator_sort:asc`).
    const sortByParam = sortBy.startsWith('creator_sort:')
      ? sortBy.replace('creator_sort:', 'creator_sort(missing_values:last):')
      : sortBy;

    // The body is built as a function so we can re-issue the request
    // without the `stopwords` field if Typesense 404s with "stopword set
    // missing" (recovery path further down).
    const buildBody = (includeStopwords: boolean) => ({
      searches: [
        {
          collection,
          q,
          // query_by is surface-specific (see queryBy above): content uses
          // title + ocr + abstract + aliases + embedding; the entity
          // collection uses only title + aliases. Typesense ignores
          // query_by when q=* so browse mode drops straight through.
          query_by: queryBy,
          // Stopwords keep "le", "la", "des" etc. from polluting matches.
          // Conditionally included so the recovery retry can drop it.
          ...(includeStopwords ? { stopwords: 'fr_default' } : {}),
          filter_by: filterBy || undefined,
          sort_by: sortByParam,
          page: args.page ?? 1,
          per_page: args.perPage ?? this.bootstrap.results_per_page,
          highlight_fields: highlightFields,
          highlight_full_fields: 'title_txt',
          snippet_threshold: 30,
          highlight_affix_num_tokens: 8,
          // No limit_hits: Typesense's default is no cap, so users can page
          // through every match (not just the first 250). per_page stays ≤ 50,
          // and Pagination windows the page bar, so deep result sets are fine.
          facet_by: facets.length > 0 ? facets.join(',') : undefined,
          // Show up to 50 values per facet — enough for "show more" inside
          // a facet group without paging the facet API.
          max_facet_values: 50,
          // Result diversification (Typesense 30.2 MMR). Only on a real
          // query: browse mode (q=*) is date-sorted and must not be
          // reshuffled, and the clustering of near-identical syndicated
          // articles this fixes only happens under text-match ranking.
          // `curation_tags` activates the iwac_diversity curation set
          // linked on the collection (see CurationSync.php); the server
          // ignores it on collections without that link, so it's safe to
          // send during the v1→v2 cutover. diversity_lambda tunes the
          // relevance↔diversity balance (1 = relevance, 0 = max variety).
          ...(!isBrowse && this.bootstrap.diversify_tag
            ? {
                curation_tags: this.bootstrap.diversify_tag,
                diversity_lambda: this.bootstrap.diversity_lambda ?? 0.7,
              }
            : {}),
        },
      ],
    });

    const post = (body: ReturnType<typeof buildBody>) =>
      fetch(this.bootstrap.endpoints.search, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-TYPESENSE-API-KEY': key.key,
        },
        body: JSON.stringify(body),
      });

    // Recovery path for a missing stopword set on the Typesense server.
    // Typesense surfaces "stopword set not found" in TWO different shapes:
    //   1. HTTP 404 at the multi_search wrapper (top-level)
    //   2. HTTP 200 with `{code: 404, error: "..."}` inside results[0]
    //      — the per-search error envelope (more common in practice; this
    //      is what Typesense emits for newer versions when the missing
    //      stopwords reference is on a per-search basis)
    // Either way, drop the `stopwords` field and retry once. Stopwords
    // are an enhancement, not a correctness requirement. Operator should
    // run `discovery:reindex` (or the cli/stopwords-sync.php helper) to
    // restore the set so French stopword filtering works again.
    const tryOnce = async (
      includeStopwords: boolean,
    ): Promise<
      | { ok: true; payload: { results: Array<IwacSearchResponse | TypesensePerSearchError> } }
      | { ok: false; message: string; isStopwordError: boolean }
    > => {
      const res = await post(buildBody(includeStopwords));
      if (!res.ok) {
        const message = await formatHttpError('Search', res);
        return {
          ok: false,
          message,
          isStopwordError: res.status === 404 && /stopword set/i.test(message),
        };
      }
      const payload = (await res.json()) as {
        results: Array<IwacSearchResponse | TypesensePerSearchError>;
      };
      const first = payload.results?.[0];
      if (first && 'error' in first && typeof first.error === 'string') {
        const code = 'code' in first ? first.code : '';
        return {
          ok: false,
          message: `Search HTTP ${code}: ${first.error}`,
          isStopwordError: /stopword set/i.test(first.error),
        };
      }
      return { ok: true, payload };
    };

    let result = await tryOnce(true);
    if (!result.ok && result.isStopwordError) {
      console.warn(
        '[iwac-search] Typesense stopword set missing; retrying without stopwords. Run discovery:reindex (or cli/stopwords-sync.php) to provision.',
      );
      result = await tryOnce(false);
    }
    if (!result.ok) {
      throw new Error(result.message);
    }
    return validateSearchResult('Search', result.payload.results?.[0]);
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

    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;
    const filterBy = combineFilters(
      this.bootstrap.locked_filters,
      buildFilterBy(args.activeFilters ?? {}),
      buildYearRangeFilter(args.yearRange ?? null),
    );
    const isBrowse = !args.q.trim();
    const q = isBrowse ? '*' : args.q;
    const queryBy = this.bootstrap.query_by ?? CONTENT_QUERY_BY_FALLBACK;

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

    const res = await fetch(this.bootstrap.endpoints.search, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-TYPESENSE-API-KEY': key.key,
      },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error(await formatHttpError('Facet', res));
    }
    const json = (await res.json()) as {
      results: Array<IwacSearchResponse | TypesensePerSearchError>;
    };
    const first = json.results?.[0];
    if (!first) {
      throw new Error('Facet response missing results[0]');
    }
    // per_page:0 responses carry no hits[], so we read facet_counts directly
    // (like suggest()) rather than validateSearchResult, which requires hits[].
    if ('error' in first && typeof first.error === 'string') {
      const code = 'code' in first ? first.code : '';
      throw new Error(`Facet HTTP ${code}: ${first.error}`);
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
   * decides whether to surface in the UI or swallow.
   */
  async suggest(prefix: string, perPage = 6): Promise<SuggestResult> {
    const trimmed = prefix.trim();
    if (trimmed.length < 2) {
      return { articles: [], entities: [] };
    }

    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;
    const filterBy = this.bootstrap.locked_filters?.trim() || undefined;
    const isEntitySurface = this.bootstrap.card === 'entity';

    // Facet fields this surface suggests from: its prominent facets, kept to
    // the suggestable string facets. A country block gets places / topics /
    // persons / organisations; the references scope gets authors, journals/
    // publishers and merged subjects. Surfaces without prominent facets
    // (header box) fall back to the entity quartet. Entity-index surfaces
    // skip facet suggestions entirely — their facets (entity_type_s,
    // is_part_of_ss) make poor typeahead rows.
    const prominent = (this.bootstrap.prominent_facets ?? []).filter((f) =>
      SUGGESTABLE_FACET_FIELDS.has(f),
    );
    const facetFields = isEntitySurface
      ? []
      : (prominent.length > 0 ? prominent : [...DEFAULT_SUGGEST_FACET_FIELDS]).slice(
          0,
          MAX_SUGGEST_FACET_FIELDS,
        );

    // One multi_search request bundles:
    //   [0]    article title hits (title + alternative titles + aliases)
    //   [1…n]  one facet_query per suggestable facet field
    //   [last] the entity INDEX collection, queried by name + alias — this is
    //          what reconciles alternative spellings: typing "RCI" surfaces
    //          "Radio Côte d'Ivoire" even though no facet VALUE contains
    //          "RCI". Mapped back to a content facet via entity_type_s.
    // locked_filters apply to the content sub-searches so suggestions on a
    // country block stay scoped; the index sub-search runs unfiltered (its
    // schema lacks the content fields a locked filter may reference).
    const titleSearch = {
      collection,
      q: trimmed,
      // Narrower than the main search query_by — dropdown should surface
      // clear title hits, not fuzzy OCR matches that wouldn't make sense
      // out of context. alt_title_txt reconciles variant titles.
      query_by: isEntitySurface
        ? 'title_txt,entity_aliases_txt'
        : 'title_txt,alt_title_txt,entity_aliases_txt',
      prefix: true,
      filter_by: filterBy,
      // Newest first feels right for a typeahead (recent docs surface
      // before older ones even when match scores are similar).
      sort_by: '_text_match:desc,date:desc',
      page: 1,
      per_page: Math.max(1, Math.min(15, perPage)),
      highlight_fields: 'title_txt',
      highlight_full_fields: 'title_txt',
      // No snippet — keeps the response tiny and the dropdown render fast.
      exclude_fields: 'ocr_text,embedding',
      limit_hits: 50,
    };

    const entitySearches = facetFields.map((field) => ({
      collection,
      q: '*',
      query_by: 'title_txt',
      filter_by: filterBy,
      facet_by: field,
      // Prefix/substring-match facet values against the typed text.
      facet_query: `${field}:${trimmed}`,
      max_facet_values: 4,
      per_page: 0,
    }));

    const indexAlias = !isEntitySurface ? this.bootstrap.index_collection_alias : undefined;
    const indexSearch = indexAlias
      ? [
          {
            collection: indexAlias,
            q: trimmed,
            query_by: 'title_txt,entity_aliases_txt',
            prefix: true,
            sort_by: '_text_match:desc,frequency:desc',
            page: 1,
            per_page: 4,
            include_fields: 'title,entity_type_s,frequency',
            highlight_fields: 'title_txt',
          },
        ]
      : [];

    const res = await fetch(this.bootstrap.endpoints.search, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-TYPESENSE-API-KEY': key.key,
      },
      body: JSON.stringify({ searches: [titleSearch, ...entitySearches, ...indexSearch] }),
    });
    if (!res.ok) {
      throw new Error(await formatHttpError('Suggest', res));
    }
    const json = (await res.json()) as {
      results: Array<IwacSearchResponse | TypesensePerSearchError>;
    };

    const articles = validateSearchResult('Suggest', json.results?.[0]).hits;

    // Collect matching facet values across the facet sub-searches.
    const entities: EntitySuggestion[] = [];
    facetFields.forEach((field, i) => {
      const r = json.results?.[i + 1];
      if (!r || 'error' in r) return;
      const fc = (r as IwacSearchResponse).facet_counts?.find((f) => f.field_name === field);
      for (const c of fc?.counts ?? []) {
        if (c.value) entities.push({ field, value: c.value, count: c.count });
      }
    });
    // Highest-coverage entities first.
    entities.sort((a, b) => b.count - a.count);

    // Index-collection hits — alias-reconciled entities the facet_query
    // pass can't see. Appended after the scope-accurate facet matches,
    // deduped on (field, value).
    if (indexAlias) {
      const r = json.results?.[1 + facetFields.length];
      if (r && !('error' in r)) {
        const seen = new Set(entities.map((e) => `${e.field}|${e.value}`));
        for (const hit of (r as IwacSearchResponse).hits ?? []) {
          const doc = hit.document;
          const field = doc.entity_type_s ? ENTITY_TYPE_FACET[doc.entity_type_s] : undefined;
          const value = (doc.title ?? '').trim();
          if (!field || !value || seen.has(`${field}|${value}`)) continue;
          seen.add(`${field}|${value}`);
          entities.push({ field, value, count: doc.frequency ?? 0 });
        }
      }
    }

    return { articles, entities: entities.slice(0, 6) };
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
    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;
    const filterBy = combineFilters(
      this.bootstrap.locked_filters,
      buildFilterBy(args.activeFilters ?? {}),
      buildYearRangeFilter(args.yearRange ?? null),
    );
    const isBrowse = !args.q.trim();
    const q = isBrowse ? '*' : args.q;
    const queryBy = this.bootstrap.query_by ?? CONTENT_QUERY_BY_FALLBACK;
    const sortBy =
      args.sortBy && args.sortBy !== '_text_match:desc'
        ? args.sortBy
        : isBrowse
          ? 'date:desc'
          : (this.bootstrap.default_sort ?? '_text_match:desc');
    const sortByParam = sortBy.startsWith('creator_sort:')
      ? sortBy.replace('creator_sort:', 'creator_sort(missing_values:last):')
      : sortBy;

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
            sort_by: sortByParam,
            page,
            per_page: 250,
            include_fields: EXPORT_INCLUDE_FIELDS,
            highlight_fields: 'none',
          },
        ],
      };
      const res = await fetch(this.bootstrap.endpoints.search, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-TYPESENSE-API-KEY': key.key,
        },
        body: JSON.stringify(body),
      });
      if (!res.ok) {
        throw new Error(await formatHttpError('Export', res));
      }
      const json = (await res.json()) as {
        results: Array<IwacSearchResponse | TypesensePerSearchError>;
      };
      const first = json.results?.[0];
      if (first && 'error' in first && typeof first.error === 'string') {
        if (useStopwords && /stopword set/i.test(first.error)) {
          useStopwords = false;
          page--; // retry this page without stopwords
          continue;
        }
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

    const res = await fetch(this.bootstrap.endpoints.search, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-TYPESENSE-API-KEY': key.key,
      },
      body: JSON.stringify({ searches }),
    });
    if (!res.ok) {
      throw new Error(await formatHttpError('Counts', res));
    }
    const json = (await res.json()) as {
      results: Array<{ found?: number; error?: string }>;
    };
    return collections.map((_, i) => {
      const r = json.results?.[i];
      if (!r || r.error || typeof r.found !== 'number') {
        return null;
      }
      return r.found;
    });
  }

  /**
   * Get a valid scoped key, refreshing in-flight requests get coalesced
   * so a burst of debounced searches doesn't N-amplify token requests.
   */
  private async getKey(): Promise<ScopedKeyResponse> {
    const now = Math.floor(Date.now() / 1000);
    if (this.cachedKey && this.cachedKey.expires_at - 60 > now) {
      return this.cachedKey;
    }
    if (this.inflight) {
      return this.inflight;
    }
    this.inflight = (async () => {
      try {
        const res = await fetch(this.bootstrap.endpoints.token, {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!res.ok) {
          throw new Error(await formatHttpError('Token', res));
        }
        const key = (await res.json()) as ScopedKeyResponse;
        if (!key.key) {
          throw new Error('Token endpoint returned no key');
        }
        this.cachedKey = key;
        return key;
      } finally {
        this.inflight = null;
      }
    })();
    return this.inflight;
  }
}

/**
 * Build a Typesense `filter_by` string from the active facet selections.
 *
 *   { country_ss: ['Burkina Faso', 'Niger'], newspaper_ss: ['Sidwaya'] }
 *     →  country_ss:=[`Burkina Faso`,`Niger`] && newspaper_ss:=[`Sidwaya`]
 *
 * Backticks around values escape spaces and most punctuation. We
 * defensively strip backticks from values themselves (no IWAC entity
 * name contains one, but better safe than sorry).
 */
function buildFilterBy(filters: ActiveFilters): string {
  const parts: string[] = [];
  for (const [field, values] of Object.entries(filters)) {
    if (!values || values.length === 0) continue;
    if (NUMERIC_FACET_FIELDS.has(field)) {
      // Numeric exact-match-any: bare numbers, NO backticks. Typesense
      // rejects a backticked number ("Numerical field has an invalid
      // comparator"), so e.g. gemini_subjectivite:=[1,2] — never [`1`,`2`].
      const nums = values.filter((v) => v.trim() !== '' && Number.isFinite(Number(v)));
      if (nums.length === 0) continue;
      parts.push(`${field}:=[${nums.join(',')}]`);
    } else if (BOOLEAN_FACET_FIELDS.has(field)) {
      // Booleans are bare like numerics: has_fulltext:=[true] — never
      // backticked. Anything other than true/false is dropped.
      const bools = values.filter((v) => v === 'true' || v === 'false');
      if (bools.length === 0) continue;
      parts.push(`${field}:=[${bools.join(',')}]`);
    } else {
      const escaped = values.map((v) => v.replaceAll('`', '')).map((v) => `\`${v}\``);
      parts.push(`${field}:=[${escaped.join(',')}]`);
    }
  }
  return parts.join(' && ');
}

/**
 * Build the pub_year range filter clause. Returns "" when no bounds.
 *   { from: 1990, to: 2010 }  →  pub_year:>=1990 && pub_year:<=2010
 *   { from: 1990 }            →  pub_year:>=1990
 *   { to: 2010 }              →  pub_year:<=2010
 */
function buildYearRangeFilter(range: YearRange | null): string {
  if (!range) return '';
  const parts: string[] = [];
  if (typeof range.from === 'number' && Number.isFinite(range.from)) {
    parts.push(`pub_year:>=${Math.trunc(range.from)}`);
  }
  if (typeof range.to === 'number' && Number.isFinite(range.to)) {
    parts.push(`pub_year:<=${Math.trunc(range.to)}`);
  }
  return parts.join(' && ');
}

function combineFilters(...parts: Array<string | undefined | null>): string {
  return parts
    .map((p) => p?.trim())
    .filter((p): p is string => !!p)
    .join(' && ');
}

/**
 * Per-search error envelope Typesense embeds inside multi_search results.
 *
 * `multi_search` always returns HTTP 200 even when individual searches
 * fail — so a 422 ("Could not find a field named X" / bad filter syntax /
 * missing collection) shows up as `{code: 422, error: "..."}` inside
 * `results[i]` rather than as a non-2xx HTTP status.
 */
interface TypesensePerSearchError {
  code: number;
  error: string;
}

/**
 * Surface per-search errors as thrown errors so the existing error UI
 * catches them. Returning the raw envelope to callers would let
 * `response.hits.length` blow up downstream when ResultsList /
 * SuggestDropdown try to render hits that aren't there.
 *
 * Two failure modes detected:
 *   1. The server reported an error inside the result envelope
 *      (`{code, error}`) — surface its message verbatim.
 *   2. The result is shaped like a success but lacks `hits` — defensive
 *      catch-all for unexpected response shapes that would otherwise
 *      trigger an opaque undefined-property crash on render.
 */
function validateSearchResult(
  label: string,
  result: IwacSearchResponse | TypesensePerSearchError | undefined,
): IwacSearchResponse {
  if (!result) {
    throw new Error(`${label} response missing results[0]`);
  }
  if ('error' in result && typeof result.error === 'string') {
    const code = 'code' in result ? result.code : '';
    throw new Error(`${label} HTTP ${code}: ${result.error}`);
  }
  if (!Array.isArray((result as IwacSearchResponse).hits)) {
    throw new Error(`${label} response missing hits[]`);
  }
  return result as IwacSearchResponse;
}

/**
 * Build a useful error string for an HTTP failure on one of our JSON
 * endpoints. Tries hard to surface server-emitted detail:
 *
 *   1. If the body is JSON with our `{error, message, detail}` envelope,
 *      use `${message} — ${detail}` so the user sees the *root* cause
 *      (e.g. "Failed to bootstrap … ← caused by: Connection refused")
 *      not just the wrapper.
 *   2. If the body is JSON without our envelope (e.g. a Typesense error),
 *      fall back to the most informative-looking field.
 *   3. Otherwise (HTML error page, plain text), include the body raw —
 *      capped at 1024 chars so a multi-megabyte HTML 500 doesn't fill
 *      the user's console.
 *
 * The previous helper sliced any body to 200 chars, which silently
 * truncated the chain-walked `detail` field and made the diagnostic
 * useless. The fix is to parse first, slice last, and only slice
 * non-JSON bodies.
 */
async function formatHttpError(label: string, res: Response): Promise<string> {
  let raw: string;
  try {
    raw = await res.text();
  } catch {
    return `${label} HTTP ${res.status}: <unreadable body>`;
  }

  try {
    const body: unknown = JSON.parse(raw);
    if (body && typeof body === 'object') {
      const obj = body as Record<string, unknown>;
      const message = typeof obj.message === 'string' ? obj.message : undefined;
      const detail = typeof obj.detail === 'string' ? obj.detail : undefined;
      if (message && detail) {
        return `${label} HTTP ${res.status}: ${message} — ${detail}`;
      }
      if (message) {
        return `${label} HTTP ${res.status}: ${message}`;
      }
      // typesense-style errors put the message under `error`.
      if (typeof obj.error === 'string') {
        return `${label} HTTP ${res.status}: ${obj.error}`;
      }
    }
  } catch {
    // Not JSON — fall through to the raw-text branch.
  }

  return `${label} HTTP ${res.status}: ${raw.slice(0, 1024)}`;
}

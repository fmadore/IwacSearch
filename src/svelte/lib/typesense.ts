import type {
  ActiveFilters,
  EntitySuggestion,
  IwacBootstrap,
  IwacSearchResponse,
  ScopedKeyResponse,
  SuggestResult,
  YearRange,
} from './types';
import { NUMERIC_FACET_FIELDS } from './i18n';

/**
 * Authority facet fields the typeahead prefix-matches via `facet_query`,
 * so a user typing "cotonou" sees the place entity, not just articles
 * whose title contains the word.
 */
const ENTITY_FACET_FIELDS = ['places_ss', 'topics_ss', 'persons_ss', 'organisations_ss'] as const;

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
    const queryBy =
      this.bootstrap.query_by ?? 'title_txt,ocr_text,abstract,entity_aliases_txt,embedding';
    const highlightFields = this.bootstrap.highlight_fields ?? 'title_txt,ocr_text';
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
          // Cap total hits we'll page through — protects from runaway
          // crawler-style requests.
          limit_hits: 250,
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

    // One multi_search request bundles:
    //   [0]  article title hits (full-text prefix on title + aliases)
    //   [1…] one facet_query per entity field, surfacing matching
    //        authority values (places / topics / persons / organisations).
    // The same locked_filters apply to every sub-search, so suggestions on
    // /browse/benin stay Bénin-scoped.
    const titleSearch = {
      collection,
      q: trimmed,
      // Narrower than the main search query_by — dropdown should surface
      // clear title hits, not fuzzy OCR matches that wouldn't make sense
      // out of context.
      query_by: 'title_txt,entity_aliases_txt',
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

    const entitySearches = ENTITY_FACET_FIELDS.map((field) => ({
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

    const res = await fetch(this.bootstrap.endpoints.search, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-TYPESENSE-API-KEY': key.key,
      },
      body: JSON.stringify({ searches: [titleSearch, ...entitySearches] }),
    });
    if (!res.ok) {
      throw new Error(await formatHttpError('Suggest', res));
    }
    const json = (await res.json()) as {
      results: Array<IwacSearchResponse | TypesensePerSearchError>;
    };

    const articles = validateSearchResult('Suggest', json.results?.[0]).hits;

    // Collect matching entity values across the four facet searches.
    const entities: EntitySuggestion[] = [];
    ENTITY_FACET_FIELDS.forEach((field, i) => {
      const r = json.results?.[i + 1];
      if (!r || 'error' in r) return;
      const fc = (r as IwacSearchResponse).facet_counts?.find((f) => f.field_name === field);
      for (const c of fc?.counts ?? []) {
        if (c.value) entities.push({ field, value: c.value, count: c.count });
      }
    });
    // Highest-coverage entities first; cap so the dropdown stays compact.
    entities.sort((a, b) => b.count - a.count);

    return { articles, entities: entities.slice(0, 6) };
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

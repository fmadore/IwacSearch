import type {
  ActiveFilters,
  IwacBootstrap,
  IwacSearchResponse,
  ScopedKeyResponse,
  YearRange,
} from './types';

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
   * Empty query returns an empty response (the public scoped key still
   * carries `filter_by: is_public:=true` baked in, so a `q=*` would
   * happily dump all 14 K public docs — never what the user wants).
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
    if (!args.q.trim()) {
      return emptyResponse(args.page ?? 1);
    }

    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;

    const filterBy = combineFilters(
      this.bootstrap.locked_filters,
      buildFilterBy(args.activeFilters ?? {}),
      buildYearRangeFilter(args.yearRange ?? null),
    );

    const facets = args.facetBy ?? this.bootstrap.prominent_facets;

    const body = {
      searches: [
        {
          collection,
          q: args.q,
          // Same query_by everywhere: full-text on title + ocr (highlights
          // only via exclude_fields), entity aliases for spelling tolerance,
          // and the embedding field for semantic recall.
          query_by: 'title_txt,ocr_text,entity_aliases_txt,embedding',
          // Stopwords keep "le", "la", "des" etc. from polluting matches
          stopwords: 'fr_default',
          filter_by: filterBy || undefined,
          sort_by: args.sortBy ?? this.bootstrap.default_sort,
          page: args.page ?? 1,
          per_page: args.perPage ?? this.bootstrap.results_per_page,
          highlight_fields: 'title_txt,ocr_text',
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
      throw new Error(`Search HTTP ${res.status}: ${await safeText(res)}`);
    }
    const json = (await res.json()) as { results: IwacSearchResponse[] };
    if (!json.results?.[0]) {
      throw new Error('Search response missing results[0]');
    }
    return json.results[0];
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
          throw new Error(`Token HTTP ${res.status}: ${await safeText(res)}`);
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
    const escaped = values.map((v) => v.replaceAll('`', '')).map((v) => `\`${v}\``);
    parts.push(`${field}:=[${escaped.join(',')}]`);
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

async function safeText(res: Response): Promise<string> {
  try {
    return (await res.text()).slice(0, 200);
  } catch {
    return '<unreadable body>';
  }
}

function emptyResponse(page: number): IwacSearchResponse {
  return {
    found: 0,
    page,
    request_params: {},
    hits: [],
    facet_counts: [],
    search_time_ms: 0,
  };
}

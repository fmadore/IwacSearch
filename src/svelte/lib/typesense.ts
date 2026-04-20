import type { IwacBootstrap, IwacSearchResponse, ScopedKeyResponse } from './types';

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
   * Run a search. Empty query returns nothing — the public scoped key
   * still has `filter_by: is_public:=true` baked in, so we'd technically
   * see all public docs, but a 14K-doc dump is never what the user wants.
   */
  async search(args: {
    q: string;
    page?: number;
    perPage?: number;
    sortBy?: string;
    extraFilter?: string;
  }): Promise<IwacSearchResponse> {
    if (!args.q.trim()) {
      return emptyResponse(args.page ?? 1);
    }

    const key = await this.getKey();
    const collection = this.bootstrap.collection_alias ?? key.collection;
    const filter = combineFilters(this.bootstrap.locked_filters, args.extraFilter);

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
          filter_by: filter || undefined,
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
          facet_by: this.bootstrap.prominent_facets.join(','),
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
    search_time_ms: 0,
  };
}

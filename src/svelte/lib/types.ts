/**
 * Bootstrap config the server emits per mount as a JSON <script>.
 * Same shape for /search, /browse/{slug}, and every page block.
 */
export interface IwacBootstrap {
  block_id: string | number;
  mode: 'full' | 'compact' | 'results-only';
  locked_filters: string; // raw Typesense filter_by
  prominent_facets: string[]; // schema field names
  default_sort: string; // e.g. "_text_match:desc"
  results_per_page: number;
  collection_alias?: string; // defaults to "iwac_current"
  endpoints: {
    token: string; // /discovery/token (server-mounted)
    search: string; // /search-api/multi_search (proxied)
  };
}

/**
 * Response from /discovery/token. The browser stores the key in memory
 * only — never localStorage — so it dies with the page.
 */
export interface ScopedKeyResponse {
  key: string;
  expires_at: number; // unix seconds
  host: string; // ignored by client; informative
  collection: string;
}

/**
 * Subset of the Typesense document shape we render. Optional fields
 * track schema.yaml — the server may or may not emit them per row.
 *
 * `ocr_text` is intentionally absent — public scoped keys carry
 * `exclude_fields: ocr_text` so it never reaches the browser.
 */
export interface IwacDoc {
  id: string;
  title: string;
  type_s?: 'article' | 'publication' | 'document' | 'audiovisual';
  date?: number; // unix epoch seconds
  pub_year?: number;
  country_ss?: string[];
  newspaper_ss?: string[];
  language_ss?: string[];
  topics_ss?: string[];
  persons_ss?: string[];
  places_ss?: string[];
  organisations_ss?: string[];
  events_ss?: string[];
  thumbnail_url?: string;
  iiif_manifest?: string;
  omeka_url?: string;
  source_url?: string;
}

export interface IwacHighlight {
  field: string;
  snippet?: string;
  matched_tokens?: string[];
}

export interface IwacHit {
  document: IwacDoc;
  highlights: IwacHighlight[];
  text_match?: number;
}

export interface IwacFacetCount {
  value: string;
  count: number;
  highlighted?: string;
}

export interface IwacFacet {
  field_name: string;
  counts: IwacFacetCount[];
  /** Number of facet values found across all matches (not just the top N shown). */
  stats?: { total_values?: number };
}

export interface IwacSearchResponse {
  found: number;
  page: number;
  request_params: { q?: string; per_page?: number };
  hits: IwacHit[];
  facet_counts?: IwacFacet[];
  search_time_ms: number;
}

/**
 * In-memory selection state for one facet. Field name is the schema
 * field (e.g. `country_ss`); values are the user-picked tokens.
 */
export type ActiveFilters = Record<string, string[]>;

/**
 * Year range filter. Either bound may be omitted (open-ended on that
 * side); a fully empty range is represented as `null`.
 *
 * Maps to a Typesense filter clause: `pub_year:>=X && pub_year:<=Y`.
 * Kept separate from categorical `ActiveFilters` because it has range
 * semantics, not set-membership semantics.
 */
export interface YearRange {
  from?: number;
  to?: number;
}

/**
 * The full URL-syncable state for one mount instance. Standalone /search
 * persists this to window.location; page blocks keep it in memory only.
 */
export interface SearchState {
  q: string;
  page: number;
  sort: string; // e.g. "_text_match:desc", "date:desc"
  filters: ActiveFilters;
  yearRange: YearRange | null;
}

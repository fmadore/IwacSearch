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

export interface IwacSearchResponse {
  found: number;
  page: number;
  request_params: { q?: string; per_page?: number };
  hits: IwacHit[];
  search_time_ms: number;
}

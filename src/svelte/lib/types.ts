/**
 * Bootstrap config the server emits per mount as a JSON <script>.
 * Same shape for /search, /browse/{slug}, and every page block.
 */
export interface IwacBootstrap {
  block_id: string | number;
  mode: 'full' | 'compact' | 'results-only';
  /** Site locale for UI strings + facet/type labels. Defaults to 'fr'. */
  locale?: 'fr' | 'en';
  /**
   * Which card + sort vocabulary to render. 'content' (default) is the
   * article/document surface; 'entity' is the index/authority browse page
   * (entity cards with occurrence counts, frequency-based sort).
   */
  card?: 'content' | 'entity';
  /**
   * Initial query string. Set by the federated page so a reused App seeds
   * with the shared query; absent on standalone surfaces (which read the
   * query from the URL or start empty).
   */
  initial_query?: string;
  /**
   * Initial facet selections. Set by the federated page when a chip on the
   * union "All" tab hands off to a per-collection tab (so the clicked
   * filter survives the tab switch); absent everywhere else.
   */
  initial_filters?: ActiveFilters;
  locked_filters: string; // raw Typesense filter_by
  prominent_facets: string[]; // schema field names
  /**
   * Suppress the country chip on result cards. Set for single-country
   * scopes (the per-country presets), where repeating the country on every
   * row is noise — the whole page is already that country. The whole-corpus,
   * references and entity-index scopes leave it on. Display only; country
   * stays a working facet wherever the scope advertises it. Defaults to false.
   */
  hide_country?: boolean;
  default_sort: string; // e.g. "_text_match:desc"
  results_per_page: number;
  collection_alias?: string; // defaults to "iwac_current"
  /** Entity collection alias — lets the autocomplete federate to it. */
  index_collection_alias?: string;
  /**
   * Typesense query_by / highlight_fields for THIS surface's collection.
   * The entity collection lacks ocr_text/abstract/embedding, so these must
   * match the collection's schema or Typesense 404s on missing fields.
   */
  query_by?: string;
  highlight_fields?: string;
  /**
   * Result diversification (Typesense 30.2 MMR). When set, text queries on
   * this surface pass `curation_tags: <diversify_tag>` to activate the
   * `iwac_diversity` curation set (linked on the collection), which spreads
   * out near-duplicate syndicated articles. Unset on browse pages / page
   * blocks, so they keep raw relevance order. Browse mode (q=*) never
   * diversifies regardless — text-match clustering only happens on a query.
   */
  diversify_tag?: string | null;
  /**
   * MMR relevance↔diversity balance: 1 = pure relevance, 0 = max diversity.
   * Defaults to 0.7 (mostly relevance, gentle dedup) when diversify_tag is
   * set. Ignored without diversify_tag.
   */
  diversity_lambda?: number;
  endpoints: {
    token: string; // /discovery/token (server-mounted)
    search: string; // /search-api/multi_search (proxied)
  };
  /**
   * First-page results + facet counts pre-rendered server-side by
   * InitialResponseRenderer. When present, the Svelte client uses it
   * to seed its `response` state and skips the initial fetch — so the
   * page paints real content on first frame instead of a spinner. The
   * server omits this field if Typesense is unreachable or the response
   * was malformed; the client then falls back to its normal scoped-key
   * + fetch flow, same end state with one extra roundtrip.
   */
  initial_response?: IwacSearchResponse;
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
  /** Citation key (dcterms:identifier), e.g. "iwac-0001234". */
  identifier?: string;
  title: string;
  type_s?: 'article' | 'publication' | 'document' | 'audiovisual' | 'photograph' | 'reference';
  date?: number; // unix epoch seconds
  pub_year?: number;
  /**
   * Public-safe display body — the human-written abstract for references,
   * or the AI `descriptionAI` summary for articles/documents/audiovisual.
   * Distinct from (and unlike) the licensing-restricted ocr_text, which
   * the scoped key excludes. Rendered as a couple of lines on the card.
   */
  abstract?: string;
  /** Author(s) / creator(s). Pipe-split upstream into one value per author. */
  creator_ss?: string[];
  /** Alternative titles (dcterms:alternative) — FTS channel, rarely shown. */
  alt_title_txt?: string[];
  /** Whether the full text (bibo:content) is publicly readable on the item page. */
  has_fulltext?: boolean;
  // ── Reference bibliographic detail (references subset only) ──
  /** RDF class: "Article de revue" | "Chapitre" | "Livre" | … — drives citation format. */
  reference_type_ss?: string[];
  /** Journal title (articles) or publisher (books / chapters / theses / reports). */
  publisher_s?: string;
  /** Containing book title (chapters). */
  book_title_s?: string;
  volume_s?: string;
  issue_s?: string;
  /** Page range, pre-formatted e.g. "185–209". */
  pages_s?: string;
  editor_ss?: string[];
  edition_s?: string;
  doi?: string;
  country_ss?: string[];
  newspaper_ss?: string[];
  language_ss?: string[];
  topics_ss?: string[];
  persons_ss?: string[];
  places_ss?: string[];
  organisations_ss?: string[];
  events_ss?: string[];
  /** Merged dcterms:subject facet (persons + organisations + topics). */
  subjects_ss?: string[];
  thumbnail_url?: string;
  iiif_manifest?: string;
  omeka_url?: string;
  source_url?: string;
  // ── Index/authority entity fields (entity collection only) ──
  /** Entity kind: "Personnes" | "Lieux" | "Organisations" | … (raw data value). */
  entity_type_s?: string;
  /** Occurrence count — how many content items reference this entity. */
  frequency?: number;
  /** Mention span (years), shown as the entity card eyebrow. */
  first_year?: number;
  last_year?: number;
  /** Entity category via dcterms:isPartOf (org kind for organisations). */
  is_part_of_ss?: string[];
  /**
   * Per-year mention histogram for an index entity, encoded as a compact
   * `"year:count"` list joined by `;` (e.g. "1983:4;1984:7;1990:2"). Powers
   * the entity-card mentions sparkline. index:false in the entity schema —
   * stored for display only, parsed client-side by parseMentionsByYear().
   * Absent until the collection is rebuilt by a reindex that emits it, so the
   * sparkline degrades gracefully (no field → no sparkline).
   */
  mentions_by_year_s?: string;
  /**
   * Geopoint of an index entity, [lat, lng] (Typesense order — MapLibre
   * wants [lng, lat], swap before rendering). Set with has_coords=true when
   * the curated coordinates parsed; absent otherwise. Present only after
   * the iwac_index_v3 rebuild.
   */
  geo?: [number, number];
  has_coords?: boolean;
  /** Raw curated "lat, lng" literal (display-only). */
  coordinates?: string;
}

/**
 * One entity (place / topic / person / organisation) surfaced by the
 * typeahead via Typesense `facet_query`. Picking one applies it as a
 * facet filter rather than running a full-text query.
 */
export interface EntitySuggestion {
  /** Schema facet field, e.g. `places_ss`. */
  field: string;
  /** The entity value, e.g. "Cotonou". */
  value: string;
  /** Doc count for this entity within the current scope. */
  count: number;
}

/** Typeahead payload: article title hits + matching entity values. */
export interface SuggestResult {
  articles: IwacHit[];
  entities: EntitySuggestion[];
}

export interface IwacHighlight {
  field: string;
  /** Scalar string fields: the marked-up snippet (WINDOWED for long text). */
  snippet?: string;
  /**
   * Full marked-up field text — present for fields in
   * highlight_full_fields (title_txt). Prefer this over `snippet` when
   * rendering a complete value: past ~30 tokens the snippet is a window.
   */
  value?: string;
  /**
   * Array (string[]) fields: one marked-up snippet per matched element,
   * with `indices` pointing at the matching positions in the source array.
   * Used for the matched-in attribution chips (creator_ss, subjects_ss, …).
   */
  snippets?: string[];
  indices?: number[];
  matched_tokens?: string[] | string[][];
}

export interface IwacHit {
  document: IwacDoc;
  /** Absent on browse (q=*) responses — Typesense only highlights real queries. */
  highlights?: IwacHighlight[];
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
 * One bar of the year-distribution histogram drawn under the date slider:
 * how many documents carry that `pub_year`. Sourced from a counts-only
 * Typesense facet query that deliberately omits the year-range filter, so
 * the bars show the full span (see TypesenseClient.yearDistribution).
 */
export interface YearBucket {
  year: number;
  count: number;
}

/**
 * Result presentation mode. `list` is the dense ledger (default — density
 * first); `gallery` is the image-forward tile grid for browsing the corpus's
 * photographs, plates and scans; `map` plots geo-tagged index entities
 * (entity surfaces only — offered when the surface's collection carries
 * geopoints). Synced to the URL (`&view=…`) and localStorage so it's
 * shareable and sticky.
 */
export type ViewMode = 'list' | 'gallery' | 'map';

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
  /** Presentation mode (does not affect the query). Defaults to 'list'. */
  view: ViewMode;
}

/**
 * One tab on the federated /search/everything page. `bootstrap` is a full
 * per-collection IwacBootstrap the reused <App> mounts when the tab is
 * active; `id` distinguishes the content collection from the entity index.
 */
export interface IwacFederatedTab {
  id: 'content' | 'entities';
  bootstrap: IwacBootstrap;
}

/**
 * Bootstrap for the federated "search everything" page, mounted on
 * [data-iwac-federated-root]. The page owns a shared query + active tab,
 * runs one counts-only multi_search across both collections for the tab
 * badges, then mounts the active tab's <App> (search box suppressed) for
 * the faceted results. Both collections are reachable with a single scoped
 * key (the search-only parent key spans all collections).
 */
export interface IwacFederatedBootstrap {
  variant: 'federated';
  locale?: 'fr' | 'en';
  initial_query: string;
  default_tab: 'content' | 'entities';
  tabs: IwacFederatedTab[];
  endpoints: {
    token: string;
    search: string;
  };
}

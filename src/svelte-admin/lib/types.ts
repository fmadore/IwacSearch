/**
 * Types shared across the admin Svelte app.
 *
 * Mirrors the PHP DTO (src/Browse/BrowseConfig.php) field-for-field
 * and the API envelope (src/Controller/Admin/BrowseConfigController.php).
 * Keep these in sync — both sides must agree on field names.
 */

/**
 * One row of iwac_browse_config as the JSON API returns it.
 *
 * `id` is null for unsaved drafts and set to a positive int after
 * create. Validation mirrors the PHP side:
 *   - slug: /^[a-z][a-z0-9_-]{0,79}$/ and unique
 *   - title: non-empty
 *   - results_per_page: 1..50
 *   - default_sort: one of the allowed values from FacetCatalog
 */
export interface BrowseConfig {
  id: number | null;
  slug: string;
  title: string;
  intro_html: string;
  locked_filters: string;
  prominent_facets: string[];
  default_sort: string;
  results_per_page: number;
  position: number;
}

export interface FacetOption {
  name: string;
  label: string;
}

export interface SortOption {
  value: string;
  label: string;
}

export interface AdminBootstrap {
  endpoints: {
    list: string;
    /** Item endpoint with a literal `0` placeholder — replace with the real id at call time. */
    item: string;
  };
  catalog: {
    facets: FacetOption[];
    sorts: SortOption[];
  };
  configs: BrowseConfig[];
  csrf_token: string;
}

export interface ApiError {
  error: string;
  message: string;
  detail?: string;
}

/** Envelope for a successful response: `{ data: ... }`. */
export interface ApiSuccess<T> {
  data: T;
}

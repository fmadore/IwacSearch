/**
 * What the surface has fetched, and the rules for fetching it — extracted
 * from App.svelte, where it was a 120-line `$effect` that no test could reach.
 *
 * App still owns the search STATE (query, page, sort, filters, year range,
 * page size) because every control writes it. This owns what that state
 * turns into: the response, the loading/error flags, the year histogram and
 * the zero-result "did you mean" candidates — plus, in {@link createMapResults},
 * the geo-tagged set the Map view draws.
 *
 * Same shape as filterDrawer's `attach()`: App calls `run()` from inside an
 * `$effect`, passing the state it read, so the effect's dependencies stay
 * visible where the state lives; `run()` also reads the internal retry
 * counter, so {@link SearchResults.retry} re-runs the effect by itself. Tests
 * call `run()` directly against a fake client — no effect root needed.
 *
 * The rules this carries, each of which regressed at least once:
 *
 *  - The SSR snapshot is adopted for the pristine first run only, and that
 *    run skips the search — but a full-mode surface still asks for the
 *    histogram, which the snapshot does not carry.
 *  - The histogram depends on the query + categorical filters ONLY, so it is
 *    requested as a second sub-search of the same POST just when that pair
 *    changed. Paging, sorting or moving the year range reuses the bars.
 *  - A superseded (aborted) request settles nothing: the newer one owns the
 *    loading flag, and clearing it would blank the skeleton under it.
 *  - A semantic-only response is a dead query wearing a full result list:
 *    it stays out of the recent-searches history and gets the spelling
 *    suggestions, exactly like a zero-hit one.
 */
import type {
  ActiveFilters,
  EntitySuggestion,
  IwacDoc,
  IwacSearchResponse,
  YearBucket,
  YearRange,
} from './types';
import type { TypesenseClient } from './typesense';
import { SeqGuard, isAbortError } from './transport';
import { facetUnion } from './queryBuilders';
import { isSemanticOnlyResponse } from './semanticFallback';
import { recordSearch } from './searchHistory';

/** The slice of search state one fetch is made from. */
export interface SearchRequest {
  q: string;
  page: number;
  sort: string;
  filters: ActiveFilters;
  yearRange: YearRange | null;
  /** The reader's page-size choice; null means the surface's configured one. */
  perPage: number | null;
}

/** Detail of the `iwac-search:outcome` window event (read by analytics). */
export interface SearchOutcomeDetail {
  query: string;
  collection: string | undefined;
  found: number;
  keywordFound: number | undefined;
  semanticOnly: boolean;
}

export interface SearchResultsOptions {
  /** Full-mode surfaces draw the year histogram; compact ones never ask for it. */
  withHistogram: boolean;
  /** Always requested, whatever is selected (see facetUnion). */
  prominentFacets: readonly string[];
  /** Reported on the outcome event. */
  collection?: string;
  /** The SSR snapshot, when the initial state is pristine enough to adopt it. */
  initialResponse: IwacSearchResponse | null;
  /** Settled, non-empty query. Defaults to dispatching `iwac-search:outcome`. */
  onOutcome?: (detail: SearchOutcomeDetail) => void;
  /** A query that found something. Defaults to the recent-searches history. */
  onFruitfulQuery?: (q: string) => void;
}

export type SearchResultsClient = Pick<TypesenseClient, 'search' | 'yearDistribution' | 'suggest'>;

export interface SearchResults {
  readonly response: IwacSearchResponse | null;
  readonly isLoading: boolean;
  readonly error: string | null;
  readonly yearDistribution: YearBucket[];
  readonly yearsUnavailable: boolean;
  readonly didYouMean: EntitySuggestion[];
  /** Re-run the current search (the histogram-unavailable notice's button). */
  retry(): void;
  /** Run inside an $effect: fetch for `req`, returns the cleanup when one is needed. */
  run(req: SearchRequest): (() => void) | undefined;
}

/** The histogram is keyed on the query + categorical filters, never page/sort/years. */
function histogramKeyOf(q: string, filters: ActiveFilters): string {
  return `${q}\u0000${JSON.stringify(filters)}`;
}

function dispatchOutcome(detail: SearchOutcomeDetail): void {
  window.dispatchEvent(new CustomEvent('iwac-search:outcome', { detail }));
}

export function createSearchResults(
  client: SearchResultsClient,
  opts: SearchResultsOptions,
): SearchResults {
  const onOutcome = opts.onOutcome ?? dispatchOutcome;
  const onFruitfulQuery = opts.onFruitfulQuery ?? recordSearch;

  let response = $state<IwacSearchResponse | null>(opts.initialResponse);
  let isLoading = $state(false);
  let error = $state<string | null>(null);
  // Empty until the first response resolves, and on any surface without a
  // facet panel.
  let yearDistribution = $state<YearBucket[]>([]);
  let yearsUnavailable = $state(false);
  let retryVersion = $state(0);
  // "Did you mean" candidates for a zero-result query: entity suggestions
  // fetched through the typo-tolerant suggest path (facet_query + the alias
  // index), so a near-miss spelling ("Tidjaniya") can offer the canonical
  // entity.
  let didYouMean = $state<EntitySuggestion[]>([]);

  // Plain `let`s, not $state: `run()` reads them without wanting a reactive
  // dependency, so writing them never re-triggers the calling effect.
  // The signature the current bars were computed for.
  let lastHistogramKey: string | null = null;
  // The first run skips its fetch when a snapshot was adopted.
  let skipNextFetch = opts.initialResponse != null;

  /**
   * Zero-result recovery: ask the typo-tolerant suggest path for entities
   * near the dead query. Best-effort — failures (including aborts) just mean
   * no banner.
   */
  function fetchDidYouMean(q: string): void {
    client
      .suggest(q, 3)
      .then((s) => {
        didYouMean = s.entities.slice(0, 4);
      })
      .catch(() => {
        didYouMean = [];
      });
  }

  function run(req: SearchRequest): (() => void) | undefined {
    const { q, page, sort, filters, yearRange, perPage } = req;
    // Read for its dependency only: bumping it re-runs the calling effect.
    void retryVersion;

    // First run with the adopted snapshot: it already is this state's first
    // page, so don't refetch it. The histogram is not in the snapshot.
    if (skipNextFetch) {
      skipNextFetch = false;
      if (!opts.withHistogram) return undefined;
      let cancelled = false;
      client
        .yearDistribution(q)
        .then((years) => {
          if (cancelled) return;
          yearDistribution = years;
          lastHistogramKey = histogramKeyOf(q, filters);
        })
        .catch(() => {
          if (!cancelled) yearsUnavailable = true;
        });
      return () => {
        cancelled = true;
      };
    }

    const histogramKey = opts.withHistogram ? histogramKeyOf(q, filters) : null;
    const needHistogram = histogramKey !== null && histogramKey !== lastHistogramKey;

    isLoading = true;
    error = null;
    didYouMean = [];
    client
      .search({
        q,
        page,
        sortBy: sort,
        activeFilters: filters,
        yearRange,
        perPage: perPage ?? undefined,
        facetBy: facetUnion(opts.prominentFacets, filters),
        withYearDistribution: needHistogram,
      })
      .then(({ response: r, years, yearsUnavailable: unavailable }) => {
        if (needHistogram) {
          yearsUnavailable = unavailable ?? false;
          if (unavailable) yearDistribution = [];
        }
        response = r;
        // The typeahead is deliberately NOT touched here — neither closed nor
        // re-armed. Search-as-you-type means results land while the reader is
        // still composing and still reading the suggestions; closing the panel
        // on the commit (3.16.0) made a two-second pause enough to lose the row
        // they were aiming at, and re-opening it (pre-3.16.0) put it back over
        // its own answer. It closes on an outside press, blur, Escape, a pick,
        // or an emptied box — all of which are the reader saying so.
        if (years !== undefined) {
          yearDistribution = years;
          lastHistogramKey = histogramKey;
        }
        if (q.trim() !== '') {
          const semanticOnly = isSemanticOnlyResponse(r, q);
          onOutcome({
            query: q,
            collection: opts.collection,
            found: r.found,
            keywordFound: r.keyword_found,
            semanticOnly,
          });
          if (r.found > 0 && !semanticOnly) {
            // Only fruitful queries enter the recent-searches history, so
            // typo dead-ends don't pollute the dropdown.
            onFruitfulQuery(q);
          } else if (q.trim().length >= 3) {
            fetchDidYouMean(q);
          }
        }
        isLoading = false;
      })
      .catch((e: unknown) => {
        // Superseded: a newer request is in flight and will settle the UI.
        if (isAbortError(e)) return;
        console.error('[iwac-search] search failed', e);
        error = e instanceof Error ? e.message : String(e);
        // Not the stale response: an error that left the previous results on
        // screen would read as the filters having worked.
        response = null;
        isLoading = false;
      });
    return undefined;
  }

  return {
    get response() {
      return response;
    },
    get isLoading() {
      return isLoading;
    },
    get error() {
      return error;
    },
    get yearDistribution() {
      return yearDistribution;
    },
    get yearsUnavailable() {
      return yearsUnavailable;
    },
    get didYouMean() {
      return didYouMean;
    },
    retry(): void {
      retryVersion += 1;
    },
    run,
  };
}

/** The slice of search state the Map view is drawn from — no page, no sort. */
export interface MapRequest {
  q: string;
  filters: ActiveFilters;
  /** Unlike the histogram, the map reflects the selected window. */
  yearRange: YearRange | null;
}

export interface MapResults {
  readonly docs: IwacDoc[];
  readonly loading: boolean;
  /** Run inside an $effect while the Map view is active; returns its cleanup. */
  run(req: MapRequest): () => void;
}

/**
 * Every geo-tagged entity matching the current query + filters, for the Map
 * view. One abort signal cancels the whole paging loop; the sequence ticket
 * also guards the settle, so a fetch that lost the race to a newer one can
 * never overwrite it.
 */
export function createMapResults(client: Pick<TypesenseClient, 'fetchForMap'>): MapResults {
  let docs = $state<IwacDoc[]>([]);
  let loading = $state(false);
  const seq = new SeqGuard();

  return {
    get docs() {
      return docs;
    },
    get loading() {
      return loading;
    },
    run({ q, filters, yearRange }: MapRequest): () => void {
      const ticket = seq.start();
      const controller = new AbortController();
      loading = true;
      client
        .fetchForMap({ q, activeFilters: filters, yearRange, signal: controller.signal })
        .then((next) => {
          if (seq.isStale(ticket)) return; // superseded — newer fetch in flight
          docs = next;
          loading = false;
        })
        .catch((e: unknown) => {
          if (seq.isStale(ticket) || isAbortError(e)) return;
          console.warn('[iwac-search] map fetch failed', e);
          docs = [];
          loading = false;
        });
      return () => {
        controller.abort();
        seq.start();
      };
    },
  };
}

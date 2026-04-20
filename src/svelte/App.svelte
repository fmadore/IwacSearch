<script lang="ts">
  import type {
    ActiveFilters,
    IwacBootstrap,
    IwacSearchResponse,
    SearchState,
    YearRange,
  } from './lib/types';
  import { TypesenseClient } from './lib/typesense';
  import { onUrlPop, readUrlState, syncToUrl } from './lib/urlState';
  import SearchInput from './components/SearchInput.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import SortSelect from './components/SortSelect.svelte';

  /**
   * One App instance per mount target. Owns the full search state:
   *   - query string
   *   - page number
   *   - sort order
   *   - categorical filter selections (from facets)
   *   - year range (from the date slider)
   *
   * Standalone /search route syncs this state to window.location and
   * listens for popstate so back/forward gives a meaningful history.
   * Page blocks keep everything in memory — multiple block instances
   * on one page would clash if they all fought over the URL.
   */

  interface Props {
    bootstrap: IwacBootstrap;
  }

  const { bootstrap }: Props = $props();

  const isStandalone = $derived(String(bootstrap.block_id) === 'standalone');

  // TypesenseClient caches a scoped key, so we want exactly one per
  // mount. $derived.by reruns only if bootstrap changes, which it
  // doesn't post-mount — same effect as `const`, but satisfies
  // svelte-check's reactivity rules.
  const client = $derived.by(() => new TypesenseClient(bootstrap));

  // Initial state — URL for /search, empty for blocks. `isStandalone`
  // and `bootstrap` are read once at mount; svelte-check warns because
  // the read isn't reactive, but neither value can change post-mount
  // (bootstrap is server-emitted, isStandalone is derived from it).
  // svelte-ignore state_referenced_locally
  const initial: SearchState = isStandalone
    ? readUrlState()
    : {
        q: '',
        page: 1,
        sort: bootstrap.default_sort || '_text_match:desc',
        filters: {},
        yearRange: null,
      };

  let query = $state(initial.q);
  let page = $state(initial.page);
  let sort = $state(initial.sort);
  let filters = $state<ActiveFilters>(initial.filters);
  let yearRange = $state<YearRange | null>(initial.yearRange);

  let response = $state<IwacSearchResponse | null>(null);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // Previous snapshot for URL-sync diffing (pushState vs replaceState).
  let prevState: SearchState | null = null;

  // Push state → URL whenever anything observable changes.
  $effect(() => {
    if (!isStandalone) return;
    const next: SearchState = {
      q: query,
      page,
      sort,
      filters,
      yearRange,
    };
    syncToUrl(next, prevState);
    prevState = structuredClone(next);
  });

  // Back / forward → re-hydrate state from URL.
  $effect(() => {
    if (!isStandalone) return;
    return onUrlPop((s) => {
      query = s.q;
      page = s.page;
      sort = s.sort;
      filters = s.filters;
      yearRange = s.yearRange;
    });
  });

  // Query → search. Tracks every reactive state field by reading it.
  $effect(() => {
    const q = query;
    const p = page;
    const s = sort;
    const f = filters;
    const y = yearRange;

    if (!q.trim()) {
      response = null;
      error = null;
      return;
    }

    // Facet union: always request counts for prominent facets + any
    // facet the user has currently selected, so selected values don't
    // vanish from the UI even if they fall outside the top-N prominent
    // list.
    const facetBy = Array.from(new Set([...bootstrap.prominent_facets, ...Object.keys(f)]));

    isLoading = true;
    error = null;
    client
      .search({
        q,
        page: p,
        sortBy: s,
        activeFilters: f,
        yearRange: y,
        facetBy,
      })
      .then((r) => {
        response = r;
      })
      .catch((e: Error) => {
        console.error('[iwac-search] search failed', e);
        error = e.message;
        // Keep stale response visible on error? No — show the error
        // explicitly so the user doesn't think filters succeeded.
        response = null;
      })
      .finally(() => {
        isLoading = false;
      });
  });

  function handleQueryChange(next: string): void {
    query = next;
    page = 1; // any new query resets pagination
  }

  function handleSortChange(next: string): void {
    sort = next;
    page = 1;
  }

  function handleFacetToggle(field: string, value: string, nextChecked: boolean): void {
    const current = filters[field] ?? [];
    if (nextChecked) {
      if (!current.includes(value)) {
        filters = { ...filters, [field]: [...current, value] };
      }
    } else {
      const kept = current.filter((v) => v !== value);
      if (kept.length === 0) {
        // Drop the key entirely so empty filter entries don't pollute
        // the URL state.
        const next = { ...filters };
        delete next[field];
        filters = next;
      } else {
        filters = { ...filters, [field]: kept };
      }
    }
    page = 1;
  }

  function handleClearField(field: string): void {
    const next = { ...filters };
    delete next[field];
    filters = next;
    page = 1;
  }

  function handleClearAll(): void {
    filters = {};
    yearRange = null;
    page = 1;
  }

  function handleYearRangeChange(next: YearRange | null): void {
    yearRange = next;
    page = 1;
  }

  function loadMore(): void {
    if (!response) return;
    if (response.hits.length >= response.found) return;
    page += 1;
  }

  const facets = $derived(response?.facet_counts ?? []);
</script>

<div class="iwac-search" class:iwac-search--compact={bootstrap.mode === 'compact'}>
  {#if bootstrap.mode !== 'results-only'}
    <SearchInput
      value={query}
      placeholder="Rechercher dans les archives IWAC…"
      onChange={handleQueryChange}
    />
  {/if}

  {#if error}
    <div class="iwac-search__error" role="alert">
      <strong>Search unavailable.</strong>
      <span>{error}</span>
    </div>
  {/if}

  {#if bootstrap.mode === 'full'}
    <div class="iwac-search__layout">
      <div class="iwac-search__facets">
        <FacetPanel
          {facets}
          selected={filters}
          {yearRange}
          onToggle={handleFacetToggle}
          onClearAll={handleClearAll}
          onClearField={handleClearField}
          onYearRangeChange={handleYearRangeChange}
        />
      </div>

      <div class="iwac-search__results">
        {#if response}
          <div class="iwac-search__results-head">
            <SortSelect value={sort} onChange={handleSortChange} />
          </div>
        {/if}
        {#if isLoading && !response}
          <p class="iwac-search__status" aria-live="polite">Searching…</p>
        {:else if response}
          <ResultsList {response} onLoadMore={loadMore} {isLoading} />
        {:else if query.trim() === ''}
          <p class="iwac-search__hint">Type a search term to query the IWAC archive.</p>
        {/if}
      </div>
    </div>
  {:else if isLoading && !response}
    <p class="iwac-search__status" aria-live="polite">Searching…</p>
  {:else if response}
    <ResultsList {response} onLoadMore={loadMore} {isLoading} />
  {:else if query.trim() === '' && bootstrap.mode !== 'compact'}
    <p class="iwac-search__hint">Type a search term to query the IWAC archive.</p>
  {/if}
</div>

<style>
  .iwac-search {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #222);
    font-size: var(--text-base, 1rem);
  }
  .iwac-search__layout {
    display: grid;
    grid-template-columns: minmax(16rem, 20rem) 1fr;
    gap: var(--space-lg, 1.5rem);
    align-items: start;
  }
  @media (max-width: 48rem) {
    /* Stack on mobile. A proper drawer comes in M5. */
    .iwac-search__layout {
      grid-template-columns: 1fr;
    }
  }
  .iwac-search__facets {
    position: sticky;
    top: var(--space-md, 1rem);
    align-self: start;
    max-height: calc(100vh - var(--space-xl, 2rem));
    overflow-y: auto;
  }
  @media (max-width: 48rem) {
    .iwac-search__facets {
      position: static;
      max-height: none;
    }
  }
  .iwac-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0; /* allow snippet wrap */
  }
  .iwac-search__results-head {
    display: flex;
    justify-content: flex-end;
  }
  .iwac-search__error {
    background: color-mix(in srgb, var(--primary, #c66) 12%, var(--surface, #fff));
    border: 1px solid color-mix(in srgb, var(--primary, #c66) 35%, transparent);
    border-radius: var(--radius-md, 0.75rem);
    padding: var(--space-md, 1rem);
    color: var(--ink-strong, var(--ink, #222));
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-search__status,
  .iwac-search__hint {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
</style>

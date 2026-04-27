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
  import SuggestDropdown from './components/SuggestDropdown.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import Drawer from '../svelte-shared/components/Drawer.svelte';

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

  // Hydrate from the SSR'd first page when present. The server inlines
  // a `initial_response` in the bootstrap JSON for curated browse pages,
  // page blocks with locked filters, and the standalone /search shell —
  // so `response` is already set on first frame and the user sees real
  // content, not a spinner. Falls back to null (client-side fetch path)
  // when the server couldn't pre-render. The Array.isArray guard rejects
  // malformed bootstraps that would otherwise crash ResultsList on its
  // first render with `Cannot read properties of undefined (reading 'length')`.
  // svelte-ignore state_referenced_locally
  const initialResponse = deriveInitialResponse(bootstrap);
  let response = $state<IwacSearchResponse | null>(initialResponse);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // First $effect run skips its fetch when the live state matches the
  // state the server used for SSR (empty q, page 1, default sort, no
  // filters, no year range). Any URL-hydrated non-pristine state (e.g.
  // /search?q=ramadan) triggers a real fetch so the user sees what they
  // asked for, not the "browse everything" SSR snapshot. Plain `let`
  // (not $state) — reading it inside the effect doesn't create a
  // reactive dependency, so mutating it doesn't re-trigger the effect.
  let skipNextFetch =
    initialResponse != null &&
    initial.q === '' &&
    initial.page === 1 &&
    Object.keys(initial.filters).length === 0 &&
    initial.yearRange === null;

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
  // Always fires on mount (including with an empty query) so browse
  // surfaces — curated pages, blocks with locked_filters, or just a
  // bare /search arrival — show items + facets immediately. The
  // typesense client translates an empty query into `q=*` browse mode.
  $effect(() => {
    const q = query;
    const p = page;
    const s = sort;
    const f = filters;
    const y = yearRange;

    // Facet union: always request counts for prominent facets + any
    // facet the user has currently selected, so selected values don't
    // vanish from the UI even if they fall outside the top-N prominent
    // list.
    const facetBy = Array.from(new Set([...bootstrap.prominent_facets, ...Object.keys(f)]));

    // First run with a fresh SSR snapshot that matches the current
    // state? Don't refetch — we already have the right data. Any
    // subsequent state change falls through to the normal fetch.
    if (skipNextFetch) {
      skipNextFetch = false;
      return;
    }

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
    // Re-arm the dropdown whenever the query mutates — even if the
    // user has just blurred the input, fresh keystrokes should reopen
    // suggestions.
    suggestOpen = true;
  }

  // ── Typeahead state ─────────────────────────────────────────────────
  // Open while the search box has focus AND the user has typed at least
  // 2 chars. Closes on blur (after a tick to let dropdown clicks land),
  // on Esc, after picking a suggestion, or on result navigation.
  let suggestOpen = $state(false);
  let suggestRef: SuggestDropdown | undefined = $state();

  function handleSearchFocus(): void {
    suggestOpen = true;
  }
  function handleSearchBlur(): void {
    // Defer the close so a click on a suggestion item registers before
    // the dropdown is unmounted. The dropdown also blocks the parent
    // blur via preventBlur on mousedown — both belts make this robust.
    window.setTimeout(() => {
      suggestOpen = false;
    }, 120);
  }
  function handleSuggestClose(): void {
    suggestOpen = false;
  }
  function handleSuggestPickQuery(text: string): void {
    handleQueryChange(text);
    suggestOpen = false;
  }
  function handleSearchKeydown(e: KeyboardEvent): void {
    if (suggestRef?.handleKeydown(e)) {
      // Dropdown consumed it (arrow / enter / escape).
      return;
    }
  }

  // ── Mobile filter drawer ────────────────────────────────────────────
  // On wide screens the FacetPanel sits in a sticky left column.
  // On narrow screens it hides behind a "Filters" trigger and slides
  // in from the right inside the shared <Drawer> component — same
  // chrome (animation, backdrop, ESC, scroll lock) as the admin uses.
  //
  // matchMedia gives us a reactive boolean for "narrow viewport" so
  // the conditional render below picks the right shell. FacetPanel
  // re-mounts on a resize across the breakpoint; that's acceptable
  // because the only state worth preserving is FacetGroup's expand
  // memory, and resizing across breakpoints mid-session is rare.
  let filterDrawerOpen = $state(false);
  let isNarrow = $state(false);

  $effect(() => {
    if (typeof window === 'undefined') return;
    const mq = window.matchMedia('(max-width: 48rem)');
    const update = (): void => {
      isNarrow = mq.matches;
      // Desktop should never have the drawer "open" — close it
      // proactively on resize so the next narrow→wide→narrow cycle
      // doesn't snap an already-open overlay back into view.
      if (!mq.matches) filterDrawerOpen = false;
    };
    update();
    mq.addEventListener('change', update);
    return () => mq.removeEventListener('change', update);
  });

  function openFilterDrawer(): void {
    filterDrawerOpen = true;
  }
  function closeFilterDrawer(): void {
    filterDrawerOpen = false;
  }

  // Number of currently-active filters (categorical chips + year range).
  // Drives the badge on the mobile Filters trigger.
  const activeFilterCount = $derived(
    Object.values(filters).reduce((n, vs) => n + (vs?.length ?? 0), 0) + (yearRange ? 1 : 0),
  );

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

  /**
   * SSR'd `initial_response` is only safe to hydrate when its shape is
   * the one ResultsList expects (`hits` must be an array). A malformed
   * bootstrap — pre-0.2.5 SSR could emit a per-search-error envelope
   * via the bootstrap script — would otherwise blow up first render
   * with `Cannot read properties of undefined (reading 'length')`.
   * Pulled out of the script body so the read of `bootstrap.initial_response`
   * doesn't trigger Svelte's `state_referenced_locally` warning.
   */
  function deriveInitialResponse(bs: IwacBootstrap): IwacSearchResponse | null {
    return bs.initial_response && Array.isArray(bs.initial_response.hits)
      ? bs.initial_response
      : null;
  }
</script>

<div class="iwac-search" class:iwac-search--compact={bootstrap.mode === 'compact'}>
  {#if bootstrap.mode !== 'results-only'}
    <!--
      <form role="search"> is the canonical container for a search UI.
      onfocusin / onfocusout bubble (unlike onfocus / onblur), so the
      wrapper catches focus state for the inner <input> without
      SearchInput having to expose any event props. onkeydown does the
      same for arrow/enter/escape navigation in the SuggestDropdown.
      Submit is suppressed because the search runs reactively on every
      keystroke — Enter shouldn't reload the page.

      svelte-check flags listeners on a "non-interactive" form, but
      these are bubbling delegations from the inner <input> (which IS
      interactive), so the warning is a false positive here.
    -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form
      class="iwac-search__searchbox"
      role="search"
      onfocusin={handleSearchFocus}
      onfocusout={handleSearchBlur}
      onkeydown={handleSearchKeydown}
      onsubmit={(e) => e.preventDefault()}
    >
      <SearchInput
        value={query}
        placeholder="Rechercher dans les archives IWAC…"
        onChange={handleQueryChange}
      />
      <SuggestDropdown
        bind:this={suggestRef}
        {query}
        {client}
        enabled={suggestOpen}
        onPickQuery={handleSuggestPickQuery}
        onClose={handleSuggestClose}
      />
    </form>
  {/if}

  {#if error}
    <div class="iwac-search__error" role="alert">
      <strong>Search unavailable.</strong>
      <span>{error}</span>
    </div>
  {/if}

  {#if bootstrap.mode === 'full'}
    <div class="iwac-search__layout">
      {#if isNarrow}
        <!-- Narrow viewport: facets live behind the Filters trigger,
             rendered into the shared Drawer when opened. -->
        <Drawer
          open={filterDrawerOpen}
          onClose={closeFilterDrawer}
          title="Filters"
          side="right"
          width="min(22rem, 92vw)"
        >
          <div class="iwac-search__facets-body">
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
        </Drawer>
      {:else}
        <!-- Wide viewport: classic sticky left column. -->
        <aside class="iwac-search__facets-inline" aria-label="Filters">
          <FacetPanel
            {facets}
            selected={filters}
            {yearRange}
            onToggle={handleFacetToggle}
            onClearAll={handleClearAll}
            onClearField={handleClearField}
            onYearRangeChange={handleYearRangeChange}
          />
        </aside>
      {/if}

      <div class="iwac-search__results">
        <!-- Toolbar: result count + Filters trigger (mobile-only) + sort -->
        {#if response}
          <div class="iwac-search__results-head">
            <span class="iwac-search__results-count" aria-live="polite">
              {#if response.found > 0}
                {response.found.toLocaleString()}
                {response.found === 1 ? 'result' : 'results'}
              {:else}
                No results
              {/if}
            </span>
            <button
              type="button"
              class="iwac-search__filters-trigger"
              onclick={openFilterDrawer}
              aria-label="Open filters"
            >
              Filters
              {#if activeFilterCount > 0}
                <span class="iwac-search__filters-trigger-badge">{activeFilterCount}</span>
              {/if}
            </button>
            <SortSelect value={sort} onChange={handleSortChange} />
          </div>
        {/if}

        {#if isLoading && !response}
          <p class="iwac-search__status" aria-live="polite">Searching…</p>
        {:else if response && response.found === 0}
          <div class="iwac-search__empty" role="status">
            <strong>No results.</strong>
            {#if activeFilterCount > 0}
              <p>Try removing a filter or two.</p>
              <button type="button" class="iwac-search__clear-link" onclick={handleClearAll}>
                Clear all filters
              </button>
            {:else if query.trim() !== ''}
              <p>Try a broader query, or check your spelling.</p>
            {:else}
              <p>The corpus seems empty — please contact the site administrator.</p>
            {/if}
          </div>
        {:else if response}
          <ResultsList {response} onLoadMore={loadMore} {isLoading} />
        {/if}
      </div>
    </div>
  {:else if isLoading && !response}
    <p class="iwac-search__status" aria-live="polite">Searching…</p>
  {:else if response}
    <ResultsList {response} onLoadMore={loadMore} {isLoading} />
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
  /* Anchors the floating SuggestDropdown — must be positioned. */
  .iwac-search__searchbox {
    position: relative;
  }
  .iwac-search__layout {
    display: grid;
    grid-template-columns: minmax(16rem, 20rem) 1fr;
    gap: var(--space-lg, 1.5rem);
    align-items: start;
  }

  /*
   * Wide viewport: facets sit in a sticky left column.
   * Narrow viewport: column collapses; FacetPanel is rendered inside
   * the shared <Drawer>, no positioning needed here. The "Filters"
   * trigger button is hidden on wide and shown on narrow.
   */
  .iwac-search__facets-inline {
    position: sticky;
    top: var(--space-md, 1rem);
    align-self: start;
    max-height: calc(100vh - var(--space-xl, 2rem));
    overflow-y: auto;
  }
  .iwac-search__facets-body {
    /* Padding inside the drawer body. The drawer header already has
       its own padding from src/svelte-shared/components/Drawer.svelte. */
    padding: var(--space-md, 1rem);
  }
  .iwac-search__filters-trigger {
    display: none;
  }

  @media (max-width: 48rem) {
    .iwac-search__layout {
      grid-template-columns: 1fr;
    }
    .iwac-search__filters-trigger {
      display: inline-flex;
      align-items: center;
      gap: var(--space-xs, 0.25rem);
      padding: 0.4rem 0.75rem;
      border: 1px solid var(--border, #ccc);
      border-radius: var(--radius-md, 0.75rem);
      background: var(--surface, #fff);
      color: var(--ink, #222);
      font-size: var(--text-sm, 0.9rem);
      font-weight: 500;
      cursor: pointer;
    }
    .iwac-search__filters-trigger:hover {
      border-color: var(--primary, #c66);
      color: var(--primary, #c66);
    }
    .iwac-search__filters-trigger-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.25rem;
      height: 1.25rem;
      padding: 0 0.375rem;
      background: var(--primary, #c66);
      color: var(--surface, #fff);
      border-radius: var(--radius-full, 9999px);
      font-size: var(--text-xs, 0.75rem);
      font-weight: 600;
      font-variant-numeric: tabular-nums;
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
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    flex-wrap: wrap;
  }
  .iwac-search__results-count {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
    /* Push the trigger + sort to the end on a single line. */
    margin-inline-end: auto;
  }
  .iwac-search__empty {
    background: var(--surface-sunken, #f9f9f9);
    border: 1px dashed var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    padding: var(--space-xl, 2rem) var(--space-lg, 1.5rem);
    text-align: center;
    color: var(--muted, #666);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-search__empty strong {
    color: var(--ink, #222);
    font-size: var(--text-lg, 1.125rem);
  }
  .iwac-search__empty p {
    margin: 0;
  }
  .iwac-search__clear-link {
    background: none;
    border: 1px solid var(--primary, #c66);
    color: var(--primary, #c66);
    border-radius: var(--radius-md, 0.75rem);
    padding: 0.4rem 0.75rem;
    font-size: var(--text-sm, 0.9rem);
    cursor: pointer;
    margin-top: var(--space-xs, 0.25rem);
  }
  .iwac-search__clear-link:hover {
    background: var(--primary, #c66);
    color: var(--surface, #fff);
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
  .iwac-search__status {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
</style>

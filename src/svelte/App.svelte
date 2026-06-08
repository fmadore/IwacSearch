<script module lang="ts">
  /**
   * Match the `limit_hits` cap sent in lib/typesense.ts. Kept here so
   * ResultsList can build a pagination bar that never invites a click
   * past the deepest page Typesense will return.
   */
  const HITS_CAP = 250;
</script>

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
  import { normalizeCard, normalizeLocale, provideI18n } from './lib/i18n';
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
   *
   * Modularity note: this component is the orchestrator. It owns state
   * and wires events. The actual UI is in small focused components
   * (SearchInput, FacetPanel, ResultsList, Pagination, ResultItem,
   * SortSelect, …) so styling and behaviour stay scoped.
   */

  interface Props {
    bootstrap: IwacBootstrap;
    /**
     * Whether this App renders its own search box. The federated
     * /search/everything page owns one shared search box across its tabs,
     * so it mounts each tab's App with showSearchBox={false}. Standalone
     * surfaces (/search, page blocks) default to true.
     */
    showSearchBox?: boolean;
  }

  const { bootstrap, showSearchBox = true }: Props = $props();

  // Provide the locale + translator to the whole component subtree. Read
  // once at init from the server-detected bootstrap locale (defaults to
  // French). svelte-ignore: bootstrap is a prop, not reactive state.
  // svelte-ignore state_referenced_locally
  const { t } = provideI18n(normalizeLocale(bootstrap.locale), normalizeCard(bootstrap.card));

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
        // initial_query is set by the federated page so the tab seeds with
        // the shared query; empty on page blocks.
        q: bootstrap.initial_query ?? '',
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

  // Anchor element above the result list — page changes scroll back
  // to this so the new page lands at the top. Bound via bind:this on
  // the toolbar header below.
  let resultsAnchor: HTMLElement | null = $state(null);

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
    // Snapshot for the next diff. NOT structuredClone(next): `filters` and
    // `yearRange` are deep Svelte 5 reactive proxies, and structuredClone
    // throws DataCloneError on a proxy. Build a plain deep copy by hand so
    // the clone is guaranteed serialisable and proxy-free.
    prevState = {
      q: next.q,
      page: next.page,
      sort: next.sort,
      filters: Object.fromEntries(Object.entries(next.filters).map(([k, v]) => [k, [...v]])),
      yearRange: next.yearRange ? { ...next.yearRange } : null,
    };
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

  // ARIA combobox wiring. block_id is unique per mount, so the listbox id (and
  // the option ids derived from it) never collide when several search surfaces
  // share a page. suggestActiveId is mirrored up from the dropdown so the input
  // can set aria-activedescendant; suggestExpanded matches the dropdown's own
  // render condition (open AND ≥2 chars typed).
  const suggestListboxId = $derived(`iwac-suggest-${bootstrap.block_id}`);
  let suggestActiveId = $state<string | null>(null);
  const suggestExpanded = $derived(suggestOpen && query.trim().length >= 2);

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
  // Enter (or clicking the "Search for …" row) runs a full-text search for
  // exactly what was typed — no need to pick a suggestion. The search runs
  // reactively off `query`; this just commits the text and closes the
  // dropdown so the user lands on results.
  function handleSuggestRunSearch(text: string): void {
    if (text !== query) {
      query = text;
      page = 1;
    }
    suggestOpen = false;
  }
  // Picking an entity (place / topic / person / organisation) applies it as
  // a facet filter and clears the free-text query, so the user sees every
  // document tagged with that entity within the current scope.
  function handleSuggestPickEntity(field: string, value: string): void {
    query = '';
    suggestOpen = false;
    handleFacetToggle(field, value, true);
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

  /**
   * Pagination handler. Sets the page state (which kicks the search
   * effect) and scrolls back to the toolbar so the user lands at the
   * top of the new page rather than midway through the previous one.
   *
   * Smooth scroll is opt-in via prefers-reduced-motion: callers who
   * disable motion still get the navigation, just instantly.
   */
  function handlePageChange(next: number): void {
    if (next === page) return;
    page = next;
    if (resultsAnchor) {
      const reduced =
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      resultsAnchor.scrollIntoView({
        behavior: reduced ? 'auto' : 'smooth',
        block: 'start',
      });
    }
  }

  const facets = $derived(response?.facet_counts ?? []);
  const perPage = $derived(response?.request_params?.per_page ?? bootstrap.results_per_page ?? 20);
  const searchTimeMs = $derived(response?.search_time_ms ?? 0);

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
  {#if showSearchBox && bootstrap.mode !== 'results-only'}
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
        placeholder={t('search_placeholder')}
        onChange={handleQueryChange}
        listboxId={suggestListboxId}
        expanded={suggestExpanded}
        activeDescendant={suggestActiveId}
      />
      <SuggestDropdown
        bind:this={suggestRef}
        {query}
        {client}
        enabled={suggestOpen}
        listboxId={suggestListboxId}
        onActiveChange={(id) => (suggestActiveId = id)}
        onPickQuery={handleSuggestPickQuery}
        onRunSearch={handleSuggestRunSearch}
        onPickEntity={handleSuggestPickEntity}
        onClose={handleSuggestClose}
      />
    </form>
  {/if}

  {#if error}
    <div class="iwac-search__error" role="alert">
      <strong>{t('search_unavailable')}</strong>
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
          title={t('filters')}
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
        <aside class="iwac-search__facets-inline" aria-label={t('filters')}>
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

      <div class="iwac-search__results" aria-busy={isLoading}>
        <!-- Toolbar: result count + Filters trigger (mobile-only) + sort -->
        {#if response}
          <header class="iwac-search__toolbar" bind:this={resultsAnchor} aria-live="polite">
            <div class="iwac-search__count-block">
              {#if response.found > 0}
                <span class="iwac-search__count-number">
                  {response.found.toLocaleString()}
                </span>
                <span class="iwac-search__count-label">
                  {response.found === 1 ? t('result_one') : t('result_other')}
                </span>
                {#if searchTimeMs > 0}
                  <span class="iwac-search__count-timing">
                    · {searchTimeMs} ms
                  </span>
                {/if}
              {:else}
                <span class="iwac-search__count-label iwac-search__count-label--empty">
                  {t('no_results_short')}
                </span>
              {/if}
            </div>
            <div class="iwac-search__toolbar-actions">
              <button
                type="button"
                class="iwac-search__filters-trigger"
                onclick={openFilterDrawer}
                aria-label={t('open_filters')}
              >
                {t('filters')}
                {#if activeFilterCount > 0}
                  <span class="iwac-search__filters-trigger-badge">{activeFilterCount}</span>
                {/if}
              </button>
              <SortSelect value={sort} onChange={handleSortChange} />
            </div>
          </header>
        {/if}

        {#if isLoading && !response}
          <p class="iwac-search__status" aria-live="polite">{t('searching')}</p>
        {:else if response && response.found === 0}
          <div class="iwac-search__empty" role="status">
            <strong>{t('no_results_title')}</strong>
            {#if activeFilterCount > 0}
              <p>{t('try_removing_filter')}</p>
              <button type="button" class="iwac-search__clear-link" onclick={handleClearAll}>
                {t('clear_all_filters')}
              </button>
            {:else if query.trim() !== ''}
              <p>{t('try_broader_query')}</p>
            {:else}
              <p>{t('corpus_empty')}</p>
            {/if}
          </div>
        {:else if response}
          <ResultsList {response} {perPage} hitsCap={HITS_CAP} onPageChange={handlePageChange} />
        {/if}
      </div>
    </div>
  {:else if isLoading && !response}
    <p class="iwac-search__status" aria-live="polite">{t('searching')}</p>
  {:else if response}
    <ResultsList {response} {perPage} hitsCap={HITS_CAP} onPageChange={handlePageChange} />
  {/if}
</div>

<style>
  .iwac-search {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #2c2f37);
    font-size: var(--text-base, 1.0625rem);
  }
  /* Anchors the floating SuggestDropdown — must be positioned. */
  .iwac-search__searchbox {
    position: relative;
  }
  .iwac-search__layout {
    display: grid;
    grid-template-columns: minmax(15rem, 18rem) 1fr;
    gap: var(--space-xl, 2rem);
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
    /* The single scroll container for filters. Facet groups no longer
       scroll individually (see FacetGroup .iwac-facet__list), so this is
       the only scrollbar in the sidebar — and it only appears when the
       collapsed facet column is taller than the viewport. Thin + stable
       gutter keeps it from crowding the divider. */
    max-height: calc(100vh - var(--space-xl, 2rem));
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-gutter: stable;
    /* Subtle right "rail" so the column has a visual edge against the
       results without becoming a card. */
    padding-inline-end: var(--space-md, 1rem);
    border-inline-end: 1px solid var(--border-light, #e6e7eb);
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
      gap: var(--space-md, 1rem);
    }
    .iwac-search__filters-trigger {
      display: inline-flex;
      align-items: center;
      gap: var(--space-xs, 0.25rem);
      height: var(--size-control-md, 2.5rem);
      padding-inline: var(--space-md, 1rem);
      border: 1px solid var(--border, #d4d6da);
      border-radius: var(--radius-md, 0.5rem);
      background: var(--surface, #fdfdfd);
      color: var(--ink, #2c2f37);
      box-shadow: none;
      font-size: var(--text-sm, 0.9375rem);
      font-weight: 500;
      cursor: pointer;
      transition:
        border-color var(--transition-fast, 150ms ease),
        color var(--transition-fast, 150ms ease);
    }
    .iwac-search__filters-trigger:hover {
      background: var(--surface, #fdfdfd);
      border-color: var(--primary, #e64a19);
      color: var(--primary, #e64a19);
      box-shadow: none;
      transform: none;
    }
    .iwac-search__filters-trigger:focus-visible {
      outline: none;
      box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    }
    .iwac-search__filters-trigger-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.25rem;
      height: 1.25rem;
      padding: 0 0.375rem;
      background: var(--primary, #e64a19);
      color: var(--white, #fff);
      border-radius: var(--radius-full, 9999px);
      font-size: var(--text-xs, 0.8125rem);
      font-weight: 600;
      font-variant-numeric: tabular-nums;
    }
    /*
     * Toolbar stacks on narrow viewports: the result count takes the
     * first line on its own (so "· 34 ms" never gets squeezed off it),
     * and the actions drop to a second line — Filters pinned left, Sort
     * pushed right. Without this the count-block (flex: 1; min-width: 0)
     * shrinks to share the line with the buttons and its text wraps
     * behind them.
     */
    .iwac-search__count-block {
      flex-basis: 100%;
    }
    .iwac-search__toolbar-actions {
      width: 100%;
      justify-content: space-between;
    }
  }

  .iwac-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0; /* allow snippet wrap */
    /* When a paged search is in flight, dim the list slightly so the
       user gets feedback without losing scroll position. The bar itself
       is provided by ResultsList — this is just a passive cue. */
    transition: opacity var(--transition-base, 200ms ease);
  }
  .iwac-search__results[aria-busy='true'] {
    opacity: 0.65;
  }
  .iwac-search__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md, 1rem);
    flex-wrap: wrap;
    /* Anchor under the search box — gives pagination something to
       scroll back to. */
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-search__count-block {
    display: inline-flex;
    align-items: baseline;
    gap: 0.375rem;
    color: var(--muted, #767880);
    font-size: var(--text-sm, 0.9375rem);
    font-variant-numeric: tabular-nums;
    flex: 1;
    min-width: 0;
  }
  .iwac-search__count-number {
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-size: var(--text-lg, 1.1875rem);
    font-weight: 700;
  }
  .iwac-search__count-label {
    font-weight: 500;
  }
  .iwac-search__count-label--empty {
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-weight: 600;
  }
  .iwac-search__count-timing {
    color: var(--muted, #767880);
    font-size: var(--text-xs, 0.8125rem);
    /* Keep "· 34 ms" as one unit — never let it break to its own line. */
    white-space: nowrap;
  }
  .iwac-search__toolbar-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    /* Don't let the actions get squeezed; they wrap to their own line
       below the count on narrow viewports (see the media query). */
    flex-shrink: 0;
  }

  .iwac-search__empty {
    background: var(--surface-sunken, #f3f3f1);
    border: 1px dashed var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    text-align: center;
    color: var(--muted, #767880);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-search__empty strong {
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-size: var(--text-lg, 1.1875rem);
  }
  .iwac-search__empty p {
    margin: 0;
  }
  .iwac-search__clear-link {
    background: none;
    border: 1px solid var(--primary, #e64a19);
    color: var(--primary, #e64a19);
    border-radius: var(--radius-md, 0.5rem);
    padding: 0.4rem 0.75rem;
    box-shadow: none;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    margin-top: var(--space-xs, 0.25rem);
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-search__clear-link:hover {
    background: var(--primary, #e64a19);
    color: var(--white, #fff);
    box-shadow: none;
    transform: none;
  }
  .iwac-search__clear-link:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-search__error {
    background: color-mix(in oklab, var(--error, #c0392b) 12%, var(--surface, #fdfdfd));
    border: 1px solid color-mix(in oklab, var(--error, #c0392b) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    color: var(--ink-strong, var(--ink, #2c2f37));
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-search__status {
    color: var(--muted, #767880);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>

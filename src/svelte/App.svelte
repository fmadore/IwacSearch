<script lang="ts">
  import type {
    ActiveFilters,
    EntitySuggestion,
    IwacBootstrap,
    IwacDoc,
    IwacFacetCount,
    IwacSearchResponse,
    SearchState,
    YearBucket,
    YearRange,
  } from './lib/types';
  import { TypesenseClient } from './lib/typesense';
  import { isAbortError } from './lib/transport';
  import { onUrlPop, readUrlState, syncToUrl } from './lib/urlState';
  import { facetLabel, normalizeCard, normalizeLocale, provideI18n } from './lib/i18n';
  import { createViewMode } from './lib/viewMode.svelte';
  import { createFilterDrawer } from './lib/filterDrawer.svelte';
  import { recordSearch } from './lib/searchHistory';
  import type { ActiveFilterChip } from './lib/filterChips';
  import SearchInput from './components/SearchInput.svelte';
  import SuggestDropdown from './components/SuggestDropdown.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import ResultSummary from './components/ResultSummary.svelte';
  import ResultSkeleton from './components/ResultSkeleton.svelte';
  import ResultsEmpty from './components/ResultsEmpty.svelte';
  import ViewToggle from './components/ViewToggle.svelte';
  import MapView from './components/MapView.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
  import SortSelect from './components/SortSelect.svelte';
  import ExportMenu from './components/ExportMenu.svelte';
  import Icon from './components/Icon.svelte';
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
   * SortSelect, …); cross-cutting state mechanics live in composables
   * (viewMode.svelte.ts, filterDrawer.svelte.ts) so styling and
   * behaviour stay scoped.
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
  const { t, card, locale } = provideI18n(
    normalizeLocale(bootstrap.locale),
    normalizeCard(bootstrap.card),
  );

  const isStandalone = $derived(String(bootstrap.block_id) === 'standalone');

  // URL ↔ state sync. The standalone /search route uses bare, shareable
  // params; full-mode page blocks namespace their params by block id so
  // several search blocks on one page (and the host page's own ?page=/?q=)
  // never collide. Federated inner apps (showSearchBox=false, they own ?q/?tab
  // via FederatedApp) and compact/results-only blocks (no facet panel) don't
  // sync — they keep state in memory.
  const syncUrl = $derived(isStandalone || (showSearchBox && bootstrap.mode === 'full'));
  const urlPrefix = $derived(isStandalone ? '' : `b${bootstrap.block_id}.`);

  // TypesenseClient caches a scoped key, so we want exactly one per
  // mount. $derived.by reruns only if bootstrap changes, which it
  // doesn't post-mount — same effect as `const`, but satisfies
  // svelte-check's reactivity rules.
  const client = $derived.by(() => new TypesenseClient(bootstrap));

  // Initial state — hydrated from the URL on every surface that syncs
  // (standalone /search and full-mode page blocks, each via its own prefix),
  // empty otherwise. `syncUrl`, `urlPrefix` and `bootstrap` are read once at
  // mount; svelte-check warns because the read isn't reactive, but none can
  // change post-mount (bootstrap is server-emitted, the rest derive from it).
  // svelte-ignore state_referenced_locally
  const initial: SearchState = syncUrl
    ? readUrlState(window.location.href, urlPrefix)
    : {
        // initial_query / initial_filters are set by the federated page so
        // the tab seeds with the shared query (and any filter handed off
        // from the union tab); empty on page blocks.
        q: bootstrap.initial_query ?? '',
        page: 1,
        sort: bootstrap.default_sort || '_text_match:desc',
        filters: bootstrap.initial_filters ?? {},
        yearRange: null,
        view: 'list',
      };

  let query = $state(initial.q);
  let page = $state(initial.page);
  let sort = $state(initial.sort);
  let filters = $state<ActiveFilters>(initial.filters);
  let yearRange = $state<YearRange | null>(initial.yearRange);

  // ── Result presentation (List / Gallery / Map) ───────────────────────
  // Content surfaces offer the image-forward Gallery (design review §01);
  // the entity index offers the geo Map instead. Resolution rules (URL wins
  // → sticky localStorage → default) + the one-shot gallery auto-suggest
  // live in the composable.
  // svelte-ignore state_referenced_locally
  const view = createViewMode({
    modes: card === 'entity' ? (['list', 'map'] as const) : (['list', 'gallery'] as const),
    syncUrl,
    urlPrefix,
    initialView: initial.view,
  });

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

  // Year-distribution histogram data for the date slider. Fetched separately
  // from the results (see the effect below) because it must ignore the year
  // range to show the full span; empty until the first fetch resolves, and on
  // any surface without a facet panel.
  let yearDistribution = $state<YearBucket[]>([]);

  // "Did you mean" candidates for a zero-result query: entity suggestions
  // fetched through the typo-tolerant suggest path (facet_query + the alias
  // index), so a near-miss spelling ("Tidjaniya") can offer the canonical
  // entity. Rendered above the empty state; picking one applies the filter.
  let didYouMean = $state<EntitySuggestion[]>([]);

  // Geo-tagged docs for the Map view (entity surfaces). Fetched when the
  // map is active and the query/filter state changes.
  let mapDocs = $state<IwacDoc[]>([]);
  let mapLoading = $state(false);

  // First $effect run skips its fetch when the live state matches the
  // state the server used for SSR (empty q, page 1, default sort, no
  // filters, no year range). Any URL-hydrated non-pristine state (e.g.
  // /search?q=ramadan or ?sort=date:asc) triggers a real fetch so the user
  // sees what they asked for, not the "browse everything" SSR snapshot.
  // The sort check matters: the snapshot was built with the bootstrap
  // default sort, so a sorted share link must not reuse it. Plain `let`
  // (not $state) — reading it inside the effect doesn't create a
  // reactive dependency, so mutating it doesn't re-trigger the effect.
  // The bootstrap.default_sort read is deliberately non-reactive too —
  // bootstrap is server-emitted and never changes post-mount.
  // svelte-ignore state_referenced_locally
  let skipNextFetch =
    initialResponse != null &&
    initial.q === '' &&
    initial.page === 1 &&
    initial.sort === (bootstrap.default_sort || '_text_match:desc') &&
    Object.keys(initial.filters).length === 0 &&
    initial.yearRange === null;

  // Previous snapshot for URL-sync diffing (pushState vs replaceState).
  let prevState: SearchState | null = null;

  // Anchor element above the result list — page changes scroll back
  // to this so the new page lands at the top. Bound via bind:this on
  // the toolbar header below.
  let resultsAnchor: HTMLElement | null = $state(null);

  // Push state → URL whenever anything observable changes. `view` only goes to
  // the URL when explicitly chosen — an auto-suggested gallery stays a session
  // hint and never mutates a shared link.
  $effect(() => {
    if (!syncUrl) return;
    const next: SearchState = {
      q: query,
      page,
      sort,
      filters,
      yearRange,
      view: view.explicit ? view.mode : 'list',
    };
    syncToUrl(next, prevState, urlPrefix);
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
      view: next.view,
    };
  });

  // Back / forward → re-hydrate state from URL.
  $effect(() => {
    if (!syncUrl) return;
    return onUrlPop((s) => {
      query = s.q;
      page = s.page;
      sort = s.sort;
      filters = s.filters;
      yearRange = s.yearRange;
      view.applyPop(s.view);
    }, urlPrefix);
  });

  // Auto-suggest Gallery once, on the first response, when the set is
  // image-heavy and the user hasn't explicitly chosen a view (design review
  // §01). Never overrides an explicit choice; doesn't persist (session hint).
  $effect(() => {
    const r = response;
    if (r) view.autoSuggest(r);
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
    didYouMean = [];
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
        if (q.trim() !== '') {
          if (r.found > 0) {
            // Only fruitful queries enter the recent-searches history, so
            // typo dead-ends don't pollute the dropdown.
            recordSearch(q);
          } else if (q.trim().length >= 3) {
            fetchDidYouMean(q);
          }
        }
        isLoading = false;
      })
      .catch((e: unknown) => {
        // A superseded (aborted) request means a newer one is already in
        // flight — its .then/.catch will settle the UI state; touching
        // isLoading here would blank the spinner under the live request.
        if (isAbortError(e)) return;
        console.error('[iwac-search] search failed', e);
        error = e instanceof Error ? e.message : String(e);
        // Keep stale response visible on error? No — show the error
        // explicitly so the user doesn't think filters succeeded.
        response = null;
        isLoading = false;
      });
  });

  /**
   * Zero-result recovery: ask the typo-tolerant suggest path (facet_query +
   * the alias-reconciling entity index) for entities near the dead query.
   * Best-effort — failures (including aborts) just mean no banner.
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

  // Year-distribution histogram. Tracks ONLY the query + categorical filters
  // — not page, sort, or the year range — because the bars show the full span
  // regardless of the selected window (dragging the slider just repaints which
  // bars are highlighted, no refetch). One cheap counts-only request; only the
  // 'full' mode renders the slider, so other modes skip it. Failures degrade
  // to no bars (the slider still works).
  $effect(() => {
    if (bootstrap.mode !== 'full') return;
    const q = query;
    const f = filters;
    client
      .yearDistribution({ q, activeFilters: f })
      .then((d) => {
        yearDistribution = d;
      })
      .catch((e: unknown) => {
        if (isAbortError(e)) return; // superseded — newer histogram in flight
        console.warn('[iwac-search] year distribution failed', e);
        yearDistribution = [];
      });
  });

  // Map data: when the Map view is active, fetch every geo-tagged entity
  // matching the current query + filters (year range included — unlike the
  // histogram, the map should reflect the selected window).
  //
  // Sequence-guarded: fetchForMap is a multi-page loop with no
  // AbortController, so without the guard two quick filter toggles could
  // let the slower (stale) loop finish last and overwrite the newer
  // markers.
  let mapSeq = 0;
  $effect(() => {
    if (view.mode !== 'map') return;
    const q = query;
    const f = filters;
    const y = yearRange;
    const seq = ++mapSeq;
    mapLoading = true;
    client
      .fetchForMap({ q, activeFilters: f, yearRange: y })
      .then((docs) => {
        if (seq !== mapSeq) return; // superseded — newer fetch in flight
        mapDocs = docs;
        mapLoading = false;
      })
      .catch((e: unknown) => {
        if (seq !== mapSeq) return;
        console.warn('[iwac-search] map fetch failed', e);
        mapDocs = [];
        mapLoading = false;
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
  // Open while the search box has focus. With ≥2 chars typed it shows
  // suggestions; empty it shows recent searches. Closes on blur (after a
  // tick to let dropdown clicks land), on Esc, after picking a suggestion,
  // or on result navigation.
  let suggestOpen = $state(false);
  let suggestRef: SuggestDropdown | undefined = $state();

  // ARIA combobox wiring. block_id is unique per mount, so the listbox id (and
  // the option ids derived from it) never collide when several search surfaces
  // share a page. suggestActiveId is mirrored up from the dropdown so the input
  // can set aria-activedescendant; suggestExpanded matches the dropdown's own
  // render condition.
  const suggestListboxId = $derived(`iwac-suggest-${bootstrap.block_id}`);
  let suggestActiveId = $state<string | null>(null);
  const suggestExpanded = $derived(suggestOpen && suggestActiveId !== null);

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

  // ── "/" keyboard shortcut ───────────────────────────────────────────
  // Slash focuses the search box from anywhere on the page (the GitHub /
  // Wikipedia convention), unless the user is already typing somewhere.
  let searchFormEl: HTMLFormElement | null = $state(null);

  $effect(() => {
    if (!showSearchBox) return;
    const onKeydown = (e: KeyboardEvent): void => {
      if (e.key !== '/' || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
      const target = e.target as HTMLElement | null;
      if (
        target &&
        (target.isContentEditable ||
          target.tagName === 'INPUT' ||
          target.tagName === 'TEXTAREA' ||
          target.tagName === 'SELECT')
      ) {
        return;
      }
      const input = searchFormEl?.querySelector('input');
      if (input) {
        e.preventDefault();
        input.focus();
        input.select();
      }
    };
    window.addEventListener('keydown', onKeydown);
    return () => window.removeEventListener('keydown', onKeydown);
  });

  // ── Copy link ───────────────────────────────────────────────────────
  // The URL mirrors the full search state on syncing surfaces, so "copy
  // link" is just the address — the button saves the trip to the URL bar
  // and confirms the copy. Transient label swap instead of a toast.
  let linkCopied = $state(false);
  let linkCopiedTimer: number | null = null;

  function handleCopyLink(): void {
    const url = window.location.href;
    const confirm = (): void => {
      linkCopied = true;
      if (linkCopiedTimer !== null) window.clearTimeout(linkCopiedTimer);
      linkCopiedTimer = window.setTimeout(() => {
        linkCopied = false;
      }, 2000);
    };
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).then(confirm, () => fallbackCopy(url) && confirm());
    } else if (fallbackCopy(url)) {
      confirm();
    }
  }

  /** execCommand fallback for non-secure contexts / older WebViews. */
  function fallbackCopy(text: string): boolean {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch {
      return false;
    }
  }

  // ── Mobile filter drawer ────────────────────────────────────────────
  // Composable owns the matchMedia listener + open/close state; the
  // conditional render below picks the sticky column vs the Drawer.
  const drawer = createFilterDrawer();
  $effect(() => drawer.attach());

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

  /**
   * Server-side facet-value search. Lets a FacetGroup find values beyond the
   * top-50 the main response carries (e.g. an author not in the first 50 on
   * the references surface). Scoped to the live query + filters + year range
   * so the counts match the current result set. Returns a promise the
   * FacetGroup awaits; errors propagate for it to surface.
   */
  function handleFacetSearch(field: string, text: string): Promise<IwacFacetCount[]> {
    return client.searchFacetValues({
      field,
      query: text,
      q: query,
      activeFilters: filters,
      yearRange,
    });
  }

  /**
   * Export fetch: the CURRENT result set (query + filters + year range +
   * sort + locked scope), capped inside fetchForExport. Passed to the
   * ExportMenu, which serializes and downloads client-side.
   */
  function handleExportFetch(): ReturnType<TypesenseClient['fetchForExport']> {
    return client.fetchForExport({
      q: query,
      sortBy: sort,
      activeFilters: filters,
      yearRange,
    });
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

  /**
   * Remove one active-filter chip from the summary strip / empty state. The
   * year chip clears the range; every other chip toggles its facet value off.
   */
  function handleRemoveChip(chip: ActiveFilterChip): void {
    if (chip.kind === 'year') {
      handleYearRangeChange(null);
    } else {
      handleFacetToggle(chip.field, chip.value, false);
    }
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
  // Single-country scopes hide the (redundant) country chip on result cards.
  const hideCountry = $derived(bootstrap.hide_country ?? false);

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
      bind:this={searchFormEl}
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
      {#if drawer.isNarrow}
        <!-- Narrow viewport: facets live behind the Filters trigger,
             rendered into the shared Drawer when opened. -->
        <Drawer
          open={drawer.open}
          onClose={() => drawer.close()}
          title={t('filters')}
          side="right"
          width="min(22rem, 92vw)"
        >
          <div class="iwac-search__facets-body">
            <FacetPanel
              {facets}
              selected={filters}
              {yearRange}
              distribution={yearDistribution}
              onToggle={handleFacetToggle}
              onClearAll={handleClearAll}
              onClearField={handleClearField}
              onYearRangeChange={handleYearRangeChange}
              onFacetSearch={handleFacetSearch}
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
            distribution={yearDistribution}
            onToggle={handleFacetToggle}
            onClearAll={handleClearAll}
            onClearField={handleClearField}
            onYearRangeChange={handleYearRangeChange}
            onFacetSearch={handleFacetSearch}
          />
        </aside>
      {/if}

      <div class="iwac-search__results" aria-busy={isLoading}>
        {#if response}
          <!-- Result controls. On desktop one row: view toggle (left), then
               copy-link + export + sort (right). On a phone two rows: [view ·
               filters · actions] then a full-width sort, so the bar reads as a
               tidy unit instead of three cramped stacked rows. -->
          <div class="iwac-search__controls" bind:this={resultsAnchor}>
            <div class="iwac-search__controls-bar">
              {#if view.supportsToggle}
                <ViewToggle value={view.mode} modes={view.modes} onChange={(m) => view.set(m)} />
              {/if}
              <div class="iwac-search__controls-actions">
                <button
                  type="button"
                  class="iwac-search__filters-trigger"
                  onclick={() => drawer.show()}
                  aria-label={t('open_filters')}
                >
                  <span class="iwac-search__filters-trigger-icon" aria-hidden="true">
                    <Icon name="filter" />
                  </span>
                  <span class="iwac-search__filters-trigger-label">{t('filters')}</span>
                  {#if activeFilterCount > 0}
                    <span class="iwac-search__filters-trigger-badge">{activeFilterCount}</span>
                  {/if}
                </button>
                {#if syncUrl}
                  <button
                    type="button"
                    class="iwac-search__copylink"
                    class:is-copied={linkCopied}
                    onclick={handleCopyLink}
                    aria-label={t('copy_link')}
                  >
                    <span class="iwac-search__copylink-icon" aria-hidden="true">
                      <Icon name="link" />
                    </span>
                    <span class="iwac-search__copylink-label">
                      {linkCopied ? t('link_copied') : t('copy_link')}
                    </span>
                  </button>
                {/if}
                {#if card === 'content' && response.found > 0}
                  <ExportMenu fetchDocs={handleExportFetch} {query} found={response.found} />
                {/if}
              </div>
            </div>
            <SortSelect value={sort} onChange={handleSortChange} />
          </div>

          <!-- Persistent count + scope + sort summary, visible on every
               viewport (the mobile filter readout). Closed by a 2px ink rule. -->
          {#if response.found > 0}
            <ResultSummary
              found={response.found}
              {searchTimeMs}
              {filters}
              {yearRange}
              {sort}
              onRemoveChip={handleRemoveChip}
              onClearAll={handleClearAll}
            />
          {/if}
        {/if}

        {#if view.mode === 'map'}
          <!-- The map owns its own loading/empty states and reflects the
               live query + filters; the summary strip above still shows
               the textual result count. -->
          <MapView docs={mapDocs} loading={mapLoading} />
        {:else if isLoading}
          <!-- Galley-proof skeleton in the active view (replaces the opacity
               dim) — holds geometry so the page doesn't jump (§03A). -->
          <ResultSkeleton view={view.mode} count={Math.min(Math.max(perPage, 4), 8)} />
        {:else if response && response.found === 0}
          {#if didYouMean.length > 0}
            <div class="iwac-search__didyoumean" role="status">
              <span class="iwac-search__didyoumean-label">{t('did_you_mean')}</span>
              {#each didYouMean as s (s.field + s.value)}
                <button
                  type="button"
                  class="iwac-search__didyoumean-chip"
                  onclick={() => handleSuggestPickEntity(s.field, s.value)}
                >
                  {s.value}
                  <span class="iwac-search__didyoumean-tag">{facetLabel(s.field, locale)}</span>
                </button>
              {/each}
            </div>
          {/if}
          <ResultsEmpty
            {filters}
            {yearRange}
            {query}
            onRemoveChip={handleRemoveChip}
            onClearAll={handleClearAll}
          />
        {:else if response}
          <ResultsList
            {response}
            {perPage}
            onPageChange={handlePageChange}
            activeFilters={filters}
            onFacetToggle={handleFacetToggle}
            {hideCountry}
            view={view.mode}
          />
        {/if}
      </div>
    </div>
  {:else if isLoading && !response}
    <p class="iwac-search__status" aria-live="polite">{t('searching')}</p>
  {:else if response}
    <ResultsList
      {response}
      {perPage}
      onPageChange={handlePageChange}
      activeFilters={filters}
      onFacetToggle={handleFacetToggle}
      {hideCountry}
    />
  {/if}
</div>

<style>
  .iwac-search {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #13161c);
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
    border-inline-end: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-search__facets-body {
    /* Padding inside the drawer body. The drawer header already has
       its own padding from src/svelte-shared/components/Drawer.svelte. */
    padding: var(--space-md, 1rem);
  }
  .iwac-search__filters-trigger {
    display: none;
  }

  /*
   * Copy-link — quiet outlined control matching the toolbar vocabulary.
   * Swaps its label to a confirmation for 2 s after a successful copy.
   */
  .iwac-search__copylink {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-md, 1rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-search__copylink:hover {
    background: var(--surface, #fdfcfb);
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    box-shadow: none;
    transform: none;
  }
  .iwac-search__copylink:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-search__copylink.is-copied {
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
  }
  .iwac-search__copylink-icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.9em;
    color: var(--muted, #66696e);
  }
  .iwac-search__copylink:hover .iwac-search__copylink-icon,
  .iwac-search__copylink.is-copied .iwac-search__copylink-icon {
    color: var(--primary, #ce4115);
  }

  /*
   * "Did you mean" banner on zero-result queries: entity chips from the
   * typo-tolerant suggest path; picking one applies it as a filter.
   */
  .iwac-search__didyoumean {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-sm, 0.5rem);
    padding: var(--space-sm, 0.5rem) 0;
  }
  .iwac-search__didyoumean-label {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-search__didyoumean-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    padding: 0.25rem var(--space-sm, 0.5rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-search__didyoumean-chip:hover,
  .iwac-search__didyoumean-chip:focus-visible {
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    background: var(--surface, #fdfcfb);
    box-shadow: none;
    transform: none;
    outline: none;
  }
  .iwac-search__didyoumean-tag {
    font-size: var(--text-xs, 0.8125rem);
    color: var(--muted, #66696e);
    background: var(--surface-sunken, #f4f1ef);
    padding: 0.0625rem 0.375rem;
    border-radius: var(--radius-full, 9999px);
  }

  @media (max-width: 48rem) {
    .iwac-search__layout {
      grid-template-columns: 1fr;
      gap: var(--space-md, 1rem);
    }

    /*
     * Two tidy rows on a phone: [view · filters · actions] on top, then a
     * full-width sort row. Stacking the controls (column) bounds the sort row
     * to the viewport so its <select> can't overflow.
     */
    .iwac-search__controls {
      flex-direction: column;
      align-items: stretch;
      gap: var(--space-sm, 0.5rem);
    }
    .iwac-search__controls-bar {
      width: 100%;
    }
    .iwac-search__controls :global(.iwac-sort) {
      display: flex;
      width: 100%;
    }
    .iwac-search__controls :global(.iwac-sort__select) {
      flex: 1 1 auto;
      min-width: 0;
    }

    /* Comfortable 44px touch targets across the whole bar. */
    .iwac-search__filters-trigger,
    .iwac-search__copylink,
    .iwac-search__controls :global(.iwac-view__btn),
    .iwac-search__controls :global(.iwac-export__trigger),
    .iwac-search__controls :global(.iwac-sort__select) {
      height: var(--size-control-lg, 2.75rem);
    }

    /*
     * Filters trigger — outlined, icon-forward (funnel + label + count). Hidden
     * on desktop, where filters live in the sticky sidebar; here it opens the
     * drawer, so it's the most important control on the row and keeps its label
     * longest (collapses to the funnel only on the narrowest phones below).
     */
    .iwac-search__filters-trigger {
      display: inline-flex;
      align-items: center;
      gap: var(--space-xs, 0.25rem);
      padding-inline: var(--space-md, 1rem);
      border: 1px solid var(--border, #ced1d6);
      border-radius: var(--radius-md, 0.5rem);
      background: var(--surface, #fdfcfb);
      color: var(--ink, #13161c);
      box-shadow: none;
      font-size: var(--text-sm, 0.9375rem);
      font-weight: 500;
      cursor: pointer;
      transition:
        border-color var(--transition-fast, 150ms ease),
        color var(--transition-fast, 150ms ease);
    }
    .iwac-search__filters-trigger:hover {
      background: var(--surface, #fdfcfb);
      border-color: var(--primary, #ce4115);
      color: var(--primary, #ce4115);
      box-shadow: none;
      transform: none;
    }
    .iwac-search__filters-trigger:focus-visible {
      outline: none;
      box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    }
    .iwac-search__filters-trigger-icon {
      display: inline-flex;
      align-items: center;
      font-size: 0.9em;
      color: var(--muted, #66696e);
    }
    .iwac-search__filters-trigger:hover .iwac-search__filters-trigger-icon {
      color: var(--primary, #ce4115);
    }
    .iwac-search__filters-trigger-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.25rem;
      height: 1.25rem;
      padding: 0 0.375rem;
      background: var(--primary, #ce4115);
      color: var(--white, #fff);
      border-radius: var(--radius-full, 9999px);
      font-size: var(--text-xs, 0.8125rem);
      font-weight: 600;
      font-variant-numeric: tabular-nums;
    }
  }

  /*
   * Smallest phones: the bar goes fully icon-forward — the view toggle and the
   * Export trigger already drop their labels at this breakpoint, so the Filters
   * and copy-link labels follow (icons stay; the buttons keep their aria-labels).
   */
  @media (max-width: 26rem) {
    .iwac-search__filters-trigger,
    .iwac-search__copylink {
      padding-inline: var(--space-sm, 0.5rem);
    }
    .iwac-search__filters-trigger-label,
    .iwac-search__copylink-label {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
      border: 0;
    }
  }

  .iwac-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0; /* allow snippet wrap */
    /* No opacity dim while paging — the ResultSkeleton provides the loading
       feedback now, keeping row geometry stable (punch-list item 2). aria-busy
       stays on the container for assistive tech. */
  }
  /*
   * Result controls. Desktop: one row — the view toggle sits at the left of a
   * growing bar that pushes export + sort to the right. Mobile: the bar and the
   * sort stack into two tidy rows (see the media query). Hairline under; anchors
   * the pagination scroll-back.
   */
  .iwac-search__controls {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    flex-wrap: wrap;
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  /* View toggle + (mobile) filters + actions. Grows so sort sits at the far end. */
  .iwac-search__controls-bar {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    flex: 1 1 auto;
    min-width: 0;
  }
  .iwac-search__controls-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    margin-inline-start: auto;
    flex-shrink: 0;
  }
  .iwac-search__error {
    background: color-mix(in oklab, var(--error, #c9222b) 12%, var(--surface, #fdfcfb));
    border: 1px solid color-mix(in oklab, var(--error, #c9222b) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    color: var(--ink-strong, var(--ink, #13161c));
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-search__status {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>

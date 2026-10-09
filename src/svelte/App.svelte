<script lang="ts">
  import type { IwacBootstrap, IwacFacetCount } from './lib/types';
  import { TypesenseClient } from './lib/typesense';
  import { effectiveSortValue } from './lib/queryBuilders';
  import { isSemanticOnlyResponse } from './lib/semanticFallback';
  import { FALLBACK_SORT, createTypingBurst, createUrlSync, onUrlPop } from './lib/urlState';
  import { adoptableSnapshot, initialSearchState } from './lib/initialState';
  import { resultAnnouncement } from './lib/announce';
  import { normalizeCard, normalizeLocale, provideI18n } from './lib/i18n';
  import { createSearchResults, createMapResults } from './lib/searchResults.svelte';
  import { createViewMode } from './lib/viewMode.svelte';
  import { createFilterDrawer } from './lib/filterDrawer.svelte';
  import { createFilterState } from './lib/filterState.svelte';
  import { createTypeahead, dismissOnOutsidePointer, slashShortcut } from './lib/typeahead.svelte';
  import { clampYearRange, resolveYearBounds, sameYearRange } from './lib/yearBounds';
  import { untrack } from 'svelte';
  import { landOnResults, refocus } from './lib/refocus';
  import SearchInput from './components/SearchInput.svelte';
  import SuggestDropdown from './components/SuggestDropdown.svelte';
  import ResultsList from './components/ResultsList.svelte';
  import ResultsToolbar from './components/ResultsToolbar.svelte';
  import ResultSummary from './components/ResultSummary.svelte';
  import ResultSkeleton from './components/ResultSkeleton.svelte';
  import ResultsEmpty from './components/ResultsEmpty.svelte';
  import DidYouMean from './components/DidYouMean.svelte';
  import SemanticFallback from './components/SemanticFallback.svelte';
  import MapView from './components/MapView.svelte';
  import FacetPanel from './components/FacetPanel.svelte';
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
   * Modularity note: this component is the orchestrator. It owns the query /
   * page / sort state and wires everything together; it no longer fetches.
   * The UI is in small focused components (SearchInput, FacetPanel,
   * ResultsToolbar, ResultsList, SemanticFallback, …); each self-contained
   * cluster of mechanics is a module —
   *
   *   searchResults.svelte.ts  what the state fetches: results, histogram,
   *                            did-you-mean, the map set (+ their rules)
   *   initialState.ts          mount state, and when the SSR page is adopted
   *   filterState.svelte.ts    facet selections + year range (+ their rules)
   *   typeahead.svelte.ts      suggest dropdown state, ARIA wiring, "/" shortcut
   *   viewMode.svelte.ts       list / gallery / map resolution + persistence
   *   filterDrawer.svelte.ts   narrow-viewport drawer
   *   announce.ts              the live region's sentence
   *
   * — so each stays independently readable and testable, and this file keeps
   * only what genuinely crosses between them.
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
    /**
     * Query owned by a parent surface (the federated page's shared search
     * box). LIVE, not a seed: changing it re-runs the search in place, so
     * switching queries keeps the facet panel's expand state, the view mode
     * and the scroll position — remounting the whole tab to change one
     * string threw all of that away. Undefined on surfaces that own their
     * own query (standalone /search, page blocks).
     */
    sharedQuery?: string;
  }

  const { bootstrap, showSearchBox = true, sharedQuery }: Props = $props();

  // Provide the locale + translator to the whole component subtree. Read
  // once at init from the server-detected bootstrap locale (defaults to
  // French). svelte-ignore: bootstrap is a prop, not reactive state.
  // svelte-ignore state_referenced_locally
  const i18n = provideI18n(normalizeLocale(bootstrap.locale), normalizeCard(bootstrap.card));
  const { t, card } = i18n;

  const isStandalone = $derived(String(bootstrap.block_id) === 'standalone');

  // URL ↔ state sync. The standalone /search route uses bare, shareable
  // params; full-mode page blocks namespace their params by block id so
  // several search blocks on one page (and the host page's own ?page=/?q=)
  // never collide. Federated inner apps (showSearchBox=false, they own ?q/?tab
  // via FederatedApp) and compact/results-only blocks (no facet panel) don't
  // sync — they keep state in memory.
  const syncUrl = $derived(isStandalone || (showSearchBox && bootstrap.mode === 'full'));
  const urlPrefix = $derived(isStandalone ? '' : `b${bootstrap.block_id}.`);

  // The surface's own default sort — the preset's (or the block admin's)
  // choice, `_text_match:desc` on /search. It is BOTH the value an absent
  // ?sort= decodes to and the value omitted when writing, so the URL round
  // trip preserves whatever this surface was configured with. Passing the
  // global fallback here instead would make every full-mode block ignore its
  // configured Default sort and discard the SSR snapshot on mount.
  const defaultSort = $derived(bootstrap.default_sort || FALLBACK_SORT);

  // One TypesenseClient per mount: it owns this surface's abort channels.
  // bootstrap is server-emitted and never changes post-mount (same read as
  // provideI18n above).
  // svelte-ignore state_referenced_locally
  const client = new TypesenseClient(bootstrap);

  // Initial state — see lib/initialState.ts. `syncUrl`, `urlPrefix`,
  // `defaultSort` and `bootstrap` are read once at mount; svelte-check warns
  // because the read isn't reactive, but none can change post-mount
  // (bootstrap is server-emitted, the rest derive from it).
  // svelte-ignore state_referenced_locally
  const initial = initialSearchState(bootstrap, { syncUrl, urlPrefix, defaultSort, sharedQuery });

  let query = $state(initial.q);
  let page = $state(initial.page);
  let sort = $state(initial.sort);
  /**
   * The reader's page-size choice, or null for the surface's configured one.
   *
   * Kept nullable all the way through to the URL: a page block's admin sets
   * `results_per_page`, and a shared link that froze the sharer's surface
   * default into `?per=` would override the recipient's block config with a
   * number nobody chose.
   */
  let perPageChoice = $state<number | null>(initial.perPage);

  /**
   * What the search box currently SAYS, as opposed to what has been searched
   * for. They differ for the 250 ms of the input debounce — so the dropdown
   * reads this and the search effect reads `query`, and the rows stay
   * describing the text on screen rather than trailing it. That still holds
   * after the commit: the panel survives it (see lib/typeahead.svelte.ts), and
   * a row it offers must always match what the reader can see themselves
   * having typed.
   *
   * Kept in step by {@link setQuery}, which every PROGRAMMATIC assignment to
   * `query` goes through (URL pop, a picked suggestion, a federated parent's
   * query). Typing is the one path that writes it directly, from SearchInput's
   * undebounced `onInput`.
   */
  let typedQuery = $state(initial.q);

  function setQuery(next: string): void {
    query = next;
    typedQuery = next;
  }

  // Adopt the parent's query whenever it changes. Guarded by the last value
  // ADOPTED (not by comparing to `query`), so a query the user then edits
  // inside this App — possible on a surface that shows its own box — isn't
  // snapped back by an unrelated re-run of this effect. The first run is a
  // no-op: `initial.q` already is the shared query.
  let adoptedQuery = initial.q;
  $effect(() => {
    const next = sharedQuery;
    if (next === undefined || next === adoptedQuery) return;
    adoptedQuery = next;
    setQuery(next);
    page = 1;
  });

  // Categorical facet selections + the year range. The composable owns the
  // mutation rules (drop empty keys, replace-don't-mutate) and resets the
  // page on every change — the one cross-cutting effect they all share.
  const filterState = createFilterState(initial.filters, initial.yearRange, () => {
    page = 1;
  });
  const filters = $derived(filterState.filters);
  const yearRange = $derived(filterState.yearRange);

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

  // ── What the state fetches ───────────────────────────────────────────
  // Hydrated from the SSR'd first page when the initial state is pristine
  // (lib/initialState.ts), so the first frame shows real content rather than
  // a skeleton; otherwise the first run fetches.
  // svelte-ignore state_referenced_locally
  const results = createSearchResults(client, {
    withHistogram: bootstrap.mode === 'full',
    prominentFacets: bootstrap.prominent_facets,
    collection: bootstrap.collection_alias,
    initialResponse: adoptableSnapshot(bootstrap, initial, defaultSort),
  });
  const response = $derived(results.response);

  /**
   * The year slider's bounds: the surface's data span once it has arrived
   * (asked for with the first histogram), else a fallback wide enough for
   * every bar on screen and both ends of the requested range. Shared by the
   * slider and every year chip, so they name the same ends.
   */
  const yearBounds = $derived(
    resolveYearBounds(results.yearSpan, results.yearDistribution, yearRange),
  );

  /**
   * The query the reader explicitly asked to see semantic near-matches for.
   *
   * Content surfaces search hybrid, so a query the keyword leg doesn't match
   * still comes back full: the vector leg returns its fixed top-k (100) and
   * the client used to render them as ordinary results — "100 results" with
   * confident facet counts over a set nothing in it actually matched
   * (lib/semanticFallback.ts). Those hits are now offered rather than
   * asserted, behind this opt-in.
   *
   * Keyed by the query string rather than a bare boolean: an opt-in belongs to
   * ONE dead query and must not carry over to the next one, while paging or
   * narrowing a facet within that query keeps it.
   */
  let semanticOptInFor = $state<string | null>(null);

  /** The surface's root, for finding where focus lands after a swap. */
  let rootEl: HTMLElement | null = $state(null);

  /** The offer and the banner are different buttons: focus follows to the other one. */
  function focusSemanticToggle(): void {
    void refocus(() => rootEl?.querySelector<HTMLElement>('.iwac-search__semantic-btn'));
  }

  function showSemantic(): void {
    semanticOptInFor = query;
    focusSemanticToggle();
  }

  function hideSemantic(): void {
    semanticOptInFor = null;
    focusSemanticToggle();
  }

  /**
   * A control in the empty state (a scope chip, Clear all, a did-you-mean
   * entity) starts a search, and the skeleton that replaces the empty state
   * takes the control with it: focus goes to the results landmark.
   */
  function thenFocusResults(action: () => void): void {
    action();
    void refocus(resultsRegion, rootEl);
  }

  // Anchor element above the result list — page changes scroll back
  // to this so the new page lands at the top. Bound to the toolbar below.
  let resultsAnchor: HTMLElement | null = $state(null);

  // Push state → URL whenever anything observable changes. An EXPLICIT view
  // always goes to the URL, `list` included, so a copied link reproduces what
  // the sharer was looking at; an auto-suggested gallery writes nothing and
  // stays a session hint (the recipient's own results re-derive it, or don't).
  // svelte-ignore state_referenced_locally
  const urlSync = createUrlSync(urlPrefix, defaultSort);

  // Search-as-you-type writes ONE history entry per typing burst (see
  // createTypingBurst): the debounced commit flags itself here, and the URL
  // effect below turns the burst's later commits into replaceState. Plain
  // variables — the effect must not depend on them.
  const typingBurst = createTypingBurst();
  let typingCommit = false;

  // A year range outside the data (a hand-edited `?date.from=2050`, an old
  // link from before the corpus grew) is brought inside the span once the
  // span is known — the slider can only show what is on its track, and the
  // address should say what the slider says. A correction, not a navigation:
  // written with replaceState, and the page is left alone.
  $effect(() => {
    const span = results.yearSpan;
    const range = yearRange;
    if (!span || !range) return;
    const clamped = clampYearRange(range, span);
    if (sameYearRange(clamped, range)) return;
    urlSync.replaceNext();
    filterState.hydrate(
      untrack(() => filters),
      clamped,
    );
  });
  $effect(() => {
    if (!syncUrl) return;
    const state = {
      q: query,
      page,
      sort,
      filters,
      yearRange,
      perPage: perPageChoice,
      view: view.explicit ? view.mode : null,
    };
    if (typingCommit) {
      if (typingBurst.typed()) urlSync.replaceNext();
    } else {
      // Any other change — a facet, a sort, a page, a pick — ends the burst.
      typingBurst.end();
    }
    typingCommit = false;
    urlSync.push(state);
  });

  // Back / forward → re-hydrate state from URL.
  $effect(() => {
    if (!syncUrl) return;
    return onUrlPop(
      (s) => {
        setQuery(s.q);
        page = s.page;
        sort = s.sort;
        perPageChoice = s.perPage;
        filterState.hydrate(s.filters, s.yearRange);
        view.applyPop(s.view);
      },
      urlPrefix,
      defaultSort,
    );
  });

  // Auto-suggest Gallery once, on the first response the reader is actually
  // SHOWN, when that set is image-heavy and no view has been chosen (design
  // review §01). Never overrides an explicit choice; doesn't persist.
  //
  // The `semanticHidden` guard is the fix for a real inversion: this effect
  // used to run before the withhold, so a query that matched nothing had its
  // presentation decided by the vector-only set the surface was refusing to
  // show — a gallery chosen on the strength of documents the reader was
  // being told did not exist.
  $effect(() => {
    const r = response;
    if (r && !semanticHidden) view.autoSuggest(r);
  });

  // Query → search. Tracks every state field by reading it here. Always fires
  // on mount (including with an empty query) so browse surfaces — curated
  // pages, blocks with locked_filters, or just a bare /search arrival — show
  // items + facets immediately. The typesense client translates an empty
  // query into `q=*` browse mode.
  $effect(() => results.run({ q: query, page, sort, filters, yearRange, perPage: perPageChoice }));

  // Map data: when the Map view is active, every geo-tagged entity matching
  // the current query + filters (year range included — unlike the histogram,
  // the map reflects the selected window).
  const map = createMapResults(client);
  $effect(() => {
    if (view.mode !== 'map') return;
    return map.run({ q: query, filters, yearRange });
  });

  /** The debounce fired: this is the search commit. `typedQuery` already
      matches (SearchInput wrote it on the keystroke), so no setQuery here. */
  function handleQueryChange(next: string): void {
    if (next !== query) typingCommit = true;
    query = next;
    page = 1; // any new query resets pagination
  }

  // ── Typeahead ───────────────────────────────────────────────────────
  // Open/closed state, the ARIA combobox wiring and the focus/blur/keydown
  // handlers live in the composable; App supplies only the two callbacks
  // that touch search state. block_id only seeds the listbox id and never
  // changes post-mount (bootstrap is server-emitted), so reading it once
  // here is correct.
  // svelte-ignore state_referenced_locally
  const suggest = createTypeahead(bootstrap.block_id, {
    onCommitQuery: (text) => {
      if (text !== query) {
        setQuery(text);
        page = 1;
      }
    },
    onPickEntity: (field, value) => {
      setQuery('');
      filterState.toggle(field, value, true);
    },
  });

  // ── "/" keyboard shortcut ───────────────────────────────────────────
  let searchFormEl: HTMLFormElement | null = $state(null);

  $effect(() => {
    if (!showSearchBox) return;
    return slashShortcut(() => searchFormEl);
  });

  // A pointer press that isn't for the typeahead closes it — including one on
  // the panel's own dead space, which is where a reader aiming at the toolbar
  // it covers actually lands.
  $effect(() => {
    if (!showSearchBox) return;
    return dismissOnOutsidePointer(
      () => suggest.open,
      () => searchFormEl,
      () => suggest.close(),
    );
  });

  // ── Mobile filter drawer ────────────────────────────────────────────
  // Composable owns the matchMedia listener + open/close state; the
  // conditional render below picks the sticky column vs the Drawer.
  const drawer = createFilterDrawer();
  $effect(() => drawer.attach());

  function handleSortChange(next: string): void {
    sort = next;
    page = 1;
  }

  /**
   * Page-size change. Resets to page 1 for the same reason a filter does: page
   * 40 of a ten-per-page set is page 8 of a fifty-per-page one, and silently
   * re-anchoring the reader somewhere they didn't ask for is worse than
   * starting them at the top of the set they just re-sized.
   */
  function handlePerPageChange(next: number): void {
    perPageChoice = next;
    page = 1;
    // The pager (and this select) unmounts under the skeleton.
    void refocus(resultsRegion);
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
    // Focus follows the navigation (the pager button pressed is about to be
    // re-rendered), and the top of the new page comes into view. Compact and
    // results-only blocks have no results landmark or toolbar, so their list
    // wrapper stands in for both — they used to get neither.
    landOnResults(resultsRegion ?? compactRegion, resultsAnchor ?? compactRegion);
  }

  // ── Announcements + the keyboard bypass ─────────────────────────────
  // Ids are per-mount: several full-mode blocks can share one page, and a
  // skip link that jumped to another block's results would be worse than
  // none. block_id never changes post-mount (server-emitted).
  const resultsId = $derived(`iwac-results-${bootstrap.block_id}`);
  const resultsHeadingId = $derived(`iwac-results-heading-${bootstrap.block_id}`);

  /** The results landmark, focused by the skip link and by pagination. */
  let resultsRegion: HTMLElement | null = $state(null);
  /** The compact surfaces' result list wrapper — their pager's landing place. */
  let compactRegion: HTMLElement | null = $state(null);

  // ── Semantic-only fallback ──────────────────────────────────────────
  // Did the keyword leg match nothing at all? (See lib/semanticFallback.ts.)
  const semanticOnly = $derived(isSemanticOnlyResponse(response, query));
  const semanticOptIn = $derived(semanticOptInFor !== null && semanticOptInFor === query);
  /**
   * The default state for such a response: the vector set is withheld. No
   * result list, no count, no facet counts, no export — nothing that would
   * present it as findings. The scope-aware empty state shows instead, with
   * the set offered behind one explicit control.
   */
  const semanticHidden = $derived(semanticOnly && !semanticOptIn);

  // Facet counts computed over a withheld set would under-write the same
  // claim the summary strip no longer makes, so the panel falls back to its
  // "Search to see filter options." state.
  const facets = $derived(semanticHidden ? [] : (response?.facet_counts ?? []));
  const perPage = $derived(
    perPageChoice ?? response?.request_params?.per_page ?? bootstrap.results_per_page ?? 20,
  );
  const searchTimeMs = $derived(response?.search_time_ms ?? 0);
  const totalPages = $derived(
    response ? Math.max(1, Math.ceil(response.found / Math.max(1, perPage))) : 1,
  );

  /** What a screen reader should be told once the surface settles (lib/announce.ts). */
  const announcement = $derived(
    resultAnnouncement({ response, semanticHidden, semanticOnly, page, totalPages }, i18n),
  );

  /**
   * The text actually in the DOM, held one beat behind {@link announcement}.
   *
   * The search itself is already debounced, but a fast typist still settles
   * several searches in a row, and a live region that changes three times in
   * a second is read as an interruption of itself. 600ms of quiet before
   * committing means one sentence per pause in typing.
   */
  let announced = $state('');
  $effect(() => {
    const next = announcement;
    if (next === announced) return;
    const id = setTimeout(() => {
      announced = next;
    }, 600);
    return () => clearTimeout(id);
  });

  /**
   * The sort order actually in force, which is not always the one in `sort`:
   * relevance is meaningless without a query, so resolveSortBy() substitutes
   * date:desc in browse mode. The summary strip and the dropdown read THIS —
   * labelling the unresolved state value is what made the resting /references
   * and FR /parcourir pages claim "sorted by Relevance" over strictly
   * date-ordered results. `bootstrap.default_sort` (not the FALLBACK_SORT-
   * defaulted `defaultSort`) because that is the exact value TypesenseClient
   * passes to resolveSortBy — the two must not diverge.
   */
  const effectiveSort = $derived(
    effectiveSortValue(sort, query.trim() === '', bootstrap.default_sort),
  );
  // Single-country scopes hide the (redundant) country chip on result cards.
  const hideCountry = $derived(bootstrap.hide_country ?? false);
</script>

<!-- The one facet panel, rendered into the sticky column or the Drawer. -->
{#snippet facetPanel()}
  <FacetPanel
    {facets}
    selected={filters}
    {yearRange}
    {yearBounds}
    distribution={results.yearDistribution}
    onToggle={(f, v, c) => filterState.toggle(f, v, c)}
    onClearAll={() => filterState.clearAll()}
    onClearField={(f) => filterState.clearField(f)}
    onYearRangeChange={(r) => filterState.setYearRange(r)}
    onFacetSearch={handleFacetSearch}
  />
{/snippet}

<div
  class="iwac-search"
  class:iwac-search--compact={bootstrap.mode === 'compact'}
  bind:this={rootEl}
>
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
      onfocusin={() => suggest.handleFocus()}
      onfocusout={(e) => {
        suggest.handleBlur(e);
        typingBurst.end();
      }}
      onkeydown={(e) => {
        // Enter settles the query (it may never reach onsubmit: the open
        // typeahead takes the key).
        if (e.key === 'Enter') typingBurst.end();
        // Only the box's own keys drive the typeahead. Delegated from the
        // whole form, Enter on the × or on "Clear history" ran the open
        // panel's highlighted row instead of pressing the button.
        if (e.target instanceof HTMLInputElement) suggest.handleKeydown(e);
      }}
      onsubmit={(e) => {
        e.preventDefault();
        typingBurst.end();
      }}
    >
      <SearchInput
        value={query}
        placeholder={t('search_placeholder')}
        onChange={handleQueryChange}
        onInput={(raw) => {
          typedQuery = raw;
          suggest.handleInput(raw);
        }}
        listboxId={suggest.listboxId}
        expanded={suggest.expanded}
        activeDescendant={suggest.activeId}
      />
      <SuggestDropdown
        bind:this={suggest.ref}
        query={typedQuery}
        {client}
        enabled={suggest.open}
        listboxId={suggest.listboxId}
        onActiveChange={(id) => suggest.setActiveId(id)}
        onPickQuery={(text) => suggest.pickQuery(text)}
        onRunSearch={(text) => suggest.runSearch(text)}
        onPickEntity={(field, value) => suggest.pickEntity(field, value)}
        onClose={() => suggest.close()}
      />
    </form>
  {/if}

  {#if results.yearsUnavailable}
    <p>
      {t('histogram_unavailable')}
      <button type="button" onclick={() => results.retry()}>{t('retry_search')}</button>
    </p>
  {/if}
  {#if results.error}
    <!-- The reader's language and a way forward. The raw transport message
         ("Token HTTP 503: Typesense scoped-key minting failed…") is operator
         detail, in English on the French site: it goes to the console, where
         searchResults already logs it, not to the page. -->
    <div class="iwac-search__error" role="alert">
      <strong>{t('search_unavailable')}</strong>
      <span>{t('search_failed_hint')}</span>
      <button type="button" onclick={() => results.retry()}>{t('retry_search')}</button>
    </div>
  {/if}

  <!--
    The one place the result count is announced. Persistent and OUTSIDE every
    conditional, because a live region that is created already populated
    announces nothing — which is exactly how the old region inside
    ResultSummary managed to be torn down for each skeleton and re-mounted
    silently. Empty at mount, filled 600ms after the surface settles.
  -->
  <p class="iwac-search__sr-only" role="status" aria-live="polite" aria-atomic="true">
    {announced}
  </p>

  {#if bootstrap.mode === 'full'}
    <div class="iwac-search__layout">
      <!--
        In-module bypass. The theme's own skipnav lands ABOVE the facet
        column, so from there the first result is still ~120 Tab presses away
        (measured: 97 of them facet stops). This one is the first thing in the
        layout, so it is the first stop after the search box.
      -->
      <a class="iwac-search__skip" href="#{resultsId}">{t('skip_to_results')}</a>

      {#if drawer.isNarrow}
        <!-- Narrow viewport: facets live behind the Filters trigger,
             rendered into the shared Drawer when opened. -->
        <Drawer
          open={drawer.open}
          onClose={() => drawer.close()}
          title={t('filters')}
          closeLabel={t('close_filters')}
          side="right"
          width="min(22rem, 92vw)"
        >
          <div class="iwac-search__facets-body">
            {@render facetPanel()}
          </div>
        </Drawer>
      {:else}
        <!-- Wide viewport: classic sticky left column. -->
        <aside class="iwac-search__facets-inline" aria-label={t('filters')}>
          {@render facetPanel()}
        </aside>
      {/if}

      <!--
        A landmark with a name, and tabindex="-1" so the skip link and the
        pager can put focus here. The heading is visually hidden but real:
        without it the page's only <h2> is "Filters", so every result <h3>
        nested under the filter sidebar in the document outline.
      -->
      <section
        class="iwac-search__results"
        id={resultsId}
        bind:this={resultsRegion}
        tabindex="-1"
        aria-labelledby={resultsHeadingId}
        aria-busy={results.isLoading}
      >
        <h2 id={resultsHeadingId} class="iwac-search__sr-only">{t('results_heading')}</h2>
        <!-- Kept through an error: the mobile Filters trigger is the only way to
             the drawer, and the drawer is where the reader undoes the filter
             that broke the search. -->
        {#if response || results.error}
          <ResultsToolbar
            bind:anchor={resultsAnchor}
            {view}
            filtersOpen={drawer.open}
            activeFilterCount={filterState.activeCount}
            onOpenFilters={() => drawer.show()}
            showCopyLink={syncUrl}
            fetchDocs={card === 'content' && response && response.found > 0 && !semanticHidden
              ? handleExportFetch
              : null}
            {query}
            found={response?.found ?? 0}
            sort={effectiveSort}
            onSortChange={handleSortChange}
          />
        {/if}
        {#if response}
          <!-- Persistent count + scope + sort summary, visible on every
               viewport (the mobile filter readout). Closed by a 2px ink rule.
               Withheld for a semantic-only response: its count is the vector
               leg's top-k, not a number of matches. -->
          {#if response.found > 0 && !semanticHidden}
            <ResultSummary
              found={response.found}
              {searchTimeMs}
              {filters}
              {yearRange}
              {yearBounds}
              sort={effectiveSort}
              onRemoveChip={(c) => filterState.removeChip(c)}
              onClearAll={() => filterState.clearAll()}
              semantic={semanticOnly}
            />
          {/if}
        {/if}

        {#if view.mode === 'map'}
          <!-- The map owns its own loading/empty states and reflects the
               live query + filters; the summary strip above still shows
               the textual result count. -->
          <MapView docs={map.docs} loading={map.loading} />
        {:else if results.isLoading}
          <!-- Galley-proof skeleton in the active view (replaces the opacity
               dim) — holds geometry so the page doesn't jump (§03A). -->
          <ResultSkeleton view={view.mode} count={Math.min(Math.max(perPage, 4), 8)} />
        {:else if response && (response.found === 0 || semanticHidden)}
          {#if results.didYouMean.length > 0}
            <DidYouMean
              suggestions={results.didYouMean}
              onPick={(field, value) => thenFocusResults(() => suggest.pickEntity(field, value))}
            />
          {/if}
          <ResultsEmpty
            {filters}
            {yearRange}
            {yearBounds}
            {query}
            onRemoveChip={(c) => thenFocusResults(() => filterState.removeChip(c))}
            onClearAll={() => thenFocusResults(() => filterState.clearAll())}
          />
          {#if semanticHidden}
            <SemanticFallback
              found={response.found}
              {query}
              shown={false}
              onShow={showSemantic}
              onHide={hideSemantic}
            />
          {/if}
        {:else if response}
          {#if semanticOnly}
            <SemanticFallback
              found={response.found}
              {query}
              shown={true}
              onShow={showSemantic}
              onHide={hideSemantic}
            />
          {/if}
          <ResultsList
            {response}
            {perPage}
            onPageChange={handlePageChange}
            onPerPageChange={handlePerPageChange}
            activeFilters={filters}
            onFacetToggle={(f, v, c) => filterState.toggle(f, v, c)}
            {hideCountry}
            view={view.mode}
          />
        {/if}
      </section>
    </div>
  {:else if results.isLoading && !response}
    <!-- No aria-live: the persistent region above already owns announcements,
         and two polite regions on one surface talk over each other. -->
    <p class="iwac-search__status">{t('searching')}</p>
  {:else if response && semanticHidden}
    <!-- Compact / results-only blocks have no facet panel or summary strip, but
         they run the same hybrid query and so could present the same vector-only
         set as findings. Same contract, smaller frame: say nothing matched, and
         offer the near neighbours explicitly. -->
    <p class="iwac-search__status">{t('results_empty_list')}</p>
    <SemanticFallback
      found={response.found}
      {query}
      shown={false}
      onShow={showSemantic}
      onHide={hideSemantic}
    />
  {:else if response}
    {#if semanticOnly}
      <SemanticFallback
        found={response.found}
        {query}
        shown={true}
        onShow={showSemantic}
        onHide={hideSemantic}
      />
    {/if}
    <div class="iwac-search__compact-results" tabindex="-1" bind:this={compactRegion}>
      <ResultsList
        {response}
        {perPage}
        onPageChange={handlePageChange}
        activeFilters={filters}
        onFacetToggle={(f, v, c) => filterState.toggle(f, v, c)}
        {hideCountry}
      />
    </div>
  {/if}
</div>

<style>
  .iwac-search {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 1rem);
    color: var(--ink, #13161c);
    font-size: var(--text-base, 1.0625rem);
  }
  /* Anchors the floating SuggestDropdown — must be positioned. */
  .iwac-search__searchbox {
    position: relative;
  }

  /*
   * Visually hidden, still announced. The module had no such utility, which
   * is why the live region and the results heading did not exist: there was
   * nowhere to put text that only assistive tech reads. `clip-path` rather
   * than the legacy `clip`, and a 1px box rather than `display: none`, which
   * would take it out of the accessibility tree entirely.
   */
  .iwac-search__sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
  }

  /*
   * Skip link — hidden until focused, then a real control at the top of the
   * results column. It is the first tabbable thing in the layout, ahead of
   * the ~97 facet stops, so the keyboard route to the results is two presses
   * rather than a hundred.
   */
  .iwac-search__skip {
    position: absolute;
    inset-inline-start: -9999px;
    inset-block-start: auto;
    /* Where the theme's own skip link sits. It is a grid item once focused,
       so the z-index still applies after it turns static. */
    z-index: var(--z-tooltip, 500);
  }
  .iwac-search__skip:focus,
  .iwac-search__skip:focus-visible {
    position: static;
    display: inline-block;
    /* Spans the grid so it can't be squeezed into the facet column's width,
       but justify-self keeps it the width of its own label — a skip link is a
       control, not a banner. */
    grid-column: 1 / -1;
    justify-self: start;
    align-self: start;
    margin-block-end: var(--space-2, 0.5rem);
    padding: var(--space-1, 0.25rem) var(--space-4, 1rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink-strong, #05070c);
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    text-decoration: none;
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-search__layout {
    display: grid;
    grid-template-columns: minmax(15rem, 18rem) 1fr;
    gap: var(--space-8, 2rem);
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
    top: var(--space-4, 1rem);
    align-self: start;
    /* The single scroll container for filters. Facet groups no longer
       scroll individually (see FacetGroup .iwac-facet__list), so this is
       the only scrollbar in the sidebar — and it only appears when the
       collapsed facet column is taller than the viewport. Thin + stable
       gutter keeps it from crowding the divider. */
    max-height: calc(100vh - var(--space-8, 2rem));
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-gutter: stable;
    /* Subtle right "rail" so the column has a visual edge against the
       results without becoming a card. The 3px start padding is not
       decoration: `overflow-y: auto` makes this a scroll container, which
       clips ink painted outside its padding box — without it the leading
       stroke of a facet control's 2px focus outline is trimmed away. */
    padding-inline: 0.1875rem var(--space-4, 1rem);
    border-inline-end: 1px solid var(--border-light, #e2e5e8);
  }
  /* From the theme's lg breakpoint the masthead collapses to its section strip
     on scroll and the strip stays pinned (IWAC-theme script.js), 2.5rem with
     its 2px rule — measured on the live page, where the column's top 24px
     sat under it. The theme publishes no token for the pinned height (only
     the full --header-height*), hence the literal; scrolling back up shows
     the full masthead over the column for as long as it is out. */
  @media (min-width: 1024px) {
    .iwac-search__facets-inline {
      top: calc(2.5rem + var(--space-4, 1rem));
      max-height: calc(100vh - 2.5rem - var(--space-8, 2rem));
    }
  }
  .iwac-search__facets-body {
    /* Padding inside the drawer body. The drawer header already has
       its own padding from src/svelte-shared/components/Drawer.svelte. */
    padding: var(--space-4, 1rem);
  }

  /* 767px = md 768 − 1 on the theme's published scale. This literal has a
     twin in lib/filterDrawer.svelte.ts's matchMedia, which decides whether
     the facet panel renders as a column or inside the Drawer; the two must
     agree or the page reserves a sidebar for a panel that moved. (The
     toolbar's own phone layout — components/ResultsToolbar.svelte — switches
     at the same width.) */
  @media (max-width: 767px) {
    .iwac-search__layout {
      grid-template-columns: 1fr;
      gap: var(--space-4, 1rem);
    }
  }

  .iwac-search__results {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 1rem);
    min-width: 0; /* allow snippet wrap */
    /* Focused programmatically by the skip link and the pager; the ring would
       be a full-column outline saying nothing about where the next Tab goes.
       :focus-visible is untouched. */
    /* No opacity dim while paging — the ResultSkeleton provides the loading
       feedback now, keeping row geometry stable (punch-list item 2). aria-busy
       stays on the container for assistive tech. */
  }
  .iwac-search__results:focus:not(:focus-visible),
  .iwac-search__compact-results:focus:not(:focus-visible) {
    outline: none;
  }
  .iwac-search__error {
    background: color-mix(in oklab, var(--error, #c9222b) 12%, var(--surface, #fdfcfb));
    border: 1px solid color-mix(in oklab, var(--error, #c9222b) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-4, 1rem);
    color: var(--ink-strong, #05070c);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-1, 0.25rem);
  }
  .iwac-search__status {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>

<script lang="ts">
  import type {
    ActiveFilters,
    IwacBootstrap,
    IwacFederatedBootstrap,
    IwacSearchResponse,
  } from '../lib/types';
  import { TypesenseClient } from '../lib/typesense';
  import { isAbortError } from '../lib/transport';
  import { createTypingBurst } from '../lib/urlState';
  import { isSemanticOnlyResponse } from '../lib/semanticFallback';
  import { resultAnnouncement } from '../lib/announce';
  import { landOnResults, refocus } from '../lib/refocus';
  import { provideI18n, normalizeLocale, type Locale } from '../lib/i18n';
  import App from '../App.svelte';
  import ResultItem from './ResultItem.svelte';
  import Pagination from './Pagination.svelte';
  import SearchInput from './SearchInput.svelte';
  import SemanticFallback from './SemanticFallback.svelte';

  /**
   * The federated "search everything" page. One instance per page.
   *
   * Owns a shared query + the active tab (All | Content | Entities). On
   * every committed query it runs one counts-only multi_search across both
   * collections (TypesenseClient.countAcross) to label the tabs.
   *
   *   - The "All" tab is a Typesense v30 UNION search: ONE merged,
   *     relevance-ranked list across the content + entity collections
   *     (deduped server-side), rendered right here — union responses carry
   *     no facet_counts, so this tab is a lean ranked list; the
   *     per-collection tabs keep the full faceted experience. Clicking a
   *     card chip on the All tab hands the filter off to the right
   *     per-collection tab via bootstrap.initial_filters.
   *   - Content / Entities mount the existing per-collection {@link App}
   *     (its own search box suppressed) so facets, sort and paging keep
   *     working unchanged.
   *
   * `?q=` + `?tab=` are kept in the URL so a federated search is shareable.
   */

  type TabId = 'all' | 'content' | 'entities';

  interface Props {
    bootstrap: IwacFederatedBootstrap;
  }

  const { bootstrap }: Props = $props();

  // svelte-ignore state_referenced_locally
  const locale: Locale = normalizeLocale(bootstrap.locale);
  // Context for the union tab's ResultItems (they detect entity docs by
  // shape); the per-tab Apps provide their own context on top.
  const i18n = provideI18n(locale, 'content');
  const { t, formatNumber } = i18n;

  // svelte-ignore state_referenced_locally
  const tabs = bootstrap.tabs;

  function readTabFromUrl(): TabId {
    if (typeof window === 'undefined') return defaultTab();
    const param = new URLSearchParams(window.location.search).get('tab');
    return param === 'all' || param === 'entities' || param === 'content' ? param : defaultTab();
  }

  /**
   * Arriving WITH a query (header search box hand-off) lands on the merged
   * "All" ranking — the closest thing to "search everything". A bare visit
   * keeps the configured browse tab (content, date-sorted).
   */
  function defaultTab(): TabId {
    return (bootstrap.initial_query ?? '').trim() !== '' ? 'all' : bootstrap.default_tab;
  }

  // svelte-ignore state_referenced_locally
  let query = $state(bootstrap.initial_query ?? '');
  // svelte-ignore state_referenced_locally
  let inputValue = $state(bootstrap.initial_query ?? '');
  let activeTab = $state<TabId>(readTabFromUrl());
  /**
   * The tab holding the roving tabindex. It follows FOCUS, not selection:
   * activation is manual (Enter / Space / click), because selecting a tab
   * mounts a whole surface and runs its search — arrowing across three tabs
   * used to fire three searches.
   */
  // svelte-ignore state_referenced_locally
  let focusTab = $state<TabId>(activeTab);
  let counts = $state<Record<string, number | null>>({});
  let countsReady = $state(false);

  // Filter handed off from a union-tab chip to a per-collection tab.
  let seed = $state<{ tab: TabId; filters: ActiveFilters } | null>(null);

  // One client just for the counts call; the active tab's App holds its own.
  // svelte-ignore state_referenced_locally
  const countClient = new TypesenseClient({
    block_id: 'federated-counts',
    mode: 'compact',
    locale,
    locked_filters: '',
    prominent_facets: [],
    default_sort: '_text_match:desc',
    results_per_page: 0,
    endpoints: bootstrap.endpoints,
  });

  /**
   * One search spec per collection tab — the same shape feeds both the
   * counts-only multi_search (tab badges) and the union "All" search.
   */
  const collectionSearches = tabs.map((tab) => ({
    collection: tab.bootstrap.collection_alias ?? 'iwac_current',
    queryBy: tab.bootstrap.query_by ?? 'title_txt',
    filterBy: tab.bootstrap.locked_filters || undefined,
  }));

  let countReq = 0;
  async function loadCounts(q: string): Promise<void> {
    const myId = ++countReq;
    try {
      const found = await countClient.countAcross(q, collectionSearches);
      if (myId !== countReq) return;
      const next: Record<string, number | null> = {};
      tabs.forEach((tab, i) => {
        next[tab.id] = found[i] ?? null;
      });
      counts = next;
      countsReady = true;
    } catch {
      if (myId !== countReq) return;
      // Counts are best-effort — a failure just blanks the badges. The active
      // tab's App surfaces any real Typesense outage on its own.
      counts = {};
      countsReady = true;
    }
  }

  // Re-run counts whenever the committed query changes (incl. on mount).
  $effect(() => {
    void loadCounts(query);
  });

  // ── Union "All" tab ─────────────────────────────────────────────────
  const unionPerPage = tabs[0]?.bootstrap.results_per_page || 20;

  let unionResponse = $state<IwacSearchResponse | null>(null);
  let unionPage = $state(1);
  let unionLoading = $state(false);
  let unionError = $state<string | null>(null);
  /** Bumped by the error state's Retry; read by the union effect to re-run it. */
  let unionRetry = $state(0);

  $effect(() => {
    if (activeTab !== 'all') return;
    const q = query;
    const p = unionPage;
    void unionRetry;
    unionLoading = true;
    unionError = null;
    countClient
      .unionSearch({ q, page: p, perPage: unionPerPage, searches: collectionSearches })
      .then((r) => {
        unionResponse = r;
        unionLoading = false;
      })
      .catch((e: unknown) => {
        if (isAbortError(e)) return; // superseded — a newer union call settles the state
        // Operator detail to the console; the page says it in the reader's language.
        console.error('[iwac-search] union search failed', e);
        unionError = e instanceof Error ? e.message : String(e);
        unionResponse = null;
        unionLoading = false;
      });
  });

  /**
   * The union carries the same vector-fabrication exposure the per-collection
   * surfaces closed in 3.14.0, by the same mechanism: the content leg's
   * `query_by` ends in `embedding`, so a query the keyword leg matches
   * nowhere still comes back full of the vector leg's fixed top-k. Merged
   * with the entity collection and labelled "N results", that reads as a
   * federated finding — the strongest claim on the site.
   *
   * Same contract as App.svelte, keyed to the query so the opt-in belongs to
   * ONE dead query and pages within it without carrying over to the next.
   */
  let unionSemanticOptInFor = $state<string | null>(null);
  const unionSemanticOnly = $derived(isSemanticOnlyResponse(unionResponse, query));
  const unionSemanticHidden = $derived(unionSemanticOnly && unionSemanticOptInFor !== query);

  /**
   * The merged ranking is capped rather than fully pageable. Deep paging a
   * union has no stable meaning — the interleaving of two collections shifts
   * as scores tighten — and nobody reads to page 51 of a relevance list. The
   * per-collection tabs page through everything, which is where a user who
   * genuinely wants the tail should be.
   */
  const UNION_MAX_PAGES = 50;

  const unionNaturalPages = $derived(
    unionResponse ? Math.max(1, Math.ceil(unionResponse.found / unionPerPage)) : 1,
  );
  const unionTotalPages = $derived(Math.min(UNION_MAX_PAGES, unionNaturalPages));
  /** True when the cap is actually hiding pages, not merely equal to them. */
  const unionCapped = $derived(unionNaturalPages > UNION_MAX_PAGES);

  /** The merged list — where focus and the scroll land on a page change. */
  let unionListEl: HTMLElement | null = $state(null);
  let rootEl: HTMLElement | null = $state(null);

  /**
   * Union paging used to set the page and nothing else: no scroll, no focus
   * (the pressed pager button re-renders, so focus fell to <body>) and no
   * announcement — on the tab every masthead search lands on. Same landing
   * as App's pager now.
   */
  function changeUnionPage(next: number): void {
    if (next === unionPage) return;
    unionPage = next;
    landOnResults(unionListEl);
  }

  /** The offer and the banner are different buttons: focus follows to the other one. */
  function setUnionSemantic(optIn: boolean): void {
    unionSemanticOptInFor = optIn ? query : null;
    void refocus(() => rootEl?.querySelector<HTMLElement>('.iwac-search__semantic-btn'));
  }

  /** The union tab's sentence for the live region — App speaks for the other tabs. */
  const unionAnnouncement = $derived(
    activeTab === 'all' && !unionLoading
      ? resultAnnouncement(
          {
            response: unionResponse,
            semanticHidden: unionSemanticHidden,
            semanticOnly: unionSemanticOnly,
            page: unionPage,
            totalPages: unionTotalPages,
          },
          i18n,
        )
      : '',
  );

  /**
   * A chip clicked on a union card hands off to the right per-collection
   * tab, pre-filtered via bootstrap.initial_filters — union responses have
   * no facets, so filtering happens where the facet panel lives.
   */
  function handleUnionChip(field: string, value: string, nextChecked: boolean): void {
    if (!nextChecked) return; // nothing is ever active on the union tab
    const target: TabId =
      field === 'entity_type_s' || field === 'is_part_of_ss' ? 'entities' : 'content';
    seed = { tab: target, filters: { [field]: [value] } };
    activeTab = focusTab = target;
  }

  // Mirror state into the URL so a federated search is shareable /
  // bookmarkable. A changed committed query or tab PUSHES (back-button-able,
  // matching the per-collection App); the first sync after mount replaces.
  // The early return when the URL already matches is what keeps popstate
  // re-hydration from pushing a duplicate entry. A typing burst writes one
  // entry, not one per debounced commit (lib/urlState.ts, createTypingBurst).
  let prevUrlState: { q: string; tab: TabId } | null = null;
  const typingBurst = createTypingBurst();
  let typingCommit = false;
  $effect(() => {
    if (typeof window === 'undefined') return;
    const next = { q: query, tab: activeTab };
    const url = new URL(window.location.href);
    if (next.q) {
      url.searchParams.set('q', next.q);
    } else {
      url.searchParams.delete('q');
    }
    url.searchParams.set('tab', next.tab);

    const prev = prevUrlState;
    prevUrlState = next;
    const typed = typingCommit;
    typingCommit = false;
    let continuesBurst = false;
    if (typed) continuesBurst = typingBurst.typed();
    else typingBurst.end();
    if (url.toString() === window.location.href) return;
    if (prev === null || continuesBurst) {
      window.history.replaceState(window.history.state, '', url.toString());
    } else {
      window.history.pushState(window.history.state, '', url.toString());
    }
  });

  // Back / forward → re-hydrate query + tab from the URL.
  $effect(() => {
    if (typeof window === 'undefined') return;
    const onPop = (): void => {
      const params = new URLSearchParams(window.location.search);
      const q = params.get('q') ?? '';
      if (q !== query) unionPage = 1;
      query = q;
      inputValue = q;
      activeTab = focusTab = readTabFromUrl();
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  });

  /**
   * SearchInput owns the debounce and the clear button, and calls this for
   * both — so committing a typed query and clearing it are the same path.
   */
  function commitQuery(next: string): void {
    inputValue = next;
    // Stop re-seeding the hand-off on a later tab switch. A filter already
    // applied inside the mounted tab stays applied — same as typing a new
    // query on /search, where filters are the scope you search WITHIN.
    seed = null;
    if (next.trim() !== query) {
      typingCommit = true;
      // A new query restarts the merged list — in the handler, not in an
      // effect that watched `query` to undo a write it could not see coming.
      unionPage = 1;
    }
    query = next.trim();
  }

  function selectTab(id: TabId): void {
    activeTab = focusTab = id;
  }

  /** Tab order: the merged ranking first, then the per-collection views. */
  const tabIds: TabId[] = ['all', ...tabs.map((tab) => tab.id)];

  /**
   * Keyboard support for the roving-tabindex tablist (WAI-ARIA tabs
   * pattern, manual activation): arrows move focus, Home/End jump to the
   * ends, Enter/Space (the buttons' own click) select. Without this, the
   * inactive tabs (tabindex="-1") are unreachable by keyboard entirely.
   */
  function onTablistKeydown(e: KeyboardEvent): void {
    const idx = tabIds.indexOf(focusTab);
    let nextIdx: number;
    switch (e.key) {
      case 'ArrowRight':
        nextIdx = (idx + 1) % tabIds.length;
        break;
      case 'ArrowLeft':
        nextIdx = (idx - 1 + tabIds.length) % tabIds.length;
        break;
      case 'Home':
        nextIdx = 0;
        break;
      case 'End':
        nextIdx = tabIds.length - 1;
        break;
      default:
        return;
    }
    e.preventDefault();
    focusTab = tabIds[nextIdx];
    document.getElementById(`iwac-fed-tab-${focusTab}`)?.focus();
  }

  /** Leaving the tablist hands the tab stop back to the selected tab. */
  function onTablistFocusout(e: FocusEvent): void {
    const next = e.relatedTarget;
    if (!(next instanceof Node && (e.currentTarget as HTMLElement).contains(next))) {
      focusTab = activeTab;
    }
  }

  function tabLabel(id: TabId): string {
    if (id === 'all') return t('tab_all');
    return id === 'entities' ? t('tab_entities') : t('tab_content');
  }

  function countLabel(id: string): string {
    if (id === 'all') {
      // Sum of the per-collection counts (union dedups, so this is an upper
      // bound — close enough for a badge).
      const values = tabs.map((tab) => counts[tab.id]);
      if (values.some((v) => typeof v !== 'number')) return '';
      return formatNumber(values.reduce((a: number, b) => a + (b as number), 0));
    }
    const n = counts[id];
    return typeof n === 'number' ? formatNumber(n) : '';
  }

  /**
   * The active tab's per-collection bootstrap, plus any union-chip filter
   * hand-off. Deliberately does NOT depend on `query` — that arrives as a
   * live prop (App's `sharedQuery`), so typing a new query updates the
   * mounted tab in place. Only a TAB switch remounts (see the {#key}
   * below), which is honest: it's a different collection, different facets,
   * different sort vocabulary.
   */
  const activeBootstrap = $derived.by<IwacBootstrap>(() => {
    const base = tabs.find((tab) => tab.id === activeTab)?.bootstrap ?? tabs[0].bootstrap;
    return {
      ...base,
      initial_filters: seed?.tab === activeTab ? seed.filters : undefined,
    };
  });
</script>

<div class="iwac-fed" bind:this={rootEl}>
  <!-- Bubbling delegations from the input inside (see App's search form). -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div
    class="iwac-fed__search"
    role="search"
    onfocusout={() => typingBurst.end()}
    onkeydown={(e) => e.key === 'Enter' && typingBurst.end()}
  >
    <SearchInput
      value={inputValue}
      placeholder={t('search_everything')}
      ariaLabel={t('search_everything')}
      onChange={commitQuery}
    />
  </div>

  <!-- Focus lives on the tab buttons (roving tabindex); the tablist itself
       only routes arrow keys, so it needs no tabindex of its own. -->
  <!-- svelte-ignore a11y_interactive_supports_focus -->
  <div
    class="iwac-fed__tabs"
    role="tablist"
    aria-label={t('result_types')}
    onkeydown={onTablistKeydown}
    onfocusout={onTablistFocusout}
  >
    {#each tabIds as id (id)}
      <button
        type="button"
        role="tab"
        id="iwac-fed-tab-{id}"
        aria-selected={id === activeTab}
        aria-controls="iwac-fed-panel"
        class="iwac-fed__tab"
        class:iwac-fed__tab--active={id === activeTab}
        class:iwac-fed__tab--empty={id !== 'all' && countsReady && (counts[id] ?? 0) === 0}
        tabindex={id === focusTab ? 0 : -1}
        onclick={() => selectTab(id)}
      >
        <span class="iwac-fed__tab-label">{tabLabel(id)}</span>
        {#if countLabel(id) !== ''}
          <span class="iwac-fed__tab-count">{countLabel(id)}</span>
        {/if}
      </button>
    {/each}
  </div>

  <!-- The union tab's live region; persistent, so it announces (App speaks
       for the per-collection tabs). -->
  <p class="iwac-fed__sr" role="status">{unionAnnouncement}</p>

  <div
    class="iwac-fed__panel"
    id="iwac-fed-panel"
    role="tabpanel"
    aria-labelledby="iwac-fed-tab-{activeTab}"
  >
    {#if activeTab === 'all'}
      <!-- Union tab: one merged relevance ranking across both collections
           (no facets — union responses carry none; the per-collection tabs
           keep the full faceted experience). -->
      <div class="iwac-fed__union" aria-busy={unionLoading} tabindex="-1" bind:this={unionListEl}>
        {#if unionError}
          <div class="iwac-fed__union-error" role="alert">
            <strong>{t('search_unavailable')}</strong>
            <span>{t('search_failed_hint')}</span>
            <button type="button" onclick={() => (unionRetry += 1)}>{t('retry_search')}</button>
          </div>
        {:else if unionLoading && !unionResponse}
          <p class="iwac-fed__union-status">{t('searching')}</p>
        {:else if unionResponse}
          {#if unionResponse.found === 0 || unionSemanticHidden}
            <!-- Withheld exactly as the per-collection surfaces withhold: say
                 nothing matched, then offer the near neighbours as an offer —
                 the same SemanticFallback component, not a second copy. -->
            <p class="iwac-fed__union-status">{t('results_empty_list')}</p>
            {#if unionSemanticHidden}
              <SemanticFallback
                found={unionResponse.found}
                {query}
                shown={false}
                onShow={() => setUnionSemantic(true)}
                onHide={() => setUnionSemantic(false)}
              />
            {/if}
          {:else}
            {#if unionSemanticOnly}
              <!-- Opted in: rendered, but never unlabelled. -->
              <SemanticFallback
                found={unionResponse.found}
                {query}
                shown={true}
                onShow={() => setUnionSemantic(true)}
                onHide={() => setUnionSemantic(false)}
              />
            {/if}
            <p class="iwac-fed__union-count">
              {formatNumber(unionResponse.found)}
              {i18n.tp(unionSemanticOnly ? 'semantic_result' : 'result', unionResponse.found)}
            </p>
            <ol class="iwac-fed__union-list">
              {#each unionResponse.hits as hit (hit.document.id)}
                <li>
                  <ResultItem {hit} activeFilters={{}} onFacetToggle={handleUnionChip} />
                </li>
              {/each}
            </ol>
            {#if unionTotalPages > 1}
              <Pagination
                currentPage={unionPage}
                totalPages={unionTotalPages}
                onPageChange={changeUnionPage}
              />
            {/if}
            {#if unionCapped && unionPage >= unionTotalPages}
              <!-- Only at the cap: saying this up front would read as a
                   limitation on a list most people never page through. -->
              <p class="iwac-fed__cap" role="status">{t('union_cap_hint')}</p>
            {/if}
          {/if}
        {/if}
      </div>
    {:else}
      {#key activeTab}
        <App bootstrap={activeBootstrap} showSearchBox={false} sharedQuery={query} />
      {/key}
    {/if}
  </div>
</div>

<style>
  .iwac-fed {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 1rem);
    color: var(--ink, #13161c);
  }

  /*
   * Shared query box. The field itself is SearchInput (same component the
   * per-tab surfaces use), so this only owns the measure — everything
   * inside, including the clear button, is the component's.
   */
  .iwac-fed__search {
    max-width: var(--measure-narrow, 44rem);
  }
  .iwac-fed__search :global(.iwac-input) {
    width: 100%;
  }

  /*
   * End-of-merged-list note. Quiet — it is guidance at a boundary, not a
   * warning: the answer is almost always to narrow the query or switch to a
   * per-collection tab, both of which are one click away.
   */
  .iwac-fed__cap {
    margin: var(--space-4, 1rem) 0 0;
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    text-align: center;
  }

  /*
   * Result-type tabs — the stack's RULED tab (the grammar of the theme's
   * "How to cite" strip and its masthead nav): a tracked uppercase strip on a
   * hairline, ink labels, and the selected tab in ink-strong over a 2px
   * --primary underline. No fill, no pill, no radius: a filled orange tab was
   * the one control here DESIGN-PHILOSOPHY rules out by name, and it needed
   * twenty !importants to beat the theme's button base. The descendant
   * selectors below outrank that base's hover rule
   * (button:hover:not(:disabled):not(.disabled)) on specificity alone.
   */
  .iwac-fed__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-6, 1.5rem);
    border-block-end: 1px solid var(--border, #ced1d6);
  }
  .iwac-fed__tabs .iwac-fed__tab {
    display: inline-flex;
    align-items: baseline;
    gap: 0.4rem;
    margin: 0 0 -1px; /* sit the underline on the strip's hairline */
    padding: var(--space-2, 0.5rem) 0;
    background: none;
    color: var(--ink, #13161c);
    border: 0;
    border-block-end: 2px solid transparent;
    border-radius: 0;
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: var(--tracking-wide, 0.04em);
    text-transform: uppercase;
    transition:
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-fed__tabs .iwac-fed__tab:hover:not(:disabled) {
    background: none;
    color: var(--ink-strong, #05070c);
    border-color: transparent;
    border-block-end-color: var(--border-strong, #aeb1b7);
  }
  .iwac-fed__tabs .iwac-fed__tab--active,
  .iwac-fed__tabs .iwac-fed__tab--active:hover:not(:disabled) {
    color: var(--ink-strong, #05070c);
    border-block-end-color: var(--primary, #ce4115);
  }
  /* An empty tab steps down by colour, never by opacity: it is still a
     control, and --muted is the token built to clear 4.5:1. */
  .iwac-fed__tabs .iwac-fed__tab--empty:not(.iwac-fed__tab--active):not(:hover) {
    color: var(--muted, #66696e);
  }
  .iwac-fed__tabs .iwac-fed__tab:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-fed__tab-count {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0;
    color: var(--muted, #66696e);
  }

  .iwac-fed__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }

  /* Union "All" tab — a lean merged list (ResultItem rows + pagination). */
  .iwac-fed__union {
    display: flex;
    flex-direction: column;
    gap: var(--space-4, 1rem);
    min-width: 0;
  }
  /* Focused programmatically after a page change; :focus-visible untouched. */
  .iwac-fed__union:focus:not(:focus-visible) {
    outline: none;
  }
  .iwac-fed__union-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
  }
  .iwac-fed__union-count,
  .iwac-fed__union-status {
    margin: 0;
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }

  .iwac-fed__union-error {
    align-items: flex-start;
    background: color-mix(in oklab, var(--error, #c9222b) 12%, var(--surface, #fdfcfb));
    border: 1px solid color-mix(in oklab, var(--error, #c9222b) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-4, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-1, 0.25rem);
  }
</style>

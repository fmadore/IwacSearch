<script lang="ts">
  import type {
    ActiveFilters,
    IwacBootstrap,
    IwacFederatedBootstrap,
    IwacSearchResponse,
  } from '../lib/types';
  import { TypesenseClient } from '../lib/typesense';
  import { isAbortError } from '../lib/transport';
  import { provideI18n, normalizeLocale, type Locale } from '../lib/i18n';
  import App from '../App.svelte';
  import ResultItem from './ResultItem.svelte';
  import Pagination from './Pagination.svelte';
  import SearchInput from './SearchInput.svelte';

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
  const { t } = provideI18n(locale, 'content');

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

  // A new committed query restarts the merged list at page 1.
  $effect(() => {
    void query;
    unionPage = 1;
  });

  $effect(() => {
    if (activeTab !== 'all') return;
    const q = query;
    const p = unionPage;
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
        unionError = e instanceof Error ? e.message : String(e);
        unionResponse = null;
        unionLoading = false;
      });
  });

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
    activeTab = target;
  }

  // Mirror state into the URL so a federated search is shareable /
  // bookmarkable. A changed committed query or tab PUSHES (back-button-able,
  // matching the per-collection App); the first sync after mount replaces.
  // The early return when the URL already matches is what keeps popstate
  // re-hydration from pushing a duplicate entry.
  let prevUrlState: { q: string; tab: TabId } | null = null;
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
    if (url.toString() === window.location.href) return;
    if (prev === null) {
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
      query = q;
      inputValue = q;
      activeTab = readTabFromUrl();
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
    query = next.trim();
  }

  function selectTab(id: TabId): void {
    activeTab = id;
  }

  /** Tab order: the merged ranking first, then the per-collection views. */
  const tabIds: TabId[] = ['all', ...tabs.map((tab) => tab.id)];

  /**
   * Keyboard support for the roving-tabindex tablist (WAI-ARIA tabs
   * pattern): arrows move focus AND selection, Home/End jump to the ends.
   * Without this, the inactive tabs (tabindex="-1") are unreachable by
   * keyboard entirely.
   */
  function onTablistKeydown(e: KeyboardEvent): void {
    const idx = tabIds.indexOf(activeTab);
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
    const id = tabIds[nextIdx];
    selectTab(id);
    document.getElementById(`iwac-fed-tab-${id}`)?.focus();
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
      return values.reduce((a: number, b) => a + (b as number), 0).toLocaleString();
    }
    const n = counts[id];
    return typeof n === 'number' ? n.toLocaleString() : '';
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

<div class="iwac-fed">
  <div class="iwac-fed__search" role="search">
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
        tabindex={id === activeTab ? 0 : -1}
        onclick={() => selectTab(id)}
      >
        <span class="iwac-fed__tab-label">{tabLabel(id)}</span>
        {#if countLabel(id) !== ''}
          <span class="iwac-fed__tab-count">{countLabel(id)}</span>
        {/if}
      </button>
    {/each}
  </div>

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
      <div class="iwac-fed__union" aria-busy={unionLoading}>
        {#if unionError}
          <div class="iwac-fed__union-error" role="alert">
            <strong>{t('search_unavailable')}</strong>
            <span>{unionError}</span>
          </div>
        {:else if unionLoading && !unionResponse}
          <p class="iwac-fed__union-status" aria-live="polite">{t('searching')}</p>
        {:else if unionResponse}
          {#if unionResponse.found === 0}
            <p class="iwac-fed__union-status">{t('results_empty_list')}</p>
          {:else}
            <p class="iwac-fed__union-count">
              {unionResponse.found.toLocaleString()}
              {t(unionResponse.found === 1 ? 'result_one' : 'result_other')}
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
                onPageChange={(next) => (unionPage = next)}
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
    gap: var(--space-md, 1rem);
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
    margin: var(--space-md, 1rem) 0 0;
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    text-align: center;
  }

  /* Type tabs. */
  .iwac-fed__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  /*
   * The IWAC theme styles every <button> as a primary pill; guard the
   * hijacked properties so tabs read as quiet chips, with the active tab in
   * the brand colour on purpose.
   */
  .iwac-fed__tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.85rem;
    margin: 0;
    border: 1px solid var(--border, #ced1d6) !important;
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfcfb) !important;
    color: var(--ink, #13161c) !important;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1.2;
    cursor: pointer;
    box-shadow: none !important;
    transform: none !important;
  }
  .iwac-fed__tab:hover {
    border-color: var(--primary, #ce4115) !important;
    color: var(--primary, #ce4115) !important;
    background: var(--surface, #fdfcfb) !important;
  }
  .iwac-fed__tab--active,
  .iwac-fed__tab--active:hover {
    background: var(--primary, #ce4115) !important;
    border-color: var(--primary, #ce4115) !important;
    color: var(--white, #fff) !important;
    font-weight: 600;
  }
  .iwac-fed__tab--empty:not(.iwac-fed__tab--active) {
    opacity: 0.55;
  }
  .iwac-fed__tab:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(206, 65, 21, 0.3)) !important;
  }
  .iwac-fed__tab-count {
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.8125rem);
    opacity: 0.85;
  }

  /* Union "All" tab — a lean merged list (ResultItem rows + pagination). */
  .iwac-fed__union {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    min-width: 0;
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
    background: color-mix(in oklab, var(--error, #c9222b) 12%, var(--surface, #fdfcfb));
    border: 1px solid color-mix(in oklab, var(--error, #c9222b) 35%, transparent);
    border-radius: var(--radius-md, 0.5rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
</style>

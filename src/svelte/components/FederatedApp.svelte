<script lang="ts">
  import type { IwacBootstrap, IwacFederatedBootstrap } from '../lib/types';
  import { TypesenseClient } from '../lib/typesense';
  import { normalizeLocale, translate, type Locale } from '../lib/i18n';
  import App from '../App.svelte';

  /**
   * The federated "search everything" page. One instance per page.
   *
   * Owns a shared query + the active tab (Content | Entities). On every
   * committed query it runs one counts-only multi_search across both
   * collections (TypesenseClient.countAcross) to label the tabs, then mounts
   * the existing per-collection {@link App} for the active tab — its own
   * search box suppressed — so facets, sort and paging keep working unchanged.
   * `?q=` + `?tab=` are kept in the URL so a federated search is shareable.
   */

  type TabId = 'content' | 'entities';

  interface Props {
    bootstrap: IwacFederatedBootstrap;
  }

  const { bootstrap }: Props = $props();

  // svelte-ignore state_referenced_locally
  const locale: Locale = normalizeLocale(bootstrap.locale);
  const t = (key: string, vars?: Record<string, string | number>): string =>
    translate(locale, key, vars);

  // svelte-ignore state_referenced_locally
  const tabs = bootstrap.tabs;

  function readTabFromUrl(): TabId {
    if (typeof window === 'undefined') return bootstrap.default_tab;
    const param = new URLSearchParams(window.location.search).get('tab');
    return param === 'entities' || param === 'content' ? param : bootstrap.default_tab;
  }

  // svelte-ignore state_referenced_locally
  let query = $state(bootstrap.initial_query ?? '');
  // svelte-ignore state_referenced_locally
  let inputValue = $state(bootstrap.initial_query ?? '');
  let activeTab = $state<TabId>(readTabFromUrl());
  let counts = $state<Record<string, number | null>>({});
  let countsReady = $state(false);
  let inputTimer: number | null = null;

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

  const countCollections = tabs.map((tab) => ({
    collection: tab.bootstrap.collection_alias ?? 'iwac_current',
    queryBy: tab.bootstrap.query_by ?? 'title_txt',
    filterBy: tab.bootstrap.locked_filters || undefined,
  }));

  let countReq = 0;
  async function loadCounts(q: string): Promise<void> {
    const myId = ++countReq;
    try {
      const found = await countClient.countAcross(q, countCollections);
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

  // Mirror state into the URL so a federated search is shareable / bookmarkable.
  $effect(() => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (query) {
      url.searchParams.set('q', query);
    } else {
      url.searchParams.delete('q');
    }
    url.searchParams.set('tab', activeTab);
    window.history.replaceState(window.history.state, '', url.toString());
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

  function onInput(e: Event): void {
    inputValue = (e.target as HTMLInputElement).value;
    if (inputTimer !== null) clearTimeout(inputTimer);
    inputTimer = window.setTimeout(() => {
      inputTimer = null;
      query = inputValue.trim();
    }, 250);
  }

  function clearQuery(): void {
    if (inputTimer !== null) {
      clearTimeout(inputTimer);
      inputTimer = null;
    }
    inputValue = '';
    query = '';
  }

  function selectTab(id: TabId): void {
    activeTab = id;
  }

  function tabLabel(id: TabId): string {
    return id === 'entities' ? t('tab_entities') : t('tab_content');
  }

  function countLabel(id: string): string {
    const n = counts[id];
    return typeof n === 'number' ? n.toLocaleString() : '';
  }

  // The active tab's full per-collection bootstrap, seeded with the shared
  // query. The {#key} below re-mounts App when the tab or query changes so it
  // re-seeds cleanly (App reads initial_query once at init).
  const activeBootstrap = $derived.by<IwacBootstrap>(() => {
    const base = tabs.find((tab) => tab.id === activeTab)?.bootstrap ?? tabs[0].bootstrap;
    return {
      ...base,
      initial_query: query,
      // The SSR'd first page is for the empty-query landing only; a typed
      // query must fetch fresh rather than flash the all-content snapshot.
      initial_response: query === '' ? base.initial_response : undefined,
    };
  });
</script>

<div class="iwac-fed">
  <div class="iwac-fed__search" role="search">
    <input
      name="q"
      class="iwac-fed__input"
      type="search"
      autocomplete="off"
      spellcheck="false"
      inputmode="search"
      aria-label={t('search_everything')}
      placeholder={t('search_everything')}
      value={inputValue}
      oninput={onInput}
    />
    {#if inputValue !== ''}
      <button
        type="button"
        class="iwac-fed__clear"
        aria-label={t('clear_search')}
        onclick={clearQuery}>×</button
      >
    {/if}
  </div>

  <div class="iwac-fed__tabs" role="tablist" aria-label={t('result_types')}>
    {#each tabs as tab (tab.id)}
      <button
        type="button"
        role="tab"
        id="iwac-fed-tab-{tab.id}"
        aria-selected={tab.id === activeTab}
        aria-controls="iwac-fed-panel"
        class="iwac-fed__tab"
        class:iwac-fed__tab--active={tab.id === activeTab}
        class:iwac-fed__tab--empty={countsReady && (counts[tab.id] ?? 0) === 0}
        tabindex={tab.id === activeTab ? 0 : -1}
        onclick={() => selectTab(tab.id)}
      >
        <span class="iwac-fed__tab-label">{tabLabel(tab.id)}</span>
        {#if countLabel(tab.id) !== ''}
          <span class="iwac-fed__tab-count">{countLabel(tab.id)}</span>
        {/if}
      </button>
    {/each}
  </div>

  <div class="iwac-fed__panel" id="iwac-fed-panel" role="tabpanel">
    {#key activeTab + '::' + query}
      <App bootstrap={activeBootstrap} showSearchBox={false} />
    {/key}
  </div>
</div>

<style>
  .iwac-fed {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
    color: var(--ink, #2c2f37);
  }

  /* Shared query box. */
  .iwac-fed__search {
    position: relative;
    display: flex;
    align-items: center;
    max-width: var(--measure-narrow, 36rem);
  }
  .iwac-fed__input {
    width: 100%;
    height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-md, 1rem) var(--space-2xl, 3rem);
    margin: 0;
    font: inherit;
    font-size: var(--text-base, 1.0625rem);
    color: var(--ink, #2c2f37);
    background: var(--surface, #fdfdfd);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
  }
  .iwac-fed__input:focus-visible {
    outline: none;
    border-color: var(--primary, #e64a19);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-fed__input::-webkit-search-cancel-button {
    -webkit-appearance: none;
    appearance: none;
    display: none;
  }
  .iwac-fed__clear {
    position: absolute;
    inset-inline-end: var(--space-sm, 0.5rem);
    top: 50%;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
    color: var(--muted, #767880);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-fed__clear:hover {
    background: color-mix(in oklab, currentColor 14%, transparent);
    color: var(--ink, #2c2f37);
  }

  /* Type tabs. */
  .iwac-fed__tabs {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
    padding-block-end: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e6e7eb);
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
    border: 1px solid var(--border, #d4d6da) !important;
    border-radius: var(--radius-full, 9999px);
    background: var(--surface, #fdfdfd) !important;
    color: var(--ink, #2c2f37) !important;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1.2;
    cursor: pointer;
    box-shadow: none !important;
    transform: none !important;
  }
  .iwac-fed__tab:hover {
    border-color: var(--primary, #e64a19) !important;
    color: var(--primary, #e64a19) !important;
    background: var(--surface, #fdfdfd) !important;
  }
  .iwac-fed__tab--active,
  .iwac-fed__tab--active:hover {
    background: var(--primary, #e64a19) !important;
    border-color: var(--primary, #e64a19) !important;
    color: var(--white, #fff) !important;
    font-weight: 600;
  }
  .iwac-fed__tab--empty:not(.iwac-fed__tab--active) {
    opacity: 0.55;
  }
  .iwac-fed__tab:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.15)) !important;
  }
  .iwac-fed__tab-count {
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.8125rem);
    opacity: 0.85;
  }
</style>

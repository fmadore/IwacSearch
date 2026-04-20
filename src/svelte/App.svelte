<script lang="ts">
  import type { IwacBootstrap, IwacSearchResponse } from './lib/types';
  import { TypesenseClient } from './lib/typesense';
  import SearchInput from './components/SearchInput.svelte';
  import ResultsList from './components/ResultsList.svelte';

  /**
   * One App instance per mount target. Owns:
   *   - the query string (from URL or empty)
   *   - the page number (from URL or 1)
   *   - the latest search response
   *   - loading + error state
   *
   * URL state sync is intentionally limited in M1: read-on-mount only.
   * M2 wires it bidirectionally so the back button and sharing work.
   */

  interface Props {
    bootstrap: IwacBootstrap;
  }

  const { bootstrap }: Props = $props();

  // URL state: only honoured on the standalone /search route. Page
  // blocks would clobber each other if multiple instances all read
  // window.location. Wrapped in $derived to satisfy svelte-check's
  // reactivity contract — bootstrap is server-emitted and stable per
  // mount, so these only ever compute once in practice.
  const isStandalone = $derived(String(bootstrap.block_id) === 'standalone');
  const initialState = $derived.by(() => {
    const params = isStandalone ? new URLSearchParams(window.location.search) : null;
    return {
      q: params?.get('q') ?? '',
      page: Number(params?.get('page') ?? '1') || 1,
    };
  });

  let query = $state('');
  let page = $state(1);
  let response = $state<IwacSearchResponse | null>(null);
  let isLoading = $state(false);
  let error = $state<string | null>(null);

  // Hydrate state from URL once on mount.
  $effect(() => {
    query = initialState.q;
    page = initialState.page;
  });

  // The TypesenseClient holds a cached scoped key, so we want exactly
  // one instance per mount. $derived.by reruns only if bootstrap changes,
  // which it doesn't post-mount — same effect as `const`, but tells
  // svelte-check that reactive reads are intentional.
  const client = $derived.by(() => new TypesenseClient(bootstrap));

  // Track query → search. The SearchInput component already debounces
  // 250 ms so we don't need an extra layer here.
  $effect(() => {
    const q = query;
    const p = page;
    if (!q.trim()) {
      response = null;
      error = null;
      return;
    }
    isLoading = true;
    error = null;
    client
      .search({ q, page: p })
      .then((r) => {
        response = r;
      })
      .catch((e: Error) => {
        console.error('[iwac-search] search failed', e);
        error = e.message;
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

  function loadMore(): void {
    if (!response) return;
    if (response.hits.length >= response.found) return;
    page += 1;
  }
</script>

<div class="iwac-search">
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

  {#if isLoading && !response}
    <p class="iwac-search__status" aria-live="polite">Searching…</p>
  {:else if response}
    <ResultsList {response} onLoadMore={loadMore} {isLoading} />
  {:else if query.trim() === '' && bootstrap.mode === 'full'}
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

<script module lang="ts">
  function formatCount(n: number): string {
    return new Intl.NumberFormat().format(n);
  }
</script>

<script lang="ts">
  import type { IwacSearchResponse } from '../lib/types';
  import ResultItem from './ResultItem.svelte';

  /**
   * Renders the result list + the load-more affordance.
   *
   * Pagination strategy: load-more (cursor-style append) rather than
   * numbered pages. Search results are usually scanned top-to-bottom;
   * paged UIs cause visitors to lose their place if they accidentally
   * back-navigate. Numbered pagination lands in M2 alongside facets.
   */

  interface Props {
    response: IwacSearchResponse;
    onLoadMore: () => void;
    isLoading: boolean;
  }

  const { response, onLoadMore, isLoading }: Props = $props();

  const totalShown = $derived(response.hits.length);
  const hasMore = $derived(totalShown < response.found);
</script>

<div class="iwac-results">
  <header class="iwac-results__meta" aria-live="polite">
    <strong>{formatCount(response.found)}</strong>
    {response.found === 1 ? 'result' : 'results'}
    <span class="iwac-results__timing">({response.search_time_ms} ms)</span>
  </header>

  {#if response.hits.length === 0}
    <p class="iwac-results__empty">No matches. Try a different word or remove a filter.</p>
  {:else}
    <ol class="iwac-results__list">
      {#each response.hits as hit (hit.document.id)}
        <li class="iwac-results__item">
          <ResultItem {hit} />
        </li>
      {/each}
    </ol>

    {#if hasMore}
      <button type="button" class="iwac-results__more" onclick={onLoadMore} disabled={isLoading}>
        {isLoading
          ? 'Loading…'
          : `Load more (${formatCount(response.found - totalShown)} remaining)`}
      </button>
    {/if}
  {/if}
</div>

<style>
  .iwac-results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }
  .iwac-results__meta {
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
  }
  .iwac-results__timing {
    margin-inline-start: var(--space-xs, 0.25rem);
    opacity: 0.7;
  }
  .iwac-results__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }
  .iwac-results__item {
    margin: 0;
  }
  .iwac-results__empty {
    color: var(--muted, #666);
    padding: var(--space-md, 1rem);
    background: var(--surface-sunken, #f5f5f5);
    border-radius: var(--radius-md, 0.75rem);
    margin: 0;
  }
  .iwac-results__more {
    align-self: center;
    height: var(--size-control-lg, 2.75rem);
    padding-inline: var(--space-lg, 1.5rem);
    background: var(--surface-raised, #fafafa);
    color: var(--ink, #222);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    font-size: var(--text-base, 1rem);
    cursor: pointer;
    transition:
      background 120ms ease,
      transform 120ms ease;
  }
  .iwac-results__more:hover:not(:disabled) {
    background: var(--surface, #fff);
    transform: translateY(var(--lift-xxs, -1px));
  }
  .iwac-results__more:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }
  .iwac-results__more:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
</style>

<script lang="ts">
  import type { ActiveFilters, IwacSearchResponse } from '../lib/types';
  import { useI18n } from '../lib/i18n';
  import ResultItem from './ResultItem.svelte';
  import Pagination from './Pagination.svelte';

  /**
   * Renders the result list and the numbered pagination bar.
   *
   * The result count + sort dropdown live in App.svelte's toolbar so
   * they sit on the same line and never duplicate. This component
   * focuses on rendering hits and paging through them.
   *
   * Pagination strategy: numbered (1 … 4 [5] 6 … 12  ‹ ›). The earlier
   * "Load more" affordance was scrapped because:
   *   - users couldn't jump to a specific page (e.g. share a deep link
   *     to "page 5 of the Nigeria results"),
   *   - long-running browse sessions accumulated DOM as they paged,
   *   - the URL state already carried `page` for back-button support
   *     but the UI never surfaced the page number. Numbered pagination
   *     finishes that loop.
   *
   * No hits cap: the search request sends no `limit_hits`, so totalPages is
   * computed straight from `found` — every match is reachable. Pagination
   * windows the bar (1 … 4998 [4999] 5000), so a deep result set is fine.
   */

  interface Props {
    response: IwacSearchResponse;
    perPage: number;
    onPageChange: (next: number) => void;
    /** Currently-active categorical filters — drives the cards' badge state. */
    activeFilters: ActiveFilters;
    /** Toggle a facet from a result-card badge (author, newspaper, type…). */
    onFacetToggle: (field: string, value: string, nextChecked: boolean) => void;
  }

  const { response, perPage, onPageChange, activeFilters, onFacetToggle }: Props = $props();

  const { t } = useI18n();

  const totalPages = $derived(Math.max(1, Math.ceil(response.found / Math.max(1, perPage))));
</script>

<div class="iwac-results">
  {#if response.hits.length === 0}
    <p class="iwac-results__empty">{t('results_empty_list')}</p>
  {:else}
    <ol class="iwac-results__list">
      {#each response.hits as hit (hit.document.id)}
        <li class="iwac-results__item">
          <ResultItem {hit} {activeFilters} {onFacetToggle} />
        </li>
      {/each}
    </ol>

    <Pagination currentPage={response.page} {totalPages} {onPageChange} />
  {/if}
</div>

<style>
  .iwac-results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }
  /* Ruled ledger: hairlines between rows, closed top and bottom. */
  .iwac-results__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0;
    border-block-end: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-results__item {
    margin: 0;
    border-block-start: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-results__empty {
    color: var(--muted, #767880);
    padding: var(--space-md, 1rem);
    background: var(--surface-sunken, #f3f3f1);
    border-radius: var(--radius-md, 0.5rem);
    margin: 0;
  }
</style>

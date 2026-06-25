<script lang="ts">
  import type { ActiveFilters, IwacSearchResponse, ViewMode } from '../lib/types';
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
    /** Hide the country chip on cards (single-country scopes). */
    hideCountry?: boolean;
    /** List ledger (default) or image-forward gallery grid. */
    view?: ViewMode;
  }

  const {
    response,
    perPage,
    onPageChange,
    activeFilters,
    onFacetToggle,
    hideCountry = false,
    view = 'list',
  }: Props = $props();

  const { t } = useI18n();

  const totalPages = $derived(Math.max(1, Math.ceil(response.found / Math.max(1, perPage))));
</script>

<div class="iwac-results">
  {#if response.hits.length === 0}
    <p class="iwac-results__empty">{t('results_empty_list')}</p>
  {:else if view === 'gallery'}
    <div class="iwac-results__gallery">
      {#each response.hits as hit (hit.document.id)}
        <ResultItem {hit} {activeFilters} {onFacetToggle} {hideCountry} layout="gallery" />
      {/each}
    </div>

    <Pagination currentPage={response.page} {totalPages} {onPageChange} />
  {:else}
    <ol class="iwac-results__list">
      {#each response.hits as hit (hit.document.id)}
        <li class="iwac-results__item">
          <ResultItem {hit} {activeFilters} {onFacetToggle} {hideCountry} layout="list" />
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
    border-block-end: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-results__item {
    margin: 0;
    border-block-start: 1px solid var(--border-light, #e2e5e8);
  }
  /*
   * Gallery grid: image-forward tiles, no boxy cards. auto-fill keeps tiles a
   * comfortable browsing size and reflows to the column count that fits — wide
   * on desktop, two-up on a phone. Matches ResultSkeleton's gallery geometry.
   */
  .iwac-results__gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: var(--space-lg, 1.5rem);
  }
  @media (max-width: 30rem) {
    .iwac-results__gallery {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--space-md, 1rem);
    }
  }
  .iwac-results__empty {
    color: var(--muted, #66696e);
    padding: var(--space-md, 1rem);
    background: var(--surface-sunken, #f4f1ef);
    border-radius: var(--radius-md, 0.5rem);
    margin: 0;
  }
</style>

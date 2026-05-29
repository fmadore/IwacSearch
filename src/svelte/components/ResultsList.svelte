<script lang="ts">
  import type { IwacSearchResponse } from '../lib/types';
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
   * Typesense's `limit_hits` cap (250 in lib/typesense.ts) bounds the
   * deepest page the user can request. We compute totalPages from the
   * lesser of `found` and that cap so the bar never invites a click
   * that would 422.
   */

  interface Props {
    response: IwacSearchResponse;
    perPage: number;
    /**
     * Match the `limit_hits` value sent in the search request so the
     * deepest page is always within Typesense's reach. App.svelte
     * passes the same constant the typesense client uses (250).
     */
    hitsCap: number;
    onPageChange: (next: number) => void;
  }

  const { response, perPage, hitsCap, onPageChange }: Props = $props();

  const { t } = useI18n();

  const reachable = $derived(Math.min(response.found, hitsCap));
  const totalPages = $derived(Math.max(1, Math.ceil(reachable / Math.max(1, perPage))));
</script>

<div class="iwac-results">
  {#if response.hits.length === 0}
    <p class="iwac-results__empty">{t('results_empty_list')}</p>
  {:else}
    <ol class="iwac-results__list">
      {#each response.hits as hit (hit.document.id)}
        <li class="iwac-results__item">
          <ResultItem {hit} />
        </li>
      {/each}
    </ol>

    <Pagination currentPage={response.page} {totalPages} {onPageChange} />

    {#if response.found > hitsCap}
      <p class="iwac-results__cap-note" role="note">
        {t('cap_note', { cap: hitsCap.toLocaleString() })}
      </p>
    {/if}
  {/if}
</div>

<style>
  .iwac-results {
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
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
  .iwac-results__cap-note {
    margin: 0;
    text-align: center;
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
  }
</style>

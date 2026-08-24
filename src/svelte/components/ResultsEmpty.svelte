<script lang="ts">
  /**
   * Scope-aware empty state (design review §02 + punch-list item 3). Instead of
   * a generic "No results", it names the active scope and offers the offending
   * filters for removal right there:
   *
   *   No results in  [Burkina Faso ×] · [1989 – 2010 ×]
   *   Try removing a filter or two.        [ Clear all filters ]
   *
   * Same chips as the summary strip + FacetPanel (shared deriveActiveChips), so
   * the empty state points at exactly the scope the user set. Falls back to the
   * broaden-query and corpus-empty messages when there are no active filters.
   */
  import type { ActiveFilters, YearRange } from '../lib/types';
  import { useI18n } from '../lib/i18n';
  import { deriveActiveChips, type ActiveFilterChip } from '../lib/filterChips';
  import FilterChip from './FilterChip.svelte';

  interface Props {
    filters: ActiveFilters;
    yearRange: YearRange | null;
    query: string;
    onRemoveChip: (chip: ActiveFilterChip) => void;
    onClearAll: () => void;
  }

  const { filters, yearRange, query, onRemoveChip, onClearAll }: Props = $props();

  const { locale, t } = useI18n();

  const chips = $derived(deriveActiveChips({ selected: filters, yearRange, locale, t }));
  const hasChips = $derived(chips.length > 0);
  const hasQuery = $derived(query.trim() !== '');
</script>

<div class="iwac-empty" role="status">
  {#if hasChips}
    <p class="iwac-empty__scope">
      <span class="iwac-empty__lead">{t('no_results_in_scope')}</span>
      <span class="iwac-empty__chips">
        {#each chips as chip, i (chip.field + '|' + chip.value)}
          <FilterChip
            {chip}
            onRemove={onRemoveChip}
            showField={false}
            size="md"
          />{#if i < chips.length - 1}<span class="iwac-empty__sep" aria-hidden="true">·</span>{/if}
        {/each}
      </span>
    </p>
    <p class="iwac-empty__hint">{t('try_removing_filter')}</p>
    <button type="button" class="iwac-empty__clear" onclick={onClearAll}>
      {t('clear_all_filters')}
    </button>
  {:else if hasQuery}
    <strong>{t('no_results_title')}</strong>
    <p class="iwac-empty__hint">{t('try_broader_query')}</p>
  {:else}
    <strong>{t('no_results_title')}</strong>
    <p class="iwac-empty__hint">{t('corpus_empty')}</p>
  {/if}
</div>

<style>
  .iwac-empty {
    /* Quiet text on the page surface — an empty ledger, not a dashed bin. */
    padding: var(--space-2xl, 3rem) var(--space-lg, 1.5rem);
    border-block-end: 1px solid var(--border-light, #e2e5e8);
    text-align: center;
    color: var(--muted, #66696e);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-empty strong {
    color: var(--ink-strong, #05070c);
    font-size: var(--text-lg, 1.1875rem);
  }
  .iwac-empty__scope {
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: center;
    gap: 0.4rem;
    font-size: var(--text-lg, 1.1875rem);
    color: var(--ink-strong, #05070c);
  }
  .iwac-empty__lead {
    font-weight: 600;
  }
  /*
   * Removable scope token. Outlined in primary like the summary chips, but the
   * offending value is the headline here, so it carries a touch more weight.
   * The IWAC theme paints every <button>; resets keep it an outline chip.
   */
  .iwac-empty__sep {
    color: var(--primary, #ce4115);
    font-weight: 700;
  }
  .iwac-empty__hint {
    margin: 0;
  }
  /* Outlined clear control — same vocabulary as App's old clear-link. */
  .iwac-empty__clear {
    background: none;
    border: 1px solid var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    border-radius: var(--radius-md, 0.5rem);
    padding: 0.4rem 0.75rem;
    box-shadow: none;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    margin-top: var(--space-xs, 0.25rem);
    transition:
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-empty__clear:hover {
    background: var(--primary, #ce4115);
    color: var(--white, #fff);
    box-shadow: none;
    transform: none;
  }
  .iwac-empty__clear:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
</style>

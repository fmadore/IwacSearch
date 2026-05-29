<script module lang="ts">
  /**
   * Build a compact page-number window around the current page.
   *
   *   1 … 4 [5] 6 … 12
   *
   * Always shows the first and last pages so users can jump to either
   * end. The window around `current` is symmetric (current ± 1) and
   * never overlaps the bookend pages — gaps collapse into a single "…"
   * marker so the bar never grows wider than 7 cells.
   *
   * Pure function so it can be unit-tested without mounting the
   * component if we ever wire up tests.
   */
  export function pageWindow(current: number, total: number): Array<number | 'gap'> {
    if (total <= 7) {
      return Array.from({ length: total }, (_, i) => i + 1);
    }
    const out: Array<number | 'gap'> = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    if (start > 2) out.push('gap');
    for (let i = start; i <= end; i++) out.push(i);
    if (end < total - 1) out.push('gap');
    out.push(total);
    return out;
  }
</script>

<script lang="ts">
  /**
   * Numbered pagination bar.
   *
   *   ‹ Prev   1 … 4 [5] 6 … 12   Next ›
   *
   * Wired to App.svelte's `page` state. Click → `onPageChange(next)`,
   * which the parent uses to update its `page` $state and trigger a
   * fresh search via the existing reactive effect. The parent also
   * scrolls back to the top of the results column so page 5 lands
   * starting at the first result, not partway through page 4.
   *
   * Renders nothing when there's only one page — pagination on a
   * single-page result set is just visual noise.
   */

  import { useI18n } from '../lib/i18n';

  interface Props {
    currentPage: number;
    totalPages: number;
    onPageChange: (next: number) => void;
  }

  const { currentPage, totalPages, onPageChange }: Props = $props();

  const { t } = useI18n();

  const items = $derived(pageWindow(currentPage, totalPages));
  const hasPrev = $derived(currentPage > 1);
  const hasNext = $derived(currentPage < totalPages);

  function go(n: number): void {
    if (n < 1 || n > totalPages || n === currentPage) return;
    onPageChange(n);
  }
</script>

{#if totalPages > 1}
  <nav class="iwac-pager" aria-label={t('results_pagination')}>
    <button
      type="button"
      class="iwac-pager__nav"
      disabled={!hasPrev}
      onclick={() => go(currentPage - 1)}
      aria-label={t('previous_page')}
    >
      <span aria-hidden="true">‹</span>
      <span class="iwac-pager__nav-label">{t('prev')}</span>
    </button>

    <ol class="iwac-pager__list">
      {#each items as it, i (i + ':' + it)}
        <li class="iwac-pager__item">
          {#if it === 'gap'}
            <span class="iwac-pager__gap" aria-hidden="true">…</span>
          {:else}
            <button
              type="button"
              class="iwac-pager__page"
              class:is-current={it === currentPage}
              aria-current={it === currentPage ? 'page' : undefined}
              aria-label={t('page_n', { n: it })}
              onclick={() => go(it)}
            >
              {it}
            </button>
          {/if}
        </li>
      {/each}
    </ol>

    <button
      type="button"
      class="iwac-pager__nav"
      disabled={!hasNext}
      onclick={() => go(currentPage + 1)}
      aria-label={t('next_page')}
    >
      <span class="iwac-pager__nav-label">{t('next')}</span>
      <span aria-hidden="true">›</span>
    </button>
  </nav>
{/if}

<style>
  .iwac-pager {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm, 0.5rem);
    flex-wrap: wrap;
    padding-block: var(--space-md, 1rem);
  }
  .iwac-pager__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-pager__item {
    margin: 0;
  }
  .iwac-pager__page,
  .iwac-pager__nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-xs, 0.25rem);
    min-width: var(--size-control-md, 2.5rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-pager__page:hover:not(.is-current),
  .iwac-pager__nav:hover:not(:disabled) {
    background: var(--surface, #fff);
    border-color: var(--primary, #c66);
    color: var(--primary, #c66);
    box-shadow: none;
    transform: none;
  }
  .iwac-pager__page:focus-visible,
  .iwac-pager__nav:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-pager__page.is-current {
    background: var(--primary, #c66);
    border-color: var(--primary, #c66);
    color: var(--primary-contrast, #fff);
    box-shadow: none;
    transform: none;
    font-weight: 600;
    cursor: default;
  }
  .iwac-pager__nav {
    padding-inline: var(--space-md, 1rem);
  }
  .iwac-pager__nav:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }
  .iwac-pager__nav-label {
    font-weight: 500;
  }
  .iwac-pager__gap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.5rem;
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
  }

  @media (max-width: 30rem) {
    /* Hide "Prev"/"Next" word labels on tiny screens — chevrons keep
       the meaning, and the row stops wrapping awkwardly. */
    .iwac-pager__nav-label {
      display: none;
    }
    .iwac-pager__nav {
      padding-inline: var(--space-sm, 0.5rem);
    }
  }
</style>

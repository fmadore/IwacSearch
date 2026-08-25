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
  import { PAGE_SIZES } from '../lib/urlState';
  import Icon from './Icon.svelte';

  interface Props {
    currentPage: number;
    totalPages: number;
    onPageChange: (next: number) => void;
    /**
     * Results per page, and the handler that changes it. Omitted on surfaces
     * that don't own the setting (the federated union tabs), where the bar
     * renders exactly as before.
     */
    perPage?: number;
    onPerPageChange?: (next: number) => void;
  }

  const { currentPage, totalPages, onPageChange, perPage, onPerPageChange }: Props = $props();

  const { t } = useI18n();
  // Unique per mount: several search blocks can share one page, and a
  // duplicated `for`/`id` pair points every label at the first control.
  const uid = $props.id();

  const items = $derived(pageWindow(currentPage, totalPages));
  const hasPrev = $derived(currentPage > 1);
  const hasNext = $derived(currentPage < totalPages);

  function go(n: number): void {
    if (n < 1 || n > totalPages || n === currentPage) return;
    onPageChange(n);
  }

  /**
   * ── Deep sets need more than a window ────────────────────────────────
   *
   * 16,544 results at ten a page is 1,655 pages, and the windowed bar
   * (1 … 4 [5] 6 … 1655) offers exactly four of them plus the two ends. Two
   * controls close that gap, and both belong HERE rather than in the toolbar:
   * the pager is where the reader learns how deep the set is.
   *
   *   - a page-size select, so 1,655 pages can become 331;
   *   - a jump box, shown only once the window actually elides pages
   *     (> 7 of them), so a shallow set keeps the bar it already had.
   */
  const showJump = $derived(totalPages > 7);
  /**
   * The offered sizes plus whatever this surface is configured with — a page
   * block's admin may set any 1–50, and a select that cannot show the current
   * value would silently change it the moment the reader touched it.
   */
  const sizeOptions = $derived(
    perPage === undefined
      ? []
      : Array.from(new Set([...PAGE_SIZES, perPage])).sort((a, b) => a - b),
  );

  let jumpValue = $state('');
  function submitJump(e: SubmitEvent): void {
    e.preventDefault();
    const n = Number(jumpValue);
    if (!Number.isFinite(n)) return;
    jumpValue = '';
    go(Math.max(1, Math.min(totalPages, Math.trunc(n))));
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
      <span aria-hidden="true"><Icon name="chevron-left" /></span>
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
      <span aria-hidden="true"><Icon name="chevron-right" /></span>
    </button>
  </nav>

  {#if showJump || sizeOptions.length > 1}
    <div class="iwac-pager__tools">
      {#if showJump}
        <form class="iwac-pager__jump" onsubmit={submitJump}>
          <label class="iwac-pager__jump-label" for="{uid}-jump">{t('jump_to_page')}</label>
          <input
            id="{uid}-jump"
            class="iwac-pager__jump-input"
            type="number"
            inputmode="numeric"
            min="1"
            max={totalPages}
            placeholder={String(currentPage)}
            bind:value={jumpValue}
          />
          <span class="iwac-pager__jump-total">{t('jump_of_total', { total: totalPages })}</span>
          <button type="submit" class="iwac-pager__jump-go">{t('jump_go')}</button>
        </form>
      {/if}
      {#if sizeOptions.length > 1 && onPerPageChange}
        <div class="iwac-pager__size">
          <label class="iwac-pager__size-label" for="{uid}-size">{t('per_page_label')}</label>
          <select
            id="{uid}-size"
            class="iwac-pager__size-select"
            value={perPage}
            onchange={(e) => onPerPageChange(Number(e.currentTarget.value))}
          >
            {#each sizeOptions as n (n)}
              <option value={n}>{t('per_page_n', { n })}</option>
            {/each}
          </select>
        </div>
      {/if}
    </div>
  {/if}
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
    /* Explicit, because the current page carries a 2px border where the rest
       carry 1px and the row must not step up by a pixel around it. */
    box-sizing: border-box;
    min-width: var(--size-control-md, 2.5rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-pager__page:hover:not(.is-current),
  .iwac-pager__nav:hover:not(:disabled) {
    background: var(--surface, #fdfcfb);
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    box-shadow: none;
    transform: none;
  }
  .iwac-pager__page:focus-visible,
  .iwac-pager__nav:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-pager__page.is-current {
    /*
     * Outlined, not filled. A --primary fill with a --white label measures
     * 4.78:1 in light mode and 3.23:1 in dark, because the dark theme's
     * --primary is a LIGHTER orange — fill and label converge. No single ink
     * passes on both fills (a dark ink on the light-mode fill is 3.92:1), and
     * the module may not define theme tokens, so the fill is the thing that
     * had to go. A 2px brand border under an ink-strong bold numeral reads as
     * "you are here" at 15.7:1 light and ~16:1 dark, and matches the
     * ViewToggle's ruled current-state grammar.
     */
    background: var(--surface, #fdfcfb);
    border-color: var(--primary, #ce4115);
    border-width: 2px;
    color: var(--ink-strong, #05070c);
    /* NO `box-shadow: none` here. It used to sit after the :focus-visible rule
       at equal specificity (class + pseudo-class are both 0,2,0), so it won
       on source order and the CURRENT page was the one control on the surface
       with no focus indicator at all — an outright 2.4.7 failure. It was also
       redundant: it dated from the theme's old loud-<button> base, and since
       2.10 a bare button carries no shadow. The base .iwac-pager__page rule
       already sets `box-shadow: none`, which keeps the UNFOCUSED current page
       ring-free at lower specificity while :focus-visible still wins. */
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
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }

  /*
   * Below the bar, quieter than it: a dateline-weight row of two utilities,
   * not a second navigation. Both read as running text with an inset field —
   * "Go to page [   ] of 1,655" — so the pager keeps one visual centre.
   */
  .iwac-pager__tools {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: var(--space-sm, 0.5rem) var(--space-lg, 1.5rem);
    padding-block-end: var(--space-md, 1rem);
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
  }
  .iwac-pager__jump,
  .iwac-pager__size {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-pager__jump-input {
    /* Theme global field rule adds margin-bottom — this row owns its rhythm. */
    margin: 0;
    box-sizing: border-box;
    width: 5rem;
    height: var(--size-control-sm, 2.25rem);
    padding-inline: var(--space-sm, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-variant-numeric: tabular-nums;
  }
  .iwac-pager__jump-input:focus {
    border-color: var(--primary, #ce4115);
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-pager__jump-go {
    height: var(--size-control-sm, 2.25rem);
    padding-inline: var(--space-md, 1rem);
    /* A SUBMIT control, which the theme paints filled-and-glowing by default
       (see IWAC-theme CLAUDE.md, "the loud one opts in" — submit opts in
       implicitly). This is a utility beside a pager, not the page's primary
       action, so it is quieted back to the toolbar's outlined vocabulary. */
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-pager__jump-go:hover {
    background: var(--surface, #fdfcfb);
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    box-shadow: none;
    transform: none;
  }
  .iwac-pager__jump-go:focus-visible,
  .iwac-pager__size-select:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-pager__jump-total {
    font-variant-numeric: tabular-nums;
  }
  .iwac-pager__size-select {
    margin: 0;
    box-sizing: border-box;
    height: var(--size-control-sm, 2.25rem);
    padding-inline: var(--space-sm, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
  }
  /* The visible labels ARE the accessible names; nothing is hidden here — a
     select whose only name is a placeholder option is a select you have to
     open to understand. */
  .iwac-pager__jump-label,
  .iwac-pager__size-label {
    color: var(--muted, #66696e);
  }

  @media (max-width: 599px) {
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

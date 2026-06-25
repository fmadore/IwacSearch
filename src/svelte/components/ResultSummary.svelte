<script lang="ts">
  /**
   * Persistent result-summary strip — the orientation line above the results,
   * on every viewport (design review §02). Reads as one sentence:
   *
   *   1,284 results in [Country: Burkina Faso ×] [Year: 1989 – 2010 ×]  Clear all   …  sorted by Newest
   *
   * Closed by a 2px ink rule, tying it to the section-head grammar and the
   * IwacVisualizations KPI "almanac" figures. The active-filter chips are
   * removable inline, so on a phone — where the FacetPanel hides behind the
   * drawer — this doubles as the filter readout: after applying filters and
   * closing the drawer, the screen still says what's being looked at.
   *
   * Chips come from the shared deriveActiveChips() so this can never disagree
   * with the FacetPanel sidebar or the empty state about the active scope.
   */
  import type { ActiveFilters, YearRange } from '../lib/types';
  import { sortOptions, useI18n } from '../lib/i18n';
  import { deriveActiveChips, type ActiveFilterChip } from '../lib/filterChips';

  interface Props {
    found: number;
    searchTimeMs: number;
    filters: ActiveFilters;
    yearRange: YearRange | null;
    sort: string;
    /** Remove one chip (the parent decides facet-toggle vs year-clear). */
    onRemoveChip: (chip: ActiveFilterChip) => void;
    onClearAll: () => void;
  }

  const { found, searchTimeMs, filters, yearRange, sort, onRemoveChip, onClearAll }: Props =
    $props();

  const { locale, card, t } = useI18n();

  const chips = $derived(deriveActiveChips({ selected: filters, yearRange, locale, t }));
  const hasChips = $derived(chips.length > 0);

  // Human label for the active sort (e.g. "Newest first"); empty if the value
  // isn't in this surface's option set, in which case the readout is hidden.
  const sortLabel = $derived(sortOptions(locale, card).find((o) => o.value === sort)?.label ?? '');
</script>

<section class="iwac-summary" aria-label={t('active_filters')} aria-live="polite">
  <div class="iwac-summary__line">
    <span class="iwac-summary__count-block">
      <span class="iwac-summary__count">{found.toLocaleString()}</span>
      <span class="iwac-summary__count-label">
        {found === 1 ? t('result_one') : t('result_other')}
      </span>
      {#if searchTimeMs > 0}
        <span class="iwac-summary__timing">· {searchTimeMs} ms</span>
      {/if}
      {#if hasChips}<span class="iwac-summary__in">{t('results_in_scope')}</span>{/if}
    </span>

    {#if hasChips}
      <ul class="iwac-summary__chips">
        {#each chips as chip (chip.field + '|' + chip.value)}
          <li>
            <button
              type="button"
              class="iwac-summary__chip"
              onclick={() => onRemoveChip(chip)}
              aria-label={t('remove_filter', { label: chip.label, value: chip.displayValue })}
            >
              <span class="iwac-summary__chip-field">{chip.label}:</span>
              <span class="iwac-summary__chip-value">{chip.displayValue}</span>
              <span class="iwac-summary__chip-x" aria-hidden="true">×</span>
            </button>
          </li>
        {/each}
      </ul>
      <button type="button" class="iwac-summary__clear" onclick={onClearAll}
        >{t('clear_all')}</button
      >
    {/if}

    {#if sortLabel}
      <span class="iwac-summary__sort">
        {t('sorted_by')}
        <span class="iwac-summary__sort-value">{sortLabel}</span>
      </span>
    {/if}
  </div>
</section>

<style>
  .iwac-summary {
    /* 2px ink rule underneath — the section-head grammar (h1.title, footer top,
       KPI figures). The strip itself is transparent: chips carry their own
       outline, no block-level wash. */
    border-block-end: 2px solid var(--ink-strong, var(--ink, #13161c));
    padding-block-end: var(--space-sm, 0.5rem);
  }
  .iwac-summary__line {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
  }
  .iwac-summary__count-block {
    display: inline-flex;
    align-items: baseline;
    gap: 0.375rem;
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    font-variant-numeric: tabular-nums;
  }
  .iwac-summary__count {
    color: var(--ink-strong, var(--ink, #13161c));
    /* Ledger numeral: display serif, tabular figures, display tracking. */
    font-family: var(--font-headings, Georgia, serif);
    font-size: var(--text-xl, 1.5rem);
    font-weight: 700;
    line-height: 1;
    letter-spacing: var(--tracking-display, -0.01em);
  }
  .iwac-summary__count-label {
    font-weight: 500;
  }
  .iwac-summary__timing {
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    white-space: nowrap;
  }
  .iwac-summary__in {
    color: var(--muted, #66696e);
    font-weight: 500;
  }

  .iwac-summary__chips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: inline-flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  /*
   * Removable scope chip — outlined in primary (current state), value in ink.
   * Same vocabulary as the FacetPanel chips so the two read as one system. The
   * IWAC theme paints every <button>; resets keep this an outline, not a pill.
   */
  .iwac-summary__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    padding: 0.2rem 0.55rem;
    background: transparent;
    border: 1px solid var(--primary, #ce4115);
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--ink, #13161c);
    line-height: 1.4;
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-summary__chip:hover {
    background: color-mix(in oklab, var(--primary, #ce4115) 10%, transparent);
    color: var(--ink-strong, var(--ink, #13161c));
    box-shadow: none;
    transform: none;
  }
  .iwac-summary__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-summary__chip-field {
    color: var(--muted, #66696e);
    font-weight: 500;
  }
  .iwac-summary__chip-value {
    font-weight: 600;
  }
  .iwac-summary__chip-x {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1;
  }

  .iwac-summary__clear {
    background: none;
    border: none;
    box-shadow: none;
    padding: 0;
    color: var(--primary, #ce4115);
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-summary__clear:hover {
    background: none;
    box-shadow: none;
    transform: none;
    color: var(--primary-hover, var(--primary, #ce4115));
  }
  .iwac-summary__clear:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  .iwac-summary__sort {
    /* Pushed to the end of the line — the dateline-style tail of the sentence. */
    margin-inline-start: auto;
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    white-space: nowrap;
  }
  .iwac-summary__sort-value {
    color: var(--ink-strong, var(--ink, #13161c));
    font-weight: 600;
  }

  @media (max-width: 48rem) {
    /* Let the sort readout sit under the count/chips rather than be squeezed. */
    .iwac-summary__sort {
      margin-inline-start: 0;
    }
  }
</style>

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
  import FilterChip from './FilterChip.svelte';

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

<!--
  NOT a live region any more. It was the surface's only one, and a bad one:
  being inside `{#if response}` it was destroyed for every skeleton and
  re-created already populated, which announces nothing — while the ms figure
  changing on an otherwise identical search re-read all 78 characters aloud.
  App.svelte now owns one persistent, debounced region; this is just the
  visible strip.
-->
<section class="iwac-summary" aria-label={t('active_filters')}>
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
            <FilterChip {chip} onRemove={onRemoveChip} />
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
    border-block-end: 2px solid var(--ink-strong, #05070c);
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
    color: var(--ink-strong, #05070c);
    /* Ledger numeral: display serif, lining figures, display tracking. */
    font-family: var(
      --font-headings,
      'Besley',
      'Source Serif 4',
      Georgia,
      'Times New Roman',
      serif
    );
    font-size: var(--text-xl, 1.5rem);
    font-weight: 700;
    line-height: 1;
    /* Lining, NOT tabular — and the tabular-nums above is inherited, so it
       has to be cleared here explicitly. Besley's `tnum` zero is frozen at a
       55-unit advance on every weight while 1-9 track the weight axis (66 at
       700), so a tabular zero renders 17% narrow and reads as un-bolded
       ("4 100"). font-feature-settings because the theme's body sets an
       inherited "onum"/"pnum" that outranks font-variant-numeric. */
    font-variant-numeric: normal;
    font-feature-settings:
      'kern' 1,
      'liga' 1,
      'lnum' 1;
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

  /*
   * Removable scope chip — outlined in primary (current state), value in ink.
   * Same vocabulary as the FacetPanel chips so the two read as one system. The
   * IWAC theme paints every <button>; resets keep this an outline, not a pill.
   */

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
    color: var(--primary-hover, #b03710);
  }
  .iwac-summary__clear:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  .iwac-summary__sort {
    /* Pushed to the end of the line — the dateline-style tail of the sentence. */
    margin-inline-start: auto;
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    white-space: nowrap;
  }
  .iwac-summary__sort-value {
    color: var(--ink-strong, #05070c);
    font-weight: 600;
  }

  @media (max-width: 767px) {
    /* Let the sort readout sit under the count/chips rather than be squeezed. */
    .iwac-summary__sort {
      margin-inline-start: 0;
    }
  }
</style>

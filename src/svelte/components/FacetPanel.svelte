<script module lang="ts">
  // Per-instance counter so the sentiment group's body id is page-unique
  // (multiple search surfaces can render a panel each).
  let panelUid = 0;
  function nextSentimentBodyId(): string {
    return `iwac-sentiment-body-${++panelUid}`;
  }
</script>

<script lang="ts">
  import type {
    ActiveFilters,
    IwacFacet,
    IwacFacetCount,
    YearBucket,
    YearRange,
  } from '../lib/types';
  import { SENTIMENT_FIELDS, NUMERIC_FACET_FIELDS, useI18n } from '../lib/i18n';
  import { deriveActiveChips } from '../lib/filterChips';
  import FacetGroup from './FacetGroup.svelte';
  import DateRangeSlider from './DateRangeSlider.svelte';
  import FilterChip from './FilterChip.svelte';

  /**
   * Filters sidebar.
   *
   *   FILTERS                     Clear all
   *   ──────────────────────────────────────
   *   Active
   *     [Burkina Faso ✕] [Niger ✕] [Sidwaya ✕]
   *   ──────────────────────────────────────
   *   Year
   *     1989 ── 2010
   *   ──────────────────────────────────────
   *   Country                              ▾
   *     ☐ Burkina Faso  1,234
   *     ☐ Côte d'Ivoire   892
   *     …
   *
   * Visual choices:
   *   - No outer card frame. The sidebar lives inside the page layout
   *     and getting a border AND background here meant we had a card
   *     inside a card inside a card (the search block root, then the
   *     facet panel, then each facet group). The new design keeps the
   *     column transparent and uses thin section dividers for rhythm.
   *   - The "Filters" eyebrow + Clear all sit at the very top so the
   *     whole panel always announces what it is, even when no filters
   *     are active yet.
   *
   * Order of facet groups = bootstrap.prominent_facets order, so the
   * block admin owns the order and we render whatever they picked.
   */

  interface Props {
    facets: IwacFacet[];
    selected: ActiveFilters;
    yearRange: YearRange | null;
    /** Year slider bounds. Defaults are sane for the IWAC corpus. */
    yearMin?: number;
    yearMax?: number;
    /** Per-year document counts, drawn as a mini histogram on the slider. */
    distribution?: YearBucket[];
    /** Schema field name → display label override (rare). */
    labels?: Record<string, string>;
    onToggle: (field: string, value: string, nextChecked: boolean) => void;
    onClearAll: () => void;
    onClearField: (field: string) => void;
    onYearRangeChange: (next: YearRange | null) => void;
    /**
     * Search a facet field's values server-side (beyond the top-N the main
     * response carries). Passed straight to each FacetGroup; when absent, the
     * group falls back to filtering its loaded values client-side only.
     */
    onFacetSearch?: (field: string, text: string) => Promise<IwacFacetCount[]>;
  }

  const {
    facets,
    selected,
    yearRange,
    yearMin = 1960,
    yearMax = 2025,
    distribution = [],
    labels,
    onToggle,
    onClearAll,
    onClearField,
    onYearRangeChange,
    onFacetSearch,
  }: Props = $props();

  const { locale, t } = useI18n();

  // Active-filter chips come from the shared deriveActiveChips() so the sidebar,
  // the result-summary strip and the empty state can never disagree about scope.
  const activeChips = $derived(
    deriveActiveChips({ selected, yearRange, locale, t, yearMin, yearMax, labels }),
  );

  const hasActive = $derived(activeChips.length > 0);

  // Sentiment facets (polarity / centrality / subjectivity) collapse into
  // one parent group so they open and close together; everything else
  // renders as a flat list of facet groups. Order within each bucket is
  // preserved from the prominent_facets list the server sent.
  const regularFacets = $derived(facets.filter((f) => !SENTIMENT_FIELDS.has(f.field_name)));
  const sentimentFacets = $derived(facets.filter((f) => SENTIMENT_FIELDS.has(f.field_name)));
  let sentimentOpen = $state(false);
  const sentimentBodyId = nextSentimentBodyId();
  const sentimentActiveCount = $derived(
    sentimentFacets.reduce((n, f) => n + (selected[f.field_name]?.length ?? 0), 0),
  );

  // Shared toggle wrapper: unchecking the last value in a field drops the
  // whole field key (keeps URL/filter state clean) — same logic the flat
  // list used before the sentiment split.
  function toggleFacet(field: string, value: string, nextChecked: boolean): void {
    if (!nextChecked && (selected[field]?.length ?? 0) === 1) {
      onClearField(field);
    } else {
      onToggle(field, value, nextChecked);
    }
  }

  function handleChipClick(chip: { field: string; kind: 'facet' | 'year'; value: string }): void {
    if (chip.kind === 'year') {
      onYearRangeChange(null);
    } else {
      onToggle(chip.field, chip.value, false);
    }
  }
</script>

<aside class="iwac-facets" aria-label={t('filters')}>
  <header class="iwac-facets__header">
    <h2 class="iwac-facets__heading">{t('filters')}</h2>
    {#if hasActive}
      <button type="button" class="iwac-facets__clear-all" onclick={onClearAll}
        >{t('clear_all')}</button
      >
    {/if}
  </header>

  {#if hasActive}
    <section
      class="iwac-facets__section iwac-facets__section--active"
      aria-label={t('active_filters')}
    >
      <ul class="iwac-facets__chips">
        {#each activeChips as chip (chip.field + '|' + chip.value)}
          <li>
            <FilterChip {chip} onRemove={handleChipClick} />
          </li>
        {/each}
      </ul>
    </section>
  {/if}

  <section class="iwac-facets__section" aria-label={t('year')}>
    <DateRangeSlider
      value={yearRange}
      min={yearMin}
      max={yearMax}
      {distribution}
      onChange={onYearRangeChange}
    />
  </section>

  {#if facets.length === 0}
    <p class="iwac-facets__empty">{t('search_to_see_options')}</p>
  {:else}
    <div class="iwac-facets__groups">
      {#each regularFacets as f (f.field_name)}
        <FacetGroup
          field={f.field_name}
          counts={f.counts}
          totalValues={f.stats?.total_values}
          selected={selected[f.field_name] ?? []}
          label={labels?.[f.field_name]}
          onToggle={toggleFacet}
          {onFacetSearch}
        />
      {/each}

      {#if sentimentFacets.length > 0}
        <section class="iwac-facets__group" class:iwac-facets__group--open={sentimentOpen}>
          <button
            type="button"
            class="iwac-facets__group-heading"
            aria-expanded={sentimentOpen}
            aria-controls={sentimentOpen ? sentimentBodyId : undefined}
            onclick={() => (sentimentOpen = !sentimentOpen)}
          >
            <span class="iwac-facets__group-label">{t('sentiment')}</span>
            {#if sentimentActiveCount > 0}
              <span class="iwac-facets__group-count">{sentimentActiveCount}</span>
            {/if}
            <span class="iwac-facets__group-chevron" aria-hidden="true">
              {sentimentOpen ? '▾' : '▸'}
            </span>
          </button>
          {#if sentimentOpen}
            <div class="iwac-facets__group-body" id={sentimentBodyId}>
              {#each sentimentFacets as f (f.field_name)}
                <FacetGroup
                  field={f.field_name}
                  counts={f.counts}
                  totalValues={f.stats?.total_values}
                  selected={selected[f.field_name] ?? []}
                  label={labels?.[f.field_name]}
                  sortMode={NUMERIC_FACET_FIELDS.has(f.field_name) ? 'value-asc' : 'count'}
                  onToggle={toggleFacet}
                  {onFacetSearch}
                />
              {/each}
            </div>
          {/if}
        </section>
      {/if}
    </div>
  {/if}
</aside>

<style>
  .iwac-facets {
    /* No background, no outer border — the sidebar is a transparent
       column. Section dividers carry the rhythm; nested-card stacking
       is gone. */
    display: flex;
    flex-direction: column;
    gap: 0;
  }
  .iwac-facets__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    padding-block: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border, #ced1d6);
  }
  .iwac-facets__heading {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    color: var(--ink-strong, #05070c);
  }
  .iwac-facets__clear-all {
    background: none;
    border: none;
    box-shadow: none;
    color: var(--primary, #ce4115);
    cursor: pointer;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    padding: 0;
  }
  .iwac-facets__clear-all:hover {
    background: none;
    box-shadow: none;
    transform: none;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-facets__clear-all:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }

  .iwac-facets__section {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-facets__section:last-of-type {
    border-bottom: none;
  }
  .iwac-facets__section--active {
    /* The chips themselves carry the active state (primary dot + border);
       the old block-level orange wash made the whole corner shout. */
    padding-block-start: var(--space-sm, 0.5rem);
  }

  .iwac-facets__groups {
    display: flex;
    flex-direction: column;
  }

  /*
   * Sentiment parent group — wraps the polarity / centrality /
   * subjectivity sub-facets so they collapse together. Heading mirrors
   * the FacetGroup eyebrow; the IWAC theme paints every <button>, so we
   * zero out its background / shadow / hover-translate explicitly.
   */
  .iwac-facets__group {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-facets__group:last-child {
    border-bottom: none;
  }
  .iwac-facets__group-heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    background: none;
    border: none;
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, #05070c);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    text-align: start;
    transition: color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-facets__group-heading:hover {
    color: var(--primary, #ce4115);
    background: none;
    box-shadow: none;
    transform: none;
  }
  .iwac-facets__group-heading:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-facets__group-label {
    flex: 1;
  }
  .iwac-facets__group-count {
    color: var(--primary, #ce4115);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0;
    text-transform: none;
  }
  .iwac-facets__group-chevron {
    color: var(--muted, #66696e);
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: 0;
  }
  .iwac-facets__group-body {
    margin-block-start: var(--space-xs, 0.25rem);
    padding-inline-start: var(--space-md, 1rem);
    /* Indentation alone groups the sub-facets; no accent rail. */
  }
  /* Tighten the nested sub-facets; the parent owns the section rhythm. */
  .iwac-facets__group-body :global(.iwac-facet) {
    padding-block: var(--space-sm, 0.5rem);
  }
  .iwac-facets__group-body :global(.iwac-facet:first-child) {
    padding-block-start: var(--space-xs, 0.25rem);
  }

  .iwac-facets__empty {
    padding-block: var(--space-md, 1rem);
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>

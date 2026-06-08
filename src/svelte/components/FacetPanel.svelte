<script module lang="ts">
  // Per-instance counter so the sentiment group's body id is page-unique
  // (multiple search surfaces can render a panel each).
  let panelUid = 0;
  function nextSentimentBodyId(): string {
    return `iwac-sentiment-body-${++panelUid}`;
  }
</script>

<script lang="ts">
  import type { ActiveFilters, IwacFacet, YearRange } from '../lib/types';
  import {
    facetLabel,
    facetValueLabel,
    SENTIMENT_FIELDS,
    NUMERIC_FACET_FIELDS,
    useI18n,
  } from '../lib/i18n';
  import FacetGroup from './FacetGroup.svelte';
  import DateRangeSlider from './DateRangeSlider.svelte';

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
    /** Schema field name → display label override (rare). */
    labels?: Record<string, string>;
    onToggle: (field: string, value: string, nextChecked: boolean) => void;
    onClearAll: () => void;
    onClearField: (field: string) => void;
    onYearRangeChange: (next: YearRange | null) => void;
  }

  const {
    facets,
    selected,
    yearRange,
    yearMin = 1960,
    yearMax = 2025,
    labels,
    onToggle,
    onClearAll,
    onClearField,
    onYearRangeChange,
  }: Props = $props();

  const { locale, t } = useI18n();

  const activeChips = $derived.by(() => {
    const chips: Array<{
      field: string;
      /** Raw value — the one toggled off when the chip is clicked. */
      value: string;
      /** Human-facing value (e.g. subjectivity 1–5 → words). */
      displayValue: string;
      label: string;
      kind: 'facet' | 'year';
    }> = [];
    for (const [field, values] of Object.entries(selected)) {
      for (const v of values) {
        chips.push({
          field,
          value: v,
          displayValue: facetValueLabel(field, v, locale),
          label: labels?.[field] ?? facetLabel(field, locale),
          kind: 'facet',
        });
      }
    }
    if (yearRange) {
      const lo = yearRange.from ?? yearMin;
      const hi = yearRange.to ?? yearMax;
      const range = `${lo} – ${hi}`;
      chips.push({
        field: 'pub_year',
        value: range,
        displayValue: range,
        label: t('year'),
        kind: 'year',
      });
    }
    return chips;
  });

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
            <button
              type="button"
              class="iwac-facets__chip"
              onclick={() => handleChipClick(chip)}
              aria-label={t('remove_filter', { label: chip.label, value: chip.displayValue })}
            >
              <span class="iwac-facets__chip-field">{chip.label}:</span>
              <span class="iwac-facets__chip-value">{chip.displayValue}</span>
              <span class="iwac-facets__chip-x" aria-hidden="true">×</span>
            </button>
          </li>
        {/each}
      </ul>
    </section>
  {/if}

  <section class="iwac-facets__section" aria-label={t('year')}>
    <DateRangeSlider value={yearRange} min={yearMin} max={yearMax} onChange={onYearRangeChange} />
  </section>

  {#if facets.length === 0}
    <p class="iwac-facets__empty">{t('search_to_see_options')}</p>
  {:else}
    <div class="iwac-facets__groups">
      {#each regularFacets as f (f.field_name)}
        <FacetGroup
          field={f.field_name}
          counts={f.counts}
          selected={selected[f.field_name] ?? []}
          label={labels?.[f.field_name]}
          onToggle={toggleFacet}
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
                  selected={selected[f.field_name] ?? []}
                  label={labels?.[f.field_name]}
                  sortMode={NUMERIC_FACET_FIELDS.has(f.field_name) ? 'value-asc' : 'count'}
                  onToggle={toggleFacet}
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
    border-bottom: 1px solid var(--border, #d4d6da);
  }
  .iwac-facets__heading {
    margin: 0;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    color: var(--ink-strong, var(--ink, #2c2f37));
  }
  .iwac-facets__clear-all {
    background: none;
    border: none;
    box-shadow: none;
    color: var(--primary, #e64a19);
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
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  .iwac-facets__section {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #e6e7eb);
  }
  .iwac-facets__section:last-of-type {
    border-bottom: none;
  }
  .iwac-facets__section--active {
    /* Subtle tint behind the active chips so users can tell at a
       glance "these are what I've selected" vs. "these are options". */
    margin-inline: calc(-1 * var(--space-sm, 0.5rem));
    padding-inline: var(--space-sm, 0.5rem);
    background: color-mix(
      in oklab,
      var(--primary, #e64a19) var(--accent-mix-subtle, 25%),
      transparent
    );
    border-radius: var(--radius-md, 0.5rem);
    border-bottom: none;
    margin-block-end: var(--space-sm, 0.5rem);
  }

  .iwac-facets__chips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-facets__chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    padding: 0.25rem 0.625rem;
    background: var(--surface, #fdfdfd);
    border: 1px solid
      color-mix(
        in oklab,
        var(--primary, #e64a19) var(--accent-mix-medium, 40%),
        var(--border, #d4d6da)
      );
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.8125rem);
    color: var(--ink, #2c2f37);
    line-height: 1.4;
    transition:
      background var(--transition-fast, 150ms ease),
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-facets__chip:hover {
    background: var(--primary, #e64a19);
    border-color: var(--primary, #e64a19);
    color: var(--white, #fff);
    box-shadow: none;
    transform: none;
  }
  .iwac-facets__chip:hover .iwac-facets__chip-field,
  .iwac-facets__chip:hover .iwac-facets__chip-x {
    color: inherit;
    opacity: 0.85;
  }
  .iwac-facets__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-facets__chip-field {
    color: var(--muted, #767880);
    font-weight: 500;
  }
  .iwac-facets__chip-value {
    font-weight: 600;
  }
  .iwac-facets__chip-x {
    color: var(--muted, #767880);
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1;
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
    border-bottom: 1px solid var(--border-light, #e6e7eb);
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
    color: var(--ink-strong, var(--ink, #2c2f37));
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    text-align: start;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-facets__group-heading:hover {
    color: var(--primary, #e64a19);
    background: none;
    box-shadow: none;
    transform: none;
  }
  .iwac-facets__group-heading:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-facets__group-label {
    flex: 1;
  }
  .iwac-facets__group-count {
    background: var(--primary, #e64a19);
    color: var(--white, #fff);
    border-radius: var(--radius-full, 9999px);
    padding: 0 0.5rem;
    min-width: 1.25rem;
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
  }
  .iwac-facets__group-chevron {
    color: var(--muted, #767880);
    font-size: var(--text-xs, 0.8125rem);
    letter-spacing: 0;
  }
  .iwac-facets__group-body {
    margin-block-start: var(--space-xs, 0.25rem);
    padding-inline-start: var(--space-sm, 0.5rem);
    /* Subtle accent rail so the three sub-facets read as one subsection. */
    border-inline-start: 2px solid
      color-mix(in oklab, var(--primary, #e64a19) 30%, var(--border-light, #e6e7eb));
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
    color: var(--muted, #767880);
    font-size: var(--text-sm, 0.9375rem);
    margin: 0;
  }
</style>

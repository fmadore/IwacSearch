<script lang="ts">
  import type { ActiveFilters, IwacFacet, YearRange } from '../lib/types';
  import { facetLabel } from '../lib/labels';
  import FacetGroup from './FacetGroup.svelte';
  import DateRangeSlider from './DateRangeSlider.svelte';

  /**
   * Facet column.
   *
   *   ┌─ Active filters ──────────┐
   *   │  Burkina Faso ✕  Niger ✕  │
   *   │  Sidwaya ✕                │
   *   │           [ Clear all ]   │
   *   ├───────────────────────────┤
   *   │ ▾ Country                 │
   *   │ ▾ Newspaper               │
   *   │ ▾ Decade                  │
   *   │ ▸ Topic (3)               │
   *   └───────────────────────────┘
   *
   * Order = bootstrap.prominent_facets order. The block admin owns the
   * order; we render whatever they picked in whatever order they picked.
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

  const activeChips = $derived.by(() => {
    const chips: Array<{
      field: string;
      value: string;
      label: string;
      kind: 'facet' | 'year';
    }> = [];
    for (const [field, values] of Object.entries(selected)) {
      for (const v of values) {
        chips.push({
          field,
          value: v,
          label: labels?.[field] ?? facetLabel(field),
          kind: 'facet',
        });
      }
    }
    if (yearRange) {
      const lo = yearRange.from ?? yearMin;
      const hi = yearRange.to ?? yearMax;
      chips.push({
        field: 'pub_year',
        value: `${lo}–${hi}`,
        label: 'Year',
        kind: 'year',
      });
    }
    return chips;
  });

  function handleChipClick(chip: { field: string; kind: 'facet' | 'year'; value: string }): void {
    if (chip.kind === 'year') {
      onYearRangeChange(null);
    } else {
      onToggle(chip.field, chip.value, false);
    }
  }
</script>

<aside class="iwac-facets" aria-label="Filters">
  {#if activeChips.length > 0}
    <div class="iwac-facets__active">
      <header class="iwac-facets__active-head">
        <span>Active filters</span>
        <button type="button" class="iwac-facets__clear-all" onclick={onClearAll}>
          Clear all
        </button>
      </header>
      <ul class="iwac-facets__chips">
        {#each activeChips as chip (chip.field + '|' + chip.value)}
          <li>
            <button
              type="button"
              class="iwac-facets__chip"
              onclick={() => handleChipClick(chip)}
              aria-label={`Remove filter ${chip.label}: ${chip.value}`}
            >
              <span class="iwac-facets__chip-field">{chip.label}</span>
              <span class="iwac-facets__chip-value">{chip.value}</span>
              <span class="iwac-facets__chip-x" aria-hidden="true">×</span>
            </button>
          </li>
        {/each}
      </ul>
    </div>
  {/if}

  <div class="iwac-facets__groups">
    <DateRangeSlider value={yearRange} min={yearMin} max={yearMax} onChange={onYearRangeChange} />

    {#each facets as f (f.field_name)}
      <FacetGroup
        field={f.field_name}
        counts={f.counts}
        selected={selected[f.field_name] ?? []}
        label={labels?.[f.field_name]}
        onToggle={(field, value, nextChecked) => {
          if (!nextChecked && (selected[field]?.length ?? 0) === 1) {
            onClearField(field);
          } else {
            onToggle(field, value, nextChecked);
          }
        }}
      />
    {/each}
    {#if facets.length === 0}
      <p class="iwac-facets__empty">Search to see filter options.</p>
    {/if}
  </div>
</aside>

<style>
  .iwac-facets {
    background: var(--surface-raised, #fafafa);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-md, 0.75rem);
    padding: var(--space-md, 1rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-md, 1rem);
  }
  .iwac-facets__active {
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
    padding-bottom: var(--space-sm, 0.5rem);
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .iwac-facets__active-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: var(--text-sm, 0.9rem);
    color: var(--muted, #666);
    font-weight: 600;
  }
  .iwac-facets__clear-all {
    background: none;
    border: none;
    color: var(--primary, #c66);
    cursor: pointer;
    font-size: var(--text-xs, 0.75rem);
    padding: var(--space-xs, 0.25rem);
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-facets__clear-all:hover {
    text-decoration: underline;
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
    padding: 0.25rem 0.5rem;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-full, 9999px);
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.75rem);
    color: var(--ink, #222);
    line-height: 1.4;
  }
  .iwac-facets__chip:hover {
    border-color: var(--primary, #c66);
    color: var(--primary, #c66);
  }
  .iwac-facets__chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-facets__chip-field {
    color: var(--muted, #666);
  }
  .iwac-facets__chip-value {
    font-weight: 500;
  }
  .iwac-facets__chip-x {
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
  }
  .iwac-facets__groups {
    display: flex;
    flex-direction: column;
  }
  .iwac-facets__empty {
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
</style>

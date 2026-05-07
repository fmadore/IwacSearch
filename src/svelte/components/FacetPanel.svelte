<script lang="ts">
  import type { ActiveFilters, IwacFacet, YearRange } from '../lib/types';
  import { facetLabel } from '../lib/labels';
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
        value: `${lo} – ${hi}`,
        label: 'Year',
        kind: 'year',
      });
    }
    return chips;
  });

  const hasActive = $derived(activeChips.length > 0);

  function handleChipClick(chip: { field: string; kind: 'facet' | 'year'; value: string }): void {
    if (chip.kind === 'year') {
      onYearRangeChange(null);
    } else {
      onToggle(chip.field, chip.value, false);
    }
  }
</script>

<aside class="iwac-facets" aria-label="Filters">
  <header class="iwac-facets__header">
    <h2 class="iwac-facets__heading">Filters</h2>
    {#if hasActive}
      <button type="button" class="iwac-facets__clear-all" onclick={onClearAll}>Clear all</button>
    {/if}
  </header>

  {#if hasActive}
    <section class="iwac-facets__section iwac-facets__section--active" aria-label="Active filters">
      <ul class="iwac-facets__chips">
        {#each activeChips as chip (chip.field + '|' + chip.value)}
          <li>
            <button
              type="button"
              class="iwac-facets__chip"
              onclick={() => handleChipClick(chip)}
              aria-label={`Remove filter ${chip.label}: ${chip.value}`}
            >
              <span class="iwac-facets__chip-field">{chip.label}:</span>
              <span class="iwac-facets__chip-value">{chip.value}</span>
              <span class="iwac-facets__chip-x" aria-hidden="true">×</span>
            </button>
          </li>
        {/each}
      </ul>
    </section>
  {/if}

  <section class="iwac-facets__section" aria-label="Year">
    <DateRangeSlider value={yearRange} min={yearMin} max={yearMax} onChange={onYearRangeChange} />
  </section>

  {#if facets.length === 0}
    <p class="iwac-facets__empty">Search to see filter options.</p>
  {:else}
    <div class="iwac-facets__groups">
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
    border-bottom: 1px solid var(--border, #ccc);
  }
  .iwac-facets__heading {
    margin: 0;
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    color: var(--ink-strong, var(--ink, #222));
  }
  .iwac-facets__clear-all {
    background: none;
    border: none;
    box-shadow: none;
    color: var(--primary, #c66);
    cursor: pointer;
    font-size: var(--text-xs, 0.75rem);
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
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .iwac-facets__section:last-of-type {
    border-bottom: none;
  }
  .iwac-facets__section--active {
    /* Subtle tint behind the active chips so users can tell at a
       glance "these are what I've selected" vs. "these are options". */
    margin-inline: calc(-1 * var(--space-sm, 0.5rem));
    padding-inline: var(--space-sm, 0.5rem);
    background: color-mix(in srgb, var(--primary, #c66) var(--accent-mix-subtle, 12%), transparent);
    border-radius: var(--radius-md, 0.75rem);
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
    background: var(--surface, #fff);
    border: 1px solid
      color-mix(in srgb, var(--primary, #c66) var(--accent-mix-medium, 40%), var(--border, #ccc));
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    font-size: var(--text-xs, 0.75rem);
    color: var(--ink, #222);
    line-height: 1.4;
    transition:
      background var(--transition-fast, 150ms ease),
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-facets__chip:hover {
    background: var(--primary, #c66);
    border-color: var(--primary, #c66);
    color: var(--primary-contrast, #fff);
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
    color: var(--muted, #666);
    font-weight: 500;
  }
  .iwac-facets__chip-value {
    font-weight: 600;
  }
  .iwac-facets__chip-x {
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    line-height: 1;
  }

  .iwac-facets__groups {
    display: flex;
    flex-direction: column;
  }
  .iwac-facets__empty {
    padding-block: var(--space-md, 1rem);
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    margin: 0;
  }
</style>

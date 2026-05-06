<script module lang="ts">
  function formatCount(n: number): string {
    return new Intl.NumberFormat().format(n);
  }
</script>

<script lang="ts">
  import type { IwacFacetCount } from '../lib/types';
  import { facetLabel } from '../lib/labels';

  /**
   * One facet field rendered as a collapsible checklist.
   *
   *   COUNTRY                         (2) ▾
   *   ─────────────────────────────────────
   *   ☑ Burkina Faso             1,234
   *   ☐ Côte d'Ivoire              892
   *   ☐ Niger                      450
   *   Show 7 more
   *
   * Behavioural choices:
   *   - Heading is the click target (bigger hit area than a chevron
   *     alone) and renders as a small-caps eyebrow so the column reads
   *     like a sequence of editorial sections, not a config dialog.
   *   - "Show more" reveals all values up to Typesense's max_facet_values
   *     (50). A search box per facet lands in M5 (typeahead milestone).
   *   - Counts come from Typesense's facet_counts response — they're
   *     post-filter, so they update as other facets are toggled.
   */

  interface Props {
    field: string;
    counts: IwacFacetCount[];
    selected: string[];
    /** Initially-shown count before "show more" expands. */
    visibleByDefault?: number;
    onToggle: (field: string, value: string, nextChecked: boolean) => void;
    /** Optional override; defaults to the global label table. */
    label?: string;
  }

  const { field, counts, selected, visibleByDefault = 8, onToggle, label }: Props = $props();

  const heading = $derived(label ?? facetLabel(field));
  const selectedSet = $derived(new Set(selected));
  let expanded = $state(false);
  let collapsed = $state(false); // user-collapsed (whole group)

  // Sort: selected first (so toggling doesn't make a value vanish under
  // the "show more" fold), then by count descending.
  const sorted = $derived.by(() => {
    return [...counts].sort((a, b) => {
      const aSel = selectedSet.has(a.value);
      const bSel = selectedSet.has(b.value);
      if (aSel !== bSel) return aSel ? -1 : 1;
      return b.count - a.count;
    });
  });

  const visible = $derived(expanded ? sorted : sorted.slice(0, visibleByDefault));
  const hiddenCount = $derived(Math.max(0, sorted.length - visibleByDefault));
</script>

<section class="iwac-facet" class:iwac-facet--collapsed={collapsed}>
  <button
    type="button"
    class="iwac-facet__heading"
    aria-expanded={!collapsed}
    onclick={() => (collapsed = !collapsed)}
  >
    <span class="iwac-facet__label">{heading}</span>
    {#if selected.length > 0}
      <span class="iwac-facet__active-count" aria-label={`${selected.length} active`}>
        {selected.length}
      </span>
    {/if}
    <span class="iwac-facet__chevron" aria-hidden="true">{collapsed ? '▸' : '▾'}</span>
  </button>

  {#if !collapsed}
    {#if counts.length === 0}
      <p class="iwac-facet__empty">No values for this filter.</p>
    {:else}
      <ul class="iwac-facet__list">
        {#each visible as fc (fc.value)}
          <li class="iwac-facet__item">
            <label class="iwac-facet__option" class:is-selected={selectedSet.has(fc.value)}>
              <input
                type="checkbox"
                class="iwac-facet__checkbox"
                checked={selectedSet.has(fc.value)}
                onchange={(e) =>
                  onToggle(field, fc.value, (e.currentTarget as HTMLInputElement).checked)}
              />
              <span class="iwac-facet__value">{fc.value}</span>
              <span class="iwac-facet__count">{formatCount(fc.count)}</span>
            </label>
          </li>
        {/each}
      </ul>

      {#if hiddenCount > 0}
        <button type="button" class="iwac-facet__more" onclick={() => (expanded = !expanded)}>
          {expanded ? 'Show less' : `Show ${hiddenCount} more`}
        </button>
      {/if}
    {/if}
  {/if}
</section>

<style>
  .iwac-facet {
    padding-block: var(--space-md, 1rem);
    border-bottom: 1px solid var(--border-light, #eee);
  }
  .iwac-facet:last-child {
    border-bottom: none;
  }

  .iwac-facet__heading {
    display: flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    width: 100%;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink-strong, var(--ink, #222));
    font-size: var(--text-xs, 0.75rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
    text-align: start;
    transition: color var(--transition-fast, 150ms ease);
  }
  .iwac-facet__heading:hover {
    color: var(--primary, #c66);
  }
  .iwac-facet__heading:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-facet__label {
    flex: 1;
  }
  .iwac-facet__active-count {
    background: var(--primary, #c66);
    color: var(--primary-contrast, #fff);
    border-radius: var(--radius-full, 9999px);
    padding: 0 0.5rem;
    min-width: 1.25rem;
    height: 1.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-xs, 0.7rem);
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
  }
  .iwac-facet__chevron {
    color: var(--muted, #888);
    font-size: var(--text-xs, 0.75rem);
    /* Strip the heading's wide tracking — chevrons are pictograms,
       letter-spacing them moves the glyph off-centre. */
    letter-spacing: 0;
  }

  .iwac-facet__list {
    list-style: none;
    margin: var(--space-sm, 0.5rem) 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.0625rem;
  }
  .iwac-facet__item {
    margin: 0;
  }
  .iwac-facet__option {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: var(--space-sm, 0.5rem);
    align-items: center;
    padding: 0.375rem var(--space-xs, 0.25rem);
    border-radius: var(--radius-sm, 0.375rem);
    cursor: pointer;
    color: var(--ink, #222);
    font-size: var(--text-sm, 0.9rem);
    line-height: 1.4;
    transition: background var(--transition-fast, 150ms ease);
  }
  .iwac-facet__option:hover {
    background: var(--surface-sunken, #f5f5f5);
  }
  .iwac-facet__option.is-selected {
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 500;
  }
  .iwac-facet__option:has(input:focus-visible) {
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-facet__checkbox {
    /* Use the theme primary as the checkbox tick colour so checked
       boxes pick up brand without a custom SVG control. */
    accent-color: var(--primary, #c66);
    width: 1rem;
    height: 1rem;
    margin: 0;
    cursor: pointer;
  }
  .iwac-facet__value {
    overflow-wrap: anywhere;
  }
  .iwac-facet__count {
    color: var(--muted, #888);
    font-variant-numeric: tabular-nums;
    font-size: var(--text-xs, 0.75rem);
  }
  .iwac-facet__option.is-selected .iwac-facet__count {
    color: var(--ink-light, var(--muted, #666));
  }

  .iwac-facet__more {
    margin-top: var(--space-sm, 0.5rem);
    background: none;
    border: none;
    color: var(--primary, #c66);
    font-size: var(--text-xs, 0.75rem);
    font-weight: 500;
    cursor: pointer;
    padding: var(--space-xs, 0.25rem) 0;
  }
  .iwac-facet__more:hover {
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-facet__more:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  .iwac-facet__empty {
    color: var(--muted, #888);
    font-size: var(--text-sm, 0.9rem);
    margin: var(--space-xs, 0.25rem) 0 0;
    padding: var(--space-xs, 0.25rem);
  }
</style>

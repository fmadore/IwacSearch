<script lang="ts">
  import type { FacetOption } from '../lib/types';

  /**
   * Reorderable checkbox list for selecting prominent facets.
   *
   * Display order matters (the order here is the order facets render
   * on the public browse page), so we surface ↑/↓ buttons next to
   * each checked item. Unchecked items float to the bottom, sorted by
   * the catalog's default order.
   */
  interface Props {
    available: FacetOption[];
    selected: string[];
    onChange: (next: string[]) => void;
  }

  const { available, selected, onChange }: Props = $props();

  // Compose display-order list: checked (in their saved order) first,
  // then unchecked in catalog order. $derived ties this to `selected`
  // so toggles update the layout without any manual bookkeeping.
  const orderedRows = $derived.by(() => {
    const byName = new Map(available.map((f) => [f.name, f]));
    const checked = selected
      .map((name) => byName.get(name))
      .filter((f): f is FacetOption => f !== undefined)
      .map((f) => ({ ...f, checked: true }));
    const unchecked = available
      .filter((f) => !selected.includes(f.name))
      .map((f) => ({ ...f, checked: false }));
    return [...checked, ...unchecked];
  });

  function toggle(name: string): void {
    if (selected.includes(name)) {
      onChange(selected.filter((f) => f !== name));
    } else {
      onChange([...selected, name]);
    }
  }

  function moveUp(name: string): void {
    const i = selected.indexOf(name);
    if (i <= 0) return;
    const next = [...selected];
    [next[i - 1], next[i]] = [next[i], next[i - 1]];
    onChange(next);
  }

  function moveDown(name: string): void {
    const i = selected.indexOf(name);
    if (i < 0 || i >= selected.length - 1) return;
    const next = [...selected];
    [next[i + 1], next[i]] = [next[i], next[i + 1]];
    onChange(next);
  }
</script>

<ul class="iwac-facets" role="list">
  {#each orderedRows as row (row.name)}
    {@const idx = selected.indexOf(row.name)}
    <li class="iwac-facets__row" class:iwac-facets__row--checked={row.checked}>
      <label class="iwac-facets__label">
        <input
          type="checkbox"
          checked={row.checked}
          onchange={() => toggle(row.name)}
          class="iwac-facets__checkbox"
        />
        <span class="iwac-facets__name">{row.label}</span>
        <code class="iwac-facets__field">{row.name}</code>
      </label>
      {#if row.checked}
        <div class="iwac-facets__reorder" aria-label="Reorder">
          <button
            type="button"
            class="iwac-facets__reorder-btn"
            aria-label="Move up"
            disabled={idx <= 0}
            onclick={() => moveUp(row.name)}
          >
            ↑
          </button>
          <button
            type="button"
            class="iwac-facets__reorder-btn"
            aria-label="Move down"
            disabled={idx >= selected.length - 1}
            onclick={() => moveDown(row.name)}
          >
            ↓
          </button>
        </div>
      {/if}
    </li>
  {/each}
</ul>

<style>
  .iwac-facets {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-2xs, 0.125rem);
  }
  .iwac-facets__row {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    padding: var(--space-xs, 0.25rem) var(--space-sm, 0.5rem);
    border: 1px solid var(--border-light, #eee);
    border-radius: var(--radius-sm, 0.375rem);
    background: var(--surface, #fff);
    transition: all 120ms ease;
  }
  .iwac-facets__row--checked {
    border-color: var(--primary, #c66);
    background: color-mix(in srgb, var(--primary, #c66) 4%, var(--surface, #fff));
  }
  .iwac-facets__label {
    display: flex;
    align-items: center;
    gap: var(--space-sm, 0.5rem);
    flex: 1;
    cursor: pointer;
    font-size: var(--text-sm, 0.9rem);
    color: var(--ink, #222);
  }
  .iwac-facets__checkbox {
    margin: 0;
    accent-color: var(--primary, #c66);
  }
  .iwac-facets__name {
    font-weight: 500;
  }
  .iwac-facets__field {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
    margin-inline-start: auto;
  }
  .iwac-facets__reorder {
    display: flex;
    gap: var(--space-2xs, 0.125rem);
  }
  .iwac-facets__reorder-btn {
    width: 1.75rem;
    height: 1.75rem;
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-sm, 0.375rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    cursor: pointer;
    font-size: var(--text-base, 1rem);
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 100ms ease;
  }
  .iwac-facets__reorder-btn:hover:not(:disabled) {
    border-color: var(--primary, #c66);
    color: var(--primary, #c66);
  }
  .iwac-facets__reorder-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
  }
</style>

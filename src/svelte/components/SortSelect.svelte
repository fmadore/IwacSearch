<script module lang="ts">
  /**
   * Map a Typesense `sort_by` string to a UI label.
   * Single source of truth for the dropdown.
   */
  export const SORT_OPTIONS: ReadonlyArray<{ value: string; label: string }> = [
    { value: '_text_match:desc', label: 'Relevance' },
    { value: 'date:desc', label: 'Newest first' },
    { value: 'date:asc', label: 'Oldest first' },
  ];
</script>

<script lang="ts">
  /**
   * Sort dropdown — sits at the top-right of the results column.
   *
   * Kept tiny on purpose: M5 may add "by sentiment", "by relevance + date
   * tie-break", etc. Adding a new option = one line in SORT_OPTIONS.
   */

  interface Props {
    value: string;
    onChange: (next: string) => void;
  }

  const { value, onChange }: Props = $props();
</script>

<label class="iwac-sort">
  <span class="iwac-sort__label">Sort by</span>
  <select
    class="iwac-sort__select"
    {value}
    onchange={(e) => onChange((e.currentTarget as HTMLSelectElement).value)}
  >
    {#each SORT_OPTIONS as opt (opt.value)}
      <option value={opt.value}>{opt.label}</option>
    {/each}
  </select>
</label>

<style>
  .iwac-sort {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    color: var(--muted, #666);
    font-size: var(--text-sm, 0.9rem);
  }
  .iwac-sort__label {
    white-space: nowrap;
  }
  .iwac-sort__select {
    height: var(--size-control-md, 2.5rem);
    padding: 0 var(--space-lg, 1.5rem) 0 var(--space-sm, 0.5rem);
    background: var(--surface, #fff);
    color: var(--ink, #222);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-md, 0.75rem);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .iwac-sort__select:hover {
    border-color: var(--border-strong, var(--border, #ccc));
  }
  .iwac-sort__select:focus-visible {
    outline: none;
    border-color: var(--primary, #c66);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
</style>

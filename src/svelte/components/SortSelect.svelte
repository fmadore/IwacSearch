<script lang="ts">
  /**
   * Sort dropdown — sits at the top-right of the results column.
   *
   * Options + labels come from the locale-aware sortOptions() helper, so
   * adding a new sort order is one line in lib/i18n.ts.
   */
  import { sortOptions, useI18n } from '../lib/i18n';

  interface Props {
    value: string;
    onChange: (next: string) => void;
  }

  const { value, onChange }: Props = $props();

  const { locale, card, t } = useI18n();
  const options = $derived(sortOptions(locale, card));
</script>

<label class="iwac-sort">
  <span class="iwac-sort__label">{t('sort_by')}</span>
  <select
    class="iwac-sort__select"
    {value}
    onchange={(e) => onChange((e.currentTarget as HTMLSelectElement).value)}
  >
    {#each options as opt (opt.value)}
      <option value={opt.value}>{opt.label}</option>
    {/each}
  </select>
</label>

<style>
  .iwac-sort {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    color: var(--muted, #767880);
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-sort__label {
    white-space: nowrap;
  }
  .iwac-sort__select {
    height: var(--size-control-md, 2.5rem);
    padding: 0 var(--space-lg, 1.5rem) 0 var(--space-sm, 0.5rem);
    background: var(--surface, #fdfdfd);
    color: var(--ink, #2c2f37);
    border: 1px solid var(--border, #d4d6da);
    border-radius: var(--radius-md, 0.5rem);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms ease),
      box-shadow var(--transition-fast, 150ms ease);
  }
  .iwac-sort__select:hover {
    border-color: var(--border-strong, var(--border, #d4d6da));
  }
  .iwac-sort__select:focus-visible {
    outline: none;
    border-color: var(--primary, #e64a19);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
</style>

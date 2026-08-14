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
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-sort__label {
    white-space: nowrap;
  }
  .iwac-sort__select {
    height: var(--size-control-md, 2.5rem);
    padding: 0 var(--space-lg, 1.5rem) 0 var(--space-sm, 0.5rem);
    /* The IWAC theme's global field rule puts `width: 100%` and
       `margin-bottom: var(--space-sm)` on every <select>; the margin made
       the flex row centre the select's margin-box, floating the control
       ~4px above the Export/Filters buttons. Reset both explicitly. */
    width: auto;
    margin: 0;
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      box-shadow var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-sort__select:hover {
    border-color: var(--border-strong, #aeb1b7);
  }
  .iwac-sort__select:focus-visible {
    outline: none;
    border-color: var(--primary, #ce4115);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(206, 65, 21, 0.3));
  }
</style>

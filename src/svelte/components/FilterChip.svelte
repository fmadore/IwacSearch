<script lang="ts">
  /**
   * One removable active-filter chip.
   *
   * The chip DATA was already shared (`deriveActiveChips`), but the markup and
   * ~40 lines of CSS were triplicated across FacetPanel, ResultSummary and
   * ResultsEmpty — three copies of the same outlined pill that had already
   * drifted in padding and weight while claiming, in their own comments, to
   * "read as one system".
   *
   * Two variants, which is what the three call sites actually needed:
   *
   *   size="sm"  sidebar + summary strip — dense, xs type
   *   size="md"  empty state — the offending value is the headline there, so
   *              it carries more weight
   *
   * `showField` prints the `Country:` prefix. The empty state omits it because
   * the surrounding sentence already supplies the context ("No results for …
   * in Bénin"), where the sidebar and strip stand alone and need it.
   */
  import type { ActiveFilterChip } from '../lib/filterChips';
  import { useI18n } from '../lib/i18n';

  interface Props {
    chip: ActiveFilterChip;
    onRemove: (chip: ActiveFilterChip) => void;
    /** Show the `Field:` prefix. Default true. */
    showField?: boolean;
    size?: 'sm' | 'md';
  }

  const { chip, onRemove, showField = true, size = 'sm' }: Props = $props();

  const { t } = useI18n();
</script>

<button
  type="button"
  class="iwac-chip iwac-chip--{size}"
  onclick={() => onRemove(chip)}
  aria-label={t('remove_filter', { label: chip.label, value: chip.displayValue })}
>
  {#if showField}<span class="iwac-chip__field">{chip.label}:</span>{/if}
  <span class="iwac-chip__value">{chip.displayValue}</span>
  <span class="iwac-chip__x" aria-hidden="true">×</span>
</button>

<style>
  /*
   * Outlined pill, primary border, value in ink. The IWAC theme paints every
   * <button>, so the background/box-shadow/transform resets are load-bearing —
   * without them this renders as a filled theme button.
   */
  .iwac-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    background: transparent;
    border: 1px solid var(--primary, #ce4115);
    border-radius: var(--radius-full, 9999px);
    box-shadow: none;
    cursor: pointer;
    font: inherit;
    color: var(--ink, #13161c);
    line-height: 1.4;
    transition:
      background var(--transition-fast, 150ms ease),
      border-color var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  .iwac-chip--sm {
    padding: 0.2rem 0.55rem;
    font-size: var(--text-xs, 0.8125rem);
  }
  .iwac-chip--md {
    padding: 0.1rem 0.55rem;
    font-size: var(--text-sm, 0.9375rem);
    color: var(--ink-strong, var(--ink, #13161c));
  }
  .iwac-chip:hover {
    background: color-mix(in oklab, var(--primary, #ce4115) 10%, transparent);
    border-color: var(--primary, #ce4115);
    color: var(--ink-strong, var(--ink, #13161c));
    box-shadow: none;
    transform: none;
  }
  .iwac-chip:hover .iwac-chip__field,
  .iwac-chip:hover .iwac-chip__x {
    color: inherit;
    opacity: 0.85;
  }
  .iwac-chip:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-chip__field {
    color: var(--muted, #66696e);
    font-weight: 500;
  }
  .iwac-chip__value {
    font-weight: 600;
  }
  .iwac-chip__x {
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
    line-height: 1;
  }
</style>

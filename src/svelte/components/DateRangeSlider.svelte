<script lang="ts">
  import type { YearRange } from '../lib/types';

  /**
   * Dual-handle year range filter — a single slider track with two thumbs.
   *
   *   Year                1989 – 2010    Reset
   *   ○──────●═══════════════●──────────○
   *
   * Implementation notes:
   *   - Two stacked native <input type="range"> with thumb-only visibility,
   *     because no browser ships a real two-handle range input and a custom
   *     DnD widget would lose keyboard accessibility (arrow keys step,
   *     Home/End jump, tab navigation).
   *   - The current range is displayed inline ("1989 – 2010") instead of
   *     duplicated as two number inputs — cleaner, and number precision
   *     isn't worth the visual cost for a 65-year span.
   *   - Defaults: 1960..2025. Override via min/max props.
   *   - Dirty/clean: a fully-default range (min..max) is treated as "no
   *     filter" — the parent receives `null` for an unset range so URL
   *     state stays clean.
   */

  interface Props {
    value: YearRange | null;
    min?: number;
    max?: number;
    onChange: (next: YearRange | null) => void;
  }

  const { value, min = 1960, max = 2025, onChange }: Props = $props();

  // Internal handle positions are always concrete numbers; "no filter"
  // means both handles sit at the extremes. Initial seed only — the
  // $effect below re-syncs whenever the parent pushes a new range.
  // svelte-ignore state_referenced_locally
  let fromHandle = $state(value?.from ?? min);
  // svelte-ignore state_referenced_locally
  let toHandle = $state(value?.to ?? max);

  // Re-sync if the parent pushes a new value (URL pop, "clear all", etc.)
  $effect(() => {
    fromHandle = value?.from ?? min;
    toHandle = value?.to ?? max;
  });

  function emit(): void {
    // Clamp + order — invariant: fromHandle <= toHandle
    const lo = Math.max(min, Math.min(fromHandle, toHandle));
    const hi = Math.min(max, Math.max(fromHandle, toHandle));
    fromHandle = lo;
    toHandle = hi;

    if (lo === min && hi === max) {
      onChange(null);
      return;
    }
    const next: YearRange = {};
    if (lo !== min) next.from = lo;
    if (hi !== max) next.to = hi;
    onChange(next);
  }

  function handleFrom(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    // Stop the from-thumb passing the to-thumb.
    fromHandle = Math.min(v, toHandle);
    emit();
  }
  function handleTo(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    toHandle = Math.max(v, fromHandle);
    emit();
  }

  function reset(): void {
    fromHandle = min;
    toHandle = max;
    emit();
  }

  const fillStart = $derived(((fromHandle - min) / (max - min)) * 100);
  const fillEnd = $derived(((toHandle - min) / (max - min)) * 100);
  const isDirty = $derived(fromHandle !== min || toHandle !== max);
</script>

<section class="iwac-daterange" aria-label="Year range">
  <header class="iwac-daterange__header">
    <span class="iwac-daterange__label">Year</span>
    <span
      class="iwac-daterange__range"
      class:iwac-daterange__range--dirty={isDirty}
      aria-live="polite"
    >
      {fromHandle} – {toHandle}
    </span>
    {#if isDirty}
      <button type="button" class="iwac-daterange__reset" onclick={reset}>Reset</button>
    {/if}
  </header>

  <div
    class="iwac-daterange__track"
    style="--iwac-fill-start: {fillStart}%; --iwac-fill-end: {fillEnd}%;"
  >
    <input
      class="iwac-daterange__handle iwac-daterange__handle--from"
      type="range"
      aria-label="From year"
      {min}
      {max}
      step="1"
      value={fromHandle}
      oninput={handleFrom}
    />
    <input
      class="iwac-daterange__handle iwac-daterange__handle--to"
      type="range"
      aria-label="To year"
      {min}
      {max}
      step="1"
      value={toHandle}
      oninput={handleTo}
    />
  </div>
</section>

<style>
  .iwac-daterange {
    border-bottom: 1px solid var(--border-light, #eee);
    padding-block: var(--space-sm, 0.5rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-daterange__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
  }
  .iwac-daterange__label {
    font-size: var(--text-base, 1rem);
  }
  .iwac-daterange__range {
    flex: 1;
    font-size: var(--text-sm, 0.9rem);
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    color: var(--muted, #666);
    text-align: end;
  }
  .iwac-daterange__range--dirty {
    color: var(--primary, #c66);
  }
  .iwac-daterange__reset {
    background: none;
    border: none;
    color: var(--primary, #c66);
    font-size: var(--text-xs, 0.75rem);
    cursor: pointer;
    padding: 0;
    margin-inline-start: var(--space-sm, 0.5rem);
  }
  .iwac-daterange__reset:hover {
    text-decoration: underline;
  }

  /*
   * Track + dual-handle slider. The two <input type="range"> elements
   * stack on top of each other with pointer-events isolated to each
   * thumb. The visible track + filled segment are drawn via the
   * parent's ::before / ::after.
   */
  .iwac-daterange__track {
    position: relative;
    height: 1.75rem;
  }
  .iwac-daterange__track::before {
    /* base track */
    content: '';
    position: absolute;
    inset-block: calc(50% - 3px);
    inset-inline: 0;
    height: 6px;
    background: var(--surface-sunken, #e5e5e5);
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-daterange__track::after {
    /* selected segment between the two handles */
    content: '';
    position: absolute;
    inset-block: calc(50% - 3px);
    inset-inline-start: var(--iwac-fill-start);
    inset-inline-end: calc(100% - var(--iwac-fill-end));
    height: 6px;
    background: var(--primary, #c66);
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-daterange__handle {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    background: transparent;
    pointer-events: none;
    appearance: none;
    -webkit-appearance: none;
    margin: 0;
  }
  .iwac-daterange__handle::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    pointer-events: auto;
    width: 1.25rem;
    height: 1.25rem;
    background: var(--surface, #fff);
    border: 2px solid var(--primary, #c66);
    border-radius: var(--radius-full, 9999px);
    cursor: grab;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    transition:
      transform 100ms ease,
      box-shadow 100ms ease;
  }
  .iwac-daterange__handle::-moz-range-thumb {
    pointer-events: auto;
    width: 1.25rem;
    height: 1.25rem;
    background: var(--surface, #fff);
    border: 2px solid var(--primary, #c66);
    border-radius: var(--radius-full, 9999px);
    cursor: grab;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    transition:
      transform 100ms ease,
      box-shadow 100ms ease;
  }
  .iwac-daterange__handle:hover::-webkit-slider-thumb,
  .iwac-daterange__handle:active::-webkit-slider-thumb {
    transform: scale(1.1);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
  }
  .iwac-daterange__handle:hover::-moz-range-thumb,
  .iwac-daterange__handle:active::-moz-range-thumb {
    transform: scale(1.1);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
  }
  .iwac-daterange__handle:focus-visible::-webkit-slider-thumb {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(204, 102, 102, 0.3));
  }
  .iwac-daterange__handle:focus-visible::-moz-range-thumb {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(204, 102, 102, 0.3));
  }
  /* Track itself is hidden — we draw our own via ::before/::after. */
  .iwac-daterange__handle::-webkit-slider-runnable-track {
    background: transparent;
    border: none;
  }
  .iwac-daterange__handle::-moz-range-track {
    background: transparent;
    border: none;
  }
</style>

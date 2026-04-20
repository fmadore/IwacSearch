<script lang="ts">
  import type { YearRange } from '../lib/types';

  /**
   * Two-handle year range filter.
   *
   *   Year ────────●═════════════●─────  1990 ↔ 2010
   *   [from input]            [to input]
   *
   * Implementation choices:
   *   - Two stacked native <input type="range"> with thumb-only visibility,
   *     because no browser ships a real two-handle range and a custom DnD
   *     widget loses keyboard accessibility.
   *   - Number inputs underneath let users type a precise year, which
   *     matters for archival research ("show me only 1989").
   *   - Defaults: 1960..2025. Override via min/max props if the corpus
   *     warrants — first reindex could feed real min/max from the data
   *     (M0+ enhancement; not blocking).
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
    // Clamp + order
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

  function handleFromRange(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    fromHandle = Math.min(v, toHandle);
    emit();
  }
  function handleToRange(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    toHandle = Math.max(v, fromHandle);
    emit();
  }
  function handleFromNumber(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    if (!Number.isFinite(v)) return;
    fromHandle = Math.max(min, Math.min(v, toHandle));
    emit();
  }
  function handleToNumber(e: Event): void {
    const v = Number((e.currentTarget as HTMLInputElement).value);
    if (!Number.isFinite(v)) return;
    toHandle = Math.min(max, Math.max(v, fromHandle));
    emit();
  }

  const fillStart = $derived(((fromHandle - min) / (max - min)) * 100);
  const fillEnd = $derived(((toHandle - min) / (max - min)) * 100);
  const isDirty = $derived(fromHandle !== min || toHandle !== max);
</script>

<section class="iwac-daterange" aria-label="Year range">
  <header class="iwac-daterange__header">
    <span class="iwac-daterange__label">Year</span>
    {#if isDirty}
      <button
        type="button"
        class="iwac-daterange__reset"
        onclick={() => {
          fromHandle = min;
          toHandle = max;
          emit();
        }}
      >
        Reset
      </button>
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
      oninput={handleFromRange}
    />
    <input
      class="iwac-daterange__handle iwac-daterange__handle--to"
      type="range"
      aria-label="To year"
      {min}
      {max}
      step="1"
      value={toHandle}
      oninput={handleToRange}
    />
  </div>

  <div class="iwac-daterange__inputs">
    <label class="iwac-daterange__input-wrap">
      <span class="iwac-daterange__input-label">From</span>
      <input
        class="iwac-daterange__input"
        type="number"
        inputmode="numeric"
        {min}
        {max}
        step="1"
        value={fromHandle}
        onchange={handleFromNumber}
      />
    </label>
    <label class="iwac-daterange__input-wrap">
      <span class="iwac-daterange__input-label">To</span>
      <input
        class="iwac-daterange__input"
        type="number"
        inputmode="numeric"
        {min}
        {max}
        step="1"
        value={toHandle}
        onchange={handleToNumber}
      />
    </label>
  </div>
</section>

<style>
  .iwac-daterange {
    border-bottom: 1px solid var(--border-light, #eee);
    padding-block: var(--space-sm, 0.5rem);
    display: flex;
    flex-direction: column;
    gap: var(--space-xs, 0.25rem);
  }
  .iwac-daterange__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--ink-strong, var(--ink, #222));
    font-weight: 600;
  }
  .iwac-daterange__label {
    font-size: var(--text-base, 1rem);
  }
  .iwac-daterange__reset {
    background: none;
    border: none;
    color: var(--primary, #c66);
    font-size: var(--text-xs, 0.75rem);
    cursor: pointer;
    padding: var(--space-xs, 0.25rem);
  }
  .iwac-daterange__reset:hover {
    text-decoration: underline;
  }

  /*
   * Track + dual-handle slider. The two <input type="range"> elements
   * stack on top of each other with z-index slicing so each thumb is
   * independently grabbable. The visible track + filled segment are
   * drawn via the parent's background gradient.
   */
  .iwac-daterange__track {
    position: relative;
    height: 2rem;
    margin-block: var(--space-xs, 0.25rem);
  }
  .iwac-daterange__track::before {
    /* base track */
    content: '';
    position: absolute;
    inset-block: calc(50% - 2px);
    inset-inline: 0;
    height: 4px;
    background: var(--surface-sunken, #e0e0e0);
    border-radius: var(--radius-full, 9999px);
  }
  .iwac-daterange__track::after {
    /* selected segment */
    content: '';
    position: absolute;
    inset-block: calc(50% - 2px);
    inset-inline-start: var(--iwac-fill-start);
    inset-inline-end: calc(100% - var(--iwac-fill-end));
    height: 4px;
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
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  }
  .iwac-daterange__handle::-moz-range-thumb {
    pointer-events: auto;
    width: 1.25rem;
    height: 1.25rem;
    background: var(--surface, #fff);
    border: 2px solid var(--primary, #c66);
    border-radius: var(--radius-full, 9999px);
    cursor: grab;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  }
  .iwac-daterange__handle:focus-visible::-webkit-slider-thumb {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
  .iwac-daterange__handle:focus-visible::-moz-range-thumb {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
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

  .iwac-daterange__inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-daterange__input-wrap {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    font-size: var(--text-xs, 0.75rem);
    color: var(--muted, #666);
  }
  .iwac-daterange__input {
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem);
    background: var(--surface, #fff);
    border: 1px solid var(--border, #ccc);
    border-radius: var(--radius-sm, 0.375rem);
    color: var(--ink, #222);
    font: inherit;
    font-size: var(--text-sm, 0.9rem);
    font-variant-numeric: tabular-nums;
  }
  .iwac-daterange__input:focus-visible {
    outline: none;
    border-color: var(--primary, #c66);
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
  }
</style>

<script lang="ts">
  import type { YearRange } from '../lib/types';
  import { useI18n } from '../lib/i18n';

  /**
   * Single-track dual-thumb year range slider.
   *
   *   Year                                           1989 – 2010    Reset
   *   ──────────●═══════════════════●──────────
   *
   * Why custom (not stacked native <input type="range">):
   *   The previous implementation stacked two native range inputs on top
   *   of each other and hid their tracks via ::-webkit-slider-runnable-track
   *   { background: transparent }. In practice browsers still rendered
   *   their own track scaffolding (especially Firefox + Chromium with
   *   accent-color enabled), so users saw two parallel lines instead of
   *   one. This component goes fully custom — one CSS-drawn track,
   *   two absolute-positioned thumbs, pointer-event drag handlers,
   *   keyboard accessibility via role="slider" + onkeydown.
   *
   * Accessibility:
   *   - Each thumb has role="slider" + aria-valuemin/max/now and an
   *     aria-label so screen readers announce "From year, 1989 of 1960
   *     to 2025" etc.
   *   - Tab focuses each thumb in order; arrow keys nudge ±1 year;
   *     Page Up/Down jump ±10; Home/End snap to the bound.
   *   - The thumbs cannot cross — moving the From thumb past the To
   *     thumb (or vice versa) clamps to the other thumb's position.
   *
   * Defaults: 1960..2025. Override via min/max props if the corpus
   * warrants. Dirty/clean: a fully-default range is treated as "no
   * filter" — the parent receives null so URL state stays clean.
   */

  interface Props {
    value: YearRange | null;
    min?: number;
    max?: number;
    onChange: (next: YearRange | null) => void;
  }

  const { value, min = 1960, max = 2025, onChange }: Props = $props();

  const { t } = useI18n();

  // svelte-ignore state_referenced_locally
  let fromHandle = $state(value?.from ?? min);
  // svelte-ignore state_referenced_locally
  let toHandle = $state(value?.to ?? max);

  // Re-sync when the parent pushes a new value (URL pop, "clear all").
  $effect(() => {
    fromHandle = value?.from ?? min;
    toHandle = value?.to ?? max;
  });

  /** Reference to the track div for hit-testing pointer coords. */
  let trackEl: HTMLDivElement | null = $state(null);
  /** Which thumb is currently being dragged (null = no drag in flight). */
  let dragging: 'from' | 'to' | null = $state(null);

  function emit(): void {
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

  function valueFromClientX(clientX: number): number {
    if (!trackEl) return min;
    const rect = trackEl.getBoundingClientRect();
    const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
    return Math.round(min + ratio * (max - min));
  }

  /** Move the dragged thumb to the year that corresponds to the cursor. */
  function moveDraggedThumb(clientX: number): void {
    const v = valueFromClientX(clientX);
    if (dragging === 'from') {
      fromHandle = Math.min(v, toHandle);
    } else if (dragging === 'to') {
      toHandle = Math.max(v, fromHandle);
    }
  }

  function startDrag(which: 'from' | 'to', e: PointerEvent): void {
    dragging = which;
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    moveDraggedThumb(e.clientX);
    e.preventDefault();
  }

  function onPointerMove(e: PointerEvent): void {
    if (dragging === null) return;
    moveDraggedThumb(e.clientX);
  }

  function endDrag(e: PointerEvent): void {
    if (dragging === null) return;
    const target = e.currentTarget as HTMLElement;
    if (target.hasPointerCapture(e.pointerId)) {
      target.releasePointerCapture(e.pointerId);
    }
    dragging = null;
    emit();
  }

  /**
   * Keyboard controls — match the native <input type="range"> shortcuts
   * so the affordance feels like a real range, even though we're not
   * using one. Arrow ± 1, Page ± 10, Home/End to bounds.
   */
  function handleKeydown(which: 'from' | 'to', e: KeyboardEvent): void {
    let delta = 0;
    let absolute: number | null = null;
    switch (e.key) {
      case 'ArrowRight':
      case 'ArrowUp':
        delta = 1;
        break;
      case 'ArrowLeft':
      case 'ArrowDown':
        delta = -1;
        break;
      case 'PageUp':
        delta = 10;
        break;
      case 'PageDown':
        delta = -10;
        break;
      case 'Home':
        absolute = which === 'from' ? min : fromHandle;
        break;
      case 'End':
        absolute = which === 'to' ? max : toHandle;
        break;
      default:
        return;
    }
    e.preventDefault();
    if (which === 'from') {
      const next = absolute ?? fromHandle + delta;
      fromHandle = Math.max(min, Math.min(next, toHandle));
    } else {
      const next = absolute ?? toHandle + delta;
      toHandle = Math.min(max, Math.max(next, fromHandle));
    }
    emit();
  }

  function reset(): void {
    fromHandle = min;
    toHandle = max;
    emit();
  }

  /**
   * Click on the track (not a thumb) — jump the nearer thumb to the
   * clicked position. Same behaviour as the macOS / iOS native slider.
   */
  function onTrackPointerDown(e: PointerEvent): void {
    if (e.target !== trackEl) return; // already handled by a thumb
    const v = valueFromClientX(e.clientX);
    const distFrom = Math.abs(v - fromHandle);
    const distTo = Math.abs(v - toHandle);
    if (distFrom <= distTo) {
      fromHandle = Math.min(v, toHandle);
    } else {
      toHandle = Math.max(v, fromHandle);
    }
    emit();
  }

  const fillStart = $derived(((fromHandle - min) / (max - min)) * 100);
  const fillEnd = $derived(((toHandle - min) / (max - min)) * 100);
  const isDirty = $derived(fromHandle !== min || toHandle !== max);
</script>

<section class="iwac-daterange" aria-label={t('year_range')}>
  <header class="iwac-daterange__header">
    <span class="iwac-daterange__label">{t('year')}</span>
    <span
      class="iwac-daterange__range"
      class:iwac-daterange__range--dirty={isDirty}
      aria-live="polite"
    >
      {fromHandle} – {toHandle}
    </span>
    {#if isDirty}
      <button type="button" class="iwac-daterange__reset" onclick={reset}>{t('reset')}</button>
    {/if}
  </header>

  <!-- One track. Two thumbs. No <input type="range"> in sight. -->
  <div
    bind:this={trackEl}
    class="iwac-daterange__track"
    style="--iwac-fill-start: {fillStart}%; --iwac-fill-end: {fillEnd}%;"
    onpointerdown={onTrackPointerDown}
    role="presentation"
  >
    <div class="iwac-daterange__filled" aria-hidden="true"></div>

    <div
      class="iwac-daterange__thumb iwac-daterange__thumb--from"
      class:iwac-daterange__thumb--dragging={dragging === 'from'}
      style="inset-inline-start: {fillStart}%;"
      role="slider"
      tabindex="0"
      aria-label={t('from_year')}
      aria-valuemin={min}
      aria-valuemax={toHandle}
      aria-valuenow={fromHandle}
      onpointerdown={(e) => startDrag('from', e)}
      onpointermove={onPointerMove}
      onpointerup={endDrag}
      onpointercancel={endDrag}
      onkeydown={(e) => handleKeydown('from', e)}
    ></div>

    <div
      class="iwac-daterange__thumb iwac-daterange__thumb--to"
      class:iwac-daterange__thumb--dragging={dragging === 'to'}
      style="inset-inline-start: {fillEnd}%;"
      role="slider"
      tabindex="0"
      aria-label={t('to_year')}
      aria-valuemin={fromHandle}
      aria-valuemax={max}
      aria-valuenow={toHandle}
      onpointerdown={(e) => startDrag('to', e)}
      onpointermove={onPointerMove}
      onpointerup={endDrag}
      onpointercancel={endDrag}
      onkeydown={(e) => handleKeydown('to', e)}
    ></div>
  </div>
</section>

<style>
  .iwac-daterange {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-daterange__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm, 0.5rem);
    color: var(--ink-strong, var(--ink, #2c2f37));
  }
  .iwac-daterange__label {
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 700;
    letter-spacing: var(--tracking-wider, 0.08em);
    text-transform: uppercase;
  }
  .iwac-daterange__range {
    flex: 1;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--muted, #767880);
    text-align: end;
  }
  .iwac-daterange__range--dirty {
    color: var(--primary, #e64a19);
  }
  .iwac-daterange__reset {
    background: none;
    border: none;
    color: var(--primary, #e64a19);
    font-size: var(--text-xs, 0.8125rem);
    font-weight: 500;
    cursor: pointer;
    padding: 0;
    margin-inline-start: var(--space-sm, 0.5rem);
  }
  .iwac-daterange__reset:hover {
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .iwac-daterange__reset:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    border-radius: var(--radius-sm, 0.375rem);
  }

  /*
   * Single visible track. The whole row is 1.5rem tall so the thumbs
   * have room to sit on top, but the track itself is just 6px.
   * Click anywhere on the row → jump the nearer thumb (handled by
   * the parent's onpointerdown when the target is the track itself).
   */
  .iwac-daterange__track {
    position: relative;
    height: 1.5rem;
    /* Inset the track by one thumb-radius (0.625rem) so the centred thumbs
       (transform: translateX(-50%)) at the 0% / 100% bounds sit flush INSIDE
       the panel, aligned with the other facet rows, instead of overhanging the
       content edge by half a thumb — that overhang was clipped by the sidebar's
       overflow and read as a missing left gutter. padding-inline keeps the
       drawn line + fill aligned within that inset track. */
    margin-inline: 0.625rem;
    padding-inline: 0.625rem;
    cursor: pointer;
    touch-action: pan-y; /* allow vertical scroll, capture horizontal drags */
  }
  .iwac-daterange__track::before {
    /* Base track — drawn once. */
    content: '';
    position: absolute;
    inset-block: calc(50% - 3px);
    inset-inline: 0.625rem;
    height: 6px;
    background: var(--surface-sunken, #f3f3f1);
    border-radius: var(--radius-full, 9999px);
    pointer-events: none;
  }
  .iwac-daterange__filled {
    /* Selected segment between the two thumbs. */
    position: absolute;
    inset-block: calc(50% - 3px);
    inset-inline-start: calc(0.625rem + var(--iwac-fill-start));
    width: calc(var(--iwac-fill-end) - var(--iwac-fill-start));
    height: 6px;
    background: var(--primary, #e64a19);
    border-radius: var(--radius-full, 9999px);
    pointer-events: none;
    transform: translateX(calc(-1 * var(--iwac-fill-start) * 0));
    /* Constrain to track-inner width: see comment below. */
  }

  .iwac-daterange__thumb {
    position: absolute;
    inset-block: calc(50% - 0.625rem);
    inset-inline-start: 0;
    /* `transform: translateX(-50%)` centres the thumb on its point. */
    transform: translateX(-50%);
    width: 1.25rem;
    height: 1.25rem;
    background: var(--surface, #fdfdfd);
    border: 2px solid var(--primary, #e64a19);
    border-radius: var(--radius-full, 9999px);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18);
    cursor: grab;
    transition:
      transform 80ms ease,
      box-shadow 80ms ease;
    /* Above the filled segment so it stays interactive. */
    z-index: 1;
  }
  .iwac-daterange__thumb:hover {
    transform: translateX(-50%) scale(1.08);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.22);
  }
  .iwac-daterange__thumb:focus-visible {
    outline: none;
    box-shadow:
      0 1px 3px rgba(0, 0, 0, 0.18),
      var(--ring-focus, 0 0 0 3px color-mix(in oklab, var(--primary, #e64a19) 30%, transparent));
  }
  .iwac-daterange__thumb--dragging {
    cursor: grabbing;
    transform: translateX(-50%) scale(1.12);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.25);
  }

  /*
   * The thumbs use `inset-inline-start: NN%` plus `transform: translateX(-50%)`
   * to centre on their value. To make the .iwac-daterange__filled segment
   * line up perfectly with the thumbs even at the extremes, we anchor it
   * to the same coordinate space (0.625rem inline-padding offset).
   */
</style>

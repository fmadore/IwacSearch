<script lang="ts">
  /**
   * Quiet segmented control toggling the result presentation — List ledger
   * (default) plus whatever the surface offers: Gallery on content surfaces
   * (design review §01), Map on the entity index. Mirrors the toolbar's
   * control vocabulary (outlined, surface ground, primary on engagement).
   * The IWAC theme paints every <button> primary + glow + hover-translate, so
   * the resets below are deliberate.
   *
   * ── Semantics ────────────────────────────────────────────────────────
   * A RADIOGROUP, not a group of toggle buttons. `aria-pressed` on each
   * segment described three independent switches that happen to be adjacent;
   * what this actually is is one setting with N mutually exclusive values,
   * which is what a radiogroup means and what the sibling FederatedApp
   * tablist already models correctly. Roving tabindex + arrow keys come with
   * that pattern: the control is ONE Tab stop, and Left/Right (or Up/Down)
   * move between and select the segments.
   *
   * ── Colour ───────────────────────────────────────────────────────────
   * The active segment used to be a primary label on a 12% primary wash,
   * which measured 3.72:1 in light mode — the wash lifts the ground toward
   * the label. It now carries ink-strong text with a 2px primary rule under
   * it: the brand still marks the current view, but the part that has to be
   * READ is the part that stays at full contrast. (Neither `--primary` on
   * `--surface`, 4.67:1, nor on `--background`, 4.40:1, leaves any headroom.)
   */
  import type { ViewMode } from '../lib/types';
  import { useI18n } from '../lib/i18n';
  import Icon from './Icon.svelte';

  interface Props {
    value: ViewMode;
    /** Modes this surface offers, in display order. */
    modes?: readonly ViewMode[];
    onChange: (next: ViewMode) => void;
  }

  const { value, modes = ['list', 'gallery'], onChange }: Props = $props();
  const { t } = useI18n();

  const SEGMENTS: Record<ViewMode, { icon: 'list' | 'grid' | 'map'; label: string }> = {
    list: { icon: 'list', label: 'view_list' },
    gallery: { icon: 'grid', label: 'view_gallery' },
    map: { icon: 'map', label: 'view_map' },
  };

  let groupEl: HTMLDivElement | null = $state(null);

  /**
   * Arrow keys move the selection (APG radiogroup: selection follows focus),
   * Home/End jump to the ends. Focus is moved explicitly because the newly
   * selected segment is the only one that will still be tabbable.
   */
  function handleKeydown(e: KeyboardEvent): void {
    const delta =
      e.key === 'ArrowRight' || e.key === 'ArrowDown'
        ? 1
        : e.key === 'ArrowLeft' || e.key === 'ArrowUp'
          ? -1
          : 0;
    let next: ViewMode | undefined;
    if (delta !== 0) {
      const i = modes.indexOf(value);
      next = modes[(i + delta + modes.length) % modes.length];
    } else if (e.key === 'Home') {
      next = modes[0];
    } else if (e.key === 'End') {
      next = modes[modes.length - 1];
    }
    if (next === undefined || next === value) return;
    e.preventDefault();
    onChange(next);
    // The DOM still holds the previous selection this tick; focus after the
    // update so the ring lands on the segment that just became current.
    queueMicrotask(() => groupEl?.querySelector<HTMLElement>('[tabindex="0"]')?.focus());
  }
</script>

<!-- Focus lives on the radios (roving tabindex); the group itself only routes
     arrow keys, so it needs no tabindex of its own — same shape as the
     FederatedApp tablist. -->
<!-- svelte-ignore a11y_interactive_supports_focus -->
<div
  class="iwac-view"
  role="radiogroup"
  aria-label={t('view')}
  bind:this={groupEl}
  onkeydown={handleKeydown}
>
  {#each modes as mode (mode)}
    <button
      type="button"
      role="radio"
      class="iwac-view__btn"
      class:is-active={value === mode}
      aria-checked={value === mode}
      tabindex={value === mode ? 0 : -1}
      onclick={() => onChange(mode)}
    >
      <span class="iwac-view__icon" aria-hidden="true"><Icon name={SEGMENTS[mode].icon} /></span>
      <span class="iwac-view__label">{t(SEGMENTS[mode].label)}</span>
    </button>
  {/each}
</div>

<style>
  .iwac-view {
    display: inline-flex;
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    overflow: hidden;
    /*
     * Never shrink. `overflow: hidden` rounds the segment corners, so a flex
     * parent squeezing this control doesn't ellipsise it — it CLIPS it, and
     * silently: on a phone the Gallery segment vanished outright and "Liste"
     * was cut to "Lis". The control is sized by its content; a cramped row
     * has to wrap instead.
     */
    flex-shrink: 0;
  }
  .iwac-view__btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    background: transparent;
    color: var(--ink-light, #3f4349);
    border: none;
    border-radius: 0;
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  /* Hairline between the two segments. */
  .iwac-view__btn + .iwac-view__btn {
    border-inline-start: 1px solid var(--border, #ced1d6);
  }
  .iwac-view__btn:hover:not(.is-active) {
    background: color-mix(in oklab, var(--primary, #ce4115) 6%, transparent);
    color: var(--ink-strong, #05070c);
    box-shadow: none;
    transform: none;
  }
  .iwac-view__btn.is-active {
    /* Current state = full-contrast label under a 2px brand rule (the toggle
       reads as a tab, not a filled pill — restraint with colour). The rule is
       an INSET shadow because .iwac-view clips; see the focus note below. */
    background: transparent;
    color: var(--ink-strong, #05070c);
    font-weight: 600;
    box-shadow: inset 0 -2px 0 0 var(--primary, #ce4115);
    transform: none;
    cursor: default;
  }
  /* INSET, unlike the rest of the toolbar: .iwac-view clips (`overflow:
     hidden`, which is what rounds the segment corners), so an outset outline
     would be trimmed on three sides of each end segment and on two of the
     middle one. z-index cannot rescue ink a clipping ancestor has cut. */
  .iwac-view__btn:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: -2px;
  }
  .iwac-view__icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.95em;
  }

  /* On the narrowest screens the labels drop; the icons keep the meaning. */
  @media (max-width: 399px) {
    .iwac-view__label {
      display: none;
    }
    .iwac-view__btn {
      padding-inline: var(--space-sm, 0.5rem);
    }
  }
</style>

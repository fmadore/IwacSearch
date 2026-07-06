<script lang="ts">
  /**
   * Quiet segmented control toggling the result presentation — List ledger
   * (default) plus whatever the surface offers: Gallery on content surfaces
   * (design review §01), Map on the entity index. Mirrors the toolbar's
   * control vocabulary (outlined, surface ground, primary on engagement);
   * the active segment carries a primary wash + primary label, the inactive
   * one stays muted. The IWAC theme paints every <button> primary + glow +
   * hover-translate, so the resets below are deliberate.
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
</script>

<div class="iwac-view" role="group" aria-label={t('view')}>
  {#each modes as mode (mode)}
    <button
      type="button"
      class="iwac-view__btn"
      class:is-active={value === mode}
      aria-pressed={value === mode}
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
  }
  .iwac-view__btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-xs, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-sm, 0.5rem) var(--space-md, 1rem);
    background: transparent;
    color: var(--ink-light, var(--ink, #13161c));
    border: none;
    border-radius: 0;
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      background var(--transition-fast, 150ms ease),
      color var(--transition-fast, 150ms ease);
  }
  /* Hairline between the two segments. */
  .iwac-view__btn + .iwac-view__btn {
    border-inline-start: 1px solid var(--border, #ced1d6);
  }
  .iwac-view__btn:hover:not(.is-active) {
    background: color-mix(in oklab, var(--primary, #ce4115) 6%, transparent);
    color: var(--ink-strong, var(--ink, #13161c));
    box-shadow: none;
    transform: none;
  }
  .iwac-view__btn.is-active {
    /* Current state = brand wash + brand label (the toggle reads as a tab, not
       a filled pill — restraint with colour). */
    background: color-mix(in oklab, var(--primary, #ce4115) 12%, transparent);
    color: var(--primary, #ce4115);
    font-weight: 600;
    box-shadow: none;
    transform: none;
    cursor: default;
  }
  .iwac-view__btn:focus-visible {
    outline: none;
    box-shadow: var(--ring-focus, 0 0 0 3px rgba(0, 0, 0, 0.1));
    /* Pull the ring above the sibling's border so it isn't clipped. */
    position: relative;
    z-index: 1;
  }
  .iwac-view__icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.95em;
  }

  /* On the narrowest screens the labels drop; the icons keep the meaning. */
  @media (max-width: 26rem) {
    .iwac-view__label {
      display: none;
    }
    .iwac-view__btn {
      padding-inline: var(--space-sm, 0.5rem);
    }
  }
</style>

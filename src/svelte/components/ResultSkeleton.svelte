<script lang="ts">
  /**
   * Loading placeholder — a galley proof before the ink lands (design review
   * §03A). Replaces the old "dim the list to 0.65 opacity + 'Searching…'" cue
   * with shimmering ledger rows (or gallery tiles) that hold the same geometry
   * as real results, so the page doesn't jump when results arrive.
   *
   * Shape follows the active view. Respects prefers-reduced-motion: the shimmer
   * drops to a static tint (see the media query).
   */
  import type { ViewMode } from '../lib/types';
  import { useI18n } from '../lib/i18n';

  interface Props {
    view: ViewMode;
    /** How many placeholder rows/tiles to draw. */
    count?: number;
  }

  const { view, count = 6 }: Props = $props();
  const { t } = useI18n();

  const rows = $derived(Array.from({ length: Math.max(1, count) }, (_, i) => i));
</script>

<div
  class="iwac-skeleton"
  class:iwac-skeleton--gallery={view === 'gallery'}
  role="status"
  aria-busy="true"
  aria-label={t('loading_results')}
>
  {#each rows as i (i)}
    {#if view === 'gallery'}
      <div class="iwac-skeleton__tile">
        <div class="iwac-skeleton__plate iwac-skeleton__shimmer"></div>
        <div
          class="iwac-skeleton__bar iwac-skeleton__shimmer"
          style="width:55%;height:0.6875rem"
        ></div>
        <div
          class="iwac-skeleton__bar iwac-skeleton__shimmer"
          style="width:92%;height:0.875rem"
        ></div>
        <div
          class="iwac-skeleton__bar iwac-skeleton__shimmer"
          style="width:40%;height:0.625rem"
        ></div>
      </div>
    {:else}
      <div class="iwac-skeleton__row">
        <div class="iwac-skeleton__thumb iwac-skeleton__shimmer"></div>
        <div class="iwac-skeleton__lines">
          <div
            class="iwac-skeleton__bar iwac-skeleton__shimmer"
            style="width:38%;height:0.6875rem"
          ></div>
          <div
            class="iwac-skeleton__bar iwac-skeleton__shimmer"
            style="width:80%;height:1.0625rem"
          ></div>
          <div
            class="iwac-skeleton__bar iwac-skeleton__shimmer"
            style="width:96%;height:0.6875rem"
          ></div>
          <div
            class="iwac-skeleton__bar iwac-skeleton__shimmer"
            style="width:30%;height:0.625rem"
          ></div>
        </div>
      </div>
    {/if}
  {/each}
</div>

<style>
  @keyframes iwac-skeleton-shimmer {
    0% {
      background-position: -180% 0;
    }
    100% {
      background-position: 180% 0;
    }
  }

  /* ── List: ruled ledger, closed bottom, matching ResultsList geometry ── */
  .iwac-skeleton {
    display: flex;
    flex-direction: column;
    border-block-end: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-skeleton__row {
    display: grid;
    grid-template-columns: 7rem 1fr;
    gap: var(--space-md, 1rem);
    padding: var(--space-md, 1rem) var(--space-sm, 0.5rem);
    border-block-start: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-skeleton__thumb {
    width: 7rem;
    height: 7rem;
    border-radius: var(--radius-sm, 0.375rem);
  }
  .iwac-skeleton__lines {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
    padding-block-start: 0.25rem;
  }
  .iwac-skeleton__bar {
    border-radius: var(--radius-sm, 0.375rem);
  }

  /* ── Gallery: tile grid, matching ResultGallery geometry ── */
  .iwac-skeleton--gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: var(--space-lg, 1.5rem);
    border-block-end: none;
  }
  .iwac-skeleton__tile {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-skeleton__plate {
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: var(--radius-sm, 0.375rem);
  }

  /* Shimmer sweep — surface→border→surface gradient slid across. */
  .iwac-skeleton__shimmer {
    background: linear-gradient(
      90deg,
      var(--surface-sunken, #f4f1ef) 25%,
      var(--border-light, #e2e5e8) 50%,
      var(--surface-sunken, #f4f1ef) 75%
    );
    background-size: 200% 100%;
    animation: iwac-skeleton-shimmer 1.4s ease-in-out infinite;
  }

  @media (prefers-reduced-motion: reduce) {
    .iwac-skeleton__shimmer {
      /* Static tint — no sweep. */
      animation: none;
      background: var(--surface-sunken, #f4f1ef);
    }
  }

  /* Mirror ResultItem's narrow-viewport stacking so the proof matches. */
  @media (max-width: 599px) {
    .iwac-skeleton__row {
      grid-template-columns: 1fr;
      gap: var(--space-sm, 0.5rem);
    }
    .iwac-skeleton__thumb {
      width: 100%;
      height: 9rem;
    }
  }
</style>

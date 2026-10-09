<script lang="ts">
  /**
   * The row above the results: view toggle (left), then the narrow-viewport
   * Filters trigger, copy-link and export, and the sort (right). On a phone
   * the bar wraps — [view] / [filters · actions] — above a full-width sort,
   * so the controls stay legible instead of clipping the view toggle.
   *
   * Extracted from App.svelte with its styles unchanged. It also anchors
   * pagination's scroll-back: App binds `anchor` and scrolls it into view on
   * a page change, so the reader lands at the top of the new page.
   */
  import type { IwacDoc } from '../lib/types';
  import type { ViewModeState } from '../lib/viewMode.svelte';
  import { useI18n } from '../lib/i18n';
  import { createCopyState } from '../lib/clipboard.svelte';
  import ViewToggle from './ViewToggle.svelte';
  import ExportMenu from './ExportMenu.svelte';
  import SortSelect from './SortSelect.svelte';
  import Icon from './Icon.svelte';

  interface Props {
    /** The toolbar element, for App's scroll-back. */
    anchor?: HTMLElement | null;
    view: ViewModeState;
    /** Whether the narrow-viewport filter drawer is open (the trigger's aria-expanded). */
    filtersOpen: boolean;
    activeFilterCount: number;
    onOpenFilters: () => void;
    /** Syncing surfaces only: there the address IS the search, so it is worth copying. */
    showCopyLink: boolean;
    /** Null hides Export (entity cards, empty or withheld sets). */
    fetchDocs: (() => Promise<{ docs: IwacDoc[]; found: number }>) | null;
    query: string;
    found: number;
    /** The RESOLVED order, so the control and the summary below can never disagree. */
    sort: string;
    onSortChange: (next: string) => void;
  }

  let {
    anchor = $bindable(null),
    view,
    filtersOpen,
    activeFilterCount,
    onOpenFilters,
    showCopyLink,
    fetchDocs,
    query,
    found,
    sort,
    onSortChange,
  }: Props = $props();

  const { t } = useI18n();

  // The URL mirrors the full search state on syncing surfaces, so "copy link"
  // is just the address — the button saves the trip to the URL bar and
  // confirms the copy with a transient label swap instead of a toast.
  const copyLink = createCopyState();
</script>

<div class="iwac-search__controls" bind:this={anchor}>
  <div class="iwac-search__controls-bar">
    {#if view.supportsToggle}
      <ViewToggle value={view.mode} modes={view.modes} onChange={(m) => view.set(m)} />
    {/if}
    <div class="iwac-search__controls-actions">
      <!-- aria-expanded, even though the drawer is a proper
           aria-modal dialog: the trigger stays in the accessibility
           tree behind the backdrop, and without it a reader who
           lands back on it cannot tell whether the panel it opens is
           already open. -->
      <button
        type="button"
        class="iwac-search__filters-trigger"
        onclick={onOpenFilters}
        aria-expanded={filtersOpen}
        aria-label={t('open_filters')}
      >
        <span class="iwac-search__filters-trigger-icon" aria-hidden="true">
          <Icon name="filter" />
        </span>
        <span class="iwac-search__filters-trigger-label">{t('filters')}</span>
        {#if activeFilterCount > 0}
          <span class="iwac-search__filters-trigger-badge">{activeFilterCount}</span>
        {/if}
      </button>
      {#if showCopyLink}
        <button
          type="button"
          class="iwac-search__copylink"
          class:is-copied={copyLink.copied}
          onclick={() => copyLink.copy(window.location.href)}
          aria-label={t('copy_link')}
        >
          <span class="iwac-search__copylink-icon" aria-hidden="true">
            <Icon name="link" />
          </span>
          <span class="iwac-search__copylink-label">
            {copyLink.copied ? t('link_copied') : t('copy_link')}
          </span>
        </button>
      {/if}
      {#if fetchDocs}
        <ExportMenu {fetchDocs} {query} {found} />
      {/if}
    </div>
  </div>
  <!-- Shows the RESOLVED order, so the control and the summary a few
       pixels below it can never disagree about the ordering — which
       also means it must not offer a value that resolves to a
       different one, hence hasQuery. -->
  <SortSelect value={sort} onChange={onSortChange} hasQuery={query.trim() !== ''} />
</div>

<style>
  /*
   * Result controls. Desktop: one row — the view toggle sits at the left of a
   * growing bar that pushes export + sort to the right. Mobile: the bar and the
   * sort stack into two tidy rows (see the media query). Hairline under; anchors
   * the pagination scroll-back.
   */
  .iwac-search__controls {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem) var(--space-4, 1rem);
    flex-wrap: wrap;
    padding-block-end: var(--space-2, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  /* View toggle + (mobile) filters + actions. Grows so sort sits at the far end. */
  .iwac-search__controls-bar {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
    flex: 1 1 auto;
    min-width: 0;
  }
  .iwac-search__controls-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
    margin-inline-start: auto;
    flex-shrink: 0;
  }

  /* Hidden on wide viewports, where the filters sit in the sticky column. */
  .iwac-search__filters-trigger {
    display: none;
  }

  /*
   * Copy-link — quiet outlined control matching the toolbar vocabulary.
   * Swaps its label to a confirmation for 2 s after a successful copy.
   */
  .iwac-search__copylink {
    display: inline-flex;
    align-items: center;
    gap: var(--space-1, 0.25rem);
    height: var(--size-control-md, 2.5rem);
    padding-inline: var(--space-4, 1rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    background: var(--surface, #fdfcfb);
    color: var(--ink, #13161c);
    box-shadow: none;
    font: inherit;
    font-size: var(--text-sm, 0.9375rem);
    font-weight: 500;
    cursor: pointer;
    transition:
      border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
      color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
  }
  .iwac-search__copylink:hover {
    background: var(--surface, #fdfcfb);
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
    box-shadow: none;
    transform: none;
  }
  .iwac-search__copylink:focus-visible {
    outline: var(--focus-outline, 2px solid #ce4115);
    outline-offset: 2px;
  }
  .iwac-search__copylink.is-copied {
    border-color: var(--primary, #ce4115);
    color: var(--primary, #ce4115);
  }
  .iwac-search__copylink-icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.9em;
    color: var(--muted, #66696e);
  }
  .iwac-search__copylink:hover .iwac-search__copylink-icon,
  .iwac-search__copylink.is-copied .iwac-search__copylink-icon {
    color: var(--primary, #ce4115);
  }

  /* 767px = md 768 − 1 on the theme's published scale — the same boundary at
     which App collapses its layout and lib/filterDrawer.svelte.ts moves the
     facet panel into the Drawer, which is why the Filters trigger appears
     here at exactly that width. */
  @media (max-width: 767px) {
    /*
     * Tidy rows on a phone: [view · filters · actions] on top, then a
     * full-width sort row. Stacking the controls (column) bounds the sort row
     * to the viewport so its <select> can't overflow.
     */
    .iwac-search__controls {
      flex-direction: column;
      align-items: stretch;
      gap: var(--space-2, 0.5rem);
    }
    .iwac-search__controls-bar {
      width: 100%;
      /*
       * Wrap rather than squeeze. With Export present (results > 0) the four
       * labelled controls need ~566px but a phone offers ~418px, and the only
       * shrinkable child was the view toggle — which clips instead of
       * ellipsising. Wrapping drops the actions onto their own row, so the
       * bar stays honest at any width and in any locale (the French labels
       * are the widest, but nothing here depends on their length).
       */
      flex-wrap: wrap;
    }
    .iwac-search__controls :global(.iwac-sort) {
      display: flex;
      width: 100%;
    }
    .iwac-search__controls :global(.iwac-sort__select) {
      flex: 1 1 auto;
      min-width: 0;
    }

    /* Comfortable 44px touch targets across the whole bar. */
    .iwac-search__filters-trigger,
    .iwac-search__copylink,
    .iwac-search__controls :global(.iwac-view__btn),
    .iwac-search__controls :global(.iwac-export__trigger),
    .iwac-search__controls :global(.iwac-sort__select) {
      height: var(--size-control-lg, 2.75rem);
    }

    /*
     * Filters trigger — outlined, icon-forward (funnel + label + count). Hidden
     * on desktop, where filters live in the sticky sidebar; here it opens the
     * drawer, so it's the most important control on the row and keeps its label
     * longest (collapses to the funnel only on the narrowest phones below).
     */
    .iwac-search__filters-trigger {
      display: inline-flex;
      align-items: center;
      gap: var(--space-1, 0.25rem);
      padding-inline: var(--space-4, 1rem);
      border: 1px solid var(--border, #ced1d6);
      border-radius: var(--radius-md, 0.5rem);
      background: var(--surface, #fdfcfb);
      color: var(--ink, #13161c);
      box-shadow: none;
      font-size: var(--text-sm, 0.9375rem);
      font-weight: 500;
      cursor: pointer;
      transition:
        border-color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1)),
        color var(--transition-fast, 150ms cubic-bezier(0.25, 1, 0.5, 1));
    }
    .iwac-search__filters-trigger:hover {
      background: var(--surface, #fdfcfb);
      border-color: var(--primary, #ce4115);
      color: var(--primary, #ce4115);
      box-shadow: none;
      transform: none;
    }
    .iwac-search__filters-trigger:focus-visible {
      outline: var(--focus-outline, 2px solid #ce4115);
      outline-offset: 2px;
    }
    .iwac-search__filters-trigger-icon {
      display: inline-flex;
      align-items: center;
      font-size: 0.9em;
      color: var(--muted, #66696e);
    }
    .iwac-search__filters-trigger:hover .iwac-search__filters-trigger-icon {
      color: var(--primary, #ce4115);
    }
    /* The active count as primary tabular text, as the facet headings show
       theirs — not a filled pill, which the ledger grammar reserves for
       nothing (DESIGN-PHILOSOPHY: "active counts as primary tabular text").
       --primary-hover, the primary that clears 4.5:1 on every ground. */
    .iwac-search__filters-trigger-badge {
      color: var(--primary-hover, #b03710);
      font-size: var(--text-xs, 0.8125rem);
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }
  }

  /*
   * Smallest phones: the bar goes fully icon-forward — the view toggle and the
   * Export trigger already drop their labels at this breakpoint, so the Filters
   * and copy-link labels follow (icons stay; the buttons keep their aria-labels).
   */
  @media (max-width: 399px) {
    .iwac-search__filters-trigger,
    .iwac-search__copylink {
      padding-inline: var(--space-2, 0.5rem);
    }
    .iwac-search__filters-trigger-label,
    .iwac-search__copylink-label {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
      border: 0;
    }
  }
</style>

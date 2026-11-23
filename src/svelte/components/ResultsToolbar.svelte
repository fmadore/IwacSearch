<script lang="ts">
  /**
   * The row above the results: view toggle (left), then copy-link and
   * export, the narrow-viewport Filters trigger, and the sort (right). On a
   * phone it becomes two rows — [view · icon actions] / [filters · sort] —
   * so the two controls a reader works with share a row at equal width.
   *
   * It also anchors
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
  {#if view.supportsToggle}
    <ViewToggle value={view.mode} modes={view.modes} onChange={(m) => view.set(m)} />
  {/if}
  <div class="iwac-search__controls-actions">
    {#if showCopyLink}
      <!-- No aria-label: it pinned the name to "Copy link" over the swapped
           visible text. The label span is the name (visually hidden, still
           read, on a phone), and the outcome is spoken by the status line
           beside it — a name change on a focused button is not reliably
           announced. -->
      <button
        type="button"
        class="iwac-search__copylink iwac-quiet-btn"
        class:is-copied={copyLink.copied}
        onclick={() => copyLink.copy(window.location.href)}
      >
        <span class="iwac-search__copylink-icon" aria-hidden="true">
          <Icon name="link" />
        </span>
        <span class="iwac-search__copylink-label">
          {copyLink.status === 'copied'
            ? t('link_copied')
            : copyLink.status === 'failed'
              ? t('copy_failed')
              : t('copy_link')}
        </span>
      </button>
      <span class="iwac-search__copylink-status" role="status">
        {copyLink.status === 'copied'
          ? t('link_copied')
          : copyLink.status === 'failed'
            ? t('copy_failed')
            : ''}
      </span>
    {/if}
    {#if fetchDocs}
      <ExportMenu {fetchDocs} {query} {found} />
    {/if}
  </div>
  <!-- After the actions in source order, so on a phone it reads (and tabs)
       in the row it is drawn in, beside the sort. aria-expanded, even though
       the drawer is a proper aria-modal dialog: the trigger stays in the
       accessibility tree behind the backdrop, and without it a reader who
       lands back on it cannot tell whether the panel it opens is already
       open. -->
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
  <!-- Shows the RESOLVED order, so the control and the summary a few
       pixels below it can never disagree about the ordering — which
       also means it must not offer a value that resolves to a
       different one, hence hasQuery. -->
  <SortSelect value={sort} onChange={onSortChange} hasQuery={query.trim() !== ''} />
</div>

<style>
  /*
   * Result controls. Desktop: one row — the view toggle at the left, then
   * copy-link + export pushed to the right, then the sort. The Filters
   * trigger is hidden there (the facets sit in the sticky column). Phone: a
   * two-row grid (see the media query). Hairline under; anchors the
   * pagination scroll-back.
   */
  .iwac-search__controls {
    display: flex;
    align-items: center;
    gap: var(--space-2, 0.5rem) var(--space-4, 1rem);
    flex-wrap: wrap;
    padding-block-end: var(--space-2, 0.5rem);
    border-bottom: 1px solid var(--border-light, #e2e5e8);
  }
  .iwac-search__controls-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2, 0.5rem);
    margin-inline-start: auto;
    flex-shrink: 0;
  }
  .iwac-search__controls-actions:empty {
    display: none;
  }

  /* Hidden on wide viewports, where the filters sit in the sticky column. */
  .iwac-search__filters-trigger {
    display: none;
  }

  /*
   * Copy-link — the module's quiet control (.iwac-quiet-btn, in
   * asset/css/iwac-search.css), at the toolbar's height. Swaps its label to
   * a confirmation for 2 s after a copy.
   */
  .iwac-search__copylink {
    height: var(--size-control-md, 2.5rem);
  }
  .iwac-search__copylink.is-copied {
    border-color: var(--primary, #18298d);
    color: var(--primary-hover, #132278);
  }
  .iwac-search__copylink-status {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }
  .iwac-search__copylink-icon {
    display: inline-flex;
    align-items: center;
    font-size: 0.9em;
    color: var(--muted, #66696e);
  }
  .iwac-search__copylink:hover .iwac-search__copylink-icon,
  .iwac-search__copylink.is-copied .iwac-search__copylink-icon {
    color: var(--primary, #18298d);
  }

  /* 767px = md 768 − 1 on the theme's published scale — the same boundary at
     which App collapses its layout and lib/filterDrawer.svelte.ts moves the
     facet panel into the Drawer, which is why the Filters trigger appears
     here at exactly that width. */
  @media (max-width: 767px) {
    /*
     * Two rows, two equal columns:
     *
     *   [ List | Gallery ]          [link] [export]
     *   [ Filters (n)    ]   [ Newest first     v ]
     *
     * Through 3.29 the phone stacked four rows — the view toggle, then the
     * three labelled buttons right-aligned under it, then a "Sort by" row —
     * a ragged staircase that pushed the first result below the fold. The
     * reader's two working controls, Filters and the sort, now share a row
     * at equal width; copy-link and export, used once in a while, go
     * icon-only beside the view toggle (each keeps its accessible name).
     *
     * Grid auto-placement keeps the rows right whichever controls a surface
     * renders: the actions are pinned to column 2, so the Filters trigger
     * (column 1, after them in source order) always opens the second row.
     */
    .iwac-search__controls {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      gap: var(--space-2, 0.5rem);
    }
    .iwac-search__controls :global(.iwac-view) {
      grid-column: 1;
      justify-self: start;
    }
    .iwac-search__controls-actions {
      grid-column: 2;
      justify-self: end;
      margin-inline-start: 0;
    }
    .iwac-search__filters-trigger {
      grid-column: 1;
    }
    .iwac-search__controls :global(.iwac-sort) {
      grid-column: 2;
      display: flex;
      min-width: 0;
    }
    .iwac-search__controls :global(.iwac-sort__select) {
      flex: 1 1 auto;
      min-width: 0;
      width: 100%;
    }
    /* The select shows the order itself ("Newest first"), so the "Sort by"
       prefix only cost the half-row it sits in. Kept for assistive tech:
       it is the select's label. */
    .iwac-search__controls :global(.iwac-sort__label),
    .iwac-search__copylink-label,
    .iwac-search__controls :global(.iwac-export__label) {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
      border: 0;
    }

    /* Comfortable 44px touch targets across the whole bar; the icon-only
       actions are square. */
    .iwac-search__filters-trigger,
    .iwac-search__copylink,
    .iwac-search__controls :global(.iwac-view__btn),
    .iwac-search__controls :global(.iwac-export__trigger),
    .iwac-search__controls :global(.iwac-sort__select) {
      height: var(--size-control-lg, 2.75rem);
    }
    /* Scoped under the bar so they outrank .iwac-quiet-btn's padding
       (asset/css/iwac-search.css, same specificity otherwise). */
    .iwac-search__controls .iwac-search__copylink,
    .iwac-search__controls :global(.iwac-export__trigger) {
      width: var(--size-control-lg, 2.75rem);
      padding-inline: 0;
      justify-content: center;
    }

    /*
     * Filters trigger — outlined, icon-forward (funnel + label + count),
     * filling its half of the row. Hidden on desktop, where filters live in
     * the sticky sidebar; here it opens the drawer.
     */
    .iwac-search__filters-trigger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: var(--space-1, 0.25rem);
      width: 100%;
      min-width: 0;
      padding-inline: var(--space-3, 0.75rem);
      border: 1px solid var(--border, #ced1d6);
      border-radius: var(--radius-md, 0);
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
      border-color: var(--primary, #18298d);
      color: var(--primary, #18298d);
    }
    .iwac-search__filters-trigger:focus-visible {
      outline: var(--focus-outline, 2px solid #18298d);
      outline-offset: 2px;
    }
    .iwac-search__filters-trigger-icon {
      display: inline-flex;
      align-items: center;
      font-size: 0.9em;
      color: var(--muted, #66696e);
    }
    .iwac-search__filters-trigger:hover .iwac-search__filters-trigger-icon {
      color: var(--primary, #18298d);
    }
    /* The active count as primary tabular text, as the facet headings show
       theirs — not a filled pill, which the ledger grammar reserves for
       nothing (DESIGN-PHILOSOPHY: "active counts as primary tabular text").
       --primary-hover, the primary that clears 4.5:1 on every ground. */
    .iwac-search__filters-trigger-badge {
      color: var(--primary-hover, #132278);
      font-size: var(--text-xs, 0.8125rem);
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }
  }
</style>

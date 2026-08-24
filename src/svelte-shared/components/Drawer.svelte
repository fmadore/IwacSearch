<script lang="ts">
  import type { Snippet } from 'svelte';
  import { focusFirstWithin, handleTrapKeydown, restoreFocus } from '../lib/focusTrap';

  /**
   * Slide-in overlay panel — the canonical shape used by:
   *
   *   - the public mobile filter drawer (App.svelte)
   *   - any future "open a side panel for one task" surface
   *
   * What it owns:
   *
   *   - Mount / unmount controlled by `open` (no internal state — the
   *     parent decides when to show).
   *   - Slide-in animation from the configured side.
   *   - Backdrop fade + click-to-close.
   *   - Esc-to-close (window keydown listener active only while open).
   *   - Body scroll lock while open, with cleanup on close + on unmount
   *     so a parent that disappears mid-open never leaves overflow:hidden
   *     stuck on document.body.
   *   - Header with a title and a close × (the only opinion in the
   *     visual chrome — every place we use this also has a header, so
   *     hiding it would just push that boilerplate back out to the
   *     consumer). Pass an empty string in `title` to hide the header.
   *   - Sensible default ARIA wiring (role=dialog + aria-modal +
   *     aria-labelledby pointed at the auto-generated title id).
   *   - The whole focus contract that `aria-modal` promises but the
   *     browser does not keep: focus moves in on open, Tab/Shift+Tab
   *     cycle inside, Esc closes, focus returns to the invoker. The
   *     mechanics live in ../lib/focusTrap.ts, where they are
   *     unit-tested; this component only decides when to call them.
   *
   * What it does NOT own:
   *
   *   - Form submission, validation, internal state. Consumer is just
   *     given a body slot via `children` and decides what to render.
   *
   * Both bundles (public + admin) import from src/svelte-shared/, so
   * adding a new shared widget is just dropping a file here.
   */

  interface Props {
    open: boolean;
    onClose: () => void;
    /** Header title shown next to the × button. Set to '' to hide the header. */
    title?: string;
    /** Side the panel slides in from. */
    side?: 'right' | 'left';
    /** CSS width (or block-size on left/right). Defaults to min(42rem, 100vw). */
    width?: string;
    /** ARIA label override — only useful when `title` is empty. */
    ariaLabel?: string;
    /**
     * Accessible name for the close ×. Defaults to English because the admin
     * bundle has no translator; every public caller passes a localised
     * string, and until one did, the French filter drawer closed with a
     * button that announced itself as "Close".
     */
    closeLabel?: string;
    /** Custom CSS class on the root for one-off tweaks. */
    panelClass?: string;
    children: Snippet;
  }

  const {
    open,
    onClose,
    title = '',
    side = 'right',
    width = 'min(42rem, 100vw)',
    ariaLabel,
    closeLabel = 'Close',
    panelClass = '',
    children,
  }: Props = $props();

  // Stable id so aria-labelledby can point at the rendered <h2>.
  // Math.random() is fine — only needs to be unique within a page render.
  const titleId = `iwac-drawer-title-${Math.random().toString(36).slice(2, 9)}`;

  function onBackdropKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onClose();
    }
  }

  function onWindowKeydown(e: KeyboardEvent): void {
    if (open && e.key === 'Escape') {
      e.preventDefault();
      onClose();
    }
  }

  // ── Focus contract ──────────────────────────────────────────────────
  // The panel element, bound below. Null while closed (the panel is inside
  // an {#if}), which is what makes the $effect's `open` dependency enough.
  let panelEl: HTMLElement | null = $state(null);

  /**
   * Whatever had focus when the drawer opened, so it can be handed back on
   * close. Deliberately NOT $state: it is read only inside the effect's
   * teardown, and making it reactive would re-run the effect that sets it.
   */
  let invoker: HTMLElement | null = null;

  function onPanelKeydown(e: KeyboardEvent): void {
    if (panelEl) handleTrapKeydown(panelEl, e);
  }

  $effect(() => {
    const panel = panelEl;
    if (!open || !panel) return;

    // Capture BEFORE moving focus, or the invoker is the panel itself.
    invoker = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    focusFirstWithin(panel);

    return () => {
      // Only take focus back if it is still ours to give. A user who clicked
      // a link behind the drawer (or whose click closed it and landed
      // elsewhere) has already chosen where focus should be; yanking it back
      // to the trigger would be the bug this exists to fix, inverted.
      const active = document.activeElement;
      const focusStillInside = active instanceof HTMLElement && panel.contains(active);
      if (focusStillInside || active === document.body || active === null) {
        restoreFocus(invoker);
      }
      invoker = null;
    };
  });

  // Body scroll lock — single source of truth, restores on close OR
  // on component unmount (so a parent that yanks the drawer mid-open
  // doesn't leak overflow:hidden onto document.body forever).
  $effect(() => {
    if (typeof document === 'undefined') return;
    if (!open) return;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  });
</script>

<svelte:window onkeydown={onWindowKeydown} />

{#if open}
  <div
    class="iwac-drawer-backdrop"
    role="presentation"
    onclick={onClose}
    onkeydown={onBackdropKeydown}
  ></div>

  <!-- tabindex="-1" so the panel itself can hold focus when it opens with
       nothing focusable inside it — a trap needs somewhere to put focus, or
       the first Tab escapes to the page behind the backdrop. -->
  <div
    class="iwac-drawer iwac-drawer--{side} {panelClass}"
    role="dialog"
    aria-modal="true"
    aria-labelledby={title ? titleId : undefined}
    aria-label={!title ? ariaLabel : undefined}
    style:--iwac-drawer-width={width}
    tabindex="-1"
    bind:this={panelEl}
    onkeydown={onPanelKeydown}
  >
    {#if title}
      <!--
        A <div>, not a <header>: in the Omeka S admin backend (the admin CRUD
        drawer) bare <header> elements inherit Omeka's navy page-header styling,
        which overrode this header's own background/colour. The <h2> + dialog
        ARIA still carry the heading semantics, so a div loses nothing.
      -->
      <div class="iwac-drawer__header">
        <h2 id={titleId} class="iwac-drawer__title">{title}</h2>
        <button type="button" class="iwac-drawer__close" aria-label={closeLabel} onclick={onClose}>
          ×
        </button>
      </div>
    {/if}

    <div class="iwac-drawer__body">
      {@render children()}
    </div>
  </div>
{/if}

<style>
  .iwac-drawer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.35);
    /* Above the IWAC-theme sticky header (z-index 200) and its menu-drawer
       (300) so the filter drawer overlays the page chrome instead of sliding
       in behind the sticky header (it appeared cut off under it on mobile). */
    z-index: 400;
    animation: iwac-drawer-fade-in 150ms ease;
  }
  .iwac-drawer {
    position: fixed;
    inset-block: 0;
    width: var(--iwac-drawer-width, min(42rem, 100vw));
    background: var(--surface, #fdfcfb);
    z-index: 401;
    display: flex;
    flex-direction: column;
  }
  /* The panel takes focus programmatically when it opens (see the trap), and
     a tabindex="-1" container is one of the few places a UA outline is pure
     noise: it is not a control, and the ring says nothing about where the
     next Tab goes. :focus-visible is untouched, so a keyboard user who
     lands here still sees it. */
  .iwac-drawer:focus:not(:focus-visible) {
    outline: none;
  }
  .iwac-drawer--right {
    inset-inline-end: 0;
    box-shadow: -12px 0 32px rgba(0, 0, 0, 0.12);
    animation: iwac-drawer-slide-from-right 200ms cubic-bezier(0.2, 0, 0, 1);
  }
  .iwac-drawer--left {
    inset-inline-start: 0;
    box-shadow: 12px 0 32px rgba(0, 0, 0, 0.12);
    animation: iwac-drawer-slide-from-left 200ms cubic-bezier(0.2, 0, 0, 1);
  }

  @keyframes iwac-drawer-fade-in {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
  @keyframes iwac-drawer-slide-from-right {
    from {
      transform: translateX(100%);
    }
    to {
      transform: translateX(0);
    }
  }
  @keyframes iwac-drawer-slide-from-left {
    from {
      transform: translateX(-100%);
    }
    to {
      transform: translateX(0);
    }
  }

  .iwac-drawer__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md, 1rem) var(--space-lg, 1.5rem);
    border-bottom: 1px solid var(--border, #ced1d6);
    /* Sticky so long bodies still show the title + close affordance. */
    position: sticky;
    inset-block-start: 0;
    background: var(--surface, #fdfcfb);
    z-index: 1;
  }
  .iwac-drawer__title {
    margin: 0;
    font-size: var(--text-xl, 1.5rem);
    color: var(--ink, #13161c);
    /* Truncate gracefully — the title's job is to identify, not narrate. */
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .iwac-drawer__close {
    flex-shrink: 0;
    border: none;
    background: none;
    font-size: var(--text-2xl, 1.875rem);
    color: var(--muted, #66696e);
    cursor: pointer;
    width: 2rem;
    height: 2rem;
    border-radius: var(--radius-full, 9999px);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    margin-inline-start: var(--space-md, 1rem);
  }
  .iwac-drawer__close:hover {
    background: var(--surface-sunken, #f4f1ef);
    color: var(--ink, #13161c);
  }
  .iwac-drawer__body {
    flex: 1;
    overflow-y: auto;
  }
</style>

/**
 * Mobile filter-drawer composable — extracted from App.svelte.
 *
 * On wide screens the FacetPanel sits in a sticky left column. On narrow
 * screens it hides behind a "Filters" trigger and slides in from the right
 * inside the shared <Drawer>. matchMedia gives a reactive boolean for
 * "narrow viewport" so the conditional render picks the right shell.
 * FacetPanel re-mounts on a resize across the breakpoint; acceptable
 * because the only state worth preserving is FacetGroup's expand memory,
 * and resizing across breakpoints mid-session is rare.
 *
 * THE BOUNDARY IS DUPLICATED. `max-width: 767px` here (md 768 − 1, the
 * theme's published breakpoint scale) must equal the `@media` in
 * App.svelte that collapses `.iwac-search__layout` to one column: this
 * decides which SHELL renders, that decides the grid it renders into, and
 * a disagreement puts the facet panel in the drawer while the page still
 * reserves a sidebar column for it (or the reverse). No guard reaches a
 * matchMedia string, so it is checked by eye — which is the reason both
 * halves name the same literal rather than one deriving from the other.
 */
export interface FilterDrawerState {
  readonly open: boolean;
  readonly isNarrow: boolean;
  show(): void;
  close(): void;
  /** Run inside an $effect — attaches the matchMedia listener, returns its cleanup. */
  attach(breakpoint?: string): (() => void) | undefined;
}

export function createFilterDrawer(): FilterDrawerState {
  let open = $state(false);
  let isNarrow = $state(false);

  return {
    get open() {
      return open;
    },
    get isNarrow() {
      return isNarrow;
    },
    show(): void {
      open = true;
    },
    close(): void {
      open = false;
    },
    attach(breakpoint = '(max-width: 767px)'): (() => void) | undefined {
      if (typeof window === 'undefined') return undefined;
      const mq = window.matchMedia(breakpoint);
      const update = (): void => {
        isNarrow = mq.matches;
        // Desktop should never have the drawer "open" — close it
        // proactively on resize so the next narrow→wide→narrow cycle
        // doesn't snap an already-open overlay back into view.
        if (!mq.matches) open = false;
      };
      update();
      mq.addEventListener('change', update);
      return () => mq.removeEventListener('change', update);
    },
  };
}

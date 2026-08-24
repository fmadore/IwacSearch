import type { IwacSearchResponse, ViewMode } from './types';

/**
 * View-mode composable — extracted from App.svelte (design review §01),
 * where the resolution rules (URL wins → sticky localStorage → default) and
 * the one-shot gallery auto-suggest had grown into ~60 lines of state
 * plumbing tangled into the orchestrator.
 *
 * Surfaces differ only in which modes they offer: content surfaces get
 * ['list', 'gallery']; the entity index gets ['list', 'map']. A mode not
 * offered here is rejected everywhere (URL, storage, popstate), so a stored
 * 'map' preference can't strand a content surface.
 */

const VIEW_STORAGE_KEY = 'iwac-view-mode';

/** Image-bearing types — when a result set is mostly these, a gallery
 * serves the researcher better than a ledger of text rows. */
const IMAGE_BEARING_TYPES = new Set(['photograph', 'document', 'audiovisual']);

export interface ViewModeOptions {
  /** Presentation modes this surface offers ('list' always included). */
  modes: readonly ViewMode[];
  /** Whether this mount syncs state to the URL. */
  syncUrl: boolean;
  urlPrefix: string;
  /** View parsed from the URL at mount; null when the URL carries none. */
  initialView: ViewMode | null;
}

export interface ViewModeState {
  readonly mode: ViewMode;
  /** Deliberately chosen (URL / localStorage / user toggle) — only explicit
   * choices sync to the URL + localStorage; an auto-suggested gallery stays
   * a per-session presentation hint. */
  readonly explicit: boolean;
  readonly supportsToggle: boolean;
  readonly modes: readonly ViewMode[];
  set(next: ViewMode): void;
  applyPop(view: ViewMode | null): void;
  autoSuggest(r: IwacSearchResponse): void;
}

export function createViewMode(opts: ViewModeOptions): ViewModeState {
  const multi = opts.modes.length > 1;
  const valid = (v: string | null | undefined): v is ViewMode =>
    !!v && (opts.modes as readonly string[]).includes(v);

  function readStored(): ViewMode | null {
    try {
      const v = window.localStorage.getItem(VIEW_STORAGE_KEY);
      return valid(v) ? v : null;
    } catch {
      return null;
    }
  }

  // Initial view: an explicit URL `view` param (shared link) wins, then the
  // sticky localStorage preference, else `list` (density first) — possibly
  // upgraded later by the image-heavy auto-suggest.
  function resolveInitial(): { view: ViewMode; explicit: boolean } {
    if (!multi) return { view: 'list', explicit: true };
    // A `view` in the URL is an explicit choice even when this surface can't
    // honour the exact mode (a stored `map` on a content surface): the reader
    // asked for a presentation, so the auto-suggest stays out of it either
    // way and the unsupported value falls back to `list`.
    if (opts.syncUrl && opts.initialView !== null) {
      return { view: valid(opts.initialView) ? opts.initialView : 'list', explicit: true };
    }
    const stored = readStored();
    if (stored) return { view: stored, explicit: true };
    return { view: 'list', explicit: false };
  }

  const initial = resolveInitial();
  let mode = $state<ViewMode>(initial.view);
  let explicit = $state(initial.explicit);
  let autoApplied = $state(false);

  return {
    get mode() {
      return mode;
    },
    get explicit() {
      return explicit;
    },
    get supportsToggle() {
      return multi;
    },
    get modes() {
      return opts.modes;
    },

    /**
     * Explicit user choice — sticks in localStorage and the URL.
     *
     * The `next === mode` early return that used to guard this was a bug, not
     * an optimisation: pressing "List" while the surface was on the IMPLICIT
     * list recorded no choice at all, so the image-heavy auto-suggest was
     * still armed and the first response flipped the reader straight out of
     * the view they had just asked for. Choosing the mode you are already in
     * is how a reader says "yes, this one, stop deciding for me".
     */
    set(next: ViewMode): void {
      if (!valid(next)) return;
      mode = next;
      explicit = true;
      try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, next);
      } catch {
        /* private mode / storage disabled — the toggle still works in-session */
      }
    },

    /** Back/forward re-hydration: a `view` in the popped URL is an explicit
     * choice to honour; its absence reverts to the implicit default. */
    applyPop(view: ViewMode | null): void {
      if (!multi) return;
      explicit = view !== null;
      mode = view !== null && valid(view) ? view : 'list';
    },

    /**
     * Auto-suggest Gallery once, on the first response, when the set is
     * image-heavy and the user hasn't explicitly chosen a view. Never
     * overrides an explicit choice; doesn't persist (session hint).
     *
     * MUST only be handed a response the reader is actually being shown. It
     * used to run on every response, including the vector-only set the
     * semantic withhold hides — so a dead query could silently flip the
     * surface to a gallery of documents nobody could see, and the flip then
     * outlived the query that caused it.
     */
    autoSuggest(r: IwacSearchResponse): void {
      if (!multi || explicit || autoApplied || !valid('gallery')) return;
      const hits = r.hits ?? [];
      if (hits.length < 4) return; // too few to judge confidently — try again
      autoApplied = true;
      const n = hits.filter((h) => IMAGE_BEARING_TYPES.has(h.document.type_s ?? '')).length;
      if (n / hits.length > 0.6) mode = 'gallery';
    },
  };
}

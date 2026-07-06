import type { IwacSearchResponse, ViewMode } from './types';
import { urlHasView } from './urlState';

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
  /** View parsed from the URL at mount ('list' when absent). */
  initialView: ViewMode;
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
  applyPop(view: ViewMode): void;
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
    if (
      opts.syncUrl &&
      urlHasView(window.location.href, opts.urlPrefix) &&
      valid(opts.initialView)
    ) {
      return { view: opts.initialView, explicit: true };
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

    /** Explicit user choice — sticks in localStorage and the URL. */
    set(next: ViewMode): void {
      if (next === mode || !valid(next)) return;
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
    applyPop(view: ViewMode): void {
      if (!multi) return;
      mode = valid(view) ? view : 'list';
      explicit = urlHasView(window.location.href, opts.urlPrefix);
    },

    /** Auto-suggest Gallery once, on the first response, when the set is
     * image-heavy and the user hasn't explicitly chosen a view. Never
     * overrides an explicit choice; doesn't persist (session hint). */
    autoSuggest(r: IwacSearchResponse): void {
      if (!multi || explicit || autoApplied || !valid('gallery')) return;
      autoApplied = true;
      const hits = r.hits ?? [];
      if (hits.length < 4) return; // too few to judge confidently
      const n = hits.filter((h) => IMAGE_BEARING_TYPES.has(h.document.type_s ?? '')).length;
      if (n / hits.length > 0.6) mode = 'gallery';
    },
  };
}

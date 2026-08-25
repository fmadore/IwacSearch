import type SuggestDropdown from '../components/SuggestDropdown.svelte';

/**
 * Search-box typeahead state: open/closed, the ARIA combobox wiring, and the
 * focus/blur/keydown handlers the search form delegates to it.
 *
 * Pulled out of App.svelte, which owned four `$state` fields, one `$derived`
 * and seven handlers for this alone — none of which the rest of the search
 * state interacts with. App keeps the parts that DO cross over (committing a
 * picked query, applying a picked entity as a filter) and passes them in.
 *
 * ── The panel's life, and why it ends where it does ──────────────────────
 *
 * The dropdown floats over the toolbar, the summary strip and the first
 * result, so its life is bounded at both ends:
 *
 *   opens   on focus, on a RAW keystroke that leaves text in the box, and on
 *           ArrowDown
 *   closes  on a pointer press outside it, on blur, on Escape, on picking a
 *           row, and when the box is emptied
 *
 * Committing a search is deliberately in NEITHER column, and that is the rule
 * 3.16.1 restores. 3.16.0 put it in the closing one — the panel yielded the
 * moment the debounced results landed — on the argument that every row it
 * could show restated a result already rendered below. That was true of the
 * panel as it existed BEFORE 3.16.0: fed the COMMITTED query, it ran 250 ms
 * behind the text on screen and could only appear at the moment it became
 * redundant. Fed the raw box contents, the argument inverts. A suggestion is
 * not a result: an entity row applies a facet filter, a title row jumps
 * straight to one document out of hundreds — neither is a row on the list
 * underneath. And the reader who pauses to READ the suggestions is doing the
 * one thing the panel exists for, so treating that pause as "done with them"
 * gave them roughly a second (the 250 ms debounce plus the round trip) between
 * typing and the panel vanishing. That is no time at all to aim at a row, and
 * it is what the owner reported against 3.16.0.
 *
 * What 3.16.0 got right is kept, because all of it bounds the panel without
 * guessing at intent: an outside press closes it — so it can never
 * PERMANENTLY occlude the toolbar it floats over, which was the Wave-2 bug —
 * blur closes it, Escape closes it, and each row (not the container) suppresses
 * its own blur, so a press on the panel's dead space or on an occluded control
 * lands first-press.
 *
 * Nothing re-opens the panel on its own. In particular the commit must not
 * re-arm one the reader dismissed: `armForQuery()` used to fire from the
 * DEBOUNCED handler, re-opening the panel at the exact moment the results
 * underneath it became the answer. Re-opening takes new typing, a new focus,
 * or ArrowDown.
 */

/** Grace period so a click on a dropdown row registers before it closes. */
const BLUR_CLOSE_MS = 120;

/**
 * Parts of the search surface a pointer press must NOT dismiss the panel from:
 * the input itself (that press is how you open it) and the actionable rows
 * (their click has to land). Everything else — the panel's own dead space
 * included — is a press aimed at the page underneath.
 */
const KEEP_OPEN_SELECTOR = '.iwac-input, .iwac-suggest__item, .iwac-suggest__clear';

export interface TypeaheadCallbacks {
  /** Commit a query string (from a recent search or the "Search for …" row). */
  onCommitQuery: (text: string) => void;
  /** Apply a picked entity as a facet filter. */
  onPickEntity: (field: string, value: string) => void;
}

export function createTypeahead(blockId: string | number, callbacks: TypeaheadCallbacks) {
  let open = $state(false);
  let activeId = $state<string | null>(null);
  let ref: SuggestDropdown | undefined = $state();

  // block_id is unique per mount, so the listbox id (and the option ids
  // derived from it) never collide when several search surfaces share a page.
  const listboxId = `iwac-suggest-${blockId}`;
  // Matches the dropdown's own render condition, so aria-expanded doesn't
  // claim a listbox that isn't there.
  const expanded = $derived(open && activeId !== null);

  return {
    get open(): boolean {
      return open;
    },
    get expanded(): boolean {
      return expanded;
    },
    get activeId(): string | null {
      return activeId;
    },
    get ref(): SuggestDropdown | undefined {
      return ref;
    },
    set ref(next: SuggestDropdown | undefined) {
      ref = next;
    },
    listboxId,

    setActiveId(id: string | null): void {
      activeId = id;
    },
    close(): void {
      open = false;
    },
    /**
     * Every RAW keystroke, undebounced.
     *
     * Text in the box arms the panel — including after a blur, a pick or an
     * Escape, all of which leave focus in the box. An EMPTY box closes it:
     * there is nothing to suggest against, and the recent-searches list would
     * otherwise pop open over the results the moment the reader backspaced the
     * last character or hit the clear button.
     *
     * Focusing an already-empty box is the other case, and it deliberately
     * does still offer the recent searches — that press is a request for them.
     */
    handleInput(raw: string): void {
      open = raw.trim() !== '';
    },
    handleFocus(): void {
      open = true;
    },
    handleBlur(): void {
      window.setTimeout(() => {
        open = false;
      }, BLUR_CLOSE_MS);
    },
    /**
     * Let the dropdown consume arrow / enter / escape first — except when it
     * is closed, where ArrowDown is the way back to a panel the reader
     * dismissed (the same affordance the masthead enhancer offers).
     */
    handleKeydown(e: KeyboardEvent): void {
      if (!open && e.key === 'ArrowDown') {
        e.preventDefault();
        open = true;
        return;
      }
      ref?.handleKeydown(e);
    },

    /** A recent search or suggestion text was picked. */
    pickQuery(text: string): void {
      callbacks.onCommitQuery(text);
      open = false;
    },
    /**
     * Enter (or the "Search for …" row) runs a full-text search for exactly
     * what was typed — no need to pick a suggestion. The search runs
     * reactively off the query, so this only commits the text and closes.
     */
    runSearch(text: string): void {
      callbacks.onCommitQuery(text);
      open = false;
    },
    /**
     * Picking an entity (place / topic / person / organisation) applies it as
     * a facet filter and clears the free-text query, so the user sees every
     * document tagged with that entity within the current scope.
     */
    pickEntity(field: string, value: string): void {
      open = false;
      callbacks.onPickEntity(field, value);
    },
  };
}

/**
 * Dismiss the panel on a pointer press that isn't for it.
 *
 * A `blur` listener alone does not cover this. The dropdown used to block the
 * blur from its CONTAINER (`onmousedown={preventBlur}` on the panel, not on
 * the rows), so a press on the panel's dead space — which is exactly where a
 * reader aiming at the occluded toolbar lands — kept focus in the input and
 * left the panel open. Clicking the same control again did the same thing,
 * indefinitely; only Escape got out. Per-row blur suppression plus this
 * listener replaces that: rows still survive their own click, and every other
 * press closes.
 *
 * `capture: true` so the close is decided before the press reaches anything
 * that might stop its propagation. `root` scopes the "keep open" exemption to
 * THIS mount, so a press in one search block's box still dismisses another's.
 *
 * Returns the `$effect` teardown.
 */
export function dismissOnOutsidePointer(
  isOpen: () => boolean,
  getRoot: () => HTMLElement | null,
  close: () => void,
): () => void {
  const onPointerDown = (e: Event): void => {
    if (!isOpen()) return;
    const root = getRoot();
    const target = e.target;
    if (root && target instanceof Element) {
      const keep = target.closest(KEEP_OPEN_SELECTOR);
      if (keep && root.contains(keep)) return;
    }
    close();
  };
  document.addEventListener('pointerdown', onPointerDown, true);
  return () => document.removeEventListener('pointerdown', onPointerDown, true);
}

/**
 * "/" focuses the search box from anywhere on the page (the GitHub /
 * Wikipedia convention), unless the user is already typing somewhere.
 *
 * Returns the `$effect` teardown, so callers use it as `$effect(() =>
 * slashShortcut(() => formEl))`.
 */
export function slashShortcut(getForm: () => HTMLFormElement | null): () => void {
  const onKeydown = (e: KeyboardEvent): void => {
    if (e.key !== '/' || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    const target = e.target as HTMLElement | null;
    if (
      target &&
      (target.isContentEditable ||
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT')
    ) {
      return;
    }
    const input = getForm()?.querySelector('input');
    if (input) {
      e.preventDefault();
      input.focus();
      input.select();
    }
  };
  window.addEventListener('keydown', onKeydown);
  return () => window.removeEventListener('keydown', onKeydown);
}

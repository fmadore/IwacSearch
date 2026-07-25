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
 * Behaviour preserved exactly: the dropdown opens on focus, re-arms on every
 * keystroke (so fresh typing reopens it after a blur), and closes on blur
 * after a short delay — the delay lets a click on a suggestion land before
 * the dropdown unmounts. The dropdown ALSO blocks the parent blur via
 * preventBlur on mousedown; both belts are deliberate.
 */

/** Grace period so a click on a dropdown row registers before it closes. */
const BLUR_CLOSE_MS = 120;

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
    /** Re-arm on every query mutation — fresh keystrokes reopen suggestions. */
    armForQuery(): void {
      open = true;
    },
    handleFocus(): void {
      open = true;
    },
    handleBlur(): void {
      window.setTimeout(() => {
        open = false;
      }, BLUR_CLOSE_MS);
    },
    /** Let the dropdown consume arrow / enter / escape first. */
    handleKeydown(e: KeyboardEvent): void {
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

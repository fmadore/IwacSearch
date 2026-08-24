/**
 * The focus half of the modal-dialog contract, as plain DOM functions.
 *
 * `aria-modal="true"` is a PROMISE to assistive technology: everything
 * outside this element is inert. The browser does not keep it — it only stops
 * screen readers from reaching the rest of the page, and sequential focus
 * traversal walks straight out of the dialog regardless. The Phase-1 audit
 * caught exactly that shape on the mobile filter drawer: six of six Tab
 * presses landed in the results column, visually behind the backdrop AND
 * hidden from AT, so a keyboard user could not reach the drawer's own 70
 * checkboxes without first tabbing the entire page.
 *
 * Three obligations, all of them here rather than in the component, so they
 * can be unit-tested without mounting Svelte:
 *
 *   1. focus moves INTO the dialog when it opens,
 *   2. Tab and Shift+Tab cycle within it,
 *   3. focus returns to whatever opened it when it closes.
 *
 * (3) is the one that is invisible when it is missing and infuriating when it
 * bites: dismissing the drawer used to drop focus to <body>, so the next Tab
 * restarted at the top of the document rather than at the Filters button the
 * user had just pressed.
 */

/**
 * Selector for the things a keyboard can land on. `[tabindex]` is matched
 * broadly and negative values are filtered below rather than excluded here —
 * `[tabindex]:not([tabindex^="-"])` misses `tabindex="-01"` and any value a
 * template computes, and a focusable element wrongly treated as unfocusable
 * is a hole in the trap.
 */
const FOCUSABLE = [
  'a[href]',
  'area[href]',
  'button',
  'input',
  'select',
  'textarea',
  'details > summary',
  'iframe',
  'audio[controls]',
  'video[controls]',
  '[contenteditable]',
  '[tabindex]',
].join(',');

/** Is this element actually reachable — not disabled, not hidden, not inert? */
function isReachable(el: HTMLElement): boolean {
  if (el.hasAttribute('disabled') || el.getAttribute('aria-hidden') === 'true') return false;
  if (Number(el.getAttribute('tabindex') ?? '0') < 0) return false;
  if (el.closest('[inert]')) return false;
  // A zero-area box is display:none, visibility:hidden, or a collapsed
  // ancestor. offsetParent alone would wrongly reject `position: fixed`
  // elements, which the drawer panel itself is.
  return el.getClientRects().length > 0;
}

/** Every tabbable element inside `root`, in document (tab) order. */
export function focusableWithin(root: HTMLElement): HTMLElement[] {
  return [...root.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(isReachable);
}

/**
 * Move focus into `root`: its first tabbable child, or the container itself.
 *
 * The container fallback is why the panel carries `tabindex="-1"`. A dialog
 * that opens with nothing focusable inside it (a drawer whose body is still
 * loading, say) must still take focus, or the trap has nothing to trap and
 * the next Tab escapes to the page behind the backdrop.
 */
export function focusFirstWithin(root: HTMLElement): void {
  const [first] = focusableWithin(root);
  (first ?? root).focus();
}

/**
 * Handle one keydown for a trapped dialog. Returns true when the event was
 * the Tab that wrapped, so the caller knows it was handled.
 *
 * Wrapping is computed from a LIVE query rather than a list captured at open
 * time: the facet panel inside the drawer grows and shrinks as groups expand,
 * values are searched, and "Show N more" is pressed, so a cached list would
 * strand focus on removed nodes within seconds of real use.
 */
export function handleTrapKeydown(root: HTMLElement, e: KeyboardEvent): boolean {
  if (e.key !== 'Tab') return false;
  const items = focusableWithin(root);
  if (items.length === 0) {
    // Nothing to move to — keep focus on the container rather than letting
    // Tab walk out of the dialog.
    e.preventDefault();
    root.focus();
    return true;
  }
  const first = items[0];
  const last = items[items.length - 1];
  const active = document.activeElement;

  // Focus sitting on the container (or somewhere outside, e.g. after a click
  // on the backdrop) is not at either end, so Tab would leave. Treat it as
  // "before the first item".
  if (active === null || active === root || !root.contains(active)) {
    e.preventDefault();
    (e.shiftKey ? last : first).focus();
    return true;
  }
  if (!e.shiftKey && active === last) {
    e.preventDefault();
    first.focus();
    return true;
  }
  if (e.shiftKey && active === first) {
    e.preventDefault();
    last.focus();
    return true;
  }
  return false;
}

/**
 * Give focus back to `el` if it is still in the document and still focusable.
 *
 * Both guards matter. The invoker can be gone (the drawer's own trigger is
 * inside a media query — a rotate-to-landscape unmounts it), and it can be
 * present but hidden, in which case `.focus()` silently does nothing and
 * leaves focus on <body>. Returns whether focus was actually restored.
 */
export function restoreFocus(el: HTMLElement | null): boolean {
  if (!el || !el.isConnected || !isReachable(el)) return false;
  el.focus();
  return true;
}

import { tick } from 'svelte';

/** An element, or a thunk evaluated after the update. */
export type FocusCandidate =
  HTMLElement | null | undefined | (() => HTMLElement | null | undefined);

/**
 * After a page change: focus the results region (the pager button pressed is
 * about to be re-rendered, which would drop focus to <body>) and bring its top
 * into view — smoothly unless the reader asked for reduced motion. Focus does
 * not scroll (`preventScroll`), so the smooth scroll is not cancelled by it.
 */
export function landOnResults(
  region: HTMLElement | null,
  anchor: HTMLElement | null = region,
): void {
  region?.focus({ preventScroll: true });
  const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  anchor?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
}

/**
 * Put focus back somewhere sensible after a control removed itself.
 *
 * A button that a click makes disappear — a chip that removes its filter, a
 * "clear" ×, "Clear all", the slider's Reset, an offer that swaps for its
 * banner — takes the keyboard focus down with it: focus falls to <body>, and
 * the next Tab restarts at the top of a page whose facet column alone is ~100
 * stops long. ResultSummary solved it for its chips first; this is that fix
 * made reusable.
 *
 * Waits for the DOM update the removal caused, then — only if focus really
 * was lost — focuses the first candidate that is still in the document.
 * Candidates are elements or thunks (evaluated after the update, so a thunk
 * can find the chip that took the removed one's place). Any focus the update
 * itself placed somewhere real is left alone.
 */
export async function refocus(...candidates: FocusCandidate[]): Promise<void> {
  await tick();
  const active = document.activeElement;
  if (active && active !== document.body && active.isConnected) return;
  for (const candidate of candidates) {
    const el = typeof candidate === 'function' ? candidate() : candidate;
    if (el?.isConnected) {
      el.focus();
      return;
    }
  }
}

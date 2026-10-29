import { getContext, setContext } from 'svelte';

/**
 * Heading levels inside a search surface, taken from the page around it.
 *
 * A surface's own headings — "Filters" and the visually hidden "Results" —
 * sit at its level; a facet group's heading, the sentiment group and every
 * result title one level below; the facets inside the sentiment group two
 * below. The level is 2 under the page's h1 (/search, /search/everything, an
 * untitled page block) and 3 under a heading the page already prints: a
 * block's own h2 title, the theme's "Items" h2 above an item set's surface.
 * Fixed h2/h3s used to put "Filters" BESIDE the block title it belongs to,
 * and every result title beside the filters.
 *
 * The mount partial says which through `data-iwac-heading-level`
 * (view/common/iwac-search-mount.phtml); main.ts hands it to the App, which
 * publishes it here for everything it renders. Ported from DRE Search's
 * lib/headings.ts, which made the same move.
 */
export type HeadingLevel = 2 | 3;

const KEY = Symbol('iwac-heading-level');

/** The level from the mount node's data attribute; anything else is 2. */
export function parseHeadingLevel(raw: string | undefined): HeadingLevel {
  return raw === '3' ? 3 : 2;
}

/** Publish the surface's heading level to the components below (call during init). */
export function provideHeadingLevel(level: HeadingLevel): void {
  setContext(KEY, level);
}

/**
 * The heading level `depth` steps below the surface's own (call during init).
 * Outside any surface — a component mounted on its own — the surface level
 * is 2.
 *
 * A number, not a tag name: the components branch `{#if level === 3}<h3>…`
 * (or set `aria-level`) rather than render `<svelte:element this="h…">`,
 * whose runtime alone cost the main bundle ~1.9 kB gzipped against a budget
 * with 1.4 kB to spare.
 */
export function headingLevel(depth: 0 | 1 | 2 = 0): number {
  return (getContext<HeadingLevel | undefined>(KEY) ?? 2) + depth;
}

import type { ActiveFilters, SearchState, ViewMode, YearRange } from './types';
import { SORT_VALUES } from './i18n';

/**
 * URL state codec for search surfaces.
 *
 * Two encodings share this module, distinguished by `prefix`:
 *
 *   - Standalone /search (prefix '') → bare params, shareable & unchanged:
 *       ?q=ramadan&page=2&sort=date:desc&f.country_ss=Niger&date.from=1990
 *
 *   - Page blocks (prefix `b{block_id}.`) → every key namespaced by the block
 *     id, so several search blocks on one page never clobber each other (or
 *     the host page's own ?page=/?q=):
 *       ?b42.q=ramadan&b42.f.country_ss=Niger&b7.f.topics_ss=Islam
 *
 * Multi-value facets use repeated query params (URLSearchParams handles them
 * natively via getAll()). The encoding round-trips through every browser's URL
 * bar, copy-paste, and the GitHub Markdown URL parser.
 *
 * The key invariant for multiple blocks: {@link syncToUrl} MERGES. It clears
 * and rewrites only the keys under its OWN prefix and leaves every other param
 * (other blocks, the host page, unknown/tracking params) untouched. That is
 * what lets N blocks coexist in one query string.
 */

const FILTER_PREFIX = 'f.';

/**
 * Retired facet field name → current one, applied when DECODING a URL.
 *
 * Facet field names are part of the shareable URL contract — they sit in
 * every link a researcher has bookmarked, pasted into a paper, or cited — so
 * a renamed field's old spelling has to keep resolving. Decoding maps it
 * forward; encoding only ever writes the current names, so a shared legacy
 * link silently upgrades itself the first time the user touches a filter.
 *
 * EMPTY on purpose since v6 — see the long note on the server-side twin,
 * FacetCatalog::LEGACY_FIELD_ALIASES. In short: the only entries were the v4
 * sentiment rename, v6 dropped the generation-1 sentiment fields from the
 * index, and aliasing them onto a generation-2 model would make a bookmarked
 * link return a different model's judgement under the name it was shared
 * with. The two maps must stay in sync (enforced by
 * scripts/check-schema-drift.js), including when both are empty.
 */
const LEGACY_FILTER_FIELDS: Readonly<Record<string, string>> = {
  // (no retired field names in flight)
};

/**
 * Sort used when a surface declares none. Every caller should pass the
 * surface's own `bootstrap.default_sort` instead — this is only the
 * last-resort fallback for a bootstrap that omits it.
 */
export const FALLBACK_SORT = '_text_match:desc';

/**
 * Decode the state a URL carries under `prefix`.
 *
 * `defaultSort` is the SURFACE's default (the preset's sort for a page block,
 * `_text_match:desc` for /search). It matters because the sort param is
 * omitted from the URL when it equals the default — so "absent" must decode
 * back to that same default, not to a global constant. Passing the wrong one
 * makes a block ignore its configured Default sort and, because the SSR
 * skip-check compares against `bootstrap.default_sort`, throw away the
 * server-rendered first page on every mount.
 */
export function readUrlState(
  href: string = window.location.href,
  prefix = '',
  defaultSort: string = FALLBACK_SORT,
): SearchState {
  const url = new URL(href);
  const params = url.searchParams;
  // "f." for the standalone route, "b42.f." for a page block.
  const filterKey = `${prefix}${FILTER_PREFIX}`;

  const filters: ActiveFilters = {};
  for (const key of new Set(params.keys())) {
    if (!key.startsWith(filterKey)) continue;
    const rawField = key.slice(filterKey.length);
    if (!isValidFieldName(rawField)) continue;
    // Object.hasOwn on BOTH lookups below, never a bare `obj[key] ?? …`.
    // Field names come from the URL and `isValidFieldName` happily accepts
    // `constructor`, which a bare lookup resolves through Object.prototype
    // to a function — the alias hop would return the Object constructor, and
    // the merge below would throw trying to spread it.
    const field = Object.hasOwn(LEGACY_FILTER_FIELDS, rawField)
      ? LEGACY_FILTER_FIELDS[rawField]
      : rawField;
    // getAll handles repeated keys; values that came in comma-separated
    // (legacy) get split too.
    const raw = params.getAll(key).flatMap((v) => v.split(',').map((s) => s.trim()));
    // A URL naming both the old and the new spelling of one field merges
    // into a single entry rather than the second silently winning.
    const seen = Object.hasOwn(filters, field) ? (filters[field] ?? []) : [];
    const values = Array.from(new Set([...seen, ...raw.filter((v) => v !== '')]));
    if (values.length > 0) {
      filters[field] = values;
    }
  }

  return {
    q: params.get(`${prefix}q`) ?? '',
    // Sanity ceiling only — Pagination computes totalPages straight from
    // `found` ("every match is reachable"), so a shared deep link must not
    // be silently re-clamped to an arbitrary low page.
    page: clampInt(params.get(`${prefix}page`), 1, 10000, 1),
    sort: parseSort(params.get(`${prefix}sort`), defaultSort),
    filters,
    yearRange: parseYearRange(params, prefix),
    view: parseView(params.get(`${prefix}view`)),
  };
}

/** Non-default views (`gallery` / `map`) are encoded; anything else is `list`. */
function parseView(raw: string | null): ViewMode {
  return raw === 'gallery' || raw === 'map' ? raw : 'list';
}

/**
 * Allowlist `?sort=` against the option set the surfaces actually offer, the
 * same way `?view=` is allowlisted just above.
 *
 * The value goes straight into a Typesense `sort_by`, where an unknown field
 * is not a bad sort but a 422 — which the client surfaces as the full-page
 * "Search unavailable" error. `?sort=junk` in a mangled share link (or a
 * crawler's guess) should degrade to the surface's default, exactly as an
 * absent param does. `defaultSort` itself is server-supplied and trusted, so
 * it is never filtered.
 */
function parseSort(raw: string | null, defaultSort: string): string {
  if (!raw) return defaultSort;
  return SORT_VALUES.has(raw) ? raw : defaultSort;
}

/**
 * Whether the URL explicitly carries a `view` param under this prefix. Lets the
 * App tell "user/shared-link chose a view" from "defaulted to list", so it only
 * falls back to localStorage / auto-suggest when no explicit choice was made.
 */
export function urlHasView(href: string = window.location.href, prefix = ''): boolean {
  return new URL(href).searchParams.has(`${prefix}view`);
}

function parseYearRange(params: URLSearchParams, prefix: string): YearRange | null {
  const from = parseYearOrNull(params.get(`${prefix}date.from`));
  const to = parseYearOrNull(params.get(`${prefix}date.to`));
  if (from === null && to === null) return null;
  const out: YearRange = {};
  if (from !== null) out.from = from;
  if (to !== null) out.to = to;
  return out;
}

function parseYearOrNull(raw: string | null): number | null {
  if (!raw) return null;
  const n = Number(raw);
  // Sanity bounds — rejects garbage URL params like ?date.from=999999.
  if (!Number.isFinite(n) || n < 1800 || n > 2100) return null;
  return Math.trunc(n);
}

/**
 * Remove every key this prefix owns from `params`, in place. Scalar keys are
 * matched exactly (so the standalone `page` never deletes a block's `b42.page`)
 * and filter keys by the `${prefix}f.` stem.
 */
function clearPrefixedKeys(params: URLSearchParams, prefix: string): void {
  const filterKey = `${prefix}${FILTER_PREFIX}`;
  const scalars = new Set([
    `${prefix}q`,
    `${prefix}page`,
    `${prefix}sort`,
    `${prefix}date.from`,
    `${prefix}date.to`,
    `${prefix}view`,
  ]);
  // Collect first, then delete — mutating while iterating keys() is unsafe.
  const toDelete: string[] = [];
  for (const key of params.keys()) {
    if (scalars.has(key) || key.startsWith(filterKey)) {
      toDelete.push(key);
    }
  }
  for (const key of toDelete) params.delete(key);
}

/** Write this prefix's keys into `params`, in place. Defaults are omitted. */
function applyState(
  params: URLSearchParams,
  state: SearchState,
  prefix: string,
  defaultSort: string,
): void {
  if (state.q.trim() !== '') {
    params.set(`${prefix}q`, state.q);
  }
  if (state.page > 1) {
    params.set(`${prefix}page`, String(state.page));
  }
  // Omitted when it matches the surface's own default — which is what
  // readUrlState() restores for an absent param, so the round trip holds
  // whatever the surface's default happens to be.
  if (state.sort && state.sort !== defaultSort) {
    params.set(`${prefix}sort`, state.sort);
  }
  for (const [field, values] of Object.entries(state.filters)) {
    if (!isValidFieldName(field)) continue;
    for (const v of values) {
      if (v) params.append(`${prefix}${FILTER_PREFIX}${field}`, v);
    }
  }
  if (state.yearRange) {
    if (typeof state.yearRange.from === 'number') {
      params.set(`${prefix}date.from`, String(state.yearRange.from));
    }
    if (typeof state.yearRange.to === 'number') {
      params.set(`${prefix}date.to`, String(state.yearRange.to));
    }
  }
  // `list` is the default, so it's omitted to keep a fresh URL clean.
  if (state.view !== 'list') {
    params.set(`${prefix}view`, state.view);
  }
}

/**
 * Merge `state` into `baseSearch` (a `window.location.search` string) under
 * `prefix` and return the resulting query string (with leading `?`, or "" when
 * empty). Only this prefix's keys are touched; everything else in baseSearch is
 * preserved — that's what keeps sibling blocks intact.
 *
 * Defaults are omitted from the URL so a fresh surface has a clean URL, not
 * one littered with &page=1&sort=_text_match:desc.
 */
export function writeUrlState(
  state: SearchState,
  prefix = '',
  baseSearch = '',
  defaultSort: string = FALLBACK_SORT,
): string {
  const params = new URLSearchParams(baseSearch);
  clearPrefixedKeys(params, prefix);
  applyState(params, state, prefix, defaultSort);
  const qs = params.toString();
  return qs ? `?${qs}` : '';
}

/**
 * Push or replace history depending on what changed.
 *   - new query OR new sort OR added/removed filter → pushState (back-button-able)
 *   - just changed page                              → replaceState (avoid history spam)
 *
 * The first call after mount is always replaceState because we're
 * synchronising URL ↔ memory, not navigating.
 *
 * Reads the LIVE window.location.search each call so a block merges against
 * whatever sibling blocks have already written this tick.
 */
export function syncToUrl(
  next: SearchState,
  prev: SearchState | null,
  prefix = '',
  defaultSort: string = FALLBACK_SORT,
  pathname: string = window.location.pathname,
): void {
  const qs = writeUrlState(next, prefix, window.location.search, defaultSort);
  const newUrl = `${pathname}${qs}`;
  if (newUrl === window.location.pathname + window.location.search) {
    return;
  }
  const onlyPaginationChanged =
    prev !== null &&
    prev.q === next.q &&
    prev.sort === next.sort &&
    sameFilters(prev.filters, next.filters) &&
    sameYearRange(prev.yearRange, next.yearRange);

  if (prev === null || onlyPaginationChanged) {
    window.history.replaceState({}, '', newUrl);
  } else {
    window.history.pushState({}, '', newUrl);
  }
}

/**
 * Listen for popstate (back/forward button) and re-hydrate state from URL.
 * Returns a cleanup function suitable for $effect's destructor.
 */
export function onUrlPop(
  handler: (state: SearchState) => void,
  prefix = '',
  defaultSort: string = FALLBACK_SORT,
): () => void {
  const listener = (): void => handler(readUrlState(window.location.href, prefix, defaultSort));
  window.addEventListener('popstate', listener);
  return () => window.removeEventListener('popstate', listener);
}

function sameYearRange(a: YearRange | null, b: YearRange | null): boolean {
  if (a === null && b === null) return true;
  if (a === null || b === null) return false;
  return a.from === b.from && a.to === b.to;
}

function sameFilters(a: ActiveFilters, b: ActiveFilters): boolean {
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  if (ka.length !== kb.length) return false;
  for (const k of ka) {
    const va = [...(a[k] ?? [])].sort();
    const vb = [...(b[k] ?? [])].sort();
    if (va.length !== vb.length) return false;
    for (let i = 0; i < va.length; i++) {
      if (va[i] !== vb[i]) return false;
    }
  }
  return true;
}

function clampInt(raw: string | null, min: number, max: number, fallback: number): number {
  const n = Number(raw);
  if (!Number.isFinite(n)) return fallback;
  return Math.max(min, Math.min(max, Math.floor(n)));
}

/**
 * Allowlist for facet field names — they go straight into a Typesense
 * filter_by, so we want to be sure the URL hasn't smuggled in something
 * like `f.is_public:=false` to bypass the public scope. Schema fields
 * follow snake_case ending in _ss / _s / _txt etc.
 */
function isValidFieldName(name: string): boolean {
  return /^[a-z][a-z0-9_]{0,40}$/.test(name);
}

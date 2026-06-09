import type { ActiveFilters, SearchState, YearRange } from './types';

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

export function readUrlState(href: string = window.location.href, prefix = ''): SearchState {
  const url = new URL(href);
  const params = url.searchParams;
  // "f." for the standalone route, "b42.f." for a page block.
  const filterKey = `${prefix}${FILTER_PREFIX}`;

  const filters: ActiveFilters = {};
  for (const key of new Set(params.keys())) {
    if (!key.startsWith(filterKey)) continue;
    const field = key.slice(filterKey.length);
    if (!isValidFieldName(field)) continue;
    // getAll handles repeated keys; values that came in comma-separated
    // (legacy) get split too.
    const raw = params.getAll(key).flatMap((v) => v.split(',').map((s) => s.trim()));
    const values = Array.from(new Set(raw.filter((v) => v !== '')));
    if (values.length > 0) {
      filters[field] = values;
    }
  }

  return {
    q: params.get(`${prefix}q`) ?? '',
    page: clampInt(params.get(`${prefix}page`), 1, 50, 1),
    sort: params.get(`${prefix}sort`) ?? '_text_match:desc',
    filters,
    yearRange: parseYearRange(params, prefix),
  };
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
function applyState(params: URLSearchParams, state: SearchState, prefix: string): void {
  if (state.q.trim() !== '') {
    params.set(`${prefix}q`, state.q);
  }
  if (state.page > 1) {
    params.set(`${prefix}page`, String(state.page));
  }
  if (state.sort && state.sort !== '_text_match:desc') {
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
export function writeUrlState(state: SearchState, prefix = '', baseSearch = ''): string {
  const params = new URLSearchParams(baseSearch);
  clearPrefixedKeys(params, prefix);
  applyState(params, state, prefix);
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
  pathname: string = window.location.pathname,
): void {
  const qs = writeUrlState(next, prefix, window.location.search);
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
export function onUrlPop(handler: (state: SearchState) => void, prefix = ''): () => void {
  const listener = (): void => handler(readUrlState(window.location.href, prefix));
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

import type { ActiveFilters, SearchState, YearRange } from './types';

/**
 * URL state codec for the standalone /search route.
 *
 * Format:
 *   ?q=ramadan
 *    &page=2
 *    &sort=date:desc
 *    &f.country_ss=Burkina+Faso&f.country_ss=Niger
 *    &f.newspaper_ss=Sidwaya
 *
 * Multi-value facets use repeated query params (URLSearchParams handles
 * them natively via getAll()). This encoding round-trips through every
 * browser's URL bar, copy-paste, and the GitHub Markdown URL parser.
 *
 * Page blocks do NOT use this codec — multiple block instances on one
 * page would clobber each other's URL state. They keep state in memory
 * via App.svelte's internal $state.
 */

const FILTER_PREFIX = 'f.';

export function readUrlState(href: string = window.location.href): SearchState {
  const url = new URL(href);
  const params = url.searchParams;

  const filters: ActiveFilters = {};
  for (const key of new Set(params.keys())) {
    if (!key.startsWith(FILTER_PREFIX)) continue;
    const field = key.slice(FILTER_PREFIX.length);
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
    q: params.get('q') ?? '',
    page: clampInt(params.get('page'), 1, 50, 1),
    sort: params.get('sort') ?? '_text_match:desc',
    filters,
    yearRange: parseYearRange(params),
  };
}

function parseYearRange(params: URLSearchParams): YearRange | null {
  const from = parseYearOrNull(params.get('date.from'));
  const to = parseYearOrNull(params.get('date.to'));
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
 * Build a query string from search state.
 * Returns "?..." (with leading `?`) when non-empty, "" otherwise.
 *
 * Defaults are omitted from the URL so a fresh /search has a clean URL,
 * not /search?q=&page=1&sort=_text_match:desc.
 */
export function writeUrlState(state: SearchState): string {
  const params = new URLSearchParams();
  if (state.q.trim() !== '') {
    params.set('q', state.q);
  }
  if (state.page > 1) {
    params.set('page', String(state.page));
  }
  if (state.sort && state.sort !== '_text_match:desc') {
    params.set('sort', state.sort);
  }
  for (const [field, values] of Object.entries(state.filters)) {
    if (!isValidFieldName(field)) continue;
    for (const v of values) {
      if (v) params.append(`${FILTER_PREFIX}${field}`, v);
    }
  }
  if (state.yearRange) {
    if (typeof state.yearRange.from === 'number') {
      params.set('date.from', String(state.yearRange.from));
    }
    if (typeof state.yearRange.to === 'number') {
      params.set('date.to', String(state.yearRange.to));
    }
  }
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
 */
export function syncToUrl(
  next: SearchState,
  prev: SearchState | null,
  pathname: string = window.location.pathname,
): void {
  const qs = writeUrlState(next);
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
export function onUrlPop(handler: (state: SearchState) => void): () => void {
  const listener = (): void => handler(readUrlState());
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

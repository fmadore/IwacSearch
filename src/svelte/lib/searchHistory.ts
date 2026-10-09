/**
 * Recent-searches memory for the typeahead — shown when the search box is
 * focused while empty. Plain localStorage, shared across every search
 * surface on the site (one history, not one per block), newest first,
 * deduped, capped. Purely a convenience: storage failures (private mode,
 * disabled storage) silently degrade to "no history".
 */

const KEY = 'iwac-search-history';
const MAX = 8;

export function readHistory(): string[] {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return [];
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter((v): v is string => typeof v === 'string' && v.trim() !== '')
      .slice(0, MAX);
  } catch {
    return [];
  }
}

/**
 * Record a query the user actually ran (a committed search that FOUND
 * something — the caller gates on that, so typo dead-ends don't pollute
 * the list). Moves an existing entry to the front.
 *
 * Search-as-you-type commits after every pause, so one typed query used to
 * leave its fragments behind: "tabaski", "tabaski au", "tabaski au Bur" —
 * eight slots filled by one search. A query that extends (or, backspacing,
 * shortens) the NEWEST entry replaces it instead of joining it. Only the
 * newest: an older "islam" is a search the reader made, not a fragment.
 */
export function recordSearch(q: string): void {
  const query = q.trim();
  if (query.length < 3) return;
  try {
    const lower = query.toLowerCase();
    let history = readHistory();
    const newest = history[0]?.toLowerCase();
    if (newest !== undefined && (lower.startsWith(newest) || newest.startsWith(lower))) {
      history = history.slice(1);
    }
    const next = [query, ...history.filter((h) => h.toLowerCase() !== lower)];
    window.localStorage.setItem(KEY, JSON.stringify(next.slice(0, MAX)));
  } catch {
    /* storage disabled — history just doesn't persist */
  }
}

export function clearHistory(): void {
  try {
    window.localStorage.removeItem(KEY);
  } catch {
    /* ignore */
  }
}

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
 */
export function recordSearch(q: string): void {
  const query = q.trim();
  if (query.length < 3) return;
  try {
    const next = [query, ...readHistory().filter((h) => h.toLowerCase() !== query.toLowerCase())];
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

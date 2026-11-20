import type { IwacFederatedTab } from './types';

/**
 * The tab badges of /search/everything, read from the server-rendered first
 * pages when they already answer the question.
 *
 * With no query, a tab's badge is its browse total, which is exactly the
 * `found` of the first page the server rendered into that tab's bootstrap
 * (same collection, same locked filters, `q=*`). The page used to ask
 * Typesense for those counts again on every load, one round trip for numbers
 * it was holding. Returns null whenever any tab lacks a snapshot, or the
 * query is not empty: the caller counts with Typesense then.
 */
export function ssrTabCounts(
  tabs: readonly IwacFederatedTab[],
  query: string,
): Record<string, number> | null {
  if (query.trim() !== '') return null;
  const counts: Record<string, number> = {};
  for (const tab of tabs) {
    const found = tab.bootstrap.initial_response?.found;
    if (typeof found !== 'number') return null;
    counts[tab.id] = found;
  }
  return counts;
}

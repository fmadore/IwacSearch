import type { IwacHit, IwacSearchResponse } from './types';

/**
 * Detecting a result set that ONLY the vector leg produced.
 *
 * Content surfaces search hybrid: `query_by` ends in `embedding`
 * (CONTENT_QUERY_BY_FALLBACK), so every free-text query runs a keyword leg AND
 * a semantic leg, and Typesense rank-fuses the two. When the keyword leg
 * matches nothing, the semantic leg still returns its fixed top-k — 100
 * documents — and the client rendered them as ordinary results: a mistyped
 * name answered with "100 results", confident facet counts, and no signal that
 * nothing actually matched (Phase-1 critique P0 #2).
 *
 * `text_match` is the signal, and it is unambiguous on the wire. Observed
 * against the live collection:
 *
 *   q=xzqvwk   found 100  every hit  text_match: 0, + vector_distance
 *                                    + hybrid_search_info.rank_fusion_score
 *   q=imam     found 5857 first hit  text_match: 578730123365189800
 *   q="imam de Ouagadougou"          text_match: 100013 (no vector leg —
 *                                    exact mode drops `embedding`)
 *
 * So: a non-empty query whose hits ALL score zero on the keyword leg found
 * nothing, whatever `found` says.
 */

/** Did this hit match the keyword leg at all? */
function hasKeywordMatch(hit: IwacHit): boolean {
  return typeof hit.text_match === 'number' && hit.text_match > 0;
}

/**
 * Is this response the semantic leg alone — hits with no keyword match behind
 * any of them?
 *
 * False for browse mode (an empty query has no keyword leg to fail; Typesense
 * omits `text_match` entirely on `q=*`), and false for a genuinely empty
 * response, which the scope-aware ResultsEmpty already handles on its own.
 *
 * @param query The query the response was fetched for — NOT `*`.
 */
export function isSemanticOnlyResponse(
  response: IwacSearchResponse | null | undefined,
  query: string,
): boolean {
  if (!response || query.trim() === '') return false;
  const hits = response.hits ?? [];
  if (hits.length === 0) return false;
  return !hits.some(hasKeywordMatch);
}

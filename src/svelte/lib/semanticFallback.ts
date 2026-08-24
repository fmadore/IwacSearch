import type { IwacHit } from './types';

/**
 * All this predicate reads is the hit list, so that is all it asks for. A
 * full `IwacSearchResponse` would exclude the one-hit probe `countAcross`
 * uses to keep a tab badge honest, which is the same question about the same
 * wire field — and answering it twice, differently, is how the browse
 * surface and its own tab badge would come to disagree.
 */
export interface KeywordScorable {
  hits?: IwacHit[];
}

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
 * The signal is `text_match_info.tokens_matched` — how many query tokens
 * actually touched the document. Observed against the live collection:
 *
 *   PER-COLLECTION search
 *     q=xzqvwk   found 100   text_match 0,  tokens_matched 0, vector_distance
 *     q=imam     found 5857  text_match 578730123365189800, tokens_matched 1
 *     q="imam …" exact mode  text_match 100013 (no vector leg)
 *
 *   UNION search (/search/everything)
 *     q=xzqvwk   found 100   text_match 1050253722 (!), tokens_matched 0,
 *                            num_tokens_dropped 1, vector_distance 0.184,
 *                            hybrid_search_info.rank_fusion_score 0.3
 *     q=imam     found 5872  text_match 578730123373578400, tokens_matched 1
 *
 * That second block is why this predicate reads `tokens_matched` and not
 * `text_match`. In union mode Typesense SYNTHESISES a large `text_match` out
 * of the rank-fusion score, so a hit no query token touched arrives scoring
 * 1,050,253,722 — and a `text_match > 0` test calls the whole fabricated set
 * genuine. The federated withhold was written against the per-collection
 * signal and was inert on the wire until the rig showed this.
 *
 * `tokens_matched` says the same thing in both modes, and says it about the
 * keyword leg specifically. `text_match` remains the fallback for any
 * response shape that omits the breakdown.
 *
 * So: a non-empty query whose hits ALL matched zero tokens found nothing,
 * whatever `found` says.
 */

/** Did this hit match the keyword leg at all? */
function hasKeywordMatch(hit: IwacHit): boolean {
  const matched = hit.text_match_info?.tokens_matched;
  if (typeof matched === 'number') return matched > 0;
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
  response: KeywordScorable | null | undefined,
  query: string,
): boolean {
  if (!response || query.trim() === '') return false;
  const hits = response.hits ?? [];
  if (hits.length === 0) return false;
  return !hits.some(hasKeywordMatch);
}

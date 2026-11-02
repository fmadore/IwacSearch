/**
 * The semantic offer, shared by App and the federated union tab.
 *
 * A hybrid query returns every keyword match PLUS the vector leg's nearest
 * neighbours, which match no query word — a fixed top-k, so a gibberish
 * query still comes back "100 results". Rank fusion does not even keep them
 * after the matches: a neighbour's vector score alone (0.3 at vector rank 1)
 * can outrank a keyword match ranked third or lower (0.7/3 when its own
 * vector rank misses the top-k). So a listed result is a keyword match,
 * and the neighbours are offered, never asserted: the client lists the
 * keyword population, counts the hybrid one alongside, and the reader opts
 * in to see the difference (`koudouss` on the Benin block: 11 matches, with
 * 97 related documents behind one button instead of "108 results").
 */

/** Query-wide evidence only: a page sample cannot establish absence. */
export interface KeywordScorable {
  keyword_found?: number;
  hybrid_found?: number;
  blended?: boolean;
  hits?: unknown[];
}

/** Did the keyword leg match nothing while the vector leg returned something? */
export function isSemanticOnlyResponse(
  response: KeywordScorable | null | undefined,
  query: string,
): boolean {
  if (!response || !query.trim() || query === '*' || response.keyword_found !== 0) return false;
  return (response.hybrid_found ?? response.hits?.length ?? 0) > 0;
}

export interface SemanticView {
  /** The listed set includes the vector leg: label it, offer the way back. */
  blended: boolean;
  /** Blended, and no keyword match at all: the whole list is related items. */
  only: boolean;
  /** Related documents the hybrid set adds — what the offer promises. */
  related: number;
  /** Offer them: a keyword list with related documents behind the button. */
  offer: boolean;
}

export function semanticView(
  response: KeywordScorable | null | undefined,
  query: string,
): SemanticView {
  const real = Boolean(response && query.trim() && query !== '*');
  const keyword = response?.keyword_found;
  const hybrid = response?.hybrid_found;
  const related =
    real && keyword !== undefined && hybrid !== undefined ? Math.max(0, hybrid - keyword) : 0;
  const blended = real && response?.blended === true;
  return {
    blended,
    only: blended && keyword === 0,
    related,
    offer: real && !blended && related > 0,
  };
}

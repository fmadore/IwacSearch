import { describe, expect, it } from 'vitest';
import { isSemanticOnlyResponse } from '../../src/svelte/lib/semanticFallback';
import type { IwacHit, IwacSearchResponse } from '../../src/svelte/lib/types';

/**
 * Content surfaces search hybrid (`query_by` ends in `embedding`), so a query
 * the keyword leg matches nothing still comes back full: the vector leg
 * returns its fixed top-k and the client used to render those 100 documents as
 * ordinary results — "100 results", confident facet counts, no signal that
 * nothing matched (Phase-1 critique P0 #2).
 *
 * The fixtures below are the SHAPES observed on the wire against the live
 * collection, not invented ones:
 *
 *   q=xzqvwk                text_match 0 on every hit, + vector_distance
 *   q=imam                  text_match 578730123365189800
 *   q="imam de Ouagadougou" text_match 100013, no vector leg (exact mode
 *                           drops `embedding`)
 *   q=*  (browse)           no text_match key at all
 */

function hit(id: string, textMatch?: number, vectorDistance?: number): IwacHit {
  const h: IwacHit = { document: { id, title: `doc ${id}` } };
  if (textMatch !== undefined) h.text_match = textMatch;
  if (vectorDistance !== undefined) {
    (h as IwacHit & { vector_distance: number }).vector_distance = vectorDistance;
  }
  return h;
}

/**
 * A hit carrying the `text_match_info` breakdown, which is the shape every
 * real response has — and the only one that tells the truth in union mode.
 */
function hitWithInfo(id: string, textMatch: number, tokensMatched: number): IwacHit {
  return {
    document: { id, title: `doc ${id}` },
    text_match: textMatch,
    text_match_info: { tokens_matched: tokensMatched, score: String(textMatch) },
  };
}

function response(hits: IwacHit[], found = hits.length): IwacSearchResponse {
  return { found, page: 1, request_params: { per_page: 10 }, hits, search_time_ms: 3 };
}

describe('isSemanticOnlyResponse', () => {
  it('flags the vector-only set: every hit scores zero on the keyword leg', () => {
    const vectorOnly = response(
      [hit('110615', 0, 0.16), hit('110616', 0, 0.18), hit('110617', 0, 0.19)],
      100,
    );
    expect(isSemanticOnlyResponse(vectorOnly, 'xzqvwk')).toBe(true);
  });

  it('does not flag a real query', () => {
    const real = response([hit('108353', 578730123365189800), hit('108354', 578730123365189000)]);
    expect(isSemanticOnlyResponse(real, 'imam')).toBe(false);
  });

  it('does not flag a MIXED set — one keyword match is enough to be real', () => {
    const mixed = response([hit('1', 0, 0.2), hit('2', 100013), hit('3', 0, 0.3)]);
    expect(isSemanticOnlyResponse(mixed, 'imam ouagadougou')).toBe(false);
  });

  it('does not flag an exact (quoted) query — exact mode drops the vector leg', () => {
    const quoted = response([hit('10198', 100013), hit('10199', 100011)]);
    expect(isSemanticOnlyResponse(quoted, '"imam de Ouagadougou"')).toBe(false);
  });

  it('never flags browse mode, where there is no keyword leg to fail', () => {
    // Typesense omits text_match entirely on q=*, so the predicate would
    // otherwise call every browse landing a fabrication.
    const browse = response([hit('1'), hit('2'), hit('3')], 16544);
    expect(isSemanticOnlyResponse(browse, '')).toBe(false);
    expect(isSemanticOnlyResponse(browse, '   ')).toBe(false);
  });

  it('leaves a genuinely empty response to the scope-aware empty state', () => {
    expect(isSemanticOnlyResponse(response([], 0), 'xzqvwk')).toBe(false);
  });

  it('is false for a missing response', () => {
    expect(isSemanticOnlyResponse(null, 'xzqvwk')).toBe(false);
    expect(isSemanticOnlyResponse(undefined, 'xzqvwk')).toBe(false);
  });

  /**
   * UNION mode (/search/everything) is a different wire shape, and the
   * difference is not cosmetic: Typesense synthesises `text_match` out of the
   * rank-fusion score there, so a hit no query token touched arrives scoring
   * over a billion. A `text_match > 0` test calls that whole fabricated set
   * genuine — which is why the federated withhold, written against the
   * per-collection signal, rendered "100 results" on the rig with the code
   * that was supposed to prevent it. `tokens_matched` is the field that says
   * the same thing in both modes.
   *
   * Every figure below was read off the live wire, not invented.
   */
  describe('union mode, where text_match lies', () => {
    it('flags a union set whose hits matched zero tokens despite huge text_match', () => {
      const unionVectorOnly = response(
        [
          hitWithInfo('1', 1050253722, 0),
          hitWithInfo('2', 1041865114, 0),
          hitWithInfo('3', 1036831949, 0),
        ],
        100,
      );
      expect(isSemanticOnlyResponse(unionVectorOnly, 'xzqvwk')).toBe(true);
    });

    it('does not flag a real union query', () => {
      const unionReal = response(
        [hitWithInfo('1', 578730123373578400, 1), hitWithInfo('2', 578730123365189800, 1)],
        5872,
      );
      expect(isSemanticOnlyResponse(unionReal, 'imam')).toBe(false);
    });

    it('prefers tokens_matched over text_match when the two disagree', () => {
      // The disagreement IS the union bug, stated as a single assertion.
      const conflicted = response([hitWithInfo('1', 1050253722, 0)], 100);
      expect(isSemanticOnlyResponse(conflicted, 'xzqvwk')).toBe(true);
    });

    it('still trusts text_match when no breakdown is present', () => {
      expect(isSemanticOnlyResponse(response([hit('1', 0, 0.18)]), 'xzqvwk')).toBe(true);
      expect(isSemanticOnlyResponse(response([hit('1', 100013)]), 'imam')).toBe(false);
    });
  });

  /**
   * The federated tab badges ask the same question of the same wire field,
   * but from a one-hit counts probe rather than a full response — so the
   * predicate takes anything carrying `hits`. Two surfaces answering "did the
   * keyword leg match" with two implementations is how a badge reading "100"
   * ends up next to the empty state of the tab it labels.
   */
  describe('the one-hit count probe (federated tab badges)', () => {
    it('flags a probe whose single best keyword match scores zero', () => {
      // Ordered by _text_match:desc, so this hit IS the best there is.
      expect(isSemanticOnlyResponse({ hits: [hit('110615', 0, 0.16)] }, 'xzqvwk')).toBe(true);
    });

    it('does not flag a probe whose top hit really matched', () => {
      expect(isSemanticOnlyResponse({ hits: [hit('108353', 578730123365189800)] }, 'imam')).toBe(
        false,
      );
    });

    it('does not flag a browse probe, which carries no hits at all', () => {
      expect(isSemanticOnlyResponse({ hits: [] }, '')).toBe(false);
      expect(isSemanticOnlyResponse({}, '')).toBe(false);
    });
  });
});

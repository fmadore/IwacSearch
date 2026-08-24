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
});

import { describe, expect, it } from 'vitest';
import { isSemanticOnlyResponse, semanticView } from '../../src/svelte/lib/semanticFallback';
describe('query-wide semantic fallback', () => {
  it('withholds only on an explicit zero keyword count', () => {
    expect(isSemanticOnlyResponse({ keyword_found: 0, hits: [{}] }, 'imam')).toBe(true);
    expect(isSemanticOnlyResponse({ keyword_found: 1, hits: [{ text_match: 0 }] }, 'imam')).toBe(
      false,
    );
  });
  it('does not infer absence from a date-sorted vector-only page', () => {
    expect(isSemanticOnlyResponse({ hits: [{ text_match: 0 }] }, 'imam')).toBe(false);
  });
  it('does not withhold browse or empty results', () => {
    expect(isSemanticOnlyResponse({ keyword_found: 0, hits: [{}] }, '')).toBe(false);
    expect(isSemanticOnlyResponse({ keyword_found: 0, hits: [{}] }, '*')).toBe(false);
    expect(isSemanticOnlyResponse({ keyword_found: 0, hits: [] }, 'imam')).toBe(false);
  });
});

describe('semanticView', () => {
  it('lists the matches and offers the related documents beside them', () => {
    // Live: `koudouss` on the Benin block — 11 matches, a hybrid set of 108.
    expect(
      semanticView({ keyword_found: 11, hybrid_found: 108, blended: false }, 'koudouss'),
    ).toEqual({ blended: false, only: false, related: 97, offer: true });
  });
  it('offers related documents under an empty keyword list', () => {
    const view = semanticView({ keyword_found: 0, hybrid_found: 100, hits: [] }, 'xzqvwk');
    expect(view).toMatchObject({ offer: true, related: 100 });
    expect(
      isSemanticOnlyResponse({ keyword_found: 0, hybrid_found: 100, hits: [] }, 'xzqvwk'),
    ).toBe(true);
  });
  it('labels an opted-in set and stops offering it', () => {
    expect(semanticView({ keyword_found: 11, hybrid_found: 108, blended: true }, 'q')).toEqual({
      blended: true,
      only: false,
      related: 97,
      offer: false,
    });
    expect(semanticView({ keyword_found: 0, hybrid_found: 100, blended: true }, 'q').only).toBe(
      true,
    );
  });
  it('offers nothing without a hybrid count, on browse, or when nothing is added', () => {
    expect(semanticView({ keyword_found: 11 }, 'q').offer).toBe(false);
    expect(semanticView({ keyword_found: 11, hybrid_found: 11 }, 'q').offer).toBe(false);
    expect(semanticView({ keyword_found: 0, hybrid_found: 100 }, '*').offer).toBe(false);
    expect(semanticView(null, 'q').offer).toBe(false);
  });
});

import { describe, expect, it } from 'vitest';
import { isSemanticOnlyResponse } from '../../src/svelte/lib/semanticFallback';
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

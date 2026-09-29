import { describe, expect, it } from 'vitest';
import { resultAnnouncement, type AnnouncementInput } from '../../src/svelte/lib/announce';
import { translate } from '../../src/svelte/lib/i18n';
import type { IwacSearchResponse } from '../../src/svelte/lib/types';

/**
 * The live region's one sentence. The rules worth pinning are the semantic
 * ones: a withheld vector-only set must never be announced as results, and a
 * shown one must be announced as what it is.
 */

const t = (key: string, vars?: Record<string, string | number>) => translate('en', key, vars);

function input(over: Partial<AnnouncementInput> & { found?: number } = {}): AnnouncementInput {
  const { found = 12, ...rest } = over;
  return {
    response: {
      found,
      page: 1,
      hits: [],
      request_params: {},
      search_time_ms: 1,
    } as IwacSearchResponse,
    semanticHidden: false,
    semanticOnly: false,
    page: 1,
    totalPages: 1,
    ...rest,
  };
}

describe('resultAnnouncement', () => {
  it('says nothing before a response', () => {
    expect(resultAnnouncement(input({ response: null }), t)).toBe('');
  });

  it('counts results, singular and plural', () => {
    expect(resultAnnouncement(input(), t)).toBe(t('announce_results_other', { n: '12' }));
    expect(resultAnnouncement(input({ found: 1 }), t)).toBe(t('announce_results_one', { n: '1' }));
  });

  it('adds the page only when there is more than one', () => {
    const one = resultAnnouncement(input({ totalPages: 1 }), t);
    const many = resultAnnouncement(input({ page: 2, totalPages: 3 }), t);
    expect(one).not.toContain(t('announce_page', { p: '2', total: '3' }));
    expect(many).toBe(`${one} ${t('announce_page', { p: '2', total: '3' })}`);
  });

  it('announces nothing found', () => {
    expect(resultAnnouncement(input({ found: 0 }), t)).toBe(t('announce_no_results'));
  });

  it('never announces a withheld semantic set as results', () => {
    const s = resultAnnouncement(
      input({ found: 100, semanticOnly: true, semanticHidden: true }),
      t,
    );
    expect(s).toBe(t('announce_semantic_other', { n: '100' }));
    expect(s).not.toBe(t('announce_results_other', { n: '100' }));
  });

  it('announces a shown semantic set as near matches, with its page', () => {
    const s = resultAnnouncement(
      input({ found: 100, semanticOnly: true, page: 1, totalPages: 5 }),
      t,
    );
    expect(s).toBe(
      `${t('announce_semantic_shown_other', { n: '100' })} ${t('announce_page', { p: '1', total: '5' })}`,
    );
  });

  it('translates every key it uses in both locales', () => {
    for (const key of [
      'announce_results_one',
      'announce_results_other',
      'announce_semantic_one',
      'announce_semantic_other',
      'announce_semantic_shown_one',
      'announce_semantic_shown_other',
      'announce_no_results',
      'announce_page',
    ]) {
      expect(translate('en', key)).not.toBe(key);
      expect(translate('fr', key)).not.toBe(key);
    }
  });
});

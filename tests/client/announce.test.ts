import { describe, expect, it } from 'vitest';
import { resultAnnouncement, type AnnouncementInput } from '../../src/svelte/lib/announce';
import { createI18n, translate } from '../../src/svelte/lib/i18n';
import type { IwacSearchResponse } from '../../src/svelte/lib/types';

/**
 * The live region's one sentence. The rules worth pinning are the semantic
 * ones: a withheld vector-only set must never be announced as results, and a
 * shown one must be announced as what it is.
 */

const i18n = createI18n('en');
const { t } = i18n;

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
    expect(resultAnnouncement(input({ response: null }), i18n)).toBe('');
  });

  it('counts results, singular and plural', () => {
    expect(resultAnnouncement(input(), i18n)).toBe(t('announce_results_other', { n: '12' }));
    expect(resultAnnouncement(input({ found: 1 }), i18n)).toBe(
      t('announce_results_one', { n: '1' }),
    );
  });

  it('adds the page only when there is more than one', () => {
    const one = resultAnnouncement(input({ totalPages: 1 }), i18n);
    const many = resultAnnouncement(
      input({ response: { ...input().response!, page: 2 }, page: 2, totalPages: 3 }),
      i18n,
    );
    expect(one).not.toContain(t('announce_page', { p: '2', total: '3' }));
    expect(many).toBe(`${one} ${t('announce_page', { p: '2', total: '3' })}`);
  });

  /** S-15: the state's page moves on the click; the results arrive a round trip later. */
  it('announces the page the response answers, not the one the state already asks for', () => {
    const s = resultAnnouncement(input({ page: 3, totalPages: 5 }), i18n);
    expect(s).toContain(t('announce_page', { p: '1', total: '5' }));
  });

  it('announces nothing found', () => {
    expect(resultAnnouncement(input({ found: 0 }), i18n)).toBe(t('announce_no_results'));
  });

  it('never announces a withheld semantic set as results', () => {
    const s = resultAnnouncement(
      input({ found: 100, semanticOnly: true, semanticHidden: true }),
      i18n,
    );
    expect(s).toBe(t('announce_semantic_other', { n: '100' }));
    expect(s).not.toBe(t('announce_results_other', { n: '100' }));
  });

  it('announces a shown semantic set as near matches, with its page', () => {
    const s = resultAnnouncement(
      input({ found: 100, semanticOnly: true, page: 1, totalPages: 5 }),
      i18n,
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

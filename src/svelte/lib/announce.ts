/**
 * The sentence the results live region reads once the surface settles.
 *
 * Nothing announced the result count before 3.x: the one polite region on the
 * surface was ResultSummary's own <section>, inside the `{#if response}` — so
 * it was torn down for the skeleton and re-mounted with its text already in
 * place, and a live region that arrives populated never announces. App now
 * keeps one persistent region and fills it from this.
 *
 * Composed only from SETTLED state (never from a loading flag), so a
 * mid-flight response can't produce a claim that is about to be replaced.
 */
import type { IwacSearchResponse } from './types';
import type { Translate } from './i18n';

export interface AnnouncementInput {
  response: IwacSearchResponse | null;
  /** A semantic-only response that the surface is withholding. */
  semanticHidden: boolean;
  /** A semantic-only response, withheld or shown. */
  semanticOnly: boolean;
  page: number;
  totalPages: number;
}

export function resultAnnouncement(input: AnnouncementInput, t: Translate): string {
  const { response, semanticHidden, semanticOnly, page, totalPages } = input;
  if (!response) return '';
  const n = response.found.toLocaleString();
  const one = response.found === 1;
  if (semanticHidden) {
    return t(one ? 'announce_semantic_one' : 'announce_semantic_other', { n });
  }
  if (response.found === 0) return t('announce_no_results');
  // Opted in to the near-neighbour set: it is rendered, so it is announced —
  // but as what it is. Reading "100 results found" over the set the banner
  // and the count line both just qualified would put the fabrication back in
  // the only channel that had never carried it.
  const count = semanticOnly
    ? t(one ? 'announce_semantic_shown_one' : 'announce_semantic_shown_other', { n })
    : t(one ? 'announce_results_one' : 'announce_results_other', { n });
  // The page is only worth saying when there is more than one of them.
  return totalPages > 1
    ? `${count} ${t('announce_page', { p: page.toLocaleString(), total: totalPages.toLocaleString() })}`
    : count;
}

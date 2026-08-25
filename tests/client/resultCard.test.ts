import { describe, expect, it } from 'vitest';
import type { IwacDoc, IwacHit } from '../../src/svelte/lib/types';
import {
  buildCitation,
  buildSourceChips,
  formatDate,
  formatDuration,
  formatYearRange,
  pickExternalLink,
  pickMatchedIn,
  pickSnippet,
  pickTitleMarkup,
  resultLanguageTag,
} from '../../src/svelte/lib/resultCard';

/**
 * The rules behind every result card. They used to live inside
 * ResultItem.svelte, where the only way to check them was to look at a page
 * — so what's pinned here is the behaviour that is easy to break and hard to
 * notice: which highlight wins, how a half-empty citation punctuates, and
 * that a reference's Jan-1 epoch never prints as a precise date.
 */

function hit(highlights: IwacHit['highlights'], document: Partial<IwacDoc> = {}): IwacHit {
  return { document: { id: '1', title: 'T', ...document } as IwacDoc, highlights };
}

describe('pickSnippet', () => {
  it('prefers the OCR match — it is the most contextual one', () => {
    const h = hit([
      { field: 'abstract', snippet: 'from the <mark>abstract</mark>' },
      { field: 'ocr_text', snippet: 'from the <mark>page</mark>' },
    ]);
    expect(pickSnippet(h)).toBe('from the <mark>page</mark>');
  });

  it('falls back to the abstract when the OCR did not match', () => {
    expect(pickSnippet(hit([{ field: 'abstract', snippet: 'the <mark>abstract</mark>' }]))).toBe(
      'the <mark>abstract</mark>',
    );
  });

  it('uses a publication ToC match before the generic card excerpt', () => {
    const h = hit([
      { field: 'abstract', snippet: 'the short <mark>excerpt</mark>' },
      { field: 'toc_txt', snippet: 'p. 5: article about <mark>education</mark>' },
    ]);
    expect(pickSnippet(h)).toBe('p. 5: article about <mark>education</mark>');
  });

  it('is empty on a browse response, which carries no highlights at all', () => {
    expect(pickSnippet(hit(undefined))).toBe('');
  });

  /** The snippet is {@html}-rendered, so this is a security property. */
  it('escapes everything except the mark tags', () => {
    const h = hit([
      { field: 'ocr_text', snippet: '<img src=x onerror=alert(1)> <mark>hit</mark>' },
    ]);
    expect(pickSnippet(h)).toBe('&lt;img src=x onerror=alert(1)&gt; <mark>hit</mark>');
  });
});

describe('pickTitleMarkup', () => {
  it('uses `value`, not `snippet` — the full title, not a truncation', () => {
    const h = hit([
      {
        field: 'title_txt',
        snippet: '…<mark>Tijaniyya</mark>…',
        value: 'La <mark>Tijaniyya</mark> au Sénégal',
      },
    ]);
    expect(pickTitleMarkup(h)).toBe('La <mark>Tijaniyya</mark> au Sénégal');
  });

  it('returns empty when the title did not actually match, so the plain title renders', () => {
    expect(pickTitleMarkup(hit([{ field: 'title_txt', value: 'No match here' }]))).toBe('');
    expect(pickTitleMarkup(hit([{ field: 'ocr_text', snippet: '<mark>x</mark>' }]))).toBe('');
  });
});

describe('pickMatchedIn', () => {
  it('names only the channels the card body does not already show', () => {
    const h = hit([
      { field: 'ocr_text', snippet: '<mark>a</mark>' },
      { field: 'title_txt', value: '<mark>a</mark>' },
      { field: 'abstract', snippet: '<mark>a</mark>' },
      { field: 'toc_txt', snippet: '<mark>a</mark>' },
      { field: 'subjects_ss', snippet: '<mark>Islam</mark>' },
    ]);
    expect(pickMatchedIn(h, 'fr').map((m) => m.field)).toEqual(['subjects_ss']);
  });

  it('keeps one entry per field and caps the line at three', () => {
    const h = hit([
      { field: 'subjects_ss', snippet: '<mark>a</mark>' },
      { field: 'subjects_ss', snippet: '<mark>b</mark>' },
      { field: 'spatial_ss', snippet: '<mark>c</mark>' },
      { field: 'creator_ss', snippet: '<mark>d</mark>' },
      { field: 'newspaper_ss', snippet: '<mark>e</mark>' },
    ]);
    const fields = pickMatchedIn(h, 'fr').map((m) => m.field);
    expect(fields).toEqual(['subjects_ss', 'spatial_ss', 'creator_ss']);
  });

  it('ignores a highlight that carries no mark — it explains nothing', () => {
    expect(pickMatchedIn(hit([{ field: 'subjects_ss', snippet: 'Islam' }]), 'fr')).toEqual([]);
  });

  it('reads the first entry of `snippets` when there is no single `snippet`', () => {
    const h = hit([{ field: 'subjects_ss', snippets: ['<mark>Islam</mark>', 'other'] }]);
    expect(pickMatchedIn(h, 'fr')[0].snippet).toBe('<mark>Islam</mark>');
  });
});

describe('buildCitation', () => {
  const doc = (d: Partial<IwacDoc>): IwacDoc => ({ id: '1', title: 'T', ...d }) as IwacDoc;

  it('cites a chapter as book (eds. …), pages — publisher', () => {
    const s = buildCitation(
      doc({
        reference_type_ss: ['Chapitre'],
        book_title_s: 'Islam et société',
        editor_ss: ['Madore', 'Sounaye'],
        pages_s: '45-67',
        publisher_s: 'Brill',
      }),
      'éds.',
    );
    expect(s).toBe('Islam et société (éds. Madore, Sounaye), 45-67 — Brill');
  });

  it('cites a journal article as journal volume(issue), pages', () => {
    const s = buildCitation(
      doc({
        reference_type_ss: ['Article de revue'],
        publisher_s: 'Islamic Africa',
        volume_s: '12',
        issue_s: '2',
        pages_s: '200-220',
      }),
      'éds.',
    );
    expect(s).toBe('Islamic Africa 12(2), 200-220');
  });

  it('reviews cite like journal articles', () => {
    const s = buildCitation(
      doc({ reference_type_ss: ['Compte rendu'], publisher_s: 'JRA', issue_s: '3' }),
      'éds.',
    );
    expect(s).toBe('JRA (3)');
  });

  /** Separators only ever join parts that exist — a stray "—" reads as missing data. */
  it('never emits a dangling separator when parts are missing', () => {
    expect(buildCitation(doc({ reference_type_ss: ['Chapitre'], pages_s: '45-67' }), 'éds.')).toBe(
      '45-67',
    );
    expect(
      buildCitation(doc({ reference_type_ss: ['Livre'], publisher_s: 'Karthala' }), 'éds.'),
    ).toBe('Karthala');
    expect(buildCitation(doc({}), 'éds.')).toBe('');
  });
});

describe('formatDate', () => {
  // 1989-11-04T00:00:00Z
  const epoch = 626140800;

  it('prints the full date for dated content', () => {
    expect(formatDate('en', epoch, 1989)).toBe('November 4, 1989');
  });

  /** References carry a Jan-1 epoch: printing a day would invent precision. */
  it('prints the year only when asked, ignoring the epoch entirely', () => {
    expect(formatDate('en', epoch, 1989, true)).toBe('1989');
  });

  it('falls back to the year when there is no usable epoch', () => {
    expect(formatDate('en', undefined, 2016)).toBe('2016');
    expect(formatDate('en', 0, 2016)).toBe('2016');
  });

  it('is empty when the document is undated, rather than showing 1970', () => {
    expect(formatDate('en', 0, undefined)).toBe('');
    expect(formatDate('en')).toBe('');
  });
});

describe('formatYearRange', () => {
  it('renders a span, collapses a single year, and tolerates one open end', () => {
    expect(formatYearRange(1989, 2004)).toBe('1989 – 2004');
    expect(formatYearRange(1989, 1989)).toBe('1989');
    expect(formatYearRange(1989, undefined)).toBe('1989');
    expect(formatYearRange(undefined, 2004)).toBe('2004');
    expect(formatYearRange(undefined, undefined)).toBe('');
  });
});

describe('formatDuration', () => {
  it('reads like a video player: m:ss under an hour, h:mm:ss over it', () => {
    expect(formatDuration(332)).toBe('5:32');
    expect(formatDuration(59)).toBe('0:59');
    expect(formatDuration(3600)).toBe('1:00:00');
    // The 9½-hour sermon set (PT573M) the deposited corpus actually holds.
    expect(formatDuration(34380)).toBe('9:33:00');
  });

  it('is empty for a missing or nonsensical duration, never "0:00"', () => {
    expect(formatDuration(undefined)).toBe('');
    expect(formatDuration(0)).toBe('');
    expect(formatDuration(-5)).toBe('');
    expect(formatDuration(Number.NaN)).toBe('');
  });
});

describe('buildSourceChips', () => {
  const doc = (d: Partial<IwacDoc>): IwacDoc => ({ id: '1', title: 'T', ...d }) as IwacDoc;
  const chips = (d: Partial<IwacDoc>, hideCountry = false): Array<[string, string]> =>
    buildSourceChips(doc(d), { hideCountry, locale: 'fr' }).map((c) => [c.field, c.display]);

  it('files a press publisher under newspaper_ss', () => {
    expect(chips({ newspaper_ss: ['Sidwaya'], country_ss: ['Burkina Faso'] })).toEqual([
      ['newspaper_ss', 'Sidwaya'],
      ['country_ss', 'Burkina Faso'],
    ]);
  });

  /** The acceptance criterion of issue #50: a channel is not a newspaper. */
  it('files a broadcaster under channel_ss, never newspaper_ss', () => {
    const out = chips({
      type_s: 'audiovisual',
      channel_ss: ['RTB - Radiodiffusion Télévision du Burkina'],
      country_ss: ['Burkina Faso'],
      media_platform_s: 'youtube',
      source_url: 'https://www.youtube.com/watch?v=abc',
    });

    expect(out[0]).toEqual(['channel_ss', 'RTB - Radiodiffusion Télévision du Burkina']);
    expect(out.some(([field]) => field === 'newspaper_ss')).toBe(false);
  });

  it('names the carrier only when there is no watch link to name it', () => {
    // A deposited DVD: nothing else on the card says it is not a web video.
    expect(
      chips({
        type_s: 'audiovisual',
        channel_ss: ['Daarul Hadeethis Salafiyyah'],
        country_ss: ['Nigeria'],
        media_platform_s: 'dvd',
      }),
    ).toEqual([
      ['channel_ss', 'Daarul Hadeethis Salafiyyah'],
      // Displayed accented in French; the FILTER value stays the unaccented
      // facet value, asserted below.
      ['country_ss', 'Nigéria'],
      ['media_platform_s', 'DVD'],
    ]);
    expect(
      buildSourceChips(doc({ country_ss: ['Nigeria'] }), { hideCountry: false, locale: 'fr' })[0]
        .value,
    ).toBe('Nigeria');

    // A YouTube video already ends its line with "Voir sur YouTube".
    expect(
      chips({
        media_platform_s: 'youtube',
        source_url: 'https://www.youtube.com/watch?v=abc',
      }).some(([field]) => field === 'media_platform_s'),
    ).toBe(false);
  });

  it('drops the country on a single-country scope, and copes with an empty doc', () => {
    expect(chips({ newspaper_ss: ['Sidwaya'], country_ss: ['Burkina Faso'] }, true)).toEqual([
      ['newspaper_ss', 'Sidwaya'],
    ]);
    expect(chips({})).toEqual([]);
  });
});

describe('pickExternalLink', () => {
  const doc = (d: Partial<IwacDoc>): IwacDoc => ({ id: '1', title: 'T', ...d }) as IwacDoc;

  it('names the platform when we know it, and stays generic otherwise', () => {
    expect(
      pickExternalLink(
        doc({ source_url: 'https://www.youtube.com/watch?v=abc', media_platform_s: 'youtube' }),
      ),
    ).toEqual({ url: 'https://www.youtube.com/watch?v=abc', labelKey: 'watch_on_youtube' });

    expect(pickExternalLink(doc({ source_url: 'https://lefaso.net/article' }))?.labelKey).toBe(
      'view_source',
    );
  });

  it('is null without a source URL — the card then links only to the IWAC item', () => {
    expect(pickExternalLink(doc({}))).toBeNull();
    expect(pickExternalLink(doc({ source_url: '   ' }))).toBeNull();
  });

  /** The URL becomes an href, so a non-http scheme must never survive. */
  it('rejects any scheme that is not http(s)', () => {
    expect(pickExternalLink(doc({ source_url: 'javascript:alert(1)' }))).toBeNull();
    expect(pickExternalLink(doc({ source_url: 'data:text/html,<script>' }))).toBeNull();
  });
});

describe('resultLanguageTag', () => {
  const doc = (d: Partial<IwacDoc>): IwacDoc => ({ id: '1', title: 'T', ...d }) as IwacDoc;

  /**
   * The corpus stores dcterms:language as French DISPLAY names — a live facet
   * count over the article subset returns Français 12,291 · Ewé 32 · Kabyè 11
   * · Anglais 7 · Dendi 2 — so the accented French spellings are the ones that
   * have to work, not the ISO codes.
   */
  it('maps the display names the corpus actually carries', () => {
    expect(resultLanguageTag(doc({ language_ss: ['Français'] }))).toBe('fr');
    expect(resultLanguageTag(doc({ language_ss: ['Anglais'] }))).toBe('en');
    expect(resultLanguageTag(doc({ language_ss: ['Ewé'] }))).toBe('ee');
    expect(resultLanguageTag(doc({ language_ss: ['Kabyè'] }))).toBe('kbp');
    expect(resultLanguageTag(doc({ language_ss: ['Dendi'] }))).toBe('ddn');
  });

  it('also accepts English names and ISO codes a future record might use', () => {
    expect(resultLanguageTag(doc({ language_ss: ['French'] }))).toBe('fr');
    expect(resultLanguageTag(doc({ language_ss: ['ara'] }))).toBe('ar');
    expect(resultLanguageTag(doc({ language_ss: ['  EN  '] }))).toBe('en');
  });

  /**
   * The point of the whole helper: a WRONG lang is worse than none, because it
   * changes a screen reader's pronunciation with confidence. Anything the map
   * doesn't recognise leaves the element inheriting the document language.
   */
  it('returns undefined rather than guessing', () => {
    expect(resultLanguageTag(doc({}))).toBeUndefined();
    expect(resultLanguageTag(doc({ language_ss: [] }))).toBeUndefined();
    expect(resultLanguageTag(doc({ language_ss: ['Gulmancema'] }))).toBeUndefined();
  });

  /** `lang` takes exactly one tag; a multilingual record has no single one. */
  it('reads the first value only', () => {
    expect(resultLanguageTag(doc({ language_ss: ['Arabe', 'Français'] }))).toBe('ar');
  });
});

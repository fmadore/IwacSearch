import type { IwacDoc, IwacHit } from './types';
import type { Locale } from './i18n';
import { countryLabel, facetLabel, mediaPlatformLabel } from './i18n';
import { sanitizeHighlight } from './sanitize';

/**
 * Every field a result card displays, derived from one Typesense hit.
 *
 * The RULES a card's fields follow — which highlight becomes the snippet,
 * how a reference's citation line is assembled, when a date is a date and
 * when it's only a year. Split out of ResultItem.svelte because rules
 * deserve tests, and inside the component they were reachable only by
 * mounting it. Covered by tests/client/resultCard.test.ts.
 *
 * Plain module, no runes: these are functions of their arguments. The
 * reactive wiring that feeds them a component's props lives next door in
 * resultCard.svelte.ts, and the layout, CSS and interaction handlers stay
 * in the component.
 */

/** A clickable card badge: a facet field + its raw filter value + display. */
export interface CardChip {
  field: string;
  value: string;
  display: string;
}

/** A metadata-channel match worth naming ("Matched in: Sujet …"). */
export interface MatchedIn {
  field: string;
  label: string;
  snippet: string;
}

/**
 * Highlight fields already visible in the card body — a match there needs no
 * "matched in" attribution, because the user can see it.
 */
const VISIBLE_MATCH_FIELDS = ['title_txt', 'ocr_text', 'toc_txt', 'abstract'];

/** Source-line chip icons — the Bootstrap Icons set the IWAC theme uses. */
export const CHIP_ICONS: Record<string, 'newspaper' | 'globe' | 'camera-video'> = {
  newspaper_ss: 'newspaper',
  channel_ss: 'camera-video',
  country_ss: 'globe',
};

/**
 * Body snippet: the OCR match first (most contextual), then the publication
 * table of contents, else the abstract match. Empty when none matched — the
 * caller falls back to the plain abstract. Sanitised: escaped client-side,
 * with only literal <mark> tags reinstated (see lib/sanitize.ts).
 */
export function pickSnippet(hit: IwacHit): string {
  const raw =
    hit.highlights?.find((h) => h.field === 'ocr_text')?.snippet ??
    hit.highlights?.find((h) => h.field === 'toc_txt')?.snippet ??
    hit.highlights?.find((h) => h.field === 'abstract')?.snippet ??
    '';
  return sanitizeHighlight(raw);
}

/**
 * The title with the query match marked, or '' to render the plain title.
 * `highlight_full_fields` covers title_txt, so `value` carries the COMPLETE
 * title — preferred over `snippet`, which would truncate it.
 */
export function pickTitleMarkup(hit: IwacHit): string {
  const h = hit.highlights?.find((x) => x.field === 'title_txt');
  const s = h?.value ?? h?.snippet ?? '';
  return s.includes('<mark>') ? sanitizeHighlight(s) : '';
}

/**
 * Why is this hit here? Surfaces matches in metadata channels (subject,
 * spatial, author, journal, alias…) that the card body doesn't show. One
 * entry per field, capped at 3 — beyond that the line stops being a hint
 * and becomes a wall.
 */
export function pickMatchedIn(hit: IwacHit, locale: Locale): MatchedIn[] {
  const out: MatchedIn[] = [];
  for (const h of hit.highlights ?? []) {
    if (VISIBLE_MATCH_FIELDS.includes(h.field)) continue;
    if (out.some((m) => m.field === h.field)) continue;
    const raw = h.snippet ?? h.snippets?.[0] ?? '';
    if (!raw.includes('<mark>')) continue;
    out.push({
      field: h.field,
      label: facetLabel(h.field, locale),
      snippet: sanitizeHighlight(raw),
    });
  }
  return out.slice(0, 3);
}

/**
 * Bibliographic source line for a reference, shaped by its reference type:
 * a chapter cites its book + editors, an article its journal + volume(issue)
 * + pages, everything else falls back to book — publisher — edition.
 * Every part is optional, so the separators are added only between parts
 * that exist (a stray " — " reads as missing data).
 *
 * @param citeEds Localised "eds." label, from the caller's translator.
 */
export function buildCitation(d: IwacDoc, citeEds: string): string {
  const rt = d.reference_type_ss?.[0] ?? '';
  const pub = (d.publisher_s ?? '').trim();
  const book = (d.book_title_s ?? '').trim();
  const vol = (d.volume_s ?? '').trim();
  const iss = (d.issue_s ?? '').trim();
  const pages = (d.pages_s ?? '').trim();
  const edition = (d.edition_s ?? '').trim();
  const editors = (d.editor_ss ?? []).join(', ');
  const volIss = vol && iss ? `${vol}(${iss})` : vol || (iss ? `(${iss})` : '');

  if (rt === 'Chapitre') {
    let s = book;
    if (editors) s += ` (${citeEds} ${editors})`;
    if (pages) s += `${s ? ', ' : ''}${pages}`;
    if (pub) s += `${s ? ' — ' : ''}${pub}`;
    return s.trim();
  }
  if (rt === 'Article de revue' || rt === 'Compte rendu') {
    let s = pub;
    if (volIss) s += `${s ? ' ' : ''}${volIss}`;
    if (pages) s += `${s ? ', ' : ''}${pages}`;
    return s.trim();
  }
  return [book, pub, edition].filter(Boolean).join(' — ');
}

/**
 * Display date. `yearOnly` is for references, whose pub_date is commonly a
 * Jan-1 epoch — printing "1 janvier 2016" would invent a precision the
 * source never had.
 */
export function formatDate(
  locale: Locale,
  epoch?: number,
  year?: number,
  yearOnly = false,
): string {
  if (!yearOnly && epoch && epoch > 0) {
    try {
      return new Date(epoch * 1000).toLocaleDateString(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
      });
    } catch {
      // Invalid locale — fall through to the year.
    }
  }
  return year ? String(year) : '';
}

/** The mention span an entity card shows as its eyebrow: "1989 – 2004". */
export function formatYearRange(first?: number, last?: number): string {
  if (first && last) return first === last ? String(first) : `${first} – ${last}`;
  return first ? String(first) : last ? String(last) : '';
}

/**
 * Running time for an audiovisual card: `5:32`, or `9:33:00` once it passes
 * an hour. Colon-separated rather than "5 min 32 s" because it is the form
 * every video player uses, and it needs no translation. Returns '' for a
 * missing / non-finite / non-positive duration, so the card omits the field
 * rather than printing "0:00".
 */
export function formatDuration(seconds?: number): string {
  if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0) return '';
  const total = Math.round(seconds);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (n: number): string => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/**
 * The external link a card may offer BESIDE (never instead of) the IWAC item
 * link: the canonical watch URL of a YouTube video, or whatever other source
 * URL the record carries. Only http(s) — a `javascript:` or `data:` value in
 * the index must never become an href.
 *
 * Returns the URL plus the i18n key for its label, so the platform names
 * itself ("Watch on YouTube") where we know it.
 */
export function pickExternalLink(d: IwacDoc): { url: string; labelKey: string } | null {
  const url = (d.source_url ?? '').trim();
  if (!/^https?:\/\//i.test(url)) return null;
  return {
    url,
    labelKey: d.media_platform_s === 'youtube' ? 'watch_on_youtube' : 'view_source',
  };
}

/**
 * The card's source line: where this record comes from, each token a
 * clickable facet.
 *
 *   press        Sidwaya · Burkina Faso
 *   audiovisual  RTB · Burkina Faso            (+ a watch link, rendered
 *                                               separately by the component)
 *   a DVD        Daarul Hadeethis Salafiyyah · Nigeria · DVD
 *
 * The publisher token reads `newspaper_ss` OR `channel_ss` — the indexer
 * routes `dcterms:publisher` to one or the other by subset, so a YouTube
 * channel is never labelled "Journal". Both are read here rather than
 * branching on `type_s`, so a card is right whichever field the document
 * carries.
 *
 * The carrier token is deliberately conditional: it appears only when the
 * row has no watch link, because a video whose line already ends in "Watch
 * on YouTube" does not need a "YouTube" chip too. What it is for is the
 * deposited DVDs and CDs, which have no link and would otherwise be
 * indistinguishable from a web video on the card.
 */
export function buildSourceChips(
  d: IwacDoc,
  opts: { hideCountry: boolean; locale: Locale },
): CardChip[] {
  const out: CardChip[] = [];
  const publisher = d.newspaper_ss?.[0]
    ? ({ field: 'newspaper_ss', value: d.newspaper_ss[0] } as const)
    : d.channel_ss?.[0]
      ? ({ field: 'channel_ss', value: d.channel_ss[0] } as const)
      : null;
  if (publisher) {
    out.push({ ...publisher, display: publisher.value });
  }
  if (!opts.hideCountry && d.country_ss?.[0]) {
    out.push({
      field: 'country_ss',
      value: d.country_ss[0],
      display: countryLabel(d.country_ss[0], opts.locale),
    });
  }
  if (d.media_platform_s && !pickExternalLink(d)) {
    out.push({
      field: 'media_platform_s',
      value: d.media_platform_s,
      display: mediaPlatformLabel(d.media_platform_s, opts.locale),
    });
  }
  return out;
}

/**
 * `dcterms:language` display value → BCP-47 subtag, for the `lang` attribute
 * on a result's title and body text.
 *
 * The archive is francophone: `<html lang="en-US">` on the English site sits
 * over result titles that are overwhelmingly French, and a screen reader
 * reads them in an English voice — "Ouagadougou" and "Côte d'Ivoire" come out
 * as noise. Only a per-result `lang` fixes that, and only the indexed value
 * can supply it, so this maps the display strings the corpus actually carries
 * (French names, plus the English names and ISO codes a future record might).
 *
 * Deliberately NOT a guess: an unrecognised value returns undefined and the
 * element inherits the document language, which is what it does today. A
 * wrong `lang` is worse than none — it changes pronunciation with confidence.
 */
const LANGUAGE_TAGS: Readonly<Record<string, string>> = {
  // French display names (what the corpus uses), accents stripped by norm().
  francais: 'fr',
  anglais: 'en',
  arabe: 'ar',
  allemand: 'de',
  espagnol: 'es',
  portugais: 'pt',
  italien: 'it',
  neerlandais: 'nl',
  russe: 'ru',
  turc: 'tr',
  persan: 'fa',
  ewe: 'ee',
  kabye: 'kbp',
  dendi: 'ddn',
  haoussa: 'ha',
  peul: 'ff',
  fulfulde: 'ff',
  moore: 'mos',
  dioula: 'dyu',
  bambara: 'bm',
  yoruba: 'yo',
  wolof: 'wo',
  haussa: 'ha',
  swahili: 'sw',
  zarma: 'dje',
  // English names + ISO 639-1/2 codes.
  french: 'fr',
  english: 'en',
  arabic: 'ar',
  german: 'de',
  spanish: 'es',
  portuguese: 'pt',
  italian: 'it',
  dutch: 'nl',
  hausa: 'ha',
  fula: 'ff',
  jula: 'dyu',
  fr: 'fr',
  fra: 'fr',
  fre: 'fr',
  en: 'en',
  eng: 'en',
  ar: 'ar',
  ara: 'ar',
  de: 'de',
  ger: 'de',
  deu: 'de',
  es: 'es',
  spa: 'es',
  pt: 'pt',
  por: 'pt',
};

/** Lowercase, strip accents and surrounding punctuation, for table lookup. */
function normLanguage(raw: string): string {
  return raw
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z]/g, '');
}

/**
 * BCP-47 tag for a result's text, or undefined when the record names no
 * language this table knows. Reads the FIRST value only: a multilingual
 * record has no single tag for its title, and `lang` takes exactly one.
 */
export function resultLanguageTag(d: IwacDoc): string | undefined {
  const raw = d.language_ss?.[0];
  if (!raw) return undefined;
  return LANGUAGE_TAGS[normLanguage(raw)];
}

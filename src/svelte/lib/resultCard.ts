import type { IwacDoc, IwacHit } from './types';
import type { Locale } from './i18n';
import { facetLabel } from './i18n';
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
const VISIBLE_MATCH_FIELDS = ['title_txt', 'ocr_text', 'abstract'];

/** Source-line chip icons — the Bootstrap Icons set the IWAC theme uses. */
export const CHIP_ICONS: Record<string, 'newspaper' | 'globe'> = {
  newspaper_ss: 'newspaper',
  country_ss: 'globe',
};

/**
 * Body snippet: the OCR match first (most contextual), else the abstract
 * match. Empty when neither matched — the caller falls back to the plain
 * abstract. Sanitised: escaped client-side, with only literal <mark> tags
 * reinstated (see lib/sanitize.ts).
 */
export function pickSnippet(hit: IwacHit): string {
  const raw =
    hit.highlights?.find((h) => h.field === 'ocr_text')?.snippet ??
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

import type { Locale } from './i18n';

/**
 * `dcterms:language` display value → BCP-47 subtag.
 *
 * Two jobs read it: the `lang` attribute on a result's title and body text
 * (resultLanguageTag), and the English label of a `language_ss` facet value
 * (languageLabel).
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
  slovene: 'sl',
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

/** BCP-47 tag for a language display value, or undefined when unknown. */
export function languageTag(raw: string): string | undefined {
  return LANGUAGE_TAGS[normLanguage(raw)];
}

/**
 * English names the archive's own language authorities give, where they
 * differ from (or are missing in) the browser's CLDR names: the authority
 * record for Mooré is titled "Mooré" in English, which CLDR calls "Mossi",
 * and Slovène "Slovene", not "Slovenian". Everything else comes from
 * Intl.DisplayNames.
 */
const ENGLISH_OVERRIDES: Readonly<Record<string, string>> = {
  mos: 'Mooré',
  ddn: 'Dendi',
  dyu: 'Dyula',
  kbp: 'Kabiyè',
  sl: 'Slovene',
};

let englishNames: Intl.DisplayNames | null | undefined;

function englishName(tag: string): string | undefined {
  if (ENGLISH_OVERRIDES[tag]) return ENGLISH_OVERRIDES[tag];
  if (englishNames === undefined) {
    try {
      englishNames = new Intl.DisplayNames(['en'], { type: 'language', fallback: 'none' });
    } catch {
      englishNames = null;
    }
  }
  return englishNames?.of(tag) ?? undefined;
}

/**
 * Display label for a `language_ss` facet value. The index stores the
 * authority's French title ("Français") on both sites, so the English site
 * gets the English name ("French"); the French site keeps the stored value,
 * which is already the authority's own spelling. An unknown value is shown
 * as stored, and the filter value itself is never altered.
 */
export function languageLabel(value: string, locale: Locale): string {
  if (locale !== 'en') return value;
  const tag = languageTag(value);
  return (tag && englishName(tag)) || value;
}

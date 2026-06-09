/**
 * Client-side i18n for the public discovery surface.
 *
 * The module ships its own FR/EN string tables instead of leaning on
 * Omeka's gettext pipeline: the Svelte bundle has no runtime i18n dep, and
 * shipping ~50 micro-strings as a compiled .mo file (which we'd have to
 * regenerate on every copy tweak) is more friction than value. The server
 * detects the site locale and passes it in the bootstrap as `locale`; this
 * module turns that into the right strings.
 *
 * Surfaces:
 *   - the IWAC site runs a French site (/s/afrique_ouest) and an English
 *     one (/s/westafrica). French is the default/fallback.
 *
 * Locale is provided once per App mount via Svelte context (provideI18n)
 * and read by descendants via useI18n(). Facet/type labels are pure
 * functions that take the locale explicitly.
 */

import { getContext, setContext } from 'svelte';

export type Locale = 'fr' | 'en';

/** Which card vocabulary + sort options a surface renders. */
export type CardKind = 'content' | 'entity';

export function normalizeLocale(value: unknown): Locale {
  return value === 'en' ? 'en' : 'fr';
}

export function normalizeCard(value: unknown): CardKind {
  return value === 'entity' ? 'entity' : 'content';
}

/** Translator bound to a locale — `t('clear_all')`, `t('page_n', {n: 3})`. */
export type Translate = (key: string, vars?: Record<string, string | number>) => string;

// ── String tables ─────────────────────────────────────────────────────
// Keys are snake_case. `{name}` placeholders are filled by translate().

const STRINGS: Record<Locale, Record<string, string>> = {
  fr: {
    search_placeholder: "Rechercher dans l'IWAC…",
    search_unavailable: 'Recherche indisponible.',
    filters: 'Filtres',
    open_filters: 'Ouvrir les filtres',
    clear_all: 'Tout effacer',
    clear_all_filters: 'Effacer tous les filtres',
    active_filters: 'Filtres actifs',
    remove_filter: 'Retirer le filtre {label} : {value}',
    add_filter: 'Filtrer par {label} : {value}',
    search_to_see_options: 'Lancez une recherche pour voir les filtres.',
    result_one: 'résultat',
    result_other: 'résultats',
    no_results_short: 'Aucun résultat',
    searching: 'Recherche…',
    no_results_title: 'Aucun résultat.',
    try_removing_filter: 'Essayez de retirer un filtre ou deux.',
    try_broader_query: "Essayez une requête plus large ou vérifiez l'orthographe.",
    corpus_empty: "Le corpus semble vide — veuillez contacter l'administrateur du site.",
    year: 'Année',
    reset: 'Réinitialiser',
    year_range: "Plage d'années",
    from_year: 'Année de début',
    to_year: 'Année de fin',
    sort_by: 'Trier par',
    sort_relevance: 'Pertinence',
    sort_newest: 'Plus récent',
    sort_oldest: 'Plus ancien',
    sort_author_az: 'Auteur (A–Z)',
    prev: 'Préc.',
    next: 'Suiv.',
    previous_page: 'Page précédente',
    next_page: 'Page suivante',
    results_pagination: 'Pagination des résultats',
    page_n: 'Page {n}',
    show_more: 'Afficher {n} de plus',
    show_less: 'Afficher moins',
    search_values: 'Rechercher {name}…',
    filter_values: 'Filtrer les valeurs : {name}',
    clear_filter: 'Effacer le filtre',
    no_values: 'Aucune valeur pour ce filtre.',
    no_matches: 'Aucune correspondance.',
    match_count: '{shown} sur {total}',
    facet_search_count: '{n} résultat(s)',
    n_active: '{n} actif(s)',
    source: 'Source',
    untitled: '[Sans titre #{id}]',
    suggestions: 'Suggestions',
    search_for: 'Rechercher « {q} »',
    entities: 'Entités',
    results_empty_list: 'Aucune correspondance. Essayez un autre mot ou retirez un filtre.',
    sentiment: 'Sentiment',
    mention_one: '{n} mention',
    mention_other: '{n} mentions',
    sort_most_mentioned: 'Plus mentionné',
    sort_least_mentioned: 'Moins mentionné',
    sort_az: 'A–Z',
    sort_most_recent: 'Plus récent',
    cite_eds: 'dir.',
    search_everything: 'Rechercher dans toute la collection…',
    clear_search: 'Effacer la recherche',
    tab_content: 'Contenu',
    tab_entities: 'Entités',
    result_types: 'Types de résultats',
  },
  en: {
    search_placeholder: 'Search the IWAC…',
    search_unavailable: 'Search unavailable.',
    filters: 'Filters',
    open_filters: 'Open filters',
    clear_all: 'Clear all',
    clear_all_filters: 'Clear all filters',
    active_filters: 'Active filters',
    remove_filter: 'Remove filter {label}: {value}',
    add_filter: 'Filter by {label}: {value}',
    search_to_see_options: 'Search to see filter options.',
    result_one: 'result',
    result_other: 'results',
    no_results_short: 'No results',
    searching: 'Searching…',
    no_results_title: 'No results.',
    try_removing_filter: 'Try removing a filter or two.',
    try_broader_query: 'Try a broader query, or check your spelling.',
    corpus_empty: 'The corpus seems empty — please contact the site administrator.',
    year: 'Year',
    reset: 'Reset',
    year_range: 'Year range',
    from_year: 'From year',
    to_year: 'To year',
    sort_by: 'Sort by',
    sort_relevance: 'Relevance',
    sort_newest: 'Newest first',
    sort_oldest: 'Oldest first',
    sort_author_az: 'Author (A–Z)',
    prev: 'Prev',
    next: 'Next',
    previous_page: 'Previous page',
    next_page: 'Next page',
    results_pagination: 'Results pagination',
    page_n: 'Page {n}',
    show_more: 'Show {n} more',
    show_less: 'Show less',
    search_values: 'Search {name}…',
    filter_values: 'Filter {name} values',
    clear_filter: 'Clear filter',
    no_values: 'No values for this filter.',
    no_matches: 'No matches.',
    match_count: '{shown} of {total}',
    facet_search_count: '{n} result(s)',
    n_active: '{n} active',
    source: 'Source',
    untitled: '[Untitled #{id}]',
    suggestions: 'Suggestions',
    search_for: 'Search for “{q}”',
    entities: 'Entities',
    results_empty_list: 'No matches. Try a different word or remove a filter.',
    sentiment: 'Sentiment',
    mention_one: '{n} mention',
    mention_other: '{n} mentions',
    sort_most_mentioned: 'Most mentioned',
    sort_least_mentioned: 'Least mentioned',
    sort_az: 'A–Z',
    sort_most_recent: 'Most recent',
    cite_eds: 'eds.',
    search_everything: 'Search the whole collection…',
    clear_search: 'Clear search',
    tab_content: 'Content',
    tab_entities: 'Entities',
    result_types: 'Result types',
  },
};

export function translate(
  locale: Locale,
  key: string,
  vars?: Record<string, string | number>,
): string {
  const table = STRINGS[locale] ?? STRINGS.fr;
  let s = table[key] ?? STRINGS.fr[key] ?? key;
  if (vars) {
    for (const [k, v] of Object.entries(vars)) {
      s = s.replaceAll(`{${k}}`, String(v));
    }
  }
  return s;
}

// ── Facet field labels ─────────────────────────────────────────────────

const FACET_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    country_ss: 'Pays',
    newspaper_ss: 'Journal',
    language_ss: 'Langue',
    topics_ss: 'Thème',
    persons_ss: 'Personne',
    places_ss: 'Lieu',
    organisations_ss: 'Organisation',
    events_ss: 'Événement',
    date_decade_ss: 'Décennie',
    pub_year: 'Année',
    type_s: 'Type',
    entity_type_s: 'Type',
    reference_type_ss: 'Type de référence',
    creator_ss: 'Auteur',
    gemini_polarite_ss: 'Polarité',
    gemini_centralite_ss: 'Centralité',
    gemini_subjectivite: 'Subjectivité',
    chatgpt_polarite_ss: 'Polarité (ChatGPT)',
    chatgpt_centralite_ss: 'Centralité (ChatGPT)',
    mistral_polarite_ss: 'Polarité (Mistral)',
    mistral_centralite_ss: 'Centralité (Mistral)',
  },
  en: {
    country_ss: 'Country',
    newspaper_ss: 'Newspaper',
    language_ss: 'Language',
    topics_ss: 'Topic',
    persons_ss: 'Person',
    places_ss: 'Place',
    organisations_ss: 'Organisation',
    events_ss: 'Event',
    date_decade_ss: 'Decade',
    pub_year: 'Year',
    type_s: 'Type',
    entity_type_s: 'Type',
    reference_type_ss: 'Reference type',
    creator_ss: 'Author',
    gemini_polarite_ss: 'Polarity',
    gemini_centralite_ss: 'Centrality',
    gemini_subjectivite: 'Subjectivity',
    chatgpt_polarite_ss: 'Polarity (ChatGPT)',
    chatgpt_centralite_ss: 'Centrality (ChatGPT)',
    mistral_polarite_ss: 'Polarity (Mistral)',
    mistral_centralite_ss: 'Centrality (Mistral)',
  },
};

export function facetLabel(field: string, locale: Locale): string {
  return FACET_LABELS[locale]?.[field] ?? FACET_LABELS.fr[field] ?? humanise(field);
}

/** Sentiment sub-facets render under one collapsible "Sentiment" group. */
export const SENTIMENT_FIELDS: ReadonlySet<string> = new Set([
  'gemini_polarite_ss',
  'gemini_centralite_ss',
  'gemini_subjectivite',
  'chatgpt_polarite_ss',
  'chatgpt_centralite_ss',
  'chatgpt_subjectivite',
  'mistral_polarite_ss',
  'mistral_centralite_ss',
  'mistral_subjectivite',
]);

/**
 * Numeric facet fields. Their filter_by values must NOT be backtick-quoted
 * (Typesense rejects a backticked number with "Numerical field has an
 * invalid comparator"); they're emitted as a bare numeric array instead.
 */
export const NUMERIC_FACET_FIELDS: ReadonlySet<string> = new Set([
  'gemini_subjectivite',
  'chatgpt_subjectivite',
  'mistral_subjectivite',
  'pub_year',
]);

// ── type_s value labels ────────────────────────────────────────────────
// The type_s enum is an internal discriminator (article|publication|…),
// not source data, so we fully control its display labels per locale.
// `audiovisual` is the 45 DVD/CD recordings — NOT photographs (those live
// only in Omeka, not the search dataset).

const TYPE_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    article: 'Article de presse',
    publication: 'Périodique islamique',
    document: 'Document',
    audiovisual: 'Audiovisuel',
    reference: 'Référence',
  },
  en: {
    article: 'News article',
    publication: 'Islamic periodical',
    document: 'Document',
    audiovisual: 'Audiovisual',
    reference: 'Reference',
  },
};

export function typeLabel(value: string, locale: Locale): string {
  return TYPE_LABELS[locale]?.[value] ?? TYPE_LABELS.fr[value] ?? '';
}

// ── Index/authority entity type labels (entity_type_s values) ──────────
// The raw values are French data strings; English gets translations.

const ENTITY_TYPE_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    Personnes: 'Personnes',
    Lieux: 'Lieux',
    Organisations: 'Organisations',
    Événements: 'Événements',
    Sujets: 'Sujets',
    "Notices d'autorité": "Notices d'autorité",
  },
  en: {
    Personnes: 'People',
    Lieux: 'Places',
    Organisations: 'Organisations',
    Événements: 'Events',
    Sujets: 'Topics',
    "Notices d'autorité": 'Authority records',
  },
};

export function entityTypeLabel(value: string, locale: Locale): string {
  return ENTITY_TYPE_LABELS[locale]?.[value] ?? value;
}

// ── Country value labels ───────────────────────────────────────────────
// The country_ss data values are mostly already correct in both locales
// (Burkina Faso, Côte d'Ivoire, Niger, Togo). Only Bénin / Nigéria need a
// French accent the source value may lack. We map both the bare and the
// accented spellings to the canonical French form, so display is correct
// regardless of how the value is stored — and the raw value (used for
// filtering) is never touched. French-only; English keeps the raw value.

const COUNTRY_LABELS: Partial<Record<Locale, Record<string, string>>> = {
  fr: {
    Benin: 'Bénin',
    Bénin: 'Bénin',
    Nigeria: 'Nigéria',
    Nigéria: 'Nigéria',
  },
};

export function countryLabel(value: string, locale: Locale): string {
  return COUNTRY_LABELS[locale]?.[value] ?? value;
}

// ── Subjectivity scale value labels (1–5 → readable label) ─────────────
// gemini/chatgpt/mistral_subjectivite are 1–5 float facets. Typesense
// returns the facet value as a string ("1", or possibly "1.0"), so the raw
// sidebar reads as a bare "1". We map the rounded integer to a human label
// — labels only; the long scale descriptions live in the dataset docs, not
// the filter UI.

const SUBJECTIVITY_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    '1': 'Très objectif',
    '2': 'Plutôt objectif',
    '3': 'Mixte',
    '4': 'Plutôt subjectif',
    '5': 'Très subjectif',
  },
  en: {
    '1': 'Very objective',
    '2': 'Rather objective',
    '3': 'Mixed',
    '4': 'Rather subjective',
    '5': 'Very subjective',
  },
};

/**
 * Display label for a facet *value* (as opposed to facetLabel, which
 * labels the field). Only the subjectivity scale needs remapping (1–5 →
 * words); every other facet shows its raw value. Falls back to the raw
 * value for unknown fields / out-of-range or non-numeric values, so the
 * underlying filter value the caller toggles on is never altered.
 */
export function facetValueLabel(field: string, value: string, locale: Locale): string {
  if (field === 'country_ss') {
    return countryLabel(value, locale);
  }
  if (field.endsWith('_subjectivite')) {
    const n = Number(value);
    if (Number.isFinite(n)) {
      const key = String(Math.round(n));
      const label = SUBJECTIVITY_LABELS[locale]?.[key] ?? SUBJECTIVITY_LABELS.fr[key];
      if (label) return label;
    }
  }
  return value;
}

/**
 * Sort options for the surface. The entity (index) browse page sorts by
 * occurrence frequency and name; content surfaces by relevance + date.
 */
export function sortOptions(
  locale: Locale,
  card: CardKind = 'content',
): ReadonlyArray<{ value: string; label: string }> {
  if (card === 'entity') {
    return [
      { value: 'frequency:desc', label: translate(locale, 'sort_most_mentioned') },
      { value: 'frequency:asc', label: translate(locale, 'sort_least_mentioned') },
      { value: 'title:asc', label: translate(locale, 'sort_az') },
      { value: 'date:desc', label: translate(locale, 'sort_most_recent') },
    ];
  }
  return [
    { value: '_text_match:desc', label: translate(locale, 'sort_relevance') },
    { value: 'date:desc', label: translate(locale, 'sort_newest') },
    { value: 'date:asc', label: translate(locale, 'sort_oldest') },
    // Sorts on the scalar creator_sort field (see schema.yaml). Docs with no
    // author sort last — see the missing_values handling in typesense.ts.
    { value: 'creator_sort:asc', label: translate(locale, 'sort_author_az') },
  ];
}

/**
 * Fallback for facets we haven't explicitly labelled — strip the suffix
 * and title-case the rest so e.g. `regional_ss` reads as "Regional".
 */
function humanise(field: string): string {
  const stripped = field.replace(/_(ss|s|txt|dt)$/, '').replace(/_/g, ' ');
  return stripped.charAt(0).toUpperCase() + stripped.slice(1);
}

// ── Svelte context plumbing ────────────────────────────────────────────

interface I18nContext {
  locale: Locale;
  card: CardKind;
  t: Translate;
}

const I18N_KEY = Symbol('iwac-i18n');

/** Call once in App.svelte during init. */
export function provideI18n(locale: Locale, card: CardKind = 'content'): I18nContext {
  const ctx: I18nContext = { locale, card, t: (key, vars) => translate(locale, key, vars) };
  setContext(I18N_KEY, ctx);
  return ctx;
}

/** Read in any descendant component during init. Falls back to French content. */
export function useI18n(): I18nContext {
  return (
    getContext<I18nContext | undefined>(I18N_KEY) ?? {
      locale: 'fr',
      card: 'content',
      t: (key, vars) => translate('fr', key, vars),
    }
  );
}

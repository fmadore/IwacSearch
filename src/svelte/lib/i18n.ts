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

/**
 * Plural-aware translator: `tp('result', 1284)` → "1 284 résultats".
 *
 * Picks `${base}_${category}` by the locale's Intl.PluralRules — never by
 * `count === 1`, because French files 0 under "one" ("0 résultat") where
 * English files it under "other", and French also has a "many" category
 * (1 000 000). A category the table does not spell out falls back to
 * `${base}_other`. `{n}` is filled with the count through
 * {@link formatNumber} unless `vars` supplies its own `n`.
 */
export type TranslatePlural = (
  base: string,
  count: number,
  vars?: Record<string, string | number>,
) => string;

/** Number formatter bound to a locale — see {@link formatNumber}. */
export type FormatNumber = (value: number, options?: Intl.NumberFormatOptions) => string;

// ── String tables ─────────────────────────────────────────────────────
// Keys are snake_case. `{name}` placeholders are filled by translate().

const STRINGS: Record<Locale, Record<string, string>> = {
  fr: {
    search_placeholder: "Rechercher dans l'IWAC…",
    search_unavailable: 'Recherche indisponible.',
    filters: 'Filtres',
    open_filters: 'Ouvrir les filtres',
    close_filters: 'Fermer les filtres',
    clear_all: 'Tout effacer',
    clear_all_filters: 'Effacer tous les filtres',
    active_filters: 'Filtres actifs',
    remove_filter: 'Retirer le filtre {label} : {value}',
    add_filter: 'Filtrer par {label} : {value}',
    search_to_see_options: 'Lancez une recherche pour voir les filtres.',
    result_one: 'résultat',
    result_other: 'résultats',
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
    per_page_label: 'Résultats par page',
    per_page_n: '{n} par page',
    jump_to_page: 'Aller à la page',
    jump_of_total: 'sur {total}',
    jump_go: 'Aller',
    show_more: 'Afficher {n} de plus',
    show_less: 'Afficher moins',
    search_values: 'Rechercher {name}…',
    filter_values: 'Filtrer les valeurs : {name}',
    clear_filter: 'Effacer le filtre',
    no_values: 'Aucune valeur pour ce filtre.',
    match_count: '{shown} sur {total}',
    facet_search_count_one: '{n} résultat',
    facet_search_count_other: '{n} résultats',
    n_active_one: '{n} actif',
    n_active_other: '{n} actifs',
    source: 'Source',
    untitled: '[Sans titre #{id}]',
    results_empty_list: 'Aucune correspondance. Essayez un autre mot ou retirez un filtre.',
    sentiment: 'Sentiment',
    mention_one: '{n} mention',
    mention_other: '{n} mentions',
    // Ventilation du compte ci-dessus : « dont 8 signés » — l'entité est
    // l'AUTEUR de ces documents, elle n'y est pas seulement citée.
    authored_one: 'dont {n} signé',
    authored_other: 'dont {n} signés',
    sort_most_mentioned: 'Plus mentionné',
    sort_least_mentioned: 'Moins mentionné',
    sort_most_authored: 'Plus signé',
    sort_az: 'A–Z',
    sort_most_recent: 'Plus récent',
    cite_eds: 'dir.',
    search_everything: 'Rechercher dans toute la collection…',
    clear_search: 'Effacer la recherche',
    tab_content: 'Contenu',
    tab_entities: 'Entités',
    result_types: 'Types de résultats',
    matched_in: 'Trouvé via',
    view: 'Affichage',
    view_list: 'Liste',
    view_gallery: 'Galerie',
    view_map: 'Carte',
    map_label: 'Carte des lieux',
    map_loading: 'Chargement de la carte…',
    map_error: 'Impossible de charger la carte.',
    histogram_unavailable: 'La répartition par année est indisponible.',
    retry_search: 'Réessayer',
    search_failed_hint: "La recherche n'a pas pu aboutir. Réessayez dans un instant.",
    map_empty: 'Aucun lieu géolocalisé dans ces résultats.',
    map_capped: 'Carte limitée aux {n} lieux les plus mentionnés.',
    copy_link: 'Copier le lien',
    link_copied: 'Lien copié !',
    did_you_mean: 'Vouliez-vous dire :',
    // Semantic fallback: a query the keyword leg didn't match at all. The
    // vector leg's top-k is offered, never asserted (see lib/semanticFallback.ts).
    show_semantic_one: 'Afficher {n} document sémantiquement proche',
    show_semantic_other: 'Afficher {n} documents sémantiquement proches',
    semantic_only_banner:
      'Aucune correspondance exacte pour « {q} » — voici des documents sémantiquement proches.',
    hide_semantic: 'Masquer ces résultats',
    // Count-line noun for the opted-in semantic set. "N résultats" would
    // re-assert as findings the very thing the opt-in exists to qualify.
    semantic_result_one: 'document sémantiquement proche',
    semantic_result_other: 'documents sémantiquement proches',
    recent_searches: 'Recherches récentes',
    clear_history: "Effacer l'historique",
    tab_all: 'Tout',
    results_in_scope: 'dans',
    sorted_by: 'triés par',
    no_results_in_scope: 'Aucun résultat dans',
    union_cap_hint:
      'Fin des résultats combinés. Affinez votre recherche, ou ouvrez un onglet pour parcourir la totalité d’une collection.',
    loading_results: 'Chargement des résultats…',
    mentions_trend: 'Évolution des mentions',
    export: 'Exporter',
    exporting: 'Export…',
    export_results: 'Exporter les résultats',
    export_txt: 'Texte (.txt)',
    export_json: 'JSON (.json)',
    export_ris: 'RIS — Zotero / EndNote (.ris)',
    export_bibtex: 'BibTeX (.bib)',
    export_limit: 'Limité aux {n} premiers résultats',
    export_failed: "L'export a échoué : {message}",
    duration: 'Durée',
    watch_on_youtube: 'Voir sur YouTube',
    view_source: 'Voir la source',
    // The EU "AI generated" mark on an AI-written card body — the theme's
    // own wording for the same mark on the item page (IWAC-theme fr.po).
    ai_generated: 'Contenu généré par IA — à vérifier avec la source originale.',
    ai_generated_title: 'Généré par un modèle d’IA. Vérifiez avec la source originale.',
    // Skip link + the results landmark's own heading: without them the only
    // <h2> on the page is "Filtres", so every result <h3> nests under the
    // filter sidebar, and reaching the first result costs ~120 Tab presses.
    skip_to_results: 'Aller aux résultats',
    results_heading: 'Résultats',
    // Polite announcements for the persistent live region. Kept to one short
    // sentence: this is read aloud after every settled search.
    announce_results_one: '{n} résultat trouvé.',
    announce_results_other: '{n} résultats trouvés.',
    announce_no_results: 'Aucun résultat.',
    announce_page: 'Page {p} sur {total}.',
    announce_semantic_one:
      'Aucune correspondance exacte. {n} document sémantiquement proche peut être affiché.',
    announce_semantic_other:
      'Aucune correspondance exacte. {n} documents sémantiquement proches peuvent être affichés.',
    announce_semantic_shown_one: 'Affichage de {n} document sémantiquement proche.',
    announce_semantic_shown_other: 'Affichage de {n} documents sémantiquement proches.',
  },
  en: {
    search_placeholder: 'Search the IWAC…',
    search_unavailable: 'Search unavailable.',
    filters: 'Filters',
    open_filters: 'Open filters',
    close_filters: 'Close filters',
    clear_all: 'Clear all',
    clear_all_filters: 'Clear all filters',
    active_filters: 'Active filters',
    remove_filter: 'Remove filter {label}: {value}',
    add_filter: 'Filter by {label}: {value}',
    search_to_see_options: 'Search to see filter options.',
    result_one: 'result',
    result_other: 'results',
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
    per_page_label: 'Results per page',
    per_page_n: '{n} per page',
    jump_to_page: 'Go to page',
    jump_of_total: 'of {total}',
    jump_go: 'Go',
    show_more: 'Show {n} more',
    show_less: 'Show less',
    search_values: 'Search {name}…',
    filter_values: 'Filter {name} values',
    clear_filter: 'Clear filter',
    no_values: 'No values for this filter.',
    match_count: '{shown} of {total}',
    facet_search_count_one: '{n} result',
    facet_search_count_other: '{n} results',
    n_active_one: '{n} active',
    n_active_other: '{n} active',
    source: 'Source',
    untitled: '[Untitled #{id}]',
    results_empty_list: 'No matches. Try a different word or remove a filter.',
    sentiment: 'Sentiment',
    mention_one: '{n} mention',
    mention_other: '{n} mentions',
    // Breakdown of the count above: "8 as author" — the entity WROTE these,
    // it is not merely cited in them.
    authored_one: '{n} as author',
    authored_other: '{n} as author',
    sort_most_mentioned: 'Most mentioned',
    sort_least_mentioned: 'Least mentioned',
    sort_most_authored: 'Most authored',
    sort_az: 'A–Z',
    sort_most_recent: 'Most recent',
    cite_eds: 'eds.',
    search_everything: 'Search the whole collection…',
    clear_search: 'Clear search',
    tab_content: 'Content',
    tab_entities: 'Entities',
    result_types: 'Result types',
    matched_in: 'Matched in',
    view: 'View',
    view_list: 'List',
    view_gallery: 'Gallery',
    view_map: 'Map',
    map_label: 'Map of places',
    map_loading: 'Loading the map…',
    map_error: 'The map could not be loaded.',
    histogram_unavailable: 'The year distribution is unavailable.',
    retry_search: 'Retry',
    search_failed_hint: 'The search could not be completed. Try again in a moment.',
    map_empty: 'No geo-located places in these results.',
    map_capped: 'Map limited to the {n} most-mentioned places.',
    copy_link: 'Copy link',
    link_copied: 'Link copied!',
    did_you_mean: 'Did you mean:',
    show_semantic_one: 'Show {n} semantically related item',
    show_semantic_other: 'Show {n} semantically related items',
    semantic_only_banner: 'No exact matches for “{q}” — showing semantically related items.',
    hide_semantic: 'Hide these results',
    semantic_result_one: 'semantically related item',
    semantic_result_other: 'semantically related items',
    recent_searches: 'Recent searches',
    clear_history: 'Clear history',
    tab_all: 'All',
    results_in_scope: 'in',
    sorted_by: 'sorted by',
    no_results_in_scope: 'No results in',
    union_cap_hint:
      'End of the combined results. Refine your search, or open a tab to page through a whole collection.',
    loading_results: 'Loading results…',
    mentions_trend: 'Mentions over time',
    export: 'Export',
    exporting: 'Exporting…',
    export_results: 'Export the results',
    export_txt: 'Text (.txt)',
    export_json: 'JSON (.json)',
    export_ris: 'RIS — Zotero / EndNote (.ris)',
    export_bibtex: 'BibTeX (.bib)',
    export_limit: 'Limited to the first {n} results',
    export_failed: 'Export failed: {message}',
    duration: 'Duration',
    watch_on_youtube: 'Watch on YouTube',
    view_source: 'View the source',
    ai_generated: 'AI-generated content — verify against the original source.',
    ai_generated_title: 'Generated by an AI model. Verify against the original source.',
    skip_to_results: 'Skip to results',
    results_heading: 'Results',
    announce_results_one: '{n} result found.',
    announce_results_other: '{n} results found.',
    announce_no_results: 'No results.',
    announce_page: 'Page {p} of {total}.',
    announce_semantic_one: 'No exact matches. {n} semantically related item can be shown.',
    announce_semantic_other: 'No exact matches. {n} semantically related items can be shown.',
    announce_semantic_shown_one: 'Showing {n} semantically related item.',
    announce_semantic_shown_other: 'Showing {n} semantically related items.',
  },
};

/**
 * The strings the site-wide header typeahead renders (header.ts).
 *
 * Kept OUT of STRINGS on purpose. The header bundle loads on every public
 * page, and importing `translate()` pulled the whole app table — both
 * locales, ~270 keys — into it for the sake of three strings. The bundler can
 * drop STRINGS from the header only if nothing it imports reads STRINGS, so
 * `translateSuggest()` reads this table alone; `translate()` falls back to it,
 * so the app's `t('no_matches')` etc. are unchanged. Parity is checked like
 * every other table (npm run lint:i18n).
 */
const SUGGEST_STRINGS: Record<Locale, Record<string, string>> = {
  fr: {
    no_matches: 'Aucune correspondance.',
    suggestions: 'Suggestions',
    search_for: 'Rechercher « {q} »',
  },
  en: {
    no_matches: 'No matches.',
    suggestions: 'Suggestions',
    search_for: 'Search for “{q}”',
  },
};

function interpolate(s: string, vars?: Record<string, string | number>): string {
  if (vars) {
    for (const [k, v] of Object.entries(vars)) {
      s = s.replaceAll(`{${k}}`, String(v));
    }
  }
  return s;
}

/** The header typeahead's translator — see SUGGEST_STRINGS. */
export function translateSuggest(
  locale: Locale,
  key: string,
  vars?: Record<string, string | number>,
): string {
  const table = SUGGEST_STRINGS[locale] ?? SUGGEST_STRINGS.fr;
  return interpolate(table[key] ?? SUGGEST_STRINGS.fr[key] ?? key, vars);
}

export function translate(
  locale: Locale,
  key: string,
  vars?: Record<string, string | number>,
): string {
  const table = STRINGS[locale] ?? STRINGS.fr;
  const s = table[key] ?? STRINGS.fr[key];
  return s === undefined ? translateSuggest(locale, key, vars) : interpolate(s, vars);
}

// ── Numbers and plurals ───────────────────────────────────────────────

/** U+202F NARROW NO-BREAK SPACE — the thousands separator in every locale. */
const GROUP_SEPARATOR = ' ';

/** Default (no-options) formatters, one per locale — built once, used per facet row. */
const DEFAULT_NUMBER_FORMATS: Partial<Record<Locale, Intl.NumberFormat>> = {};

/**
 * Format a number for display in the PAGE's locale — never the browser's.
 *
 * The rule is the stack's (IWAC-theme docs/DESIGN-SYSTEM.md): thousands are
 * grouped with U+202F in every locale, "never a comma a French reader would
 * take for a decimal mark"; the decimal mark follows the page (fr `12,5`, en
 * `12.5`); a French percent keeps its U+202F before the sign (`12,5 %`), an
 * English one does not (`12.5%`). Before this, the module called a bare
 * `toLocaleString()` in thirteen places, so the English site read "20,944" on
 * one surface and "6 879" on the next, and the French site in an English
 * browser read "1,234 résultats".
 *
 * Years are not numbers in this sense — render them as plain strings, or
 * 1989 becomes "1 989".
 */
export function formatNumber(
  value: number,
  locale: Locale,
  options?: Intl.NumberFormatOptions,
): string {
  const format = options
    ? new Intl.NumberFormat(locale, options)
    : (DEFAULT_NUMBER_FORMATS[locale] ??= new Intl.NumberFormat(locale));
  // A one-space literal is French typography's space before `%` (or a compact
  // suffix): engines disagree on NBSP vs U+202F there, so it is normalised too.
  return format
    .formatToParts(value)
    .map((part) =>
      part.type === 'group' || (part.type === 'literal' && /^\s$/u.test(part.value))
        ? GROUP_SEPARATOR
        : part.value,
    )
    .join('');
}

const PLURAL_RULES: Partial<Record<Locale, Intl.PluralRules>> = {};

/** Does `locale`'s (or the French fallback) table define `key`? */
function hasString(locale: Locale, key: string): boolean {
  return (STRINGS[locale] ?? STRINGS.fr)[key] !== undefined || STRINGS.fr[key] !== undefined;
}

/**
 * The key for `count` under `base`: `${base}_${category}` when the tables
 * define that category, else `${base}_other`. See {@link TranslatePlural}.
 */
export function pluralKey(locale: Locale, base: string, count: number): string {
  const rules = (PLURAL_RULES[locale] ??= new Intl.PluralRules(locale));
  const key = `${base}_${rules.select(count)}`;
  return hasString(locale, key) ? key : `${base}_other`;
}

/** Everything a surface needs to speak one locale. */
export interface I18n {
  locale: Locale;
  t: Translate;
  tp: TranslatePlural;
  formatNumber: FormatNumber;
}

/** Bind the translators and the number formatter to one locale. Pure — no context. */
export function createI18n(locale: Locale): I18n {
  const formatFor: FormatNumber = (value, options) => formatNumber(value, locale, options);
  return {
    locale,
    t: (key, vars) => translate(locale, key, vars),
    tp: (base, count, vars) =>
      translate(locale, pluralKey(locale, base, count), { n: formatFor(count), ...vars }),
    formatNumber: formatFor,
  };
}

// ── Facet field labels ─────────────────────────────────────────────────

const FACET_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    country_ss: 'Pays',
    newspaper_ss: 'Journal',
    channel_ss: 'Chaîne / Producteur',
    media_kind_s: 'Nature du média',
    media_platform_s: 'Format / Plateforme',
    rights_s: 'Droits',
    language_ss: 'Langue',
    topics_ss: 'Thème',
    persons_ss: 'Personne',
    places_ss: 'Lieu',
    organisations_ss: 'Organisation',
    events_ss: 'Événement',
    subjects_ss: 'Sujet',
    date_decade_ss: 'Décennie',
    pub_year: 'Année',
    type_s: 'Type',
    entity_type_s: 'Type',
    reference_type_ss: 'Type de référence',
    creator_ss: 'Auteur',
    publisher_s: 'Revue / Éditeur',
    book_title_s: 'Titre du livre',
    has_fulltext: 'Texte intégral',
    is_part_of_ss: 'Catégorie',
    alt_title_txt: 'Titre alternatif',
    toc_txt: 'Table des matières',
    entity_aliases_txt: 'Autre dénomination',
    // Sentiment fields are keyed by the annotating model (see
    // data/schema.yaml). The surfaced trio keeps a bare label — the panel
    // groups it under "Sentiment" and only one model is offered, so naming
    // it in every heading would be noise. The other four are labelled
    // because their only reason to appear (a filter chip from a hand-built
    // link) is which model said it. check-schema-drift.js requires a label
    // here for every sentiment field the schema declares.
    gpt_5_6_luna_polarite_ss: 'Polarité',
    gpt_5_6_luna_centralite_ss: 'Centralité',
    gpt_5_6_luna_subjectivite: 'Subjectivité',
    mistral_small_2603_polarite_ss: 'Polarité (Mistral Small 2603)',
    mistral_small_2603_centralite_ss: 'Centralité (Mistral Small 2603)',
    mistral_small_2603_subjectivite: 'Subjectivité (Mistral Small 2603)',
    deepseek_v4_flash_0731_polarite_ss: 'Polarité (DeepSeek V4 Flash 0731)',
    deepseek_v4_flash_0731_centralite_ss: 'Centralité (DeepSeek V4 Flash 0731)',
    deepseek_v4_flash_0731_subjectivite: 'Subjectivité (DeepSeek V4 Flash 0731)',
    gemma_4_31b_it_polarite_ss: 'Polarité (Gemma 4 31B)',
    gemma_4_31b_it_centralite_ss: 'Centralité (Gemma 4 31B)',
    gemma_4_31b_it_subjectivite: 'Subjectivité (Gemma 4 31B)',
    qwen3_8_27b_polarite_ss: 'Polarité (Qwen3.8 27B)',
    qwen3_8_27b_centralite_ss: 'Centralité (Qwen3.8 27B)',
    qwen3_8_27b_subjectivite: 'Subjectivité (Qwen3.8 27B)',
  },
  en: {
    country_ss: 'Country',
    newspaper_ss: 'Newspaper',
    channel_ss: 'Channel / Producer',
    media_kind_s: 'Media kind',
    media_platform_s: 'Format / Platform',
    rights_s: 'Rights',
    language_ss: 'Language',
    topics_ss: 'Topic',
    persons_ss: 'Person',
    places_ss: 'Place',
    organisations_ss: 'Organisation',
    events_ss: 'Event',
    subjects_ss: 'Subject',
    date_decade_ss: 'Decade',
    pub_year: 'Year',
    type_s: 'Type',
    entity_type_s: 'Type',
    reference_type_ss: 'Reference type',
    creator_ss: 'Author',
    publisher_s: 'Journal / Publisher',
    book_title_s: 'Book title',
    has_fulltext: 'Full text',
    is_part_of_ss: 'Category',
    alt_title_txt: 'Alternative title',
    toc_txt: 'Table of contents',
    entity_aliases_txt: 'Also known as',
    gpt_5_6_luna_polarite_ss: 'Polarity',
    gpt_5_6_luna_centralite_ss: 'Centrality',
    gpt_5_6_luna_subjectivite: 'Subjectivity',
    mistral_small_2603_polarite_ss: 'Polarity (Mistral Small 2603)',
    mistral_small_2603_centralite_ss: 'Centrality (Mistral Small 2603)',
    mistral_small_2603_subjectivite: 'Subjectivity (Mistral Small 2603)',
    deepseek_v4_flash_0731_polarite_ss: 'Polarity (DeepSeek V4 Flash 0731)',
    deepseek_v4_flash_0731_centralite_ss: 'Centrality (DeepSeek V4 Flash 0731)',
    deepseek_v4_flash_0731_subjectivite: 'Subjectivity (DeepSeek V4 Flash 0731)',
    gemma_4_31b_it_polarite_ss: 'Polarity (Gemma 4 31B)',
    gemma_4_31b_it_centralite_ss: 'Centrality (Gemma 4 31B)',
    gemma_4_31b_it_subjectivite: 'Subjectivity (Gemma 4 31B)',
    qwen3_8_27b_polarite_ss: 'Polarity (Qwen3.8 27B)',
    qwen3_8_27b_centralite_ss: 'Centrality (Qwen3.8 27B)',
    qwen3_8_27b_subjectivite: 'Subjectivity (Qwen3.8 27B)',
  },
};

export function facetLabel(field: string, locale: Locale): string {
  return FACET_LABELS[locale]?.[field] ?? FACET_LABELS.fr[field] ?? humanise(field);
}

/**
 * Sentiment sub-facets render under one collapsible "Sentiment" group.
 * Keyed by the annotating model, mirroring data/schema.yaml and the Hugging
 * Face dataset's column names — all five models, three readings each;
 * check-schema-drift.js holds this set to the schema.
 */
export const SENTIMENT_FIELDS: ReadonlySet<string> = new Set([
  'gpt_5_6_luna_polarite_ss',
  'gpt_5_6_luna_centralite_ss',
  'gpt_5_6_luna_subjectivite',
  'mistral_small_2603_polarite_ss',
  'mistral_small_2603_centralite_ss',
  'mistral_small_2603_subjectivite',
  'deepseek_v4_flash_0731_polarite_ss',
  'deepseek_v4_flash_0731_centralite_ss',
  'deepseek_v4_flash_0731_subjectivite',
  'gemma_4_31b_it_polarite_ss',
  'gemma_4_31b_it_centralite_ss',
  'gemma_4_31b_it_subjectivite',
  'qwen3_8_27b_polarite_ss',
  'qwen3_8_27b_centralite_ss',
  'qwen3_8_27b_subjectivite',
]);

/**
 * Numeric facet fields. Their filter_by values must NOT be backtick-quoted
 * (Typesense rejects a backticked number with "Numerical field has an
 * invalid comparator"); they're emitted as a bare numeric array instead.
 * A float field missing here 400s every search filtered on it, so
 * check-schema-drift.js requires every schema `_subjectivite` field.
 */
export const NUMERIC_FACET_FIELDS: ReadonlySet<string> = new Set([
  'gpt_5_6_luna_subjectivite',
  'mistral_small_2603_subjectivite',
  'deepseek_v4_flash_0731_subjectivite',
  'gemma_4_31b_it_subjectivite',
  'qwen3_8_27b_subjectivite',
  'pub_year',
]);

/**
 * Boolean facet fields. Like numerics, their filter_by values must be bare
 * (`has_fulltext:=[true]`, never backticked) or Typesense rejects the
 * filter. Facet counts come back with the string values "true"/"false",
 * which facetValueLabel maps to readable labels.
 */
export const BOOLEAN_FACET_FIELDS: ReadonlySet<string> = new Set(['has_fulltext']);

const BOOLEAN_VALUE_LABELS: Record<Locale, Record<string, string>> = {
  fr: { true: 'Disponible', false: 'Non disponible' },
  en: { true: 'Available', false: 'Not available' },
};

// ── type_s value labels ────────────────────────────────────────────────
// The type_s enum is an internal discriminator (article|publication|…),
// not source data, so we fully control its display labels per locale.
// `audiovisual` covers BOTH populations of class 38 — the deposited DVD/CD
// recordings and the videos ingested from public YouTube channels; the
// media_platform_s facet is what separates them.

const TYPE_LABELS: Record<Locale, Record<string, string>> = {
  fr: {
    article: 'Article de presse',
    publication: 'Publication islamique',
    document: 'Document',
    audiovisual: 'Audiovisuel',
    photograph: 'Photographie',
    reference: 'Référence',
  },
  en: {
    article: 'News article',
    publication: 'Islamic publication',
    document: 'Document',
    audiovisual: 'Audiovisual',
    photograph: 'Photograph',
    reference: 'Reference',
  },
};

export function typeLabel(value: string, locale: Locale): string {
  return TYPE_LABELS[locale]?.[value] ?? TYPE_LABELS.fr[value] ?? '';
}

// ── media_kind_s / media_platform_s value labels ───────────────────────
// Both are internal enums the indexer normalises the French `dcterms:type` /
// `dcterms:medium` headings into (AbstractMapper::MEDIA_KINDS /
// MEDIA_PLATFORMS), so — like type_s — we own their display text per locale
// and a share link never carries a localised string. `web` is the honest
// label for a web video whose host we don't recognise; every YouTube record
// resolves to `youtube` instead.

const MEDIA_KIND_LABELS: Record<Locale, Record<string, string>> = {
  fr: { video: 'Vidéo', audio: 'Audio' },
  en: { video: 'Video', audio: 'Audio' },
};

const MEDIA_PLATFORM_LABELS: Record<Locale, Record<string, string>> = {
  fr: { youtube: 'YouTube', web: 'Vidéo en ligne', dvd: 'DVD', cd: 'CD' },
  en: { youtube: 'YouTube', web: 'Web video', dvd: 'DVD', cd: 'CD' },
};

export function mediaKindLabel(value: string, locale: Locale): string {
  return MEDIA_KIND_LABELS[locale]?.[value] ?? MEDIA_KIND_LABELS.fr[value] ?? value;
}

export function mediaPlatformLabel(value: string, locale: Locale): string {
  return MEDIA_PLATFORM_LABELS[locale]?.[value] ?? MEDIA_PLATFORM_LABELS.fr[value] ?? value;
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
// Every *_subjectivite field (one per model) is a 1–5 float facet. Typesense
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
 * labels the field). Remapped fields: the type_s discriminator and the
 * entity_type_s data values get their locale labels (the filter list must
 * read "Publication islamique", not the raw "publication"), booleans get
 * Available/Not available, the subjectivity scale gets its 1–5 words, and
 * country spellings are normalised. Falls back to the raw value for
 * unknown fields/values, so the underlying filter value the caller
 * toggles on is never altered.
 */
export function facetValueLabel(field: string, value: string, locale: Locale): string {
  if (field === 'type_s') {
    return typeLabel(value, locale) || value;
  }
  if (field === 'entity_type_s') {
    return entityTypeLabel(value, locale);
  }
  if (BOOLEAN_FACET_FIELDS.has(field)) {
    return BOOLEAN_VALUE_LABELS[locale]?.[value] ?? BOOLEAN_VALUE_LABELS.fr[value] ?? value;
  }
  if (field === 'country_ss') {
    return countryLabel(value, locale);
  }
  if (field === 'media_kind_s') {
    return mediaKindLabel(value, locale);
  }
  if (field === 'media_platform_s') {
    return mediaPlatformLabel(value, locale);
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
      // "Most authored" — the question frequency:desc cannot answer, since it
      // ranks by every role at once. Entities that signed nothing sort to the
      // bottom on a 0, which is what the field stores for them.
      { value: 'authored_count:desc', label: translate(locale, 'sort_most_authored') },
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
 * Every sort value ANY surface offers, across both card vocabularies.
 *
 * Derived from sortOptions() rather than listed, so a new sort order stays a
 * one-line change there — a hand-maintained twin is the thing that drifts. The
 * values are locale-independent (only the labels are translated), so reading
 * one locale is enough.
 *
 * This is the allowlist `?sort=` is validated against on decode: the param
 * goes straight into a Typesense `sort_by`, where an unknown field is a 422
 * that error-states the whole surface. Every other URL param was already
 * clamped or allowlisted (see urlState.ts); sort was the one hole.
 */
export const SORT_VALUES: ReadonlySet<string> = /* @__PURE__ */ collectSortValues();

/**
 * Built through a PURE-annotated call so a bundle that never reads
 * SORT_VALUES can drop it. Written inline, the top-level `new Set([...
 * sortOptions()])` was a call the bundler had to assume had side effects, so
 * the site-wide header typeahead — which sorts nothing — carried
 * sortOptions(), translate() and the whole STRINGS table on every page.
 */
function collectSortValues(): ReadonlySet<string> {
  return new Set([
    ...sortOptions('fr', 'content').map((o) => o.value),
    ...sortOptions('fr', 'entity').map((o) => o.value),
  ]);
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

interface I18nContext extends I18n {
  card: CardKind;
}

const I18N_KEY = Symbol('iwac-i18n');

/** Call once in App.svelte during init. */
export function provideI18n(locale: Locale, card: CardKind = 'content'): I18nContext {
  const ctx: I18nContext = { ...createI18n(locale), card };
  setContext(I18N_KEY, ctx);
  return ctx;
}

/** Read in any descendant component during init. Falls back to French content. */
export function useI18n(): I18nContext {
  return getContext<I18nContext | undefined>(I18N_KEY) ?? { ...createI18n('fr'), card: 'content' };
}

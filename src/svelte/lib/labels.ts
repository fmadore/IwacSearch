/**
 * Schema field name → display label.
 *
 * Kept in TypeScript instead of pulling from a translation file so the
 * Svelte bundle has no runtime i18n dependency. M5 will swap to Omeka's
 * translate() helper if/when we localise the UI for the English site.
 *
 * Single source of truth — used by FacetPanel, SortSelect, and any
 * "active filter chip" UI.
 */

const FACET_LABELS: Record<string, string> = {
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
  lda_topic_label: 'LDA topic',
  creator_ss: 'Author',
  gemini_polarite_ss: 'Sentiment (Gemini)',
  gemini_centralite_ss: 'Centrality (Gemini)',
  chatgpt_polarite_ss: 'Sentiment (ChatGPT)',
  chatgpt_centralite_ss: 'Centrality (ChatGPT)',
  mistral_polarite_ss: 'Sentiment (Mistral)',
  mistral_centralite_ss: 'Centrality (Mistral)',
};

export function facetLabel(field: string): string {
  return FACET_LABELS[field] ?? humanise(field);
}

/**
 * Fallback for facets we haven't explicitly labelled — strip the
 * suffix and title-case the rest so e.g. `regional_ss` reads as "Regional".
 */
function humanise(field: string): string {
  const stripped = field.replace(/_(ss|s|txt|dt)$/, '').replace(/_/g, ' ');
  return stripped.charAt(0).toUpperCase() + stripped.slice(1);
}

import type { EntitySuggestion, IwacBootstrap, IwacSearchResponse, SuggestResult } from './types';
import { getScopedKey } from './scopedKey';
import { type MultiSearchEnvelope, postJson, validateSearchResult } from './transport';

/**
 * The typeahead/suggest request, as a free function over a bootstrap.
 *
 * Split out of TypesenseClient so the site-wide header enhancer can run a
 * suggest without pulling the whole search client onto every public page:
 * `header.ts` needs `suggest()` and nothing else, but a class method can't be
 * tree-shaken, so importing the client shipped export / map / union /
 * histogram / facet-value code with it.
 *
 * TypesenseClient.suggest() delegates here, so both callers share one
 * implementation — the tuning below IS the contract, and having the in-app
 * dropdown and the header box disagree about it would be its own bug.
 */

/**
 * Facet fields the typeahead may prefix-match via `facet_query`. The list a
 * surface actually queries is its prominent_facets ∩ this set, so the
 * suggestions follow the scope: a country block suggests places / topics /
 * persons / organisations, while the references scope suggests authors,
 * journals/publishers and merged subjects — without any extra config.
 */
const SUGGESTABLE_FACET_FIELDS: ReadonlySet<string> = new Set([
  'places_ss',
  'topics_ss',
  'persons_ss',
  'organisations_ss',
  'events_ss',
  'subjects_ss',
  'creator_ss',
  'publisher_s',
  'book_title_s',
  'newspaper_ss',
]);

/** Fallback for surfaces without prominent facets (e.g. the header box). */
const DEFAULT_SUGGEST_FACET_FIELDS = [
  'places_ss',
  'topics_ss',
  'persons_ss',
  'organisations_ss',
] as const;

/** Cap on facet_query sub-searches per keystroke. */
const MAX_SUGGEST_FACET_FIELDS = 5;

/** Minimum prefix length before a suggest is worth a network call. */
const MIN_SUGGEST_CHARS = 2;

/** Entity rows returned to the dropdown. */
const MAX_SUGGEST_ENTITIES = 6;

/**
 * Entity-index `entity_type_s` → the content facet field a suggestion
 * picked from the index collection should toggle. "Notices d'autorité"
 * are deliberately absent (they never feed content facets).
 */
const ENTITY_TYPE_FACET: Record<string, string> = {
  Personnes: 'persons_ss',
  Lieux: 'places_ss',
  Organisations: 'organisations_ss',
  Événements: 'events_ss',
  Sujets: 'topics_ss',
};

/**
 * Run a typeahead/suggest query for a short prefix string.
 *
 * Tuned differently from the main search:
 *   - `prefix=true` so each query token does prefix matching (Typesense
 *     default, but explicit here in case future versions change).
 *   - `query_by` is narrower (title + entity_aliases) — OCR fulltext
 *     prefix-matching produces too much noise for a dropdown.
 *   - per_page is small (default 6) and we ignore facets — the whole
 *     point is one cheap call per keystroke.
 *   - The same scoped key + same locked_filters apply, so suggestions
 *     respect the surface's curatorial scope (a country block's
 *     suggestion never leaks docs from another country).
 *
 * Returns up to `perPage` hits with `title_txt` highlighting, ready to
 * render in a dropdown. Empty / very short prefixes resolve to an empty
 * response without hitting the network — saves the cheapest fetch.
 *
 * Errors are translated to a thrown Error like search() — the caller
 * decides whether to surface them or swallow. A superseded call (via the
 * passed `signal`) rejects with an AbortError; see transport.isAbortError.
 */
export async function runSuggest(
  bootstrap: IwacBootstrap,
  prefix: string,
  perPage = 6,
  signal?: AbortSignal,
): Promise<SuggestResult> {
  const trimmed = prefix.trim();
  if (trimmed.length < MIN_SUGGEST_CHARS) {
    return { articles: [], entities: [] };
  }

  const key = await getScopedKey(bootstrap.endpoints.token);
  const collection = bootstrap.collection_alias ?? key.collection;
  const filterBy = bootstrap.locked_filters?.trim() || undefined;
  const isEntitySurface = bootstrap.card === 'entity';

  // Facet fields this surface suggests from: its prominent facets, kept to
  // the suggestable string facets. A country block gets places / topics /
  // persons / organisations; the references scope gets authors, journals/
  // publishers and merged subjects. Surfaces without prominent facets
  // (header box) fall back to the entity quartet. Entity-index surfaces
  // skip facet suggestions entirely — their facets (entity_type_s,
  // is_part_of_ss) make poor typeahead rows.
  const prominent = (bootstrap.prominent_facets ?? []).filter((f) =>
    SUGGESTABLE_FACET_FIELDS.has(f),
  );
  const facetFields = isEntitySurface
    ? []
    : (prominent.length > 0 ? prominent : [...DEFAULT_SUGGEST_FACET_FIELDS]).slice(
        0,
        MAX_SUGGEST_FACET_FIELDS,
      );

  // One multi_search request bundles:
  //   [0]    article title hits (title + alternative titles + aliases)
  //   [1…n]  one facet_query per suggestable facet field
  //   [last] the entity INDEX collection, queried by name + alias — this is
  //          what reconciles alternative spellings: typing "RCI" surfaces
  //          "Radio Côte d'Ivoire" even though no facet VALUE contains
  //          "RCI". Mapped back to a content facet via entity_type_s.
  // locked_filters apply to the content sub-searches so suggestions on a
  // country block stay scoped; the index sub-search runs unfiltered (its
  // schema lacks the content fields a locked filter may reference).
  const titleSearch = {
    collection,
    q: trimmed,
    // Narrower than the main search query_by — dropdown should surface
    // clear title hits, not fuzzy OCR matches that wouldn't make sense
    // out of context. alt_title_txt reconciles variant titles.
    query_by: isEntitySurface
      ? 'title_txt,entity_aliases_txt'
      : 'title_txt,alt_title_txt,entity_aliases_txt',
    prefix: true,
    filter_by: filterBy,
    // Newest first feels right for a typeahead (recent docs surface
    // before older ones even when match scores are similar).
    sort_by: '_text_match:desc,date:desc',
    page: 1,
    per_page: Math.max(1, Math.min(15, perPage)),
    highlight_fields: 'title_txt',
    highlight_full_fields: 'title_txt',
    // No snippet — keeps the response tiny and the dropdown render fast.
    exclude_fields: 'ocr_text,embedding',
    limit_hits: 50,
  };

  const entitySearches = facetFields.map((field) => ({
    collection,
    q: '*',
    query_by: 'title_txt',
    filter_by: filterBy,
    facet_by: field,
    // Prefix/substring-match facet values against the typed text.
    facet_query: `${field}:${trimmed}`,
    max_facet_values: 4,
    per_page: 0,
  }));

  const indexAlias = !isEntitySurface ? bootstrap.index_collection_alias : undefined;
  const indexSearch = indexAlias
    ? [
        {
          collection: indexAlias,
          q: trimmed,
          query_by: 'title_txt,entity_aliases_txt',
          prefix: true,
          sort_by: '_text_match:desc,frequency:desc',
          page: 1,
          per_page: 4,
          include_fields: 'title,entity_type_s,frequency',
          highlight_fields: 'title_txt',
        },
      ]
    : [];

  const json = await postJson<MultiSearchEnvelope>(
    bootstrap.endpoints.search,
    key.key,
    { searches: [titleSearch, ...entitySearches, ...indexSearch] },
    'Suggest',
    signal,
  );

  const articles = validateSearchResult('Suggest', json.results?.[0]).hits;

  // Collect matching facet values across the facet sub-searches.
  const entities: EntitySuggestion[] = [];
  facetFields.forEach((field, i) => {
    const r = json.results?.[i + 1];
    if (!r || 'error' in r) return;
    const fc = (r as IwacSearchResponse).facet_counts?.find((f) => f.field_name === field);
    for (const c of fc?.counts ?? []) {
      if (c.value) entities.push({ field, value: c.value, count: c.count });
    }
  });
  // Highest-coverage entities first.
  entities.sort((a, b) => b.count - a.count);

  // Index-collection hits — alias-reconciled entities the facet_query
  // pass can't see. Appended after the scope-accurate facet matches,
  // deduped on (field, value).
  if (indexAlias) {
    const r = json.results?.[1 + facetFields.length];
    if (r && !('error' in r)) {
      const seen = new Set(entities.map((e) => `${e.field}|${e.value}`));
      for (const hit of (r as IwacSearchResponse).hits ?? []) {
        const doc = hit.document;
        const field = doc.entity_type_s ? ENTITY_TYPE_FACET[doc.entity_type_s] : undefined;
        const value = (doc.title ?? '').trim();
        if (!field || !value || seen.has(`${field}|${value}`)) continue;
        seen.add(`${field}|${value}`);
        entities.push({ field, value, count: doc.frequency ?? 0 });
      }
    }
  }

  return { articles, entities: entities.slice(0, MAX_SUGGEST_ENTITIES) };
}

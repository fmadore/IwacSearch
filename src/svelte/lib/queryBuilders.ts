import type { ActiveFilters, YearRange } from './types';
import { BOOLEAN_FACET_FIELDS, NUMERIC_FACET_FIELDS } from './i18n';

/**
 * Pure builders for Typesense query parameters — extracted from
 * TypesenseClient, where four methods (search / yearDistribution /
 * searchFacetValues / fetchForExport) had each grown its own copy of the
 * filter / sort / exact-mode logic. No fetch, no state: everything here is
 * a string-in/string-out function the client methods compose.
 */

/** Default content query_by — mirrors SearchDefaults::CONTENT_QUERY_BY. */
export const CONTENT_QUERY_BY_FALLBACK =
  'title_txt,alt_title_txt,ocr_text,toc_txt,abstract,' +
  'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt,embedding';
export const CONTENT_HIGHLIGHT_FALLBACK =
  'title_txt,alt_title_txt,ocr_text,toc_txt,abstract,' +
  'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt';

/**
 * Build a Typesense `filter_by` string from the active facet selections.
 *
 *   { country_ss: ['Burkina Faso', 'Niger'], newspaper_ss: ['Sidwaya'] }
 *     →  country_ss:=[`Burkina Faso`,`Niger`] && newspaper_ss:=[`Sidwaya`]
 *
 * Backticks around values escape spaces and most punctuation. We
 * defensively strip backticks from values themselves (no IWAC entity
 * name contains one, but better safe than sorry).
 */
export function buildFilterBy(filters: ActiveFilters): string {
  const parts: string[] = [];
  for (const [field, values] of Object.entries(filters)) {
    if (!values || values.length === 0) continue;
    if (NUMERIC_FACET_FIELDS.has(field)) {
      // Numeric exact-match-any: bare numbers, NO backticks. Typesense
      // rejects a backticked number ("Numerical field has an invalid
      // comparator"), so e.g. gpt_5_6_luna_subjectivite:=[1,2] — never
      // [`1`,`2`].
      const nums = values.filter((v) => v.trim() !== '' && Number.isFinite(Number(v)));
      if (nums.length === 0) continue;
      parts.push(`${field}:=[${nums.join(',')}]`);
    } else if (BOOLEAN_FACET_FIELDS.has(field)) {
      // Booleans are bare like numerics: has_fulltext:=[true] — never
      // backticked. Anything other than true/false is dropped.
      const bools = values.filter((v) => v === 'true' || v === 'false');
      if (bools.length === 0) continue;
      parts.push(`${field}:=[${bools.join(',')}]`);
    } else {
      const escaped = values.map((v) => v.replaceAll('`', '')).map((v) => `\`${v}\``);
      parts.push(`${field}:=[${escaped.join(',')}]`);
    }
  }
  return parts.join(' && ');
}

/**
 * Build the pub_year range filter clause. Returns "" when no bounds.
 *   { from: 1990, to: 2010 }  →  pub_year:>=1990 && pub_year:<=2010
 *   { from: 1990 }            →  pub_year:>=1990
 *   { to: 2010 }              →  pub_year:<=2010
 */
export function buildYearRangeFilter(range: YearRange | null): string {
  if (!range) return '';
  const parts: string[] = [];
  if (typeof range.from === 'number' && Number.isFinite(range.from)) {
    parts.push(`pub_year:>=${Math.trunc(range.from)}`);
  }
  if (typeof range.to === 'number' && Number.isFinite(range.to)) {
    parts.push(`pub_year:<=${Math.trunc(range.to)}`);
  }
  return parts.join(' && ');
}

/** AND-join the non-empty filter clauses. */
export function combineFilters(...parts: Array<string | undefined | null>): string {
  return parts
    .map((p) => p?.trim())
    .filter((p): p is string => !!p)
    .join(' && ');
}

/**
 * Does the query carry an exact-match operator — a "quoted phrase" or a
 * -excluded term? Such queries switch to strict keyword matching (see the
 * exact-mode handling in TypesenseClient.search()), so Typesense's operators
 * behave literally instead of being softened by hybrid/semantic ranking and
 * typo tolerance.
 *
 * Typesense `q` syntax supports phrases (`"…"`) and exclusion (`-term`) only;
 * it has no free-text AND/OR — set logic lives in the facet filters
 * (filter_by). So this intentionally detects just those two operators.
 */
export function isExactQuery(q: string): boolean {
  if (q.includes('"')) return true;
  // A '-' that starts a term (string start or after whitespace) is an
  // exclusion operator; a hyphen inside a word (e.g. "Faso-Dan") is not.
  return /(^|\s)-\S/.test(q);
}

/**
 * Drop one comma-separated entry from a query_by / field list, trimming
 * whitespace. Used to strip `embedding` from query_by for exact queries so
 * the search runs pure-keyword (no semantic vector blending).
 */
export function withoutField(list: string, field: string): string {
  return list
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '' && s !== field)
    .join(',');
}

/** Typo/typing-fuzz parameters switched OFF for an exact query. */
export const EXACT_MODE_PARAMS = {
  num_typos: 0,
  typo_tokens_threshold: 0,
  drop_tokens_threshold: 0,
} as const;

/**
 * Resolve the effective sort_by:
 *
 *   - Relevance sort is meaningless in browse mode (q=*), so it falls back
 *     to date:desc unless the surface configured its own default.
 *   - creator_sort is an optional field (only docs with an author have it);
 *     Typesense needs an explicit missing-values rule for optional sort
 *     fields, or it errors. Push author-less docs (e.g. unsigned press) to
 *     the end. Done here, not in the sort-option value, so the URL and the
 *     dropdown stay clean (`creator_sort:asc`).
 */
export function resolveSortBy(
  requested: string | undefined,
  isBrowse: boolean,
  defaultSort: string | undefined,
): string {
  const sortBy =
    requested && requested !== '_text_match:desc'
      ? requested
      : isBrowse
        ? 'date:desc'
        : (defaultSort ?? '_text_match:desc');
  return sortBy.startsWith('creator_sort:')
    ? sortBy.replace('creator_sort:', 'creator_sort(missing_values:last):')
    : sortBy;
}

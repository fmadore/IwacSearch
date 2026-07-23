import type { EntitySuggestion, IwacHit } from './types';
import { escapeHtml, sanitizeHighlight } from './sanitize';

/**
 * Shared typeahead row model + markup helpers — ONE definition for the two
 * suggest surfaces (the in-app SuggestDropdown component and the
 * framework-free site-wide header enhancer in header.ts), which had grown
 * parallel copies of the same row building, sanitising and activation
 * logic.
 */

/** One keyboard-navigable dropdown row, in display order. */
export type SuggestRow =
  | { kind: 'search'; query: string }
  | { kind: 'history'; query: string }
  | { kind: 'article'; hit: IwacHit }
  | { kind: 'entity'; entity: EntitySuggestion };

/** Stable identity for {#each} keys / DOM ids. */
export function rowKey(row: SuggestRow): string {
  switch (row.kind) {
    case 'search':
      return 'search';
    case 'history':
      return `history:${row.query}`;
    case 'article':
      return `article:${row.hit.document.id}`;
    case 'entity':
      return `entity:${row.entity.field}:${row.entity.value}`;
  }
}

/**
 * Build the ordered row list for a typed prefix: the "Search for …" action
 * first (Enter runs the search, not the first article — the old behaviour
 * the design brief flagged), then article hits, then entity suggestions.
 */
export function buildSuggestRows(
  query: string,
  articles: IwacHit[],
  entities: EntitySuggestion[],
): SuggestRow[] {
  const rows: SuggestRow[] = [{ kind: 'search', query }];
  for (const hit of articles) rows.push({ kind: 'article', hit });
  for (const entity of entities) rows.push({ kind: 'entity', entity });
  return rows;
}

/** Rows for an EMPTY focused box: recent searches (newest first). */
export function buildHistoryRows(history: string[]): SuggestRow[] {
  return history.map((query) => ({ kind: 'history', query }));
}

/** Marked-up title of a hit (full highlight → windowed snippet → escaped raw). */
export function titleMarkupOf(hit: IwacHit): string {
  // `highlights` is absent on browse (q=*) responses — never assume it.
  const titleHl = hit.highlights?.find((h) => h.field === 'title_txt');
  // `value` is the full marked-up title; `snippet` windows long titles.
  const markup = titleHl?.value ?? titleHl?.snippet;
  if (markup) {
    return sanitizeHighlight(markup);
  }
  return escapeHtml(hit.document.title ?? '');
}

/** Where an article row navigates: the item page, else the original source. */
export function urlOf(hit: IwacHit): string | null {
  return hit.document.omeka_url ?? hit.document.source_url ?? null;
}

/**
 * What activating a row should DO, as data — so each surface (Svelte
 * component, DOM-rendered header dropdown) maps it onto its own callbacks:
 *
 *   navigate    → follow url (article with a page)
 *   run-search  → commit `query` as a full-text search
 *   pick-query  → seed the input with `query` (article without a URL)
 *   pick-entity → apply field:value as a facet filter
 */
export type SuggestAction =
  | { type: 'navigate'; url: string }
  | { type: 'run-search'; query: string }
  | { type: 'pick-query'; query: string }
  | { type: 'pick-entity'; field: string; value: string };

export function actionOf(row: SuggestRow): SuggestAction {
  switch (row.kind) {
    case 'search':
    case 'history':
      return { type: 'run-search', query: row.query };
    case 'entity':
      return { type: 'pick-entity', field: row.entity.field, value: row.entity.value };
    case 'article': {
      const url = urlOf(row.hit);
      if (url) return { type: 'navigate', url };
      const title = (row.hit.document.title ?? '').trim();
      return title !== ''
        ? { type: 'pick-query', query: title }
        : { type: 'run-search', query: '' };
    }
  }
}

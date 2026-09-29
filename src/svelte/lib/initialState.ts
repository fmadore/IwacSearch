/**
 * Where a surface starts: the state it mounts with, and whether the server's
 * pre-rendered first page may stand in for the first fetch.
 *
 * Extracted from App.svelte so the pristine rule — which decides whether a
 * reader sees the SSR snapshot or what they actually asked for — is a tested
 * function rather than an expression inside a component.
 */
import type { IwacBootstrap, IwacSearchResponse, SearchState } from './types';
import { readUrlState } from './urlState';

export interface InitialStateOptions {
  /** Surfaces that sync hydrate from the URL; the others start empty. */
  syncUrl: boolean;
  urlPrefix: string;
  /** The surface's own default sort (preset or block admin), not the global fallback. */
  defaultSort: string;
  /** A federated parent's live query, when there is one. */
  sharedQuery?: string;
  /** Read only when syncing. */
  href?: string;
}

/**
 * The state a surface mounts with: from the URL on every surface that syncs
 * (standalone /search and full-mode page blocks, each under its own prefix),
 * otherwise the federated parent's query and any filter handed off from a
 * union-tab chip — both absent on page blocks, which start empty.
 */
export function initialSearchState(
  bootstrap: IwacBootstrap,
  opts: InitialStateOptions,
): SearchState {
  if (opts.syncUrl) {
    return readUrlState(opts.href ?? window.location.href, opts.urlPrefix, opts.defaultSort);
  }
  return {
    q: opts.sharedQuery ?? '',
    page: 1,
    sort: opts.defaultSort,
    filters: bootstrap.initial_filters ?? {},
    yearRange: null,
    perPage: null,
    view: null,
  };
}

/**
 * The SSR'd first page, when it may be adopted as the first response.
 *
 * The server inlines `initial_response` for curated browse pages, page blocks
 * with locked filters and the standalone /search shell, so the first frame
 * shows real content rather than a skeleton. But the snapshot is the surface's
 * DEFAULT first page, so it is adopted only for the pristine state: empty q,
 * page 1, the surface's default sort, no filters (URL or handed off), no year
 * range, default page size. Any URL-hydrated state (/search?q=ramadan,
 * ?sort=date:asc) fetches what the reader asked for instead — mounting with a
 * query would otherwise flash the whole corpus for a frame. The server skips
 * the SSR in that case too (SearchStateQuery.php).
 *
 * The `hits` array check rejects a malformed bootstrap (pre-0.2.5 SSR could
 * emit a per-search error envelope), which would otherwise crash ResultsList
 * on its first render.
 */
export function adoptableSnapshot(
  bootstrap: IwacBootstrap,
  initial: SearchState,
  defaultSort: string,
): IwacSearchResponse | null {
  const pristine =
    Object.keys(bootstrap.initial_filters ?? {}).length === 0 &&
    initial.q === '' &&
    initial.page === 1 &&
    initial.sort === defaultSort &&
    Object.keys(initial.filters).length === 0 &&
    initial.yearRange === null &&
    initial.perPage === null;
  if (!pristine) return null;
  const snapshot = bootstrap.initial_response;
  return snapshot && Array.isArray(snapshot.hits) ? snapshot : null;
}

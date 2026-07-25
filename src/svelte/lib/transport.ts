import type { IwacSearchResponse } from './types';

/**
 * HTTP + response-envelope plumbing for the Typesense client — extracted
 * from TypesenseClient so every method shares ONE fetch wrapper, one error
 * formatter, and one response validator instead of five hand-rolled copies.
 */

/**
 * Per-search error envelope Typesense embeds inside multi_search results.
 *
 * `multi_search` always returns HTTP 200 even when individual searches
 * fail — so a 422 ("Could not find a field named X" / bad filter syntax /
 * missing collection) shows up as `{code: 422, error: "..."}` inside
 * `results[i]` rather than as a non-2xx HTTP status.
 */
export interface TypesensePerSearchError {
  code: number;
  error: string;
}

/** The standard multi_search response wrapper (non-union mode). */
export interface MultiSearchEnvelope {
  results: Array<IwacSearchResponse | TypesensePerSearchError>;
}

/**
 * POST a JSON body and parse the JSON response, throwing a formatted error
 * on a non-2xx status. `signal` aborts superseded requests (fast typing) —
 * see isAbortError() for how callers distinguish that from a real failure.
 */
export async function postJson<T>(
  url: string,
  apiKey: string,
  body: unknown,
  label: string,
  signal?: AbortSignal,
): Promise<T> {
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-TYPESENSE-API-KEY': apiKey,
    },
    body: JSON.stringify(body),
    signal,
  });
  if (!res.ok) {
    throw new Error(await formatHttpError(label, res));
  }
  return (await res.json()) as T;
}

/**
 * True when an exception is the DOMException a superseded (aborted) fetch
 * rejects with. Callers MUST swallow these silently — an aborted request
 * means "a newer one is in flight", never "show the error state".
 */
/**
 * One "latest request wins" channel.
 *
 * Every keystroke-driven call needs the same three lines — abort the
 * predecessor, make a fresh controller, hand out its signal — once per
 * channel (search, suggest, histogram, union), so they live here instead of
 * four times over. Callers swallow the resulting AbortError via
 * {@link isAbortError}.
 */
export class AbortSlot {
  private current: AbortController | null = null;

  /** Abort whatever is in flight on this channel and open a new one. */
  next(): AbortSignal {
    this.current?.abort();
    this.current = new AbortController();
    return this.current.signal;
  }
}

/**
 * Sequence guard for request loops that can't be aborted — `fetchForMap`
 * pages through several requests, so an AbortController on any single one
 * wouldn't stop the loop. Each start() takes a ticket; isStale() tells a
 * completing loop whether a newer one has started since.
 */
export class SeqGuard {
  private seq = 0;

  start(): number {
    return ++this.seq;
  }

  isStale(ticket: number): boolean {
    return ticket !== this.seq;
  }
}

export function isAbortError(e: unknown): boolean {
  return e instanceof DOMException && e.name === 'AbortError';
}

/** The per-search error envelope of one result, or null if it succeeded. */
export function perSearchError(
  result: IwacSearchResponse | TypesensePerSearchError | undefined,
): TypesensePerSearchError | null {
  if (result && 'error' in result && typeof result.error === 'string') {
    return result as TypesensePerSearchError;
  }
  return null;
}

/**
 * Surface per-search errors as thrown errors so the existing error UI
 * catches them. Returning the raw envelope to callers would let
 * `response.hits.length` blow up downstream when ResultsList /
 * SuggestDropdown try to render hits that aren't there.
 *
 * Two failure modes detected:
 *   1. The server reported an error inside the result envelope
 *      (`{code, error}`) — surface its message verbatim.
 *   2. The result is shaped like a success but lacks `hits` — defensive
 *      catch-all for unexpected response shapes that would otherwise
 *      trigger an opaque undefined-property crash on render.
 */
export function validateSearchResult(
  label: string,
  result: IwacSearchResponse | TypesensePerSearchError | undefined,
): IwacSearchResponse {
  if (!result) {
    throw new Error(`${label} response missing results[0]`);
  }
  const err = perSearchError(result);
  if (err) {
    throw new Error(`${label} HTTP ${err.code ?? ''}: ${err.error}`);
  }
  if (!Array.isArray((result as IwacSearchResponse).hits)) {
    throw new Error(`${label} response missing hits[]`);
  }
  return result as IwacSearchResponse;
}

/**
 * Build a useful error string for an HTTP failure on one of our JSON
 * endpoints. Tries hard to surface server-emitted detail:
 *
 *   1. If the body is JSON with our `{error, message, detail}` envelope,
 *      use `${message} — ${detail}` so the user sees the *root* cause
 *      (e.g. "Failed to bootstrap … ← caused by: Connection refused")
 *      not just the wrapper.
 *   2. If the body is JSON without our envelope (e.g. a Typesense error),
 *      fall back to the most informative-looking field.
 *   3. Otherwise (HTML error page, plain text), include the body raw —
 *      capped at 1024 chars so a multi-megabyte HTML 500 doesn't fill
 *      the user's console.
 */
export async function formatHttpError(label: string, res: Response): Promise<string> {
  let raw: string;
  try {
    raw = await res.text();
  } catch {
    return `${label} HTTP ${res.status}: <unreadable body>`;
  }

  try {
    const body: unknown = JSON.parse(raw);
    if (body && typeof body === 'object') {
      const obj = body as Record<string, unknown>;
      const message = typeof obj.message === 'string' ? obj.message : undefined;
      const detail = typeof obj.detail === 'string' ? obj.detail : undefined;
      if (message && detail) {
        return `${label} HTTP ${res.status}: ${message} — ${detail}`;
      }
      if (message) {
        return `${label} HTTP ${res.status}: ${message}`;
      }
      // typesense-style errors put the message under `error`.
      if (typeof obj.error === 'string') {
        return `${label} HTTP ${res.status}: ${obj.error}`;
      }
    }
  } catch {
    // Not JSON — fall through to the raw-text branch.
  }

  return `${label} HTTP ${res.status}: ${raw.slice(0, 1024)}`;
}

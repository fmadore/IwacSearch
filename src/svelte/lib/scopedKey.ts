import type { ScopedKeyResponse } from './types';
import { formatHttpError } from './transport';

/**
 * Scoped-key lifecycle for every Typesense caller in the browser.
 *
 * Lives on its own — outside TypesenseClient — so the small site-wide header
 * bundle can mint a key without importing the full search client. (Class
 * methods are never tree-shaken, so `header.ts` importing `TypesenseClient`
 * for one method dragged export/map/union/histogram/facet-search code onto
 * every public page.)
 *
 * The cache is MODULE-scoped and keyed by token endpoint, not per-instance:
 * several callers on one page (multiple blocks, the federated page's per-tab
 * App remounts, the header box) share one key and one refresh cycle.
 * In-flight requests are coalesced so a burst of debounced searches doesn't
 * N-amplify token requests.
 *
 * Keys are memory-only — never written to storage — so they die with the tab
 * regardless of their (1 h) server-side expiry.
 */
const keyCache = new Map<
  string,
  { key: ScopedKeyResponse | null; inflight: Promise<ScopedKeyResponse> | null }
>();

/** Refresh this many seconds before the server-side expiry. */
const RENEW_MARGIN_SECONDS = 60;

export async function getScopedKey(endpoint: string): Promise<ScopedKeyResponse> {
  const slot = keyCache.get(endpoint) ?? { key: null, inflight: null };
  keyCache.set(endpoint, slot);

  const now = Math.floor(Date.now() / 1000);
  if (slot.key && slot.key.expires_at - RENEW_MARGIN_SECONDS > now) {
    return slot.key;
  }
  if (slot.inflight) {
    return slot.inflight;
  }
  slot.inflight = (async () => {
    try {
      const res = await fetch(endpoint, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) {
        throw new Error(await formatHttpError('Token', res));
      }
      const key = (await res.json()) as ScopedKeyResponse;
      if (!key.key) {
        throw new Error('Token endpoint returned no key');
      }
      slot.key = key;
      return key;
    } finally {
      slot.inflight = null;
    }
  })();
  return slot.inflight;
}

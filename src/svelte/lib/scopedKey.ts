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
 * Keys are also kept in `sessionStorage`, so navigating between pages of one
 * tab reuses the key instead of booting Omeka for a new one on every page.
 * Session storage still dies with the tab, as the memory-only cache did; the
 * key is public-shaped (the server embeds every restriction in it); and a key
 * the server rejects is dropped from storage as well as memory. Storage is
 * best-effort: private modes and blocked site data fall back to memory.
 */
const keyCache = new Map<
  string,
  { key: ScopedKeyResponse | null; inflight: Promise<ScopedKeyResponse> | null }
>();

/** Refresh this many seconds before the server-side expiry. */
const RENEW_MARGIN_SECONDS = 60;

const STORAGE_PREFIX = 'iwac-search:scoped-key:';

function readStored(endpoint: string): ScopedKeyResponse | null {
  try {
    const raw = globalThis.sessionStorage?.getItem(STORAGE_PREFIX + endpoint);
    if (!raw) return null;
    const key = JSON.parse(raw) as Partial<ScopedKeyResponse>;
    return typeof key.key === 'string' && key.key !== '' && typeof key.expires_at === 'number'
      ? (key as ScopedKeyResponse)
      : null;
  } catch {
    return null;
  }
}

function writeStored(endpoint: string, key: ScopedKeyResponse | null): void {
  try {
    if (key) {
      globalThis.sessionStorage?.setItem(STORAGE_PREFIX + endpoint, JSON.stringify(key));
    } else {
      globalThis.sessionStorage?.removeItem(STORAGE_PREFIX + endpoint);
    }
  } catch {
    // Storage full or blocked: the in-memory cache still serves this page.
  }
}

export async function getScopedKey(
  endpoint: string,
  rejectedKey?: string,
): Promise<ScopedKeyResponse> {
  const slot = keyCache.get(endpoint) ?? { key: readStored(endpoint), inflight: null };
  keyCache.set(endpoint, slot);

  if (rejectedKey && slot.key?.key === rejectedKey) {
    slot.key = null;
    writeStored(endpoint, null);
  }
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
        signal: AbortSignal.timeout(15000),
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
      writeStored(endpoint, key);
      return key;
    } finally {
      slot.inflight = null;
    }
  })();
  return slot.inflight;
}

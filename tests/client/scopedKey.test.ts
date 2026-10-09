import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The scoped key survives page navigation within a tab (sessionStorage), so
 * browsing the site does not boot Omeka for a fresh key on every page — while
 * a rejected or expired key is never reused, and blocked storage degrades to
 * the per-page memory cache.
 */

const ENDPOINT = '/discovery/token';
const STORED = 'iwac-search:scoped-key:' + ENDPOINT;

function token(key: string, expiresIn = 3600) {
  return { key, expires_at: Math.floor(Date.now() / 1000) + expiresIn, host: '', collection: 'c' };
}

function mockTokenEndpoint(...keys: string[]) {
  let call = 0;
  const fetch = vi.fn(async () => {
    const key = keys[Math.min(call, keys.length - 1)];
    call += 1;
    return new Response(JSON.stringify(token(key)), { status: 200 });
  });
  vi.stubGlobal('fetch', fetch);
  return fetch;
}

/** A fresh module instance — what the next page load in the same tab sees. */
async function freshPage() {
  vi.resetModules();
  return (await import('../../src/svelte/lib/scopedKey')).getScopedKey;
}

beforeEach(() => sessionStorage.clear());
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('scoped key persistence', () => {
  it('reuses the key on the next page of the same tab', async () => {
    const fetch = mockTokenEndpoint('first');
    expect((await (await freshPage())(ENDPOINT)).key).toBe('first');
    expect((await (await freshPage())(ENDPOINT)).key).toBe('first');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('ignores a stored key that is about to expire', async () => {
    sessionStorage.setItem(STORED, JSON.stringify(token('stale', 30)));
    const fetch = mockTokenEndpoint('fresh');
    expect((await (await freshPage())(ENDPOINT)).key).toBe('fresh');
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it('forgets a rejected key in storage as well as in memory', async () => {
    const fetch = mockTokenEndpoint('revoked', 'replacement');
    const getScopedKey = await freshPage();
    await getScopedKey(ENDPOINT);

    expect((await getScopedKey(ENDPOINT, 'revoked')).key).toBe('replacement');
    expect(JSON.parse(sessionStorage.getItem(STORED) ?? '{}').key).toBe('replacement');
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('ignores malformed stored values', async () => {
    sessionStorage.setItem(STORED, '{"key": 42}');
    mockTokenEndpoint('fresh');
    expect((await (await freshPage())(ENDPOINT)).key).toBe('fresh');
  });

  it('still works when storage is blocked', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError');
    });
    const fetch = mockTokenEndpoint('memory-only');
    const getScopedKey = await freshPage();
    expect((await getScopedKey(ENDPOINT)).key).toBe('memory-only');
    expect((await getScopedKey(ENDPOINT)).key).toBe('memory-only');
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});

/**
 * S-14: the token endpoint's failure path. The PHP side answers 503 with a
 * deliberately generic message (the exception chain goes to Omeka's log
 * only); the client turns that into one thrown error — and must not cache
 * the failure, or one blip would disable search for the whole tab.
 */
describe('token endpoint failures', () => {
  it('throws the server message on a non-2xx answer, then retries on the next call', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(
        new Response(
          JSON.stringify({
            error: 'token_unavailable',
            message: 'Typesense scoped-key minting failed.',
          }),
          { status: 503 },
        ),
      )
      .mockResolvedValueOnce(new Response(JSON.stringify(token('fresh')), { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    const getScopedKey = await freshPage();

    await expect(getScopedKey(ENDPOINT)).rejects.toThrow(
      'Token HTTP 503: Typesense scoped-key minting failed.',
    );
    expect(sessionStorage.getItem(STORED)).toBeNull();
    expect((await getScopedKey(ENDPOINT)).key).toBe('fresh');
    expect(fetch).toHaveBeenCalledTimes(2);
  });

  it('refuses a 200 that carries no key', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response(JSON.stringify({ key: '' }), { status: 200 })),
    );
    const getScopedKey = await freshPage();
    await expect(getScopedKey(ENDPOINT)).rejects.toThrow('Token endpoint returned no key');
  });
});

/** S-18: a visitor's clock may be far off the server's; renewal must not care. */
describe('key expiry', () => {
  it('renews from the relative lifetime, not by comparing two clocks', async () => {
    // The server's expires_at is already in the past by this browser's clock
    // (a clock an hour fast), but the key has an hour to live.
    const fetch = vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            key: 'k1',
            expires_at: Math.floor(Date.now() / 1000) - 3600,
            expires_in: 3600,
            host: '',
            collection: 'c',
          }),
          { status: 200 },
        ),
    );
    vi.stubGlobal('fetch', fetch);
    const getScopedKey = await freshPage();
    await getScopedKey(ENDPOINT);
    expect((await getScopedKey(ENDPOINT)).key).toBe('k1');
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});

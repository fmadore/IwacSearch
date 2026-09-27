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

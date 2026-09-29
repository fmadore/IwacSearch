import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  MAPLIBRE_FILES,
  MAPLIBRE_INTEGRITY,
  loadMapLibre,
  resetMapLibreLoaderForTests,
} from '../../src/svelte/lib/maplibreLoader';

/**
 * The MapLibre loader.
 *
 * MapLibre 6 is ESM-only, so the library arrives through `import()`, which
 * takes no `integrity`. What keeps the CDN files verified is that the entry
 * and its chunk are `modulepreload`ed WITH their hashes and the import waits
 * for the entry's preload. These cases pin that ordering, the reuse of a copy
 * IwacVisualizations already imported on the same page, and the retry after
 * a failed load.
 */

const fakeLib = { Map: function FakeMap() {}, Popup: function FakePopup() {} };

let appended: HTMLLinkElement[] = [];

beforeEach(() => {
  resetMapLibreLoaderForTests();
  delete (window as unknown as Record<string, unknown>).maplibregl;
  appended = [];
  document.head.innerHTML = '';
  // jsdom implements neither modulepreload nor link loading: report support,
  // and settle every appended link's load on the next tick, as a browser
  // would once the (verified) response arrives.
  vi.spyOn(DOMTokenList.prototype, 'supports').mockImplementation(
    (token) => token === 'modulepreload',
  );
  const append = Node.prototype.appendChild;
  vi.spyOn(document.head, 'appendChild').mockImplementation(function (this: Node, node: Node) {
    if (node instanceof HTMLLinkElement) {
      appended.push(node);
      setTimeout(() => node.onload?.(new Event('load')), 0);
    }
    return append.call(this, node);
  } as typeof document.head.appendChild);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('loadMapLibre', () => {
  it('pins every file it loads to a sha384 hash', () => {
    for (const url of Object.values(MAPLIBRE_FILES)) {
      expect(url).toMatch(/^https:\/\/cdn\.jsdelivr\.net\/npm\/maplibre-gl@\d+\.\d+\.\d+\/dist\//);
      expect(MAPLIBRE_INTEGRITY[url]).toMatch(/^sha384-[A-Za-z0-9+/]{64}$/);
    }
  });

  it('preloads the entry and chunk with their hashes before importing, then publishes the global', async () => {
    const importer = vi.fn(async (url: string) => {
      // The import must not start before the entry's verified preload.
      const entry = appended.find((l) => l.rel === 'modulepreload' && l.href === url);
      expect(entry, 'entry preloaded before import').toBeTruthy();
      return fakeLib;
    });
    const lib = await loadMapLibre(importer);

    expect(lib).toBe(fakeLib);
    expect(importer).toHaveBeenCalledWith(MAPLIBRE_FILES.module);
    expect((window as unknown as Record<string, unknown>).maplibregl).toBe(fakeLib);

    const byHref = Object.fromEntries(appended.map((l) => [l.href, l]));
    for (const [href, rel] of [
      [MAPLIBRE_FILES.css, 'stylesheet'],
      [MAPLIBRE_FILES.module, 'modulepreload'],
      [MAPLIBRE_FILES.chunk, 'modulepreload'],
    ]) {
      expect(byHref[href]?.rel).toBe(rel);
      expect(byHref[href]?.integrity).toBe(MAPLIBRE_INTEGRITY[href]);
      expect(byHref[href]?.crossOrigin).toBe('anonymous');
    }
  });

  it('reuses a copy another module already imported, loading nothing', async () => {
    (window as unknown as Record<string, unknown>).maplibregl = fakeLib;
    const importer = vi.fn();
    expect(await loadMapLibre(importer)).toBe(fakeLib);
    expect(importer).not.toHaveBeenCalled();
    expect(appended).toHaveLength(0);
  });

  it('loads once however many maps ask', async () => {
    const importer = vi.fn(async () => fakeLib);
    await Promise.all([loadMapLibre(importer), loadMapLibre(importer)]);
    expect(importer).toHaveBeenCalledTimes(1);
  });

  it('forgets a failed load so the next activation retries', async () => {
    const failing = vi.fn(async () => {
      throw new Error('network');
    });
    await expect(loadMapLibre(failing)).rejects.toThrow(/Failed to load maplibre-gl/);
    const working = vi.fn(async () => fakeLib);
    expect(await loadMapLibre(working)).toBe(fakeLib);
  });
});

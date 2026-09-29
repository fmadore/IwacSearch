/**
 * Lazy CDN loader for MapLibre GL — fetched only when a user activates the
 * Map view, so the library never taxes the normal search bundle.
 *
 * PINNED TO IwacVisualizations' EXACT FILES (view/common/iwac-assets.phtml):
 * the same jsDelivr URLs, so a visitor moving between a search map and a
 * dashboard map downloads MapLibre once, and the same Subresource Integrity
 * hashes. This comment claimed the shared cache for a release after
 * IwacVisualizations had moved to MapLibre 6.11 while this file still loaded
 * 5.24 — two versions of a ~800 KB library on one site, the older without
 * SRI. Upgrade both modules together; the hashes come from the npm tarball
 * (`npm pack maplibre-gl@<v>`, sha384 of dist/*), which is also how
 * IwacVisualizations' `npm run update:sri` derives them.
 *
 * MapLibre 6 is ESM-ONLY: there is no `dist/maplibre-gl.js`, so the library is
 * `import()`ed, and its namespace published as `window.maplibregl` — the global
 * IwacVisualizations publishes too, so whichever module loads first, the other
 * reuses it. `import()` takes no `integrity`, so the entry and its ~500 KB
 * chunk are `modulepreload`ed with their hashes and the import waits for the
 * entry's preload: it then resolves against the verified module-map entry
 * (and the chunk downloads in parallel instead of after the entry). The
 * worker needs no configuration: v6 boots it from a blob that re-imports the
 * CDN URL, which requires `blob:` in `worker-src` wherever a CSP is enforced.
 *
 * Basemap conventions (CARTO styles, cooperative gestures) also follow that
 * module — see MapView.svelte.
 */

const MAPLIBRE_VERSION = '6.11.2';
const CDN = `https://cdn.jsdelivr.net/npm/maplibre-gl@${MAPLIBRE_VERSION}/dist/`;

export const MAPLIBRE_FILES = {
  module: `${CDN}maplibre-gl.mjs`,
  chunk: `${CDN}maplibre-gl-shared.mjs`,
  css: `${CDN}maplibre-gl.css`,
} as const;

/** sha384 per pinned file — identical to IwacVisualizations' `$cdnIntegrity`. */
export const MAPLIBRE_INTEGRITY: Readonly<Record<string, string>> = {
  [MAPLIBRE_FILES.module]:
    'sha384-KQzExYlfg1SnYNpLaXHTnaCTjr5wmiZ6X3sasvPb94caZfH+3+T1Sl4eJziXUtmN',
  [MAPLIBRE_FILES.chunk]: 'sha384-V59ofCEPEqpSk5Mswc19DtDYbZWJtne210dFzS328Il6O31eLwTV7v57+Z3NjW/7',
  [MAPLIBRE_FILES.css]: 'sha384-ntw3zEt6rcVML7jDK0ULmHa5hxLB23afsPqzqfY+gLgMfAkbFCnCgPpkZvV5mmZX',
};

/**
 * CARTO's free GL basemaps (OSM data, no API key), one per theme.
 *
 * The map used to be pinned to positron on the grounds that "the IWAC search
 * surface is light-themed" — which stopped being true when the theme grew its
 * lamplit dark mode, and left the entity map as a slab of white paper in an
 * otherwise dark reading room. IwacVisualizations already swaps these two
 * styles on the same site (`IWACVis.getBasemapStyle`); this is the same pair,
 * so the two modules' maps never disagree about what theme the page is in.
 */
const BASEMAP_LIGHT = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';
const BASEMAP_DARK = 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json';

export type ThemeMode = 'light' | 'dark';

export function basemapStyleUrl(theme: ThemeMode): string {
  return theme === 'dark' ? BASEMAP_DARK : BASEMAP_LIGHT;
}

/**
 * The theme the page is actually in: the explicit `body[data-theme]` the IWAC
 * theme's toggle writes, else the OS preference. Same resolution order as
 * `IWACVis.getCurrentTheme` and as the module's own CSS
 * (`body[data-theme='dark']` / `body:not([data-theme='light'])` under
 * prefers-color-scheme).
 */
export function readTheme(): ThemeMode {
  const attr = document.body?.getAttribute('data-theme');
  if (attr === 'light' || attr === 'dark') return attr;
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/**
 * Call `onChange` whenever the resolved theme flips — from the toggle (a
 * `data-theme` mutation) or from the OS. Returns the `$effect` teardown.
 *
 * MapLibre cannot read custom properties, so every colour it uses is read
 * once, imperatively. Without this, "read once" meant "read once ever": the
 * markers kept the palette the page happened to be in when the Map view was
 * first opened, for the rest of the session.
 */
export function watchTheme(onChange: (theme: ThemeMode) => void): () => void {
  let current = readTheme();
  const settle = (): void => {
    const next = readTheme();
    if (next === current) return;
    current = next;
    onChange(next);
  };
  const observer = new MutationObserver(settle);
  observer.observe(document.body, { attributes: true, attributeFilter: ['data-theme'] });
  const media = window.matchMedia?.('(prefers-color-scheme: dark)');
  media?.addEventListener('change', settle);
  return () => {
    observer.disconnect();
    media?.removeEventListener('change', settle);
  };
}

/**
 * Minimal structural typing for the parts of the MapLibre API we touch —
 * the library is a CDN global, not an npm dependency, so no @types exist.
 */
export interface MapLibreMapLike {
  on(event: string, layerOrHandler: unknown, handler?: unknown): void;
  addSource(id: string, source: unknown): void;
  getSource(id: string): unknown;
  addLayer(layer: unknown): void;
  addControl(control: unknown, position?: string): void;
  fitBounds(bounds: [[number, number], [number, number]], opts?: unknown): void;
  easeTo(opts: unknown): void;
  getCanvas(): HTMLCanvasElement;
  remove(): void;
}

export interface MapLibrePopupLike {
  setLngLat(lngLat: [number, number]): MapLibrePopupLike;
  setHTML(html: string): MapLibrePopupLike;
  addTo(map: MapLibreMapLike): MapLibrePopupLike;
}

export interface MapLibreGlobal {
  Map: new (opts: unknown) => MapLibreMapLike;
  Popup: new (opts?: unknown) => MapLibrePopupLike;
  NavigationControl: new (opts?: unknown) => unknown;
  FullscreenControl: new (opts?: unknown) => unknown;
}

let loader: Promise<MapLibreGlobal> | null = null;

/** A `<link>` carrying its pinned file's hash (SRI on a cross-origin file needs CORS). */
function pinnedLink(rel: string, href: string): HTMLLinkElement {
  const link = document.createElement('link');
  link.rel = rel;
  link.integrity = MAPLIBRE_INTEGRITY[href];
  link.crossOrigin = 'anonymous';
  link.href = href;
  return link;
}

/** Settles once the `modulepreload` of `href` has fetched and verified it. */
function modulePreload(href: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const link = pinnedLink('modulepreload', href);
    link.onload = () => resolve();
    link.onerror = () => reject(new Error(`Module failed integrity or load: ${href}`));
    document.head.appendChild(link);
  });
}

function supportsModulePreload(): boolean {
  try {
    const link = document.createElement('link');
    return Boolean(link.relList?.supports?.('modulepreload'));
  } catch {
    return false; // an engine whose relList has no supported-token set
  }
}

/**
 * Load the pinned CSS + modules once and resolve the library namespace.
 * `importer` exists for the unit test; production uses the native import.
 */
export function loadMapLibre(
  importer: (url: string) => Promise<unknown> = (url) => import(/* @vite-ignore */ url),
): Promise<MapLibreGlobal> {
  loader ??= (async () => {
    const globals = window as unknown as Record<string, unknown>;
    if (globals.maplibregl) return globals.maplibregl as MapLibreGlobal;

    document.head.appendChild(pinnedLink('stylesheet', MAPLIBRE_FILES.css));

    if (supportsModulePreload()) {
      const chunk = modulePreload(MAPLIBRE_FILES.chunk);
      // The chunk's own failure surfaces through the import that needs it.
      chunk.catch(() => {});
      await modulePreload(MAPLIBRE_FILES.module);
    }
    const lib = (await importer(MAPLIBRE_FILES.module)) as MapLibreGlobal;
    if (!lib || typeof lib.Map !== 'function')
      throw new Error('maplibre-gl loaded without a Map export');
    globals.maplibregl = lib;
    return lib;
  })().catch((error: unknown) => {
    loader = null; // allow a retry on the next activation
    throw new Error(
      `Failed to load maplibre-gl from jsDelivr: ${(error as Error)?.message ?? error}`,
    );
  });
  return loader;
}

/** Test seam: forget the memoised load. */
export function resetMapLibreLoaderForTests(): void {
  loader = null;
}

/**
 * MapLibre's style validator only accepts CSS Color Level 3, but IWAC-theme
 * tokens are OKLCH — rasterise any CSS color through a 1×1 canvas to get a
 * plain rgb() string (same trick as IwacVisualizations'
 * normalizeColorForMapLibre).
 */
export function normalizeColor(cssColor: string, fallback: string): string {
  try {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d');
    if (!ctx) return fallback;
    ctx.fillStyle = fallback;
    ctx.fillStyle = cssColor; // invalid values keep the fallback
    ctx.fillRect(0, 0, 1, 1);
    const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
    return `rgb(${r}, ${g}, ${b})`;
  } catch {
    return fallback;
  }
}

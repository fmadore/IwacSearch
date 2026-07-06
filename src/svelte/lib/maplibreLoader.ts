/**
 * Lazy CDN loader for MapLibre GL — fetched only when a user activates the
 * Map view, so the ~230 KB library never taxes the normal search bundle.
 *
 * Deliberately mirrors IwacVisualizations (the analytics module on the same
 * site): the SAME exact-pinned jsDelivr URLs, so the browser cache is shared
 * between the two modules and the version is immutable-cached for a year.
 * Basemap conventions (CARTO styles, cooperative gestures) also follow that
 * module — see MapView.svelte.
 */

const MAPLIBRE_VERSION = '5.24.0';
const JS_URL = `https://cdn.jsdelivr.net/npm/maplibre-gl@${MAPLIBRE_VERSION}/dist/maplibre-gl.js`;
const CSS_URL = `https://cdn.jsdelivr.net/npm/maplibre-gl@${MAPLIBRE_VERSION}/dist/maplibre-gl.css`;

/** CARTO's free GL basemap (OSM data, no API key). Light variant only —
 * the IWAC search surface is light-themed. */
export const BASEMAP_STYLE_URL = 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';

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

/** Inject the pinned CSS + JS once and resolve the `maplibregl` global. */
export function loadMapLibre(): Promise<MapLibreGlobal> {
  loader ??= new Promise<MapLibreGlobal>((resolve, reject) => {
    const existing = (window as unknown as Record<string, unknown>).maplibregl;
    if (existing) {
      resolve(existing as MapLibreGlobal);
      return;
    }

    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = CSS_URL;
    document.head.appendChild(css);

    const script = document.createElement('script');
    script.src = JS_URL;
    script.defer = true;
    script.onload = () => {
      const lib = (window as unknown as Record<string, unknown>).maplibregl;
      if (lib) {
        resolve(lib as MapLibreGlobal);
      } else {
        reject(new Error('maplibre-gl loaded but the global is missing'));
      }
    };
    script.onerror = () => {
      loader = null; // allow a retry on the next activation
      reject(new Error('Failed to load maplibre-gl from jsDelivr'));
    };
    document.head.appendChild(script);
  });
  return loader;
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

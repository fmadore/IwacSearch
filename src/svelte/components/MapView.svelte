<script lang="ts">
  /**
   * Map view for the entity index — plots every geo-tagged place (and any
   * other entity carrying curated coordinates) matching the current query +
   * filters. Purely presentational: App fetches the docs (fetchForMap) and
   * hands them down; this component owns only the MapLibre lifecycle.
   *
   * Conventions follow IwacVisualizations (the analytics module already on
   * the site): exact-pinned MapLibre from jsDelivr (shared browser cache),
   * CARTO positron basemap, cooperative gestures, compact attribution.
   * Markers cluster via the GeoJSON source's built-in clustering — click a
   * cluster to zoom in, click a point for a popup linking to the entity.
   */
  import type { IwacDoc } from '../lib/types';
  import { escapeHtml } from '../lib/sanitize';
  import { MAP_MAX_HITS } from '../lib/typesense';
  import {
    basemapStyleUrl,
    loadMapLibre,
    normalizeColor,
    readTheme,
    watchTheme,
    type MapLibreGlobal,
    type MapLibreMapLike,
  } from '../lib/maplibreLoader';
  import { useI18n } from '../lib/i18n';
  import { localizeSiteUrl } from '../lib/siteUrl';

  interface Props {
    docs: IwacDoc[];
    loading: boolean;
  }

  const { docs, loading }: Props = $props();
  const { t } = useI18n();

  let container: HTMLDivElement | null = $state(null);
  let map: MapLibreMapLike | null = null;
  let lib: MapLibreGlobal | null = null;
  let styleReady = $state(false);
  let loadError = $state<string | null>(null);

  const SOURCE_ID = 'iwac-entities';

  /**
   * The page's theme, watched rather than sampled.
   *
   * MapLibre can't read `var()`, so its basemap and every marker colour are
   * resolved imperatively at mount — which used to mean the map kept whatever
   * palette the page was in the first time the Map view opened, and rendered a
   * white-paper basemap under the dark theme forever after. Re-keying the
   * mount effect on this rebuilds the map on a theme flip. The viewport is not
   * preserved, but the data effect re-fits the bounds and a theme change is a
   * deliberate, rare act; setStyle() would keep the camera at the cost of
   * re-installing every source and layer on `styledata`.
   */
  let theme = $state(readTheme());
  $effect(() => watchTheme((next) => (theme = next)));

  // West Africa initial framing — same viewport IwacVisualizations uses for
  // its spatial-exploration panel ([lng, lat] — MapLibre order).
  const DEFAULT_CENTER: [number, number] = [2, 10];
  const DEFAULT_ZOOM = 3.2;

  /** Docs with a parseable geopoint → GeoJSON (swapping [lat, lng] → [lng, lat]). */
  const featureCollection = $derived.by(() => ({
    type: 'FeatureCollection' as const,
    features: docs
      .filter((d) => Array.isArray(d.geo) && d.geo.length === 2)
      .map((d) => ({
        type: 'Feature' as const,
        properties: {
          id: d.id,
          title: d.title,
          entityType: d.entity_type_s ?? '',
          frequency: d.frequency ?? 0,
          // Site-localised, like every other link out of a result (siteUrl.ts).
          url: d.omeka_url ? localizeSiteUrl(d.omeka_url) : '',
        },
        geometry: {
          type: 'Point' as const,
          coordinates: [d.geo![1], d.geo![0]] as [number, number],
        },
      })),
  }));

  const isEmpty = $derived(!loading && featureCollection.features.length === 0);
  const isCapped = $derived(docs.length >= MAP_MAX_HITS);

  // Mount the map once the container exists; tear it down with the view.
  $effect(() => {
    const el = container;
    if (!el) return;
    // Tracked so a theme flip tears this map down and builds the other one.
    const mode = theme;
    let cancelled = false;

    // Read once per (re)mount, from the live cascade — the hex literals are the
    // degraded-mode fallbacks for a page without the theme, never the values a
    // themed page paints with. `--surface` is the page ground, so the marker
    // stroke and the cluster numeral track the basemap: near-white on positron,
    // near-black on dark-matter. It is also the one choice that keeps the
    // numeral legible on the marker fill in both themes (white on the dark
    // theme's lighter --primary measures 3.23:1; --surface measures 6.1:1).
    const css = getComputedStyle(el);
    const readColor = (name: string, fallback: string): string =>
      normalizeColor(css.getPropertyValue(name).trim() || fallback, fallback);
    const primary = readColor('--primary', '#ce4115');
    const ground = readColor('--surface', '#fdfcfb');

    loadMapLibre()
      .then((maplibre) => {
        if (cancelled || !el.isConnected) return;
        lib = maplibre;
        map = new maplibre.Map({
          container: el,
          style: basemapStyleUrl(mode),
          center: DEFAULT_CENTER,
          zoom: DEFAULT_ZOOM,
          // Scroll shouldn't hijack the page — require ctrl/cmd (or two
          // fingers), with MapLibre's built-in localized hint overlay.
          cooperativeGestures: true,
          attributionControl: { compact: true },
        });
        map.addControl(new maplibre.NavigationControl({ visualizePitch: false }), 'top-right');
        map.addControl(new maplibre.FullscreenControl(), 'top-right');

        map.on('load', () => {
          if (cancelled || !map) return;
          map.addSource(SOURCE_ID, {
            type: 'geojson',
            data: featureCollection,
            cluster: true,
            clusterMaxZoom: 11,
            clusterRadius: 42,
          });
          map.addLayer({
            id: 'clusters',
            type: 'circle',
            source: SOURCE_ID,
            filter: ['has', 'point_count'],
            paint: {
              'circle-color': primary,
              'circle-opacity': 0.85,
              'circle-radius': ['step', ['get', 'point_count'], 14, 25, 20, 100, 26],
              'circle-stroke-width': 2,
              'circle-stroke-color': ground,
            },
          });
          map.addLayer({
            id: 'cluster-count',
            type: 'symbol',
            source: SOURCE_ID,
            filter: ['has', 'point_count'],
            layout: {
              'text-field': ['get', 'point_count_abbreviated'],
              'text-size': 12,
              'text-font': ['Montserrat Medium', 'Open Sans Regular', 'Noto Sans Regular'],
            },
            paint: { 'text-color': ground },
          });
          map.addLayer({
            id: 'unclustered-point',
            type: 'circle',
            source: SOURCE_ID,
            filter: ['!', ['has', 'point_count']],
            paint: {
              // Radius scales gently with how often the place is mentioned.
              'circle-radius': [
                'interpolate',
                ['linear'],
                ['get', 'frequency'],
                0,
                5,
                50,
                8,
                500,
                12,
              ],
              'circle-color': primary,
              'circle-opacity': 0.8,
              'circle-stroke-width': 1.5,
              'circle-stroke-color': ground,
            },
          });

          // Cluster click → zoom one expansion step in.
          map.on('click', 'clusters', (e: unknown) => {
            const evt = e as { features?: Array<{ properties: { cluster_id: number } }> };
            const feature = evt.features?.[0];
            const src = map?.getSource(SOURCE_ID) as {
              getClusterExpansionZoom(id: number): Promise<number>;
            } | null;
            if (!feature || !src) return;
            void src.getClusterExpansionZoom(feature.properties.cluster_id).then((zoom) => {
              const geom = (
                evt.features?.[0] as unknown as {
                  geometry: { coordinates: [number, number] };
                }
              ).geometry;
              map?.easeTo({ center: geom.coordinates, zoom });
            });
          });

          // Point click → popup with the entity link.
          map.on('click', 'unclustered-point', (e: unknown) => {
            const evt = e as {
              features?: Array<{
                geometry: { coordinates: [number, number] };
                properties: {
                  title: string;
                  entityType: string;
                  frequency: number;
                  url: string;
                };
              }>;
            };
            const f = evt.features?.[0];
            if (!f || !lib || !map) return;
            const props = f.properties;
            const esc = escapeHtml;
            const mentions = t(props.frequency === 1 ? 'mention_one' : 'mention_other', {
              n: props.frequency,
            });
            const title = props.url
              ? `<a href="${esc(props.url)}">${esc(props.title)}</a>`
              : esc(props.title);
            const popup = new lib.Popup({ maxWidth: '320px', closeButton: true });
            popup
              .setLngLat(f.geometry.coordinates)
              .setHTML(
                `<div class="iwac-map__popup"><strong>${title}</strong>` +
                  `<span>${esc(props.entityType)} · ${esc(mentions)}</span></div>`,
              )
              .addTo(map);
          });

          // Pointer affordance on interactive layers.
          for (const layer of ['clusters', 'unclustered-point']) {
            map.on('mouseenter', layer, () => {
              if (map) map.getCanvas().style.cursor = 'pointer';
            });
            map.on('mouseleave', layer, () => {
              if (map) map.getCanvas().style.cursor = '';
            });
          }

          styleReady = true;
        });
      })
      .catch((e: Error) => {
        if (!cancelled) loadError = e.message;
      });

    return () => {
      cancelled = true;
      styleReady = false;
      map?.remove();
      map = null;
    };
  });

  // Push fresh data into the source whenever the docs change, and frame the
  // markers (padding keeps edge markers visible; maxZoom stops a one-marker
  // set from zooming to street level).
  $effect(() => {
    const fc = featureCollection;
    if (!map || !styleReady) return;
    const src = map.getSource(SOURCE_ID) as { setData(data: unknown): void } | null;
    if (!src) return;
    src.setData(fc);
    if (fc.features.length > 0) {
      let minLng = Infinity;
      let minLat = Infinity;
      let maxLng = -Infinity;
      let maxLat = -Infinity;
      for (const f of fc.features) {
        const [lng, lat] = f.geometry.coordinates;
        minLng = Math.min(minLng, lng);
        minLat = Math.min(minLat, lat);
        maxLng = Math.max(maxLng, lng);
        maxLat = Math.max(maxLat, lat);
      }
      map.fitBounds(
        [
          [minLng, minLat],
          [maxLng, maxLat],
        ],
        { padding: 48, maxZoom: 8, duration: 600 },
      );
    }
  });
</script>

<div class="iwac-map" role="region" aria-label={t('map_label')}>
  {#if loadError}
    <p class="iwac-map__status" role="alert">{t('map_error')} <span>{loadError}</span></p>
  {:else}
    <div class="iwac-map__canvas" bind:this={container}></div>
    {#if loading || !styleReady}
      <p class="iwac-map__status" aria-live="polite">{t('map_loading')}</p>
    {:else if isEmpty}
      <p class="iwac-map__status">{t('map_empty')}</p>
    {:else if isCapped}
      <p class="iwac-map__status iwac-map__status--note">
        {t('map_capped', { n: MAP_MAX_HITS })}
      </p>
    {/if}
  {/if}
</div>

<style>
  .iwac-map {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm, 0.5rem);
  }
  .iwac-map__canvas {
    width: 100%;
    height: min(70vh, 34rem);
    border: 1px solid var(--border, #ced1d6);
    border-radius: var(--radius-md, 0.5rem);
    overflow: hidden;
    background: var(--surface-sunken, #f4f1ef);
  }
  .iwac-map__status {
    margin: 0;
    color: var(--muted, #66696e);
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-map__status--note {
    font-style: italic;
  }
  /* Popup body (rendered by MapLibre outside Svelte's scope). */
  .iwac-map :global(.iwac-map__popup) {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: var(--text-sm, 0.9375rem);
  }
  .iwac-map :global(.iwac-map__popup a) {
    color: var(--primary, #ce4115);
  }
  .iwac-map :global(.iwac-map__popup span) {
    color: var(--muted, #66696e);
  }
</style>

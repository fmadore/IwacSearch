import { escapeHtml } from './sanitize';

/** The GeoJSON properties MapView puts on every marker. */
export interface MapPointProps {
  id: string | number;
  title: string;
  entityType: string;
  frequency: number;
  url: string;
}

export interface MapPopupLabels {
  /** "Lieux" → "Places": the raw value is a French data string. */
  typeLabel(entityType: string): string;
  /** "12 mentions". */
  mentions(n: number): string;
  /** "3 entries at this location" — heads a popup listing several. */
  here(n: number): string;
}

/**
 * Popup markup for the entities at one spot on the map.
 *
 * One entity reads as it always did: its linked title over its type and
 * mention count. Several are the case clustering cannot resolve — entities
 * sharing identical coordinates stay one cluster up to `clusterMaxZoom` and
 * one stacked dot past it, where a click reaches only the top marker — so
 * they are listed together, most-mentioned first. Duplicates are dropped:
 * MapLibre can report one feature once per tile it straddles.
 */
export function mapPopupHtml(points: readonly MapPointProps[], labels: MapPopupLabels): string {
  const seen = new Set<string>();
  const unique = points.filter((p) => {
    const key = String(p.id);
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  });
  const entry = (p: MapPointProps): string => {
    const title = p.url
      ? `<a href="${escapeHtml(p.url)}">${escapeHtml(p.title)}</a>`
      : escapeHtml(p.title);
    return (
      `<strong>${title}</strong>` +
      `<span>${escapeHtml(labels.typeLabel(p.entityType))} · ${escapeHtml(labels.mentions(p.frequency))}</span>`
    );
  };
  if (unique.length === 1) return `<div class="iwac-map__popup">${entry(unique[0])}</div>`;
  const sorted = [...unique].sort((a, b) => b.frequency - a.frequency);
  return (
    `<div class="iwac-map__popup iwac-map__popup--list">` +
    `<p class="iwac-map__popup-head">${escapeHtml(labels.here(sorted.length))}</p>` +
    `<ul>${sorted.map((p) => `<li>${entry(p)}</li>`).join('')}</ul></div>`
  );
}

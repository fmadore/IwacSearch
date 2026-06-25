/**
 * Mentions-over-time helpers for the entity-card sparkline (design review §03B).
 *
 * The entity collection carries `mentions_by_year_s` — a compact `year:count`
 * histogram joined by `;` (see IndexEntityMapper). These pure functions parse
 * it and densify it into a per-year series with real temporal gaps filled as 0,
 * so the sparkline's shape is honest (a 10-year silence reads as a flat run, not
 * a straight interpolation). Pure + side-effect-free so they're unit-testable.
 */

export interface YearCount {
  year: number;
  count: number;
}

/** Parse "1983:4;1984:7;1990:2" → ascending [{year,count}], dropping garbage. */
export function parseMentionsByYear(raw: string | undefined | null): YearCount[] {
  if (!raw) return [];
  const out: YearCount[] = [];
  for (const pair of raw.split(';')) {
    const idx = pair.indexOf(':');
    if (idx < 0) continue;
    const year = Number(pair.slice(0, idx));
    const count = Number(pair.slice(idx + 1));
    if (Number.isFinite(year) && Number.isFinite(count) && year > 0 && count > 0) {
      out.push({ year, count });
    }
  }
  out.sort((a, b) => a.year - b.year);
  return out;
}

/**
 * Expand sparse year/count pairs into a dense count series spanning
 * min..max year, with missing years as 0. Returns [] for empty input and the
 * bare counts when every point shares one year (no span to densify).
 */
export function densifyByYear(points: YearCount[]): number[] {
  if (points.length === 0) return [];
  const min = points[0].year;
  const max = points[points.length - 1].year;
  const span = max - min;
  if (span <= 0) return points.map((p) => p.count);
  const arr = new Array<number>(span + 1).fill(0);
  for (const p of points) arr[p.year - min] = p.count;
  return arr;
}

/**
 * The year slider's bounds, and the rule that keeps a URL's year range inside
 * them.
 *
 * The slider used to be hardcoded to 1960–2025, in four places. The corpus
 * holds references from 1912 and hundreds of articles dated 2026, so neither
 * end could be selected, the histogram silently dropped every bar outside the
 * window, and `?date.from=2026` rendered "2026 – 2025" with a thumb off the
 * end of the track. The bounds now come from the data: the surface's year SPAN
 * (min/max pub_year over its locked scope, read once per mount — see
 * TypesenseClient.search's `withYearSpan`), with a fallback for the moment
 * before it arrives, or a surface where it never does.
 */
import type { YearBucket, YearRange, YearSpan } from './types';

/** Fallback floor — the press archive's first decade — used only until the span arrives. */
export const DEFAULT_YEAR_MIN = 1960;

/** Fallback ceiling: the current year, never a constant that goes stale every January. */
export function defaultYearMax(now: Date = new Date()): number {
  return now.getUTCFullYear();
}

export interface YearBounds {
  min: number;
  max: number;
}

/**
 * The span when it is known. Until then: the fallback widened to cover every
 * histogram bar and both ends of the requested range — so nothing the reader
 * asked for or can see is ever drawn off the track, even on a surface whose
 * span request failed.
 */
export function resolveYearBounds(
  span: YearSpan | null,
  buckets: readonly YearBucket[] = [],
  range: YearRange | null = null,
  now: Date = new Date(),
): YearBounds {
  if (span) return { min: span.min, max: span.max };
  const years = [
    ...buckets.map((b) => b.year),
    ...(typeof range?.from === 'number' ? [range.from] : []),
    ...(typeof range?.to === 'number' ? [range.to] : []),
  ];
  return {
    min: Math.min(DEFAULT_YEAR_MIN, ...years),
    max: Math.max(defaultYearMax(now), ...years),
  };
}

/**
 * A requested range, brought inside the bounds.
 *
 *   - an end at or beyond its own bound means "open on that side", so it is
 *     dropped (`?date.to=2030` on a corpus ending in 2026 asks for nothing
 *     the open range does not already give);
 *   - an end beyond the OTHER bound is pulled back onto it (`?date.from=2050`
 *     becomes the last year with documents, which is what the slider can show);
 *   - reversed ends are swapped.
 *
 * Returns null for a range that ends up open on both sides.
 */
export function clampYearRange(range: YearRange | null, bounds: YearBounds): YearRange | null {
  if (!range) return null;
  const clamp = (y: number): number => Math.min(bounds.max, Math.max(bounds.min, Math.trunc(y)));
  let from = typeof range.from === 'number' ? clamp(range.from) : undefined;
  let to = typeof range.to === 'number' ? clamp(range.to) : undefined;
  if (from !== undefined && to !== undefined && from > to) [from, to] = [to, from];
  const out: YearRange = {};
  if (from !== undefined && from > bounds.min) out.from = from;
  if (to !== undefined && to < bounds.max) out.to = to;
  return out.from === undefined && out.to === undefined ? null : out;
}

/** Same range, field by field (null-safe). */
export function sameYearRange(a: YearRange | null, b: YearRange | null): boolean {
  return (a?.from ?? null) === (b?.from ?? null) && (a?.to ?? null) === (b?.to ?? null);
}

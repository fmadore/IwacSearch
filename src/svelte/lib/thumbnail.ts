/**
 * Thumbnail derivative sizing.
 *
 * The indexer stores `thumbnail_url` as the item's first thumbnailed media
 * derivative path — `/files/medium/<storage_id>.jpg` (see
 * OmekaSourceReader::mediaThumbnails). Omeka generates three derivative tiers
 * for every thumbnailed media, all served under `/files/<tier>/<storage_id>`.
 * Measured against the live collection (2026-08-24), the tiers are:
 *
 *   square   200 × 200 centre-crop      8–15 KB
 *   medium   200px on the longest side  9–11 KB
 *   large    800px on the longest side  41–141 KB  (never upscales: a
 *                                       480 × 360 video still stays 480 × 360)
 *
 * Note this is NOT the IIIF Image API (`/full/240,/0/default.jpg`): the search
 * index carries the storage id, not the media id the image service keys on, so
 * Omeka's own sized derivatives are the available mechanism — and they reach
 * the same end (a sized derivative, not one upscaled thumb). Entity thumbnails
 * (EntityAuthority) use the identical path shape, so this applies to both.
 *
 * ── Why a srcset and not one tier ──────────────────────────────────────────
 *
 * The gallery asked for `large` unconditionally, on the reasoning that "the
 * 200px medium would upscale" at tile size. That holds for a portrait scan
 * (whose `medium` is 142 × 200, narrower than the 190px tile) and not at all
 * for the 480 × 360 video stills that dominate the date-sorted landing — so
 * the default browse view pulled 41 KB where 9 KB was pixel-exact, and up to
 * 141 KB on a photograph, for a 190 × 142 box. The audit measured ~1.48 MB
 * for four visible tiles.
 *
 * One tier cannot be right for both a 1× desktop tile and a 3× phone, so the
 * choice goes to the browser: `medium` and `large` are offered together with
 * an honest `sizes`, and it picks per device pixel ratio. At DPR 1 that is
 * `medium` (a 78–92% saving); at DPR ≥ 2 it is `large`, which is the correct
 * answer there rather than a regression.
 *
 * `square` is deliberately NOT a candidate for the 4:3 gallery frame: it is a
 * 1:1 centre crop, so a 4:3 video still would lose a quarter of its width
 * before `object-fit: cover` ever ran.
 *
 * Anything that isn't a recognised `/files/<tier>/…` derivative path is
 * returned unchanged, so a future absolute/IIIF URL passes through untouched
 * — and, having no known tiers, gets no srcset either.
 */

export type ThumbSize = 'square' | 'medium' | 'large';

const DERIVATIVE_RE = /\/files\/(?:square|medium|large|original)\//;

/** The intrinsic width each tier's descriptor claims. See the table above. */
const TIER_WIDTHS: Record<ThumbSize, number> = { square: 200, medium: 200, large: 800 };

/**
 * Return `url` rewritten to the requested derivative tier, or the input
 * unchanged when it isn't a recognised Omeka derivative path (or is empty).
 */
export function sizedThumbnail(url: string | undefined, size: ThumbSize): string | undefined {
  if (!url) return undefined;
  return url.replace(DERIVATIVE_RE, `/files/${size}/`);
}

/**
 * A `srcset` offering `url` at each of `tiers`, or undefined when `url` is
 * not a derivative path we can retier (in which case the caller's plain `src`
 * is the whole story).
 *
 * The `w` descriptors are the tier CONSTRAINTS, not per-image truth: Omeka
 * caps the longest side, so a portrait `medium` is really 142px wide and a
 * landscape `large` really 480px. Overstating is the safe direction — the
 * browser may fetch a larger candidate than strictly needed, never a smaller
 * one than it can use — and the alternative (per-image intrinsic sizes) is
 * data the search index does not carry.
 */
export function thumbnailSrcset(
  url: string | undefined,
  tiers: readonly ThumbSize[],
): string | undefined {
  if (!url || !DERIVATIVE_RE.test(url)) return undefined;
  return tiers.map((t) => `${sizedThumbnail(url, t)} ${TIER_WIDTHS[t]}w`).join(', ');
}

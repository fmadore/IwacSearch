/**
 * Thumbnail derivative sizing.
 *
 * The indexer stores `thumbnail_url` as the item's first thumbnailed media
 * derivative path — `/files/medium/<storage_id>.jpg` (see
 * OmekaSourceReader::mediaThumbnails). Omeka generates three derivative tiers
 * for every thumbnailed media (`square`, `medium`, `large`), all served under
 * `/files/<tier>/<storage_id>.jpg`. So "request a crisp size rather than
 * scaling one stored thumb" (design review §01) is a path swap: pick the tier
 * that fits the slot instead of upscaling the one stored `medium`.
 *
 *   list row  (112px)  → `medium`  — aspect-preserving, crisp at the row size
 *   gallery   (≥178px) → `large`   — the 200px `medium` would upscale here
 *
 * Note this is NOT the IIIF Image API (`/full/240,/0/default.jpg`): the search
 * index carries the storage id, not the media id the image service keys on, so
 * Omeka's own sized derivatives are the available mechanism — and they reach
 * the same end (a sized derivative, not one upscaled thumb). Entity thumbnails
 * (EntityAuthority) use the identical path shape, so this applies to both.
 *
 * Anything that isn't a recognised `/files/<tier>/…` derivative path is
 * returned unchanged, so a future absolute/IIIF URL passes through untouched.
 */

export type ThumbSize = 'square' | 'medium' | 'large';

const DERIVATIVE_RE = /\/files\/(?:square|medium|large|original)\//;

/**
 * Return `url` rewritten to the requested derivative tier, or the input
 * unchanged when it isn't a recognised Omeka derivative path (or is empty).
 */
export function sizedThumbnail(url: string | undefined, size: ThumbSize): string | undefined {
  if (!url) return undefined;
  return url.replace(DERIVATIVE_RE, `/files/${size}/`);
}

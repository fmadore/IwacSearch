import { describe, expect, it } from 'vitest';
import { sizedThumbnail, thumbnailSrcset } from '../../src/svelte/lib/thumbnail';

/**
 * Derivative-tier selection.
 *
 * The gallery used to request `large` for every tile on the reasoning that
 * "the 200px medium would upscale". Measured against the live collection that
 * is true only of portrait scans and false of the 480 × 360 video stills that
 * dominate the date-sorted landing page — so the default browse view pulled
 * 41 KB where 9 KB was pixel-exact, and up to 141 KB on a photograph, into a
 * 190 × 142 box. These cases pin the two things that fix it: the 1× source is
 * the small tier, and both tiers are offered so a retina screen can still
 * have the big one.
 */

const MEDIUM = 'https://islam.zmo.de/files/medium/abc123.jpg';

describe('sizedThumbnail', () => {
  it('retiers a derivative path', () => {
    expect(sizedThumbnail(MEDIUM, 'large')).toBe('https://islam.zmo.de/files/large/abc123.jpg');
    expect(sizedThumbnail(MEDIUM, 'square')).toBe('https://islam.zmo.de/files/square/abc123.jpg');
  });

  it('retiers from any known tier, including original', () => {
    expect(sizedThumbnail('/files/original/abc123.jpg', 'medium')).toBe('/files/medium/abc123.jpg');
    expect(sizedThumbnail('/files/large/abc123.jpg', 'medium')).toBe('/files/medium/abc123.jpg');
  });

  it('passes through anything that is not a derivative path', () => {
    // A future absolute or IIIF URL must survive untouched.
    const iiif = 'https://iiif.example/iiif/3/xyz/full/240,/0/default.jpg';
    expect(sizedThumbnail(iiif, 'medium')).toBe(iiif);
    expect(sizedThumbnail(undefined, 'medium')).toBeUndefined();
    expect(sizedThumbnail('', 'medium')).toBeUndefined();
  });
});

describe('thumbnailSrcset', () => {
  it('offers each tier with its constraint as the width descriptor', () => {
    expect(thumbnailSrcset(MEDIUM, ['medium', 'large'])).toBe(
      'https://islam.zmo.de/files/medium/abc123.jpg 200w, ' +
        'https://islam.zmo.de/files/large/abc123.jpg 800w',
    );
  });

  it('builds the same set from a url already pointing at another tier', () => {
    // thumbnail_url is whatever the indexer stored; the srcset must not
    // depend on which tier that happened to be.
    expect(thumbnailSrcset('/files/large/abc123.jpg', ['medium', 'large'])).toBe(
      '/files/medium/abc123.jpg 200w, /files/large/abc123.jpg 800w',
    );
  });

  it('yields nothing for a url whose tiers are unknown', () => {
    // No srcset is the correct answer here, not a fabricated one: the caller's
    // plain `src` is the whole story for an off-site or IIIF image.
    expect(
      thumbnailSrcset('https://iiif.example/full/240,/0/default.jpg', ['medium']),
    ).toBeUndefined();
    expect(thumbnailSrcset(undefined, ['medium', 'large'])).toBeUndefined();
    expect(thumbnailSrcset('', ['medium', 'large'])).toBeUndefined();
  });
});

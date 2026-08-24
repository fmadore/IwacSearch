import { describe, expect, it } from 'vitest';
import {
  SITE_SLUG_EN,
  SITE_SLUG_FR,
  currentSiteSlug,
  itemPath,
  localizeSiteUrl,
} from '../../src/svelte/lib/siteUrl';

/**
 * The indexer writes every `omeka_url` against the canonical FRENCH site and
 * SiteUrls.php's docblock has always promised that "the English UI swaps
 * afrique_ouest → westafrica in the theme". The swap never existed, so every
 * result title, thumbnail and typeahead suggestion on /s/westafrica handed the
 * reader to the French edition on the first click (Phase-1 critique P0 #1).
 *
 * These cases pin the swap in BOTH directions and — just as importantly — pin
 * where it must NOT fire: an unknown site, an external source URL, a slug
 * inside a query string.
 */
describe('currentSiteSlug', () => {
  it('reads the slug out of an Omeka site path', () => {
    expect(currentSiteSlug('/s/westafrica/page/browse')).toBe(SITE_SLUG_EN);
    expect(currentSiteSlug('/s/afrique_ouest/parcourir')).toBe(SITE_SLUG_FR);
    expect(currentSiteSlug('/s/westafrica')).toBe(SITE_SLUG_EN);
  });

  it('returns null off-site and on a site this module does not know', () => {
    expect(currentSiteSlug('/search/everything')).toBeNull();
    expect(currentSiteSlug('/')).toBeNull();
    expect(currentSiteSlug('/s/some_other_site/page/x')).toBeNull();
    expect(currentSiteSlug('')).toBeNull();
  });
});

describe('localizeSiteUrl', () => {
  const frItem = 'https://islam.zmo.de/s/afrique_ouest/item/110631';
  const enItem = 'https://islam.zmo.de/s/westafrica/item/110631';

  it('rewrites the canonical French URL onto the English site', () => {
    expect(localizeSiteUrl(frItem, SITE_SLUG_EN)).toBe(enItem);
  });

  it('rewrites in the other direction too — the mapping is symmetric', () => {
    expect(localizeSiteUrl(enItem, SITE_SLUG_FR)).toBe(frItem);
  });

  it('leaves a URL already on the current site untouched', () => {
    expect(localizeSiteUrl(frItem, SITE_SLUG_FR)).toBe(frItem);
    expect(localizeSiteUrl(enItem, SITE_SLUG_EN)).toBe(enItem);
  });

  it('handles root-relative URLs', () => {
    expect(localizeSiteUrl('/s/afrique_ouest/item/42', SITE_SLUG_EN)).toBe('/s/westafrica/item/42');
  });

  it('never rewrites when the current site is unknown', () => {
    expect(localizeSiteUrl(frItem, null)).toBe(frItem);
  });

  it('passes external source URLs through — they carry no site slug', () => {
    const yt = 'https://www.youtube.com/watch?v=vtrJSbeZNsg';
    expect(localizeSiteUrl(yt, SITE_SLUG_EN)).toBe(yt);
    expect(localizeSiteUrl('', SITE_SLUG_EN)).toBe('');
  });

  it('only touches the site segment, never a slug inside a query string', () => {
    const withQuery = 'https://islam.zmo.de/s/afrique_ouest/item/42?ref=/s/afrique_ouest/page/x';
    expect(localizeSiteUrl(withQuery, SITE_SLUG_EN)).toBe(
      'https://islam.zmo.de/s/westafrica/item/42?ref=/s/afrique_ouest/page/x',
    );
  });

  it('does not rewrite a path that merely contains /s/<slug>/ deeper down', () => {
    const nested = 'https://example.org/proxy/s/afrique_ouest/item/42';
    expect(localizeSiteUrl(nested, SITE_SLUG_EN)).toBe(nested);
  });
});

describe('itemPath', () => {
  it('builds the fallback item link on the CURRENT site', () => {
    expect(itemPath(42, SITE_SLUG_EN)).toBe('/s/westafrica/item/42');
    expect(itemPath('42', SITE_SLUG_FR)).toBe('/s/afrique_ouest/item/42');
  });

  it('falls back to the canonical French site when the site is unknown', () => {
    // Off-site (the global /search route) there is nothing to localise onto,
    // and the French URL is the one the index would have written anyway.
    expect(itemPath(42, null)).toBe('/s/afrique_ouest/item/42');
  });
});

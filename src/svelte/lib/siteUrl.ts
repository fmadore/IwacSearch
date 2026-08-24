/**
 * Site-slug rewriting for indexed item URLs.
 *
 * The indexer writes every `omeka_url` against the CANONICAL FRENCH site —
 * `https://islam.zmo.de/s/afrique_ouest/item/<id>` (see
 * src/Indexer/Mapper/SiteUrls.php, built from IwacInstance::SITE_SLUG_FR).
 * That file's docblock has always promised the other half:
 *
 *   "the English UI swaps afrique_ouest → westafrica in the theme,
 *    not in the index."
 *
 * The swap was never built in either repo, so on /s/westafrica every result
 * title, thumbnail and typeahead suggestion handed the reader to the French
 * edition on the first click. This module is that swap, done client-side from
 * the page's own context.
 *
 * The slug pair below MIRRORS IwacInstance::SITE_SLUG_FR / SITE_SLUG_EN — the
 * only two IWAC site slugs — and the rewrite is deliberately SYMMETRIC: an
 * EN-slugged URL rendered on the French site is mapped back just as an
 * FR-slugged one is mapped forward. A one-way "if English, replace" would be
 * the same asymmetry that produced the bug, one direction later.
 *
 * On any other path (a third site, or the off-site global /search route) the
 * current slug is unknown, and an unknown site gets NO rewrite: the canonical
 * French URL is a working link everywhere, a guessed one is not.
 */

/** Canonical (French) site slug — the one the index carries. */
export const SITE_SLUG_FR = 'afrique_ouest';

/** English site slug. */
export const SITE_SLUG_EN = 'westafrica';

/** The IWAC public site slugs, in no significant order. */
export const SITE_SLUGS: readonly string[] = [SITE_SLUG_FR, SITE_SLUG_EN];

/**
 * `/s/<slug>/` inside a path, anchored so it can only match the site segment:
 * either at the very start of a root-relative URL, or straight after the
 * authority of an absolute one. Matching a bare `/s/<slug>/` anywhere would
 * also rewrite the inside of a query string or a nested redirect target.
 */
const SITE_PATH_RE = new RegExp(`^((?:[a-z]+:)?//[^/]+)?(/s/)(${SITE_SLUGS.join('|')})(/)`, 'i');

/** Omeka's site-scoped path prefix, e.g. "/s/westafrica/…" → "westafrica". */
const CURRENT_SLUG_RE = /^\/s\/([^/?#]+)/;

/**
 * Which IWAC site is this page on? Returns null when the path names no site
 * (the global /search route) or names one this module doesn't know.
 */
export function currentSiteSlug(
  pathname: string | undefined = typeof window === 'undefined'
    ? undefined
    : window.location.pathname,
): string | null {
  const slug = CURRENT_SLUG_RE.exec(pathname ?? '')?.[1];
  return slug !== undefined && SITE_SLUGS.includes(slug) ? slug : null;
}

/**
 * Rewrite an indexed site URL onto the site the reader is actually on.
 *
 * Unchanged when: the URL carries no known site slug (an external `source_url`,
 * a YouTube watch link), the current site is unknown, or the URL already names
 * the current site.
 */
export function localizeSiteUrl(url: string, slug: string | null = currentSiteSlug()): string {
  if (!url || slug === null) return url;
  return url.replace(SITE_PATH_RE, (match, authority, s, found: string, tail) =>
    found.toLowerCase() === slug ? match : `${authority ?? ''}${s}${slug}${tail}`,
  );
}

/**
 * The item page for an Omeka id on the CURRENT site — the fallback used when a
 * document carries no `omeka_url` at all. Falls back to the canonical French
 * site, which is what the index would have written.
 */
export function itemPath(id: string | number, slug: string | null = currentSiteSlug()): string {
  return `/s/${slug ?? SITE_SLUG_FR}/item/${id}`;
}

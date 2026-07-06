<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Public IWAC site URL construction, shared by the content mappers
 * (AbstractMapper) and the entity mapper (IndexEntityMapper) so the site
 * base + slug live in exactly one place instead of two drifting constants.
 *
 * The FRENCH site slug is deliberate: omeka_url points at the canonical
 * (French) item page; the English UI swaps afrique_ouest → westafrica in
 * the theme, not here.
 */
final class SiteUrls
{
    public const SITE_BASE = 'https://islam.zmo.de';
    public const SITE_SLUG = 'afrique_ouest';

    /** Canonical public item page for an Omeka o:id. */
    public static function itemUrl(int $omekaId): string
    {
        return sprintf('%s/s/%s/item/%d', self::SITE_BASE, self::SITE_SLUG, $omekaId);
    }

    /** IIIF Presentation 3 manifest URL for an Omeka o:id. */
    public static function iiifManifestUrl(int $omekaId): string
    {
        return sprintf('%s/iiif/3/%d/manifest', self::SITE_BASE, $omekaId);
    }

    private function __construct()
    {
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;

/**
 * Public IWAC site URL construction, shared by the content mappers
 * (AbstractMapper) and the entity mapper (IndexEntityMapper).
 *
 * The base + slug themselves come from {@see IwacInstance} with everything
 * else this module assumes about the install. The FRENCH slug is deliberate:
 * omeka_url points at the canonical (French) item page; the English UI swaps
 * afrique_ouest → westafrica in the theme, not in the index.
 */
final class SiteUrls
{
    /** Canonical public item page for an Omeka o:id. */
    public static function itemUrl(int $omekaId): string
    {
        return sprintf('%s/s/%s/item/%d', IwacInstance::SITE_BASE, IwacInstance::SITE_SLUG_FR, $omekaId);
    }

    /** IIIF Presentation 3 manifest URL for an Omeka o:id. */
    public static function iiifManifestUrl(int $omekaId): string
    {
        return sprintf('%s/iiif/3/%d/manifest', IwacInstance::SITE_BASE, $omekaId);
    }

    private function __construct()
    {
    }
}

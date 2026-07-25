<?php
declare(strict_types=1);

namespace IwacSearch\View\Helper;

use IwacSearch\IwacInstance;
use Laminas\View\Helper\AbstractHelper;
use Throwable;

/**
 * Resolves the active UI locale ('fr' | 'en') for the discovery surface.
 *
 * The IWAC instance runs a French site and an English one (slugs in
 * {@see IwacInstance}). Site-scoped surfaces (curated browse pages, page
 * blocks) carry a site context, so we map the site slug to a locale. The
 * global /search route has no site context — it defaults to French (the
 * primary audience).
 *
 * Used by:
 *   - the mount partial, to stamp `locale` into the Svelte bootstrap, and
 *   - the browse templates, to pick localized chrome + the /parcourir vs
 *     /browse route segment.
 */
final class IwacLocale extends AbstractHelper
{
    public function __invoke(): string
    {
        $site = $this->currentSite();
        if ($site === null) {
            // Global routes (e.g. /search) have no site context — default
            // to French, the primary audience.
            return 'fr';
        }

        // Slug heuristic. The site `locale` setting isn't exposed on the
        // SiteRepresentation, so the slug is the signal — and it's the same
        // one the rest of the module keys off (nav links, noscript
        // fallbacks), which is why the slugs live in IwacInstance with
        // everything else instance-specific.
        try {
            $slug = strtolower((string) $site->slug());
        } catch (Throwable) {
            return 'fr';
        }
        if ($slug !== '' && (str_contains($slug, IwacInstance::SITE_SLUG_EN) || str_contains($slug, 'english'))) {
            return 'en';
        }
        return 'fr';
    }

    /**
     * The current site representation, or null off-site (global routes).
     * currentSite() is an Omeka view helper; guard in case it's absent.
     */
    private function currentSite(): ?object
    {
        try {
            $view = $this->getView();
            /** @var object|null $site */
            $site = $view->currentSite();
            return $site;
        } catch (Throwable) {
            return null;
        }
    }
}

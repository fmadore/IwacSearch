<?php
declare(strict_types=1);

namespace IwacSearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Throwable;

/**
 * Builds the URL of the public search landing page for the current site,
 * in the site's own language.
 *
 *   French site  (/s/afrique_ouest) → /s/afrique_ouest/recherche
 *   English site (/s/westafrica)    → /s/westafrica/search
 *   Off-site     (global /search)   → /search
 *
 * The IWAC-theme header search form calls this to set its `action`, so a
 * visitor who submits the header box lands on the module's faceted
 * Typesense surface *inside their own language site*. Mirrors the
 * /parcourir vs /browse split owned by IwacBrowse + IwacLocale.
 *
 * The theme guards on this helper's presence and falls back to Omeka core's
 * /index/search form when the module is inactive, so a broken return here
 * should still degrade rather than fatal — every failure path yields the
 * global /search page instead of an exception.
 */
final class IwacSearchUrl extends AbstractHelper
{
    public function __invoke(): string
    {
        $view = $this->getView();

        try {
            /** @var object|null $site */
            $site = $view->currentSite();
        } catch (Throwable) {
            $site = null;
        }

        // Off-site (the global /search route has no site mount to nest under).
        if ($site === null) {
            return $view->basePath('/search');
        }

        // Reuse the shared slug→locale heuristic so the /recherche vs /search
        // choice stays consistent with the rest of the discovery surface.
        $locale = 'fr';
        try {
            $locale = (string) $view->iwacLocale();
        } catch (Throwable) {
            // Helper missing/failed — default to French, the primary audience.
        }

        $routeName = $locale === 'en' ? 'site/iwac-search' : 'site/iwac-recherche';

        try {
            return (string) $view->url($routeName, ['site-slug' => $site->slug()]);
        } catch (Throwable) {
            // Route not registered yet, or URL assembly failed — degrade to
            // the global search page rather than emitting a broken link.
            return $view->basePath('/search');
        }
    }
}

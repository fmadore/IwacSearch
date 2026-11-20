<?php

declare(strict_types=1);

namespace IwacSearch\View\Helper;

use IwacSearch\IwacInstance;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Renderer\PhpRenderer;
use Throwable;

/**
 * Resolves the active UI locale ('fr' | 'en') for the discovery surface.
 *
 * The IWAC instance runs a French site and an English one (slugs in
 * {@see IwacInstance}). Site-scoped surfaces (page blocks, the site search
 * routes) carry a site context: its `locale` site setting decides, as it does
 * in the theme, IWAC-SEO and IwacVisualizations. Only a site whose setting is
 * empty or neither French nor English falls back to the slug, so a staging
 * site under another slug still speaks its own language. The global /search
 * route has no site context — it defaults to French (the primary audience).
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

        $setting = $this->siteLocaleSetting();
        if ($setting !== null) {
            return $setting;
        }

        // Slug heuristic, for a site with no usable locale setting: the slugs
        // live in IwacInstance with everything else instance-specific.
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
     * 'fr' or 'en' from the current site's `locale` setting ("fr", "en_US"…),
     * or null when it is empty (the site follows the global locale), names
     * another language, or the siteSetting helper is unavailable.
     */
    private function siteLocaleSetting(): ?string
    {
        $view = $this->getView();
        if (!$view instanceof PhpRenderer) {
            return null;
        }
        try {
            $value = $view->siteSetting('locale');
        } catch (Throwable) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $language = strtolower(substr(trim($value), 0, 2));
        return $language === 'fr' || $language === 'en' ? $language : null;
    }

    /**
     * The current site representation, or null off-site (global routes).
     * currentSite() is an Omeka view helper; guard in case it's absent.
     *
     * @return \Omeka\Api\Representation\SiteRepresentation|null
     */
    private function currentSite(): ?object
    {
        $view = $this->getView();
        if (!$view instanceof PhpRenderer) {
            return null;
        }
        try {
            return $view->currentSite();
        } catch (Throwable) {
            return null;
        }
    }
}

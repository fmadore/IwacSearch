<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use IwacSearch\Search\LegacySearchRedirect;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Redirects Omeka's public advanced-search entry points to IwacSearch.
 *
 * The routes are registered by this module, so disabling it restores Omeka's
 * own controllers automatically. The canonical URL remains the
 * iwacSearchUrl view helper: by dispatch time Omeka has prepared the current
 * site, its theme, and its locale, so the helper can choose /search/everything
 * or /recherche/tout without duplicating that policy here.
 */
final class LegacySearchController extends AbstractActionController
{
    public function __construct(private readonly PhpRenderer $view)
    {
    }

    public function redirectAction(): Response
    {
        $target = $this->view->iwacSearchUrl();
        $target = LegacySearchRedirect::targetUrl(
            $target,
            $this->params()->fromQuery('q'),
            $this->params()->fromQuery('fulltext_search'),
        );

        // Laminas' redirect plugin returns a temporary 302 response. This
        // deliberately avoids browsers caching the handoff permanently: if
        // the module is disabled, Omeka's normal route must become reachable.
        return $this->redirect()->toUrl($target);
    }
}

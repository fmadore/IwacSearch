<?php
declare(strict_types=1);

namespace IwacSearch\Asset;

use Laminas\View\Renderer\PhpRenderer;

/**
 * The one place that knows which files make up the compiled search app.
 *
 * Called from Module::injectSvelteAssets (the /search routes' view.layout
 * listener) and IwacSearchBlock::render (page blocks) — both surfaces must
 * ship the identical asset set, and the Vite bundle layout only has to be
 * updated here when it changes.
 *
 * Idempotent per page: headLink/headScript dedupe by URL, so a page with a
 * block AND the controller listener (or several blocks) still emits each
 * tag exactly once.
 */
final class SvelteAssets
{
    public static function injectSearchApp(PhpRenderer $view): void
    {
        // The block CSS (server-rendered skeleton + container) loads first.
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/iwac-search.css', 'IwacSearch')
        );
        // The compiled Svelte bundle's CSS — contains every component-scoped
        // style (FacetPanel, ResultItem, Pagination, …). Vite's IIFE lib
        // build does NOT auto-inject this from the JS at runtime, so without
        // this <link> tag the page mounts the components but renders them
        // unstyled — exactly the "hot mess" hit at 0.2.18 → 0.2.19.
        $view->headLink()->appendStylesheet(
            $view->assetUrl('dist/iwac-search.css', 'IwacSearch')
        );
        // The compiled Svelte bundle.
        $view->headScript()->appendFile(
            $view->assetUrl('dist/iwac-search.js', 'IwacSearch'),
            'text/javascript',
            ['defer' => true]
        );
    }
}

<?php
declare(strict_types=1);

/**
 * IwacSearch — Omeka S module.
 *
 * Owns the public discovery surface (/search, /browse/{slug}) backed by
 * Typesense. Admin search, item detail, ingest, and IIIF stay on Omeka.
 *
 * Lifecycle:
 *  - install/upgrade: ensure module's own tables exist (iwac_browse_config in M3+)
 *  - bootstrap (attachListeners): inject Svelte assets on /search and
 *    /browse, plus (M4) wire api.*.post listeners so edits in Omeka
 *    propagate to Typesense
 *
 * @see https://github.com/fmadore/IWAC-docker/blob/main/docs/iwac-search-roadmap.md
 */

namespace IwacSearch;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Subscribe to Omeka events.
     *
     * For M1 we only need asset injection on the standalone /search and
     * /browse routes — the page block injects assets in its own render()
     * (idempotent via headScript dedup) so no listener is needed for blocks.
     *
     * M4 will add api.create/update/delete.post listeners here for
     * incremental indexing.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            Controller\SearchController::class,
            'view.layout',
            [$this, 'injectSvelteAssets']
        );
    }

    /**
     * Append the Svelte bundle's JS + CSS to the layout when the
     * SearchController renders. headScript/headLink dedupe by URL, so
     * having the page block call appendFile() inside its render() too is
     * harmless — the asset still loads exactly once per page.
     */
    public function injectSvelteAssets(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof \Laminas\View\Renderer\PhpRenderer) {
            return;
        }
        // The block CSS (server-rendered skeleton + container) loads first.
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/iwac-search.css', 'IwacSearch')
        );
        // The compiled Svelte bundle. Vite emits both files into asset/dist;
        // the .js import-loads its sibling .css automatically.
        $view->headScript()->appendFile(
            $view->assetUrl('dist/iwac-search.js', 'IwacSearch'),
            'text/javascript',
            ['defer' => true]
        );
    }

    public function install(ServiceLocatorInterface $services): void
    {
        // M3 will create iwac_browse_config here. Empty for M0–M2.
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        // Drop module-owned tables only — never touches Typesense data,
        // since that may be shared with a parallel install.
        // Optional: clean up the cached search-only key.
        $settings = $services->get('Omeka\Settings');
        $settings->delete('iwac_search_typesense_search_key');
    }
}

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
use Laminas\ModuleManager\ModuleManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Load the module's Composer autoloader before any IwacSearch\… class
     * is referenced. Omeka's main autoloader only maps Omeka\\ → application,
     * so per-module namespaces (BrowseConfigRepository, CountrySeeder, the
     * Typesense SDK, etc.) need their own autoload wired in here.
     *
     * ModuleManager calls init() earlier than onBootstrap/install/
     * attachListeners, so this fires before any class resolution inside
     * this file. Matches the pattern used by SearchSolr/Module.php.
     */
    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
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

    /**
     * Install: create iwac_browse_config table + seed 6 country pages.
     *
     * Idempotent — `CREATE TABLE IF NOT EXISTS` and the seeder's
     * existsBySlug() guard mean re-running install (after an uninstall
     * + reinstall, or as part of an upgrade path) never clobbers data.
     */
    public function install(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement(Browse\BrowseConfigRepository::createTableSql());

        $repository = new Browse\BrowseConfigRepository($connection);
        $logger = $services->has('Omeka\Logger') ? $services->get('Omeka\Logger') : new \Psr\Log\NullLogger();
        $seeder = new Browse\CountrySeeder($repository, $logger);
        $stats = $seeder->seed();
        $logger->info('IwacSearch country browse pages seeded', $stats);
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        // Drop module-owned tables only — never touches Typesense data,
        // since that may be shared with a parallel install.
        $connection->executeStatement(Browse\BrowseConfigRepository::dropTableSql());

        // Clean up the cached search-only key so a fresh install bootstraps
        // a new one rather than reusing a key the previous install owned.
        $settings = $services->get('Omeka\Settings');
        $settings->delete('iwac_search_typesense_search_key');
    }
}

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

// Load the module's Composer autoloader at file-scope so IwacSearch\… classes
// resolve even on first-time install, where Omeka instantiates Module and
// calls install() directly without going through the ModuleManager pipeline
// (so init() wouldn't fire yet). Matches the ImageServer / IiifServer pattern.
require_once __DIR__ . '/vendor/autoload.php';

use IwacSearch\Log\OmekaPsrLogger;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Register ACL rules before the dispatch reaches the controller.
     *
     * Editors, site-admins, and global-admins can all use the curated
     * browse-config admin — it's a content operation, not a system
     * setting. The `admin/` parent route already guarantees the user
     * is authenticated, so null-role (anonymous) access is never
     * possible here; this `allow()` call merely narrows *which* roles
     * pass the check inside the admin session.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');
        $acl->allow(
            [
                Acl::ROLE_EDITOR,
                Acl::ROLE_SITE_ADMIN,
                Acl::ROLE_GLOBAL_ADMIN,
            ],
            [Controller\Admin\BrowseConfigController::class],
            // Actions on the admin controller — all four CRUD actions + the
            // HTML shell. Listed explicitly so adding a future privilege
            // (e.g. 'reorder') is a conscious change, not a silent grant.
            ['browse', 'apiList', 'apiItem']
        );
    }

    /**
     * Subscribe to Omeka events.
     *
     * For M1 we only need asset injection on the standalone /search and
     * /browse routes — the page block injects assets in its own render()
     * (idempotent via headScript dedup) so no listener is needed for blocks.
     *
     * M3.5 adds the admin CRUD surface, which loads its own bundle via
     * the view template (asset/dist/iwac-search-admin.js).
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
        // Omeka\Logger is a Laminas\Log\Logger, not PSR-3. Wrap via our own
        // adapter — Omeka's bundled Laminas\Log\PsrLoggerAdapter fatals under
        // psr/log 3.x (which typesense-php / Guzzle pull into our vendor tree).
        $logger = $services->has('Omeka\Logger')
            ? new OmekaPsrLogger($services->get('Omeka\Logger'))
            : new \Psr\Log\NullLogger();
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

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

use IwacSearch\Log\LoggerResolver;
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
     * Register ACL rules before dispatch reaches a controller.
     *
     * Two zones:
     *
     *   1. Public discovery (SearchController) — index / token / browse.
     *      Granted to the null role so anonymous site visitors can hit
     *      /search, /browse[/{slug}], and /discovery/token. Without this,
     *      Omeka's default deny-by-default ACL throws
     *      PermissionDeniedException for every anonymous request and the
     *      Svelte client shows "Search unavailable. Token HTTP 500" on
     *      every public surface (including page blocks on Site pages,
     *      because the block JS still calls /discovery/token).
     *
     *   2. Admin CRUD (Admin\BrowseConfigController) — restricted to
     *      editor + site-admin + global-admin. The /admin/ parent route
     *      already guarantees authentication, so this `allow` only
     *      narrows *which* admin roles pass the check.
     *
     * Pass `null` as the first arg to allow EVERY role (anonymous +
     * authenticated). Listing privileges explicitly means adding a
     * future action is a conscious decision rather than a silent grant.
     */
    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /** @var Acl $acl */
        $acl = $event->getApplication()->getServiceManager()->get('Omeka\Acl');

        // Public — anonymous visitors are the primary audience for
        // /search, /browse, and the page block's /discovery/token call.
        $acl->allow(
            null,
            [Controller\SearchController::class],
            ['index', 'token', 'browse']
        );

        // Admin CRUD — editors and above only.
        $acl->allow(
            [
                Acl::ROLE_EDITOR,
                Acl::ROLE_SITE_ADMIN,
                Acl::ROLE_GLOBAL_ADMIN,
            ],
            [Controller\Admin\BrowseConfigController::class],
            ['browse', 'apiList', 'apiItem']
        );

        // Maintenance page — same role tier. Lets editors dispatch
        // reindex / stopwords-sync jobs from the admin UI without
        // needing docker exec access. The actions themselves are
        // dispatched as Omeka background jobs, so a misclick can't
        // hold up the request thread.
        $acl->allow(
            [
                Acl::ROLE_EDITOR,
                Acl::ROLE_SITE_ADMIN,
                Acl::ROLE_GLOBAL_ADMIN,
            ],
            [Controller\Admin\MaintenanceController::class],
            ['index', 'reindex', 'syncStopwords']
        );
    }

    /**
     * Subscribe to Omeka events.
     *
     *   - view.layout on SearchController → asset injection for /search,
     *     /browse, /browse/{slug} (M1).
     *   - api.update.post on ItemAdapter → sync is_public changes + any
     *     future incremental doc refresh to Typesense (M4).
     *   - api.delete.post on ItemAdapter → remove the doc from Typesense
     *     so stale hits don't survive a resource delete (M4).
     *
     * NOT attached: api.create.post. New items need the full mapper
     * pipeline (authorities, OCR overlay, embeddings) that only the
     * bulk reindex provides — a half-indexed doc ranks worse than no
     * doc. New items wait for the next nightly / monthly reindex.
     *
     * The admin CRUD surface (M3.5) doesn't need event wiring; it loads
     * its own bundle via the view template.
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // view.layout for the SearchController stays inline because
        // injectSvelteAssets has nothing to inject from a service —
        // it's a plain view-helper composition.
        $sharedEventManager->attach(
            Controller\SearchController::class,
            'view.layout',
            [$this, 'injectSvelteAssets']
        );

        // M4 incremental indexing — handler bodies live in
        // Indexer\ItemEventListener so they're testable + so Module.php
        // doesn't accumulate listener logic. Resolving the listener
        // here (rather than at fire time) is fine because the listener
        // itself holds the IncrementalIndexer, which lazy-resolves the
        // TypesenseClient on first use.
        $listener = $this->resolveItemEventListener();
        if ($listener !== null) {
            $sharedEventManager->attach(
                \Omeka\Api\Adapter\ItemAdapter::class,
                'api.update.post',
                [$listener, 'onItemUpdate']
            );
            $sharedEventManager->attach(
                \Omeka\Api\Adapter\ItemAdapter::class,
                'api.delete.post',
                [$listener, 'onItemDelete']
            );
        }
    }

    /**
     * Resolve the ItemEventListener from the service manager, returning
     * null if the SL isn't available yet (extreme bootstrap edge cases).
     * `attachListeners` runs after `init` and after the SL is built, so
     * in normal operation this returns a real listener; the null branch
     * is just defensive — a missing SL means we can't attach, full stop.
     */
    private function resolveItemEventListener(): ?Indexer\ItemEventListener
    {
        try {
            $sl = $this->getServiceLocator();
            if ($sl === null) {
                return null;
            }
            /** @var Indexer\ItemEventListener $listener */
            $listener = $sl->get(Indexer\ItemEventListener::class);
            return $listener;
        } catch (\Throwable) {
            return null;
        }
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
     * Install: create iwac_browse_config table + seed default browse pages.
     *
     * Idempotent — `CREATE TABLE IF NOT EXISTS` and the seeders'
     * existsBySlug() guard mean re-running install (after an uninstall
     * + reinstall, or as part of an upgrade path) never clobbers data.
     *
     * Two seeders run on first install:
     *   - CountrySeeder    — six country browse pages (Bénin, Burkina Faso, …).
     *   - ReferencesSeeder — one /browse/references page locked to type_s=reference.
     *
     * Existing installations that upgrade from a pre-references-seeder
     * version: the references-seeder check is harmless (no row exists →
     * one gets seeded). Either run the upgrade path or invoke the
     * seeder once via a one-off CLI script.
     */
    public function install(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement(Browse\BrowseConfigRepository::createTableSql());

        $repository = new Browse\BrowseConfigRepository($connection);
        $logger = LoggerResolver::fromContainer($services);

        $countryStats = (new Browse\CountrySeeder($repository, $logger))->seed();
        $logger->info('IwacSearch country browse pages seeded', $countryStats);

        $refStats = (new Browse\ReferencesSeeder($repository, $logger))->seed();
        $logger->info('IwacSearch references browse page seeded', $refStats);
    }

    /**
     * Upgrade hook — runs when an installed module's version on disk is
     * newer than the recorded module-table version. We use it to layer
     * in browse-config seeds that didn't exist at first-install time
     * without forcing the operator to uninstall/reinstall.
     *
     * Each branch is guarded with a version comparison so re-running an
     * upgrade is a no-op once the seed has landed.
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        // 0.2.17 introduced the references browse page. Anyone on
        // <0.2.17 missed the install-time seed; trigger it on upgrade.
        if (version_compare((string) $oldVersion, '0.2.17', '<')) {
            $connection = $services->get('Omeka\Connection');
            $repository = new Browse\BrowseConfigRepository($connection);
            $logger = LoggerResolver::fromContainer($services);
            $stats = (new Browse\ReferencesSeeder($repository, $logger))->seed();
            $logger->info('IwacSearch references browse page seeded on upgrade', $stats);
        }
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

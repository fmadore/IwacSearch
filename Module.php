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

        // Header-search enhancer — a tiny, separate bundle injected on EVERY
        // public site page (not just SearchController) so the theme header
        // search box gets the Typesense typeahead everywhere. Attached to '*'
        // and guarded to site requests inside the handler, so admin + API
        // layouts stay untouched.
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'injectHeaderSearchAssets']
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
        // The compiled Svelte bundle's CSS — contains every component-scoped
        // style (FacetPanel, ResultItem, Pagination, …). Vite's IIFE lib
        // build does NOT auto-inject this from the JS at runtime, so without
        // this <link> tag the page mounts the components but renders them
        // with zero styling — which is exactly the "hot mess" we hit at
        // 0.2.18 → 0.2.19. Belongs in the same headLink stack as the static
        // CSS above; Laminas dedupes by URL so calling it from the block
        // layout too is harmless.
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

    /**
     * Inject the tiny header-search enhancer on every public SITE page.
     *
     * Separate from injectSvelteAssets (which ships the full ~90 KB search
     * app only on SearchController routes): the header search box lives in
     * the theme chrome on every page, so its typeahead must load site-wide.
     * The bundle is framework-free and small (~15 KB); it no-ops harmlessly
     * if the active theme has no [data-iwac-header-search] form.
     *
     * Skips admin + API layouts via the status() helper. The inline config
     * blob carries the (basePath-resolved) token + search endpoints and the
     * site locale; the bundle reads it as window.IWAC_HEADER_SEARCH and the
     * landing URL from the form's own action (set by the iwacSearchUrl
     * helper in the theme).
     */
    public function injectHeaderSearchAssets(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof \Laminas\View\Renderer\PhpRenderer) {
            return;
        }

        // Public site pages only — never admin / API layouts.
        try {
            if (!$view->status()->isSiteRequest()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        // Endpoints are global (the site mount doesn't prefix /discovery/token
        // or /search-api); basePath() keeps them correct under a subdirectory
        // install. Locale picks the FR/EN dropdown strings.
        $config = [
            'endpoints' => [
                'token'  => $view->basePath('/discovery/token'),
                'search' => $view->basePath('/search-api/multi_search'),
            ],
            'locale' => $view->iwacLocale(),
        ];
        // JSON_HEX_TAG neutralises any "</script>" inside the (server-trusted)
        // values so the inline blob can't break out of the <script> element.
        $json = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
        if ($json === false) {
            return;
        }

        // The header CSS only styles the typeahead dropdown, which stays hidden
        // until the user focuses / types — so it must never block first paint.
        // The whole feature is JS-gated (header.js enhances the form), so loading
        // the stylesheet from JS via the media="print" → onload swap costs no-JS
        // users nothing they'd otherwise get, and drops one render-blocking
        // request from every site page (incl. the homepage). The fetch still
        // starts during head parse, so the dropdown is styled well before any
        // interaction.
        $headerCssUrl = json_encode(
            $view->assetUrl('dist/iwac-search-header.css', 'IwacSearch'),
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
        );
        // Config first so it's defined before the deferred bundle executes.
        $view->headScript()->appendScript(
            'window.IWAC_HEADER_SEARCH = ' . $json . ';'
            . '(function(){var l=document.createElement("link");l.rel="stylesheet";'
            . 'l.href=' . $headerCssUrl . ';l.media="print";'
            . 'l.onload=function(){this.media="all";};'
            . '(document.head||document.documentElement).appendChild(l);})();'
        );
        $view->headScript()->appendFile(
            $view->assetUrl('dist/iwac-search-header.js', 'IwacSearch'),
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

        $allStats = (new Browse\AllCountriesSeeder($repository, $logger))->seed();
        $logger->info('IwacSearch all-countries browse page seeded', $allStats);

        $indexStats = (new Browse\IndexSeeder($repository, $logger))->seed();
        $logger->info('IwacSearch index browse page seeded', $indexStats);

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

        // 0.2.22 added the all-countries entry point and surfaced the
        // centrality + subjectivity sentiment facets. Seed the new page and
        // re-apply the default facet stack to the seeded country pages + the
        // all-countries page so existing installs get the Sentiment group
        // without an uninstall/reinstall. Only touches system-seeded slugs;
        // admin-authored custom pages are left untouched.
        if (version_compare((string) $oldVersion, '0.2.22', '<')) {
            $connection = $services->get('Omeka\Connection');
            $repository = new Browse\BrowseConfigRepository($connection);
            $logger = LoggerResolver::fromContainer($services);

            $allStats = (new Browse\AllCountriesSeeder($repository, $logger))->seed();
            $logger->info('IwacSearch all-countries browse page seeded on upgrade', $allStats);

            $facetsBySlug = [];
            foreach (Browse\Countries::slugs() as $slug) {
                $facetsBySlug[$slug] = Browse\CountrySeeder::DEFAULT_FACETS;
            }
            $facetsBySlug[Browse\AllCountriesSeeder::SLUG] = Browse\AllCountriesSeeder::DEFAULT_FACETS;

            foreach ($facetsBySlug as $slug => $facets) {
                $existing = $repository->findBySlug($slug);
                if ($existing === null || $existing->prominentFacets === $facets) {
                    continue;
                }
                $repository->save(new Browse\BrowseConfig(
                    id:               $existing->id,
                    slug:             $existing->slug,
                    title:            $existing->title,
                    introHtml:        $existing->introHtml,
                    lockedFilters:    $existing->lockedFilters,
                    prominentFacets:  $facets,
                    defaultSort:      $existing->defaultSort,
                    resultsPerPage:   $existing->resultsPerPage,
                    position:         $existing->position,
                ));
                $logger->info('IwacSearch refreshed browse facets on upgrade', ['slug' => $slug]);
            }
        }

        // 0.2.23 added the Index browse page (entity collection). Seed it on
        // upgrade. NOTE: the entity collection itself is built by the next
        // discovery:reindex run (it now builds both collections) — until
        // then the page renders but returns no entities.
        if (version_compare((string) $oldVersion, '0.2.23', '<')) {
            $connection = $services->get('Omeka\Connection');
            $repository = new Browse\BrowseConfigRepository($connection);
            $logger = LoggerResolver::fromContainer($services);
            $stats = (new Browse\IndexSeeder($repository, $logger))->seed();
            $logger->info('IwacSearch index browse page seeded on upgrade', $stats);
        }

        // 0.2.27 reordered the all-countries facets so Country (the natural
        // slicer for an all-corpus page) sits ABOVE Type. The seeder's
        // existsBySlug() guard never rewrites an already-seeded row, so push
        // the new order onto the existing all-countries config here. The ===
        // guard makes it a no-op once applied and leaves any admin-customised
        // facet list untouched only if it happens to already match — an admin
        // who reordered these intentionally would be re-normalised, which is
        // acceptable for a system-seeded page (custom pages are never touched).
        if (version_compare((string) $oldVersion, '0.2.27', '<')) {
            $connection = $services->get('Omeka\Connection');
            $repository = new Browse\BrowseConfigRepository($connection);
            $logger = LoggerResolver::fromContainer($services);

            $slug = Browse\AllCountriesSeeder::SLUG;
            $existing = $repository->findBySlug($slug);
            if ($existing !== null
                && $existing->prominentFacets !== Browse\AllCountriesSeeder::DEFAULT_FACETS
            ) {
                $repository->save(new Browse\BrowseConfig(
                    id:               $existing->id,
                    slug:             $existing->slug,
                    title:            $existing->title,
                    introHtml:        $existing->introHtml,
                    lockedFilters:    $existing->lockedFilters,
                    prominentFacets:  Browse\AllCountriesSeeder::DEFAULT_FACETS,
                    defaultSort:      $existing->defaultSort,
                    resultsPerPage:   $existing->resultsPerPage,
                    position:         $existing->position,
                ));
                $logger->info('IwacSearch reordered all-countries facets on upgrade', ['slug' => $slug]);
            }
        }

        // 0.2.28 reworked the references browse page: Country moved to the top
        // of the facet list (directly under the year slider) and the Author
        // (creator_ss) facet — indexed all along but never surfaced — was
        // added. Re-apply the new facet set to the existing references config;
        // the === guard makes it a no-op once applied. No Typesense reindex is
        // needed: creator_ss is already populated in the live collection.
        if (version_compare((string) $oldVersion, '0.2.28', '<')) {
            $connection = $services->get('Omeka\Connection');
            $repository = new Browse\BrowseConfigRepository($connection);
            $logger = LoggerResolver::fromContainer($services);

            $slug = Browse\ReferencesSeeder::SLUG;
            $existing = $repository->findBySlug($slug);
            if ($existing !== null
                && $existing->prominentFacets !== Browse\ReferencesSeeder::DEFAULT_FACETS
            ) {
                $repository->save(new Browse\BrowseConfig(
                    id:               $existing->id,
                    slug:             $existing->slug,
                    title:            $existing->title,
                    introHtml:        $existing->introHtml,
                    lockedFilters:    $existing->lockedFilters,
                    prominentFacets:  Browse\ReferencesSeeder::DEFAULT_FACETS,
                    defaultSort:      $existing->defaultSort,
                    resultsPerPage:   $existing->resultsPerPage,
                    position:         $existing->position,
                ));
                $logger->info('IwacSearch reordered + exposed author on references facets on upgrade', ['slug' => $slug]);
            }
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

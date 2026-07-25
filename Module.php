<?php
declare(strict_types=1);

/**
 * IwacSearch — Omeka S module.
 *
 * Owns the public discovery surface (/search, /search/everything, page blocks)
 * backed by Typesense. Admin search, item detail, ingest, and IIIF stay on
 * Omeka.
 *
 * Lifecycle:
 *  - install: nothing (the module owns no database tables)
 *  - upgrade: drop the retired iwac_browse_config table if present
 *  - bootstrap (attachListeners): inject Svelte assets on the search routes,
 *    plus wire api.*.post listeners so edits in Omeka propagate to Typesense
 *
 * @see https://github.com/fmadore/IWAC-docker/blob/main/docs/iwac-search-roadmap.md
 */

namespace IwacSearch;

// Load the module's Composer autoloader at file-scope so IwacSearch\… classes
// resolve even on first-time install, where Omeka instantiates Module and
// calls install() directly without going through the ModuleManager pipeline
// (so init() wouldn't fire yet). Matches the ImageServer / IiifServer pattern.
require_once __DIR__ . '/vendor/autoload.php';

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    /**
     * Module version that retired the curated-browse system (and its
     * `iwac_browse_config` table). Upgrades from at or above this version
     * have nothing to drop — see upgrade().
     */
    private const BROWSE_CONFIG_RETIRED_IN = '3.0.0';

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Register ACL rules before dispatch reaches a controller.
     *
     * Two zones:
     *
     *   1. Public discovery (SearchController) — index / token / browse /
     *      everything. Granted to the null role so anonymous site visitors can
     *      hit /search, /search/everything, /discovery/token, and the legacy
     *      /browse redirect. Without this, Omeka's deny-by-default ACL throws
     *      PermissionDeniedException for every anonymous request and the Svelte
     *      client shows "Search unavailable. Token HTTP 500" on every public
     *      surface (including page blocks, whose JS still calls /discovery/token).
     *
     *   2. Admin Maintenance (Admin\MaintenanceController) — restricted to
     *      editor + site-admin + global-admin. The /admin/ parent route already
     *      guarantees authentication, so this `allow` only narrows *which* admin
     *      roles pass the check.
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
            ['index', 'token', 'browse', 'everything']
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
            ['index', 'reindex', 'syncStopwords', 'syncSynonyms', 'provisionAnalytics']
        );
    }

    /**
     * Subscribe to Omeka events.
     *
     *   - view.layout on SearchController → asset injection for /search,
     *     /browse, /browse/{slug} (M1).
     *   - api.create.post / api.update.post on ItemAdapter → re-map the
     *     full document from MySQL and upsert it to Typesense (M4).
     *   - api.delete.post on ItemAdapter → remove the doc from Typesense
     *     so stale hits don't survive a resource delete (M4).
     *
     * api.create.post IS now attached: since the indexer reads the same
     * Omeka database the save just wrote to, a new item gets a complete
     * document immediately — no longer the half-baked placeholder the old
     * HF-only pipeline would have produced. Non-content items (authority
     * records, unmapped classes) are skipped inside the indexer.
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
        // doesn't accumulate listener logic.
        //
        // Resolution is DEFERRED to fire time: building the listener here
        // would construct the whole indexer graph (six mappers,
        // EntityAuthority, CountryResolver's newspaper-countries.json parse)
        // on every request — including anonymous GETs where no api.*.post
        // can ever fire. The memoized closure makes the first write event of
        // a request pay the construction cost once; read requests pay nothing.
        $listener = null;
        $deferred = function (string $method) use (&$listener): callable {
            return function (Event $event) use (&$listener, $method): void {
                $listener ??= $this->resolveItemEventListener();
                $listener?->$method($event);
            };
        };

        $itemAdapter = \Omeka\Api\Adapter\ItemAdapter::class;
        $sharedEventManager->attach($itemAdapter, 'api.create.post', $deferred('onItemCreate'));
        $sharedEventManager->attach($itemAdapter, 'api.update.post', $deferred('onItemUpdate'));
        $sharedEventManager->attach($itemAdapter, 'api.delete.post', $deferred('onItemDelete'));

        // Batch operations hydrate entities directly — the per-item
        // events above never fire for them. Privacy-relevant: a batch
        // visibility flip must reach Typesense promptly, because the
        // public key filters on the INDEXED is_public value.
        $sharedEventManager->attach($itemAdapter, 'api.batch_create.post', $deferred('onItemBatchCreate'));
        $sharedEventManager->attach($itemAdapter, 'api.batch_update.post', $deferred('onItemBatchUpdate'));
        $sharedEventManager->attach($itemAdapter, 'api.batch_delete.post', $deferred('onItemBatchDelete'));

        // Direct media edits (upload to an existing item via the API,
        // replace file, toggle visibility, delete) fire no item event,
        // yet thumbnail_url / iiif_manifest derive from the item's
        // primary media — re-map the parent.
        $mediaAdapter = \Omeka\Api\Adapter\MediaAdapter::class;
        $sharedEventManager->attach($mediaAdapter, 'api.create.post', $deferred('onMediaWrite'));
        $sharedEventManager->attach($mediaAdapter, 'api.update.post', $deferred('onMediaWrite'));
        $sharedEventManager->attach($mediaAdapter, 'api.delete.post', $deferred('onMediaDelete'));

        // Item-set deletion silently unlinks every member, and
        // country_ss (references / documents / photographs) derives
        // from those memberships. Capture members at .pre (join rows
        // are gone by .post), re-map them at .post.
        $itemSetAdapter = \Omeka\Api\Adapter\ItemSetAdapter::class;
        $sharedEventManager->attach($itemSetAdapter, 'api.delete.pre', $deferred('onItemSetDeletePre'));
        $sharedEventManager->attach($itemSetAdapter, 'api.delete.post', $deferred('onItemSetDeletePost'));
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
        // Shared with IwacSearchBlock::render — one source of truth for the
        // bundle's file set (see Asset\SvelteAssets for the why of each file).
        Asset\SvelteAssets::injectSearchApp($view);
    }

    /**
     * Inject the tiny header-search enhancer on every public SITE page.
     *
     * Separate from injectSvelteAssets (which ships the full search app only
     * on SearchController routes): the header search box lives in the theme
     * chrome on every page, so its typeahead must load site-wide. The bundle
     * is framework-free and small (~34 KB raw / ~13 KB gzipped); it no-ops
     * harmlessly if the active theme has no [data-iwac-header-search] form.
     *
     * Keep it that way: it imports `runSuggest()` rather than TypesenseClient
     * precisely because class methods can't be tree-shaken, and importing the
     * client put the export / map / union / histogram / facet-value code on
     * every public page. Adding an import here is a site-wide cost.
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
            'endpoints' => array_map(
                static fn(string $stem): string => $view->basePath($stem),
                Search\SurfaceBootstrap::ENDPOINT_STEMS
            ),
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
     * Install: nothing to do. The module owns no database tables — the former
     * curated-browse system (iwac_browse_config) was retired and its scopes
     * moved into PresetCatalog, now selected per page block.
     */
    public function install(ServiceLocatorInterface $services): void
    {
    }

    /**
     * Upgrade hook. The curated-browse system was retired, so drop its orphan
     * table on any install that still has it. iwac_browse_config held only
     * seeded + admin-authored scope rows, now superseded by PresetCatalog
     * scopes picked per page block — dropping it loses no search data. Old
     * /browse/{slug} bookmarks keep working via SearchController::browseAction,
     * which 302-redirects them to /search. Idempotent (DROP … IF EXISTS).
     *
     * Version-gated: the table was retired in 3.0.0, so an install already at
     * or past that version cannot have it. Running the DROP unconditionally on
     * every future upgrade was harmless but left no record of WHEN it stopped
     * being relevant — the guard is that record, and lets the whole branch be
     * deleted once no install can still be below the floor.
     */
    /**
     * @param mixed $oldVersion
     * @param mixed $newVersion
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        if (version_compare((string) $oldVersion, self::BROWSE_CONFIG_RETIRED_IN, '>=')) {
            return;
        }
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement('DROP TABLE IF EXISTS iwac_browse_config');
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        // Drop the retired curated-browse table if an earlier version created
        // it. Never touches Typesense data (may be shared with another install).
        $connection->executeStatement('DROP TABLE IF EXISTS iwac_browse_config');

        // Clean up the cached search-only key so a fresh install bootstraps
        // a new one rather than reusing a key the previous install owned.
        $settings = $services->get('Omeka\Settings');
        $settings->delete('iwac_search_typesense_search_key');
    }
}

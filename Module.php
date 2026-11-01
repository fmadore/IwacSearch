<?php

/**
 * IwacSearch — Omeka S module.
 *
 * Owns the public discovery surface (/search, /search/everything, page blocks)
 * backed by Typesense. Admin search, item detail, ingest, and IIIF stay on
 * Omeka.
 *
 * Lifecycle:
 *  - install/upgrade: create the durable ID-only change journal and the
 *    generation ledger (Indexer\IndexingSchema)
 *  - upgrade: drop the retired iwac_browse_config table if present
 *  - uninstall: drop the module's tables, logging the Typesense collections
 *    the ledger recorded (they are left in place)
 *  - bootstrap (attachListeners): inject Svelte assets on the search routes,
 *    plus wire api.execute.pre/post listeners that journal changed IDs; a
 *    background job (DrainChanges) applies them to Typesense
 *
 * @see docs/code-map.md
 * @see docs/operations-3.19.md
 */

declare(strict_types=1);

namespace IwacSearch;

// Load the module's Composer autoloader at file-scope so IwacSearch\… classes
// resolve even on first-time install, where Omeka instantiates Module and
// calls install() directly without going through the ModuleManager pipeline
// (so init() wouldn't fire yet). Matches the ImageServer / IiifServer pattern.
//
// Omeka require_once's every active module's Module.php on EVERY request, so
// a missing vendor/ (a source checkout without `composer install`, GitHub's
// "Source code" archive) must not fatal the whole site. Without it the
// module's own classes still load and only the Typesense SDK is absent: the
// search surfaces then degrade as they do when Typesense is unreachable.
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'IwacSearch\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

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

    /**
     * Version from which stored search blocks carry the purified-intro flag
     * (see Site\BlockLayout\IwacSearchBlock::PURIFIED_FLAG).
     */
    private const INTRO_FLAG_ADDED_IN = '3.20.0';

    /** Resolved on the first write of the request/process; null until then. */
    private ?Indexer\ItemEventListener $itemEventListener = null;

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Every action anonymous visitors may reach, per public controller.
     *
     * A new public action MUST be listed here. Omeka's ACL denies by default,
     * so an unlisted one throws PermissionDeniedException before the action
     * runs — for /discovery/token that is the Svelte client's "Search
     * unavailable. Token HTTP 500" on every public surface, page blocks
     * included. tests/php/Controller/AclContractTest.php asserts that this
     * list and the controllers' own *Action methods agree, and that every
     * listed action is routed.
     */
    public const PUBLIC_ACTIONS = [
        Controller\SearchController::class => ['index', 'everything', 'token', 'browse', 'health'],
        Controller\LegacySearchController::class => ['redirect'],
    ];

    /**
     * Every Maintenance page action, for the editor tier and up. Listed — and
     * tested — for the same reason as PUBLIC_ACTIONS, with one twist: a global
     * admin is allowed everything anyway, so an action missing here works for
     * whoever wrote it and fails only for the editors it was meant for.
     */
    public const MAINTENANCE_ACTIONS = [
        'index', 'reindex', 'syncStopwords', 'syncSynonyms', 'provisionAnalytics', 'retryChanges', 'pruneCollections',
    ];

    /**
     * Register ACL rules before dispatch reaches a controller.
     *
     * Two zones:
     *
     *   1. Public discovery ({@see PUBLIC_ACTIONS}: SearchController +
     *      LegacySearchController) — granted to the null role so anonymous
     *      site visitors can hit /search, /search/everything,
     *      /discovery/token, /discovery/health and both families of legacy
     *      redirects.
     *
     *   2. Admin Maintenance ({@see MAINTENANCE_ACTIONS}) — restricted to
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
        foreach (self::PUBLIC_ACTIONS as $controller => $actions) {
            $acl->allow(null, [$controller], $actions);
        }

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
            self::MAINTENANCE_ACTIONS
        );
    }

    /** Wire public assets and lazily resolved pre/post write journaling. */
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

        foreach (
            [
            \Omeka\Api\Adapter\ItemAdapter::class => 'items',
            \Omeka\Api\Adapter\MediaAdapter::class => 'media',
            \Omeka\Api\Adapter\ItemSetAdapter::class => 'item_sets',
            ] as $adapter => $resource
        ) {
            $sharedEventManager->attach($adapter, 'api.execute.pre', function (Event $event) use ($resource): void {
                if (Indexer\ItemEventListener::isWriteOperation($event->getParam('request')->getOperation())) {
                    $this->resolveItemEventListener()->onBeforeWrite($event, $resource);
                } else {
                    $this->itemEventListener?->settle();
                }
            }, 1000);
            $sharedEventManager->attach($adapter, 'api.execute.post', function (Event $event) use ($resource): void {
                if (Indexer\ItemEventListener::isWriteOperation($event->getParam('request')->getOperation())) {
                    $this->resolveItemEventListener()->onAfterWrite($event, $resource);
                } else {
                    $this->itemEventListener?->settle();
                }
            }, -1000);
        }
    }

    /**
     * Write failures must remain visible; reads never resolve this service.
     *
     * Kept on the module once resolved so reads can settle gate levels left by
     * a write whose adapter threw (see Indexer\WriteGate) without ever
     * building the indexing graph themselves.
     */
    private function resolveItemEventListener(): Indexer\ItemEventListener
    {
        return $this->itemEventListener ??= $this->getServiceLocator()->get(Indexer\ItemEventListener::class);
    }

    /**
     * Append the Svelte bundle's JS + CSS to the layout when the
     * SearchController renders. headScript/headLink dedupe by URL, so
     * having the page block call appendFile() in its prepareRender() too is
     * harmless — the asset still loads exactly once per page.
     *
     * @param Event<object, array<string, mixed>> $event view.layout, whose
     *   target is the layout's renderer
     */
    public function injectSvelteAssets(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view instanceof \Laminas\View\Renderer\PhpRenderer) {
            return;
        }
        // Shared with IwacSearchBlock::prepareRender — one source of truth for the
        // bundle's file set (see Asset\SvelteAssets for the why of each file).
        Asset\SvelteAssets::injectSearchApp($view);
    }

    /**
     * Inject the tiny header-search enhancer on every public SITE page.
     *
     * Separate from injectSvelteAssets (which ships the full search app only
     * on SearchController routes): the header search box lives in the theme
     * chrome on every page, so its typeahead must load site-wide. The bundle
     * is framework-free and small — its gzipped budget, and the rule that it
     * never carries the app's string table, are asserted by
     * scripts/check-bundle-size.js rather than restated here (this comment
     * said "~34 KB raw / ~13 KB gzipped" while it was 25 / 8.8). It no-ops
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
     *
     * @param Event<object, array<string, mixed>> $event view.layout, whose
     *   target is the layout's renderer
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

    /** Install the durable change journal and the generation ledger. */
    public function install(ServiceLocatorInterface $services): void
    {
        Indexer\IndexingSchema::install($services->get('Omeka\Connection'));
    }

    /**
     * Upgrade hook. Creates any missing module table (IndexingSchema, which
     * also folds the pre-3.27 rollback record into the generation ledger).
     * Omeka runs this while the module is still inactive, so it uses the
     * connection alone — none of the module's own services resolve here.
     *
     * The curated-browse system was retired, so drop its orphan
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
     *
     * @param mixed $oldVersion
     * @param mixed $newVersion
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        Indexer\IndexingSchema::install($connection);
        if (version_compare((string) $oldVersion, self::BROWSE_CONFIG_RETIRED_IN, '<')) {
            $connection->executeStatement('DROP TABLE IF EXISTS iwac_browse_config');
        }
        if (version_compare((string) $oldVersion, self::INTRO_FLAG_ADDED_IN, '<')) {
            Site\BlockLayout\IntroMigration::run(
                $connection,
                static fn () => $services->get('Omeka\HtmlPurifier')
            );
        }
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $connection = $services->get('Omeka\Connection');
        // Never touches Typesense data (it may be shared with another
        // install); the collections the ledger recorded are logged instead.
        Indexer\IndexingSchema::uninstall($connection, Log\LoggerResolver::fromContainer($services));
        // Drop the retired curated-browse table if an earlier version created it.
        $connection->executeStatement('DROP TABLE IF EXISTS iwac_browse_config');

        // Clean up the cached search-only parent keys so a fresh install
        // bootstraps a new one rather than reusing a key the previous install
        // owned. The slot name carries a hash of the collection scope, so
        // there is one slot per scope ever configured plus the legacy one —
        // deleting only the legacy name left the live key behind. Each key is
        // revoked in Typesense first, best effort: an unreachable server or a
        // missing vendor/ must not block the uninstall.
        $prefix = Search\TypesenseSearchKeyProvider::SETTINGS_KEY;
        $slots = 'id = ? OR LEFT(id, ?) = ?';
        $slotParams = [$prefix, strlen($prefix) + 1, $prefix . '_'];
        try {
            $values = array_values(array_filter(array_map(
                static fn(mixed $raw): mixed => json_decode((string) $raw, true),
                $connection->fetchFirstColumn('SELECT value FROM setting WHERE ' . $slots, $slotParams)
            ), static fn(mixed $value): bool => is_string($value) && $value !== ''));
            Search\TypesenseSearchKeyProvider::revokeCached($values, Service\TypesenseClientLazy::fromContainer($services));
        } catch (\Throwable $e) {
            $services->get('Omeka\Logger')->warn('IwacSearch uninstall: could not revoke the cached Typesense search key: ' . $e->getMessage());
        }
        $connection->executeStatement('DELETE FROM setting WHERE ' . $slots, $slotParams);
    }
}

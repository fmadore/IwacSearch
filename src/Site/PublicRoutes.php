<?php
declare(strict_types=1);

namespace IwacSearch\Site;

use IwacSearch\Controller\LegacySearchController;
use IwacSearch\Controller\SearchController;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

/**
 * The public routes, each written once.
 *
 * config/module.config.php used to spell every public surface out up to
 * three times — on the site-less root, under each language site, and again
 * under the French path the French site links to — so a change to one
 * (a constraint, a default) had to be repeated by hand in each copy. The
 * table below is the one list, and root() / site() derive the route
 * definitions from it, byte for byte what the hand-written ones were
 * (tests/php/Controller/RouteTableTest.php compares them with a snapshot
 * taken before this existed).
 *
 * The French paths are not translations applied at runtime: they are routes
 * of their own, with their own names, because iwacLocale() / iwacSearchUrl()
 * pick a route NAME per site (/s/afrique_ouest/recherche/tout vs
 * /s/westafrica/search/everything) and bookmarks of both must keep working.
 */
final class PublicRoutes
{
    /**
     * name => [path, SearchController action, on the root?, on each site?].
     *
     * A path with a `:` parameter becomes a Segment route (the only
     * parameter is a legacy browse slug); any other path is a Literal. The
     * root carries no French aliases except /parcourir, and only the root
     * mints keys and answers health probes — the set the hand-written
     * config registered, plus /discovery/health.
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public const ROUTES = [
        // Public search page — HTML shell + the compiled Svelte bundle. The
        // IWAC-theme header form posts to the site-scoped one (through
        // iwacSearchUrl) so a visitor lands inside their own language site;
        // readUrlState() hydrates ?q= + ?f.<field>= deep links on the client.
        'iwac-search'            => ['/search', 'index', true, true],
        'iwac-recherche'         => ['/recherche', 'index', false, true],
        // Federated "search everything" page — Content + Entities tabs over
        // both Typesense collections. Literal, so it never shadows /search.
        'iwac-search-everything' => ['/search/everything', 'everything', true, true],
        'iwac-recherche-tout'    => ['/recherche/tout', 'everything', false, true],
        // Scoped-key endpoint: mints a short-lived public Typesense key (1h).
        // Global only — the client resolves it through basePath(), never
        // under a site.
        'iwac-search-token'      => ['/discovery/token', 'token', true, false],
        // Health probe for an uptime monitor (Typesense reachability and the
        // indexing backlog — see Search\HealthProbe). Global, like the key.
        'iwac-search-health'     => ['/discovery/health', 'health', true, false],
        // Legacy browse routes — kept only to redirect old bookmarks: a
        // /browse/benin bookmark 302s to /search?f.country_ss=…, and the
        // site-scoped ones keep the visitor's language site (see
        // SearchController::browseAction).
        'iwac-browse'            => ['/browse[/:slug]', 'browse', true, true],
        'iwac-parcourir'         => ['/parcourir[/:slug]', 'browse', true, true],
    ];

    /**
     * Omeka's public advanced-search entry points, replaced by the federated
     * surface while this module is active. Item sets and media share the
     * same destination: neither has a separate Typesense collection, and the
     * companion theme already presents all three links as "Search the
     * collection".
     */
    public const LEGACY_SEARCH_RESOURCES = ['item', 'item-set', 'media'];

    /** What a legacy browse slug may look like. */
    private const SLUG_CONSTRAINTS = ['slug' => '[a-zA-Z0-9_-]+'];

    /**
     * The site-less routes, in table order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function root(): array
    {
        $routes = [];
        foreach (self::ROUTES as $name => [$path, $action, $onRoot]) {
            if ($onRoot) {
                $routes[$name] = self::route($path, ['controller' => SearchController::class, 'action' => $action]);
            }
        }
        return $routes;
    }

    /**
     * The child routes of Omeka's `site` route (/s/{site-slug}/…): the
     * legacy advanced-search handoffs, then the public surfaces.
     *
     * Site routes name their controller namespace, as Omeka's own site
     * routes do; the root ones never did, and still don't.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function site(): array
    {
        $routes = [];
        foreach (self::LEGACY_SEARCH_RESOURCES as $resource) {
            $routes['iwac-legacy-' . $resource . '-search'] = [
                'type' => Literal::class,
                'options' => [
                    'route' => sprintf('/%s/search', $resource),
                    'defaults' => [
                        '__NAMESPACE__' => 'IwacSearch\Controller',
                        'controller' => LegacySearchController::class,
                        'action' => 'redirect',
                    ],
                ],
                // Omeka's generic /:controller[/:action] route also matches
                // these URLs. A literal route with an explicit priority makes
                // module ownership deterministic regardless of module/config
                // merge order.
                'priority' => 1000,
            ];
        }
        foreach (self::ROUTES as $name => [$path, $action, , $onSite]) {
            if ($onSite) {
                $routes[$name] = self::route($path, [
                    '__NAMESPACE__' => 'IwacSearch\Controller',
                    'controller'    => SearchController::class,
                    'action'        => $action,
                ]);
            }
        }
        return $routes;
    }

    /**
     * @param  array<string, string> $defaults
     * @return array<string, mixed>
     */
    private static function route(string $path, array $defaults): array
    {
        if (!str_contains($path, ':')) {
            return ['type' => Literal::class, 'options' => ['route' => $path, 'defaults' => $defaults]];
        }
        return [
            'type' => Segment::class,
            'options' => ['route' => $path, 'constraints' => self::SLUG_CONSTRAINTS, 'defaults' => $defaults],
        ];
    }
}

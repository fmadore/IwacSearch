<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Controller;

use IwacSearch\Site\PublicRoutes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The module's route table against a snapshot of the hand-written one it
 * replaced (fixtures/routes.json, dumped from config/module.config.php before
 * {@see PublicRoutes} existed).
 *
 * Route names, paths, defaults, constraints and priorities are the contract:
 * every name is assembled somewhere (iwacSearchUrl, the browse redirects, the
 * theme), and every path is a bookmark. Each definition must therefore match
 * byte for byte; adding a route means adding it to the snapshot too.
 *
 * The order of the routes WITHIN one level is not compared. Laminas tries
 * same-priority routes last-registered first, so order only decides between
 * two routes that match the same URL — and no two of this module's routes
 * do (asserted below), while its site routes all register after core's
 * whatever their order among themselves. The one overlap that exists, the
 * legacy handoffs against core's generic /:controller[/:action], is settled
 * by an explicit priority, which IS compared.
 */
#[CoversClass(PublicRoutes::class)]
final class RouteTableTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function routes(): array
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';
        return $config['router']['routes'];
    }

    public function testTheRouteTableMatchesTheSnapshot(): void
    {
        $snapshot = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/routes.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertSame(self::byName($snapshot), self::byName(self::routes()));
    }

    public function testNoTwoRoutesOnOneLevelShareAPath(): void
    {
        $routes = self::routes();
        foreach (['root' => $routes, 'site' => $routes['site']['child_routes']] as $level => $map) {
            $paths = [];
            foreach ($map as $route) {
                if (isset($route['options']['route'])) {
                    $paths[] = $route['options']['route'];
                }
            }
            self::assertSame($paths, array_values(array_unique($paths)), "$level paths are disjoint");
        }
    }

    public function testEveryFrenchAliasLeadsToTheSameActionAsItsEnglishRoute(): void
    {
        $site = self::routes()['site']['child_routes'];
        foreach (['iwac-recherche' => 'iwac-search', 'iwac-recherche-tout' => 'iwac-search-everything', 'iwac-parcourir' => 'iwac-browse'] as $fr => $en) {
            self::assertSame($site[$en]['options']['defaults'], $site[$fr]['options']['defaults'], $fr);
            self::assertSame($site[$en]['type'], $site[$fr]['type'], $fr);
        }
    }

    /**
     * Each level's routes sorted by name, recursively; nothing inside a
     * route definition is reordered.
     *
     * @param  array<string, mixed> $routes
     * @return array<string, mixed>
     */
    private static function byName(array $routes): array
    {
        ksort($routes);
        foreach ($routes as $name => $route) {
            if (is_array($route) && is_array($route['child_routes'] ?? null)) {
                $route['child_routes'] = self::byName($route['child_routes']);
                $routes[$name] = $route;
            }
        }
        return $routes;
    }
}

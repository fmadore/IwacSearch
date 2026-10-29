<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Controller;

use IwacSearch\Controller\Admin\MaintenanceController;
use IwacSearch\Module;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ACL allow-lists in Module::onBootstrap() against the controllers they
 * guard.
 *
 * Omeka's ACL denies by default and checks before dispatch, so an action
 * left off the list is unreachable however correct it is: an anonymous
 * request to a public one fails with PermissionDeniedException (DRE Search's
 * v1.12.1 histogram outage), and an editor's request to a Maintenance one is
 * refused while the global admin who wrote it sees nothing wrong. Both lists
 * are therefore compared with the controllers' own `*Action` methods, and
 * every listed action must have a route.
 */
final class AclContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/Module.php';
    }

    public function testEveryPublicControllerHasAnEntry(): void
    {
        $controllers = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Controller/*Controller.php') ?: [] as $file) {
            $controllers[] = 'IwacSearch\\Controller\\' . basename($file, '.php');
        }
        sort($controllers);
        $listed = array_keys(Module::PUBLIC_ACTIONS);
        sort($listed);

        self::assertSame($controllers, $listed);
    }

    public function testEveryPublicActionIsOnTheAnonymousAllowList(): void
    {
        foreach (Module::PUBLIC_ACTIONS as $controller => $allowed) {
            self::assertSame(self::actionsOf($controller), self::sorted($allowed), $controller);
        }
    }

    public function testEveryMaintenanceActionIsOnTheEditorAllowList(): void
    {
        self::assertSame(self::actionsOf(MaintenanceController::class), self::sorted(Module::MAINTENANCE_ACTIONS));
    }

    public function testEveryAllowedActionIsRouted(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';
        $routed = [];
        self::collectRoutes($config['router']['routes'], null, $routed);

        $allowed = Module::PUBLIC_ACTIONS + [MaintenanceController::class => Module::MAINTENANCE_ACTIONS];
        foreach ($allowed as $controller => $actions) {
            foreach ($actions as $action) {
                self::assertContains("$controller::$action", $routed, "$controller::$action has no route.");
            }
        }
    }

    /**
     * The actions a controller declares itself (not Laminas's inherited
     * notFoundAction), sorted.
     *
     * @param  class-string $controller
     * @return list<string>
     */
    private static function actionsOf(string $controller): array
    {
        $actions = [];
        foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $controller && str_ends_with($method->getName(), 'Action')) {
                $actions[] = substr($method->getName(), 0, -strlen('Action'));
            }
        }
        return self::sorted($actions);
    }

    /**
     * "Controller::action" for every route, child routes inheriting their
     * parent's controller the way Laminas merges defaults.
     *
     * @param array<string, mixed> $routes
     * @param list<string>         $out
     */
    private static function collectRoutes(array $routes, ?string $controller, array &$out): void
    {
        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }
            $defaults = $route['options']['defaults'] ?? [];
            $own = is_string($defaults['controller'] ?? null) ? $defaults['controller'] : $controller;
            if ($own !== null && is_string($defaults['action'] ?? null)) {
                $out[] = $own . '::' . $defaults['action'];
            }
            if (is_array($route['child_routes'] ?? null)) {
                self::collectRoutes($route['child_routes'], $own, $out);
            }
        }
    }

    /**
     * @param  list<string> $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}

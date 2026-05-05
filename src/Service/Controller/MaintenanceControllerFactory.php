<?php
declare(strict_types=1);

namespace IwacSearch\Service\Controller;

use IwacSearch\Controller\Admin\MaintenanceController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Factory for the maintenance controller. The controller is stateless
 * and pulls everything it needs from controller plugins
 * (jobDispatcher, messenger, redirect, url, getForm), so this factory
 * is intentionally bare. Kept as a class-based factory for symmetry
 * with BrowseConfigControllerFactory.
 */
final class MaintenanceControllerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): MaintenanceController {
        return new MaintenanceController();
    }
}

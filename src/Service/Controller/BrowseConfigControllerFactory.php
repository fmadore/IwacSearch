<?php
declare(strict_types=1);

namespace IwacSearch\Service\Controller;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Controller\Admin\BrowseConfigController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Wires the admin CRUD controller. Repository is the only dependency —
 * the controller is otherwise stateless, so this factory is trivial.
 * Kept as a dedicated class (rather than an invokable closure) for
 * consistency with the rest of IwacSearch's service layer.
 */
final class BrowseConfigControllerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): BrowseConfigController {
        return new BrowseConfigController(
            repository: $container->get(BrowseConfigRepository::class)
        );
    }
}

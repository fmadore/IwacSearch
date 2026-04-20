<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Controller\SearchController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds SearchController. M0 returns the bare controller; M1 wires the
 * Typesense client + module config via the container.
 */
class SearchControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        // M1+: pull the typesense client + module config and inject them.
        //   $config    = $container->get('Config')['iwac_search'];
        //   $typesense = $container->get(TypesenseClientInterface::class);
        //   return new SearchController($typesense, $config);
        return new SearchController();
    }
}

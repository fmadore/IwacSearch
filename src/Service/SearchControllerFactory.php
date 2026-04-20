<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Controller\SearchController;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Builds the SearchController with its real dependencies in M1+.
 *
 * Wires:
 *   - TypesenseSearchKeyProvider — mints scoped keys on /discovery/token
 *   - BrowseConfigRepository     — reads `iwac_browse_config` for /browse
 *   - module config              — typesense conn + scoped-key constraints
 *
 * The TypesenseClient itself is not injected into the controller —
 * only the key provider needs it, and the controller never makes a
 * direct search call (the browser does, via /search-api/).
 */
class SearchControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $config = $container->get('Config')['iwac_search'] ?? [];

        $keyProvider = new TypesenseSearchKeyProvider(
            typesense: $container->get(TypesenseClient::class),
            settings:  $container->get('Omeka\Settings'),
            logger:    $container->has('Omeka\Logger') ? $container->get('Omeka\Logger') : new \Psr\Log\NullLogger()
        );

        return new SearchController(
            keyProvider:        $keyProvider,
            browseRepository:   $container->get(BrowseConfigRepository::class),
            config:             $config
        );
    }
}

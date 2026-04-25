<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Controller\SearchController;
use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\TypesenseSearchKeyProvider;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

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

        $logger = LoggerResolver::fromContainer($container);

        // Lazy TypesenseClient (see TypesenseClientLazy docblock) so a
        // missing Docker secret surfaces inside tokenAction's 503 path
        // instead of as a 500 HTML page before the action even runs.
        $keyProvider = new TypesenseSearchKeyProvider(
            clientFactory: TypesenseClientLazy::fromContainer($container),
            settings:      $container->get('Omeka\Settings'),
            logger:        $logger
        );

        return new SearchController(
            keyProvider:        $keyProvider,
            browseRepository:   $container->get(BrowseConfigRepository::class),
            initialRenderer:    $container->get(InitialResponseRenderer::class),
            config:             $config
        );
    }
}

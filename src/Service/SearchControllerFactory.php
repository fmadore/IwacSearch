<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Controller\SearchController;
use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\InitialResponseRenderer;
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

        $logger = LoggerResolver::fromContainer($container);

        // Lazy factory closure — we resolve the TypesenseClient only when
        // someone actually mints a key. That way a missing Docker secret or
        // an unreachable Typesense server throws inside tokenAction (which
        // returns 503 JSON) rather than here (which would 500 an HTML page
        // before the action runs, hiding the actual error from the client).
        $keyProvider = new TypesenseSearchKeyProvider(
            clientFactory: fn(): TypesenseClient => $container->get(TypesenseClient::class),
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

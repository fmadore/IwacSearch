<?php
declare(strict_types=1);

namespace IwacSearch\Service;

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
 *   - module config              — typesense conn + scoped-key constraints
 *
 * The TypesenseClient itself is not injected into the controller —
 * only the key provider needs it, and the controller never makes a
 * direct search call (the browser does, via /search-api/).
 */
class SearchControllerFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): SearchController {
        $config = $container->get('Config')['iwac_search'] ?? [];

        $logger = LoggerResolver::fromContainer($container);

        // Lazy TypesenseClient (see TypesenseClientLazy docblock) so a
        // missing Docker secret surfaces inside tokenAction's 503 path
        // instead of as a 500 HTML page before the action even runs.
        // Collection scope of the search-only parent key. Deliberately read
        // from config rather than hardcoded: the safe-by-default value is
        // wide, and tightening it is an operator decision that must be
        // reversible without a deploy (the provider re-mints when the scope
        // changes). A malformed value falls back to the default rather than
        // minting a key nobody can search with.
        $scope = $config['public_search_key']['collections'] ?? null;
        $scope = is_array($scope) && $scope !== [] ? array_values(array_map('strval', $scope)) : null;

        $keyProvider = new TypesenseSearchKeyProvider(
            clientFactory:   TypesenseClientLazy::fromContainer($container),
            settings:        $container->get('Omeka\Settings'),
            logger:          $logger,
            collectionScope: $scope ?? TypesenseSearchKeyProvider::DEFAULT_COLLECTION_SCOPE
        );

        return new SearchController(
            keyProvider:        $keyProvider,
            initialRenderer:    $container->get(InitialResponseRenderer::class),
            config:             $config,
            logger:             $logger
        );
    }
}

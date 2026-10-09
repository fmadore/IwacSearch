<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Controller\SearchController;
use IwacSearch\Indexer\AdvisoryLock;
use IwacSearch\Indexer\DatabaseLock;
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
 *   - InitialResponseRenderer    — the SSR first page (admin key, public
 *                                  constraints applied explicitly)
 *   - module config              — typesense conn + scoped-key constraints
 *
 * The TypesenseClient itself is not injected into the controller — the
 * key provider and the SSR renderer own every server-side Typesense call,
 * and live searches go from the browser through /search-api/.
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

        // Collection scope of the search-only parent key. Read from config so
        // a deployment with custom alias names can match them without a code
        // change; the default is the anchored alias-only scope, and the
        // provider re-mints when the scope changes. A malformed value falls
        // back to the default rather than minting a key nobody can search
        // with.
        $scope = $config['public_search_key']['collections'] ?? null;
        $scope = is_array($scope) && $scope !== [] ? array_values(array_map('strval', $scope)) : null;

        $keyProvider = new TypesenseSearchKeyProvider(
            // Lazy TypesenseClient (see TypesenseClientLazy docblock) so a
            // missing Docker secret surfaces inside tokenAction's 503 path
            // instead of as a 500 HTML page before the action even runs.
            clientFactory:   TypesenseClientLazy::fromContainer($container),
            settings:        $container->get('Omeka\Settings'),
            logger:          $logger,
            collectionScope: $scope ?? TypesenseSearchKeyProvider::DEFAULT_COLLECTION_SCOPE,
            // Resolved only on the rare request that has to MINT the parent key
            // (no secret, nothing cached), so a token request pays nothing for
            // them. The fresh read bypasses Omeka's per-request settings cache;
            // Omeka stores each setting JSON-encoded in `setting.value`.
            mintLock:        static fn(): AdvisoryLock => new DatabaseLock(
                $container->get('Omeka\Connection'),
                'search-key-bootstrap'
            ),
            freshSetting:    static function (string $id) use ($container): mixed {
                $raw = $container->get('Omeka\Connection')
                    ->executeQuery('SELECT value FROM setting WHERE id = ?', [$id])
                    ->fetchOne();
                return is_string($raw) ? json_decode($raw, true) : null;
            },
        );

        return new SearchController(
            keyProvider:        $keyProvider,
            initialRenderer:    $container->get(InitialResponseRenderer::class),
            config:             $config,
            logger:             $logger
        );
    }
}

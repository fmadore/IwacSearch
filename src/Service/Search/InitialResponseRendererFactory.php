<?php
declare(strict_types=1);

namespace IwacSearch\Service\Search;

use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\SnapshotCache;
use IwacSearch\Service\TypesenseClientLazy;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the SSR renderer with a lazy TypesenseClient factory so a
 * missing Docker secret or unreachable Typesense is a render-time
 * fallback (null response) rather than a request-time crash.
 */
final class InitialResponseRendererFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): InitialResponseRenderer {
        $config = $container->get('Config')['iwac_search'] ?? [];
        $defaultCollection = (string) ($config['typesense']['collection_alias'] ?? 'iwac_current');
        // 0 disables the cache entirely (and is what a dev instance wants
        // while iterating on the schema or the mappers).
        $ttl = (int) ($config['ssr_cache']['ttl_seconds'] ?? 30);

        return new InitialResponseRenderer(
            clientFactory:     TypesenseClientLazy::fromContainer($container),
            logger:            LoggerResolver::fromContainer($container),
            defaultCollection: $defaultCollection,
            cache:             new SnapshotCache($ttl),
        );
    }
}

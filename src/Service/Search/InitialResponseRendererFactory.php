<?php
declare(strict_types=1);

namespace IwacSearch\Service\Search;

use IwacSearch\Log\LoggerResolver;
use IwacSearch\Search\InitialResponseRenderer;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Builds the SSR renderer with a lazy TypesenseClient factory so a
 * missing Docker secret or unreachable Typesense is a render-time
 * fallback (null response) rather than a request-time crash.
 */
final class InitialResponseRendererFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): InitialResponseRenderer {
        $defaultCollection = (string) (
            $container->get('Config')['iwac_search']['typesense']['collection_alias']
            ?? 'iwac_current'
        );

        return new InitialResponseRenderer(
            clientFactory:     fn(): TypesenseClient => $container->get(TypesenseClient::class),
            logger:            LoggerResolver::fromContainer($container),
            defaultCollection: $defaultCollection
        );
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Log\OmekaPsrLogger;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Typesense\Client as TypesenseClient;

/**
 * Lazy TypesenseClient so the indexer never blocks Omeka startup if
 * Typesense is unreachable — failures show up at event-time and are
 * swallowed inside the indexer's try/catch, per its docblock.
 */
final class IncrementalIndexerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IncrementalIndexer {
        $config = $container->get('Config')['iwac_search']['typesense'] ?? [];
        $alias  = (string) ($config['collection_alias'] ?? 'iwac_current');

        $logger = $container->has('Omeka\Logger')
            ? new OmekaPsrLogger($container->get('Omeka\Logger'))
            : new NullLogger();

        return new IncrementalIndexer(
            clientFactory:   fn(): TypesenseClient => $container->get(TypesenseClient::class),
            collectionAlias: $alias,
            logger:          $logger
        );
    }
}

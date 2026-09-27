<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\CollectionOps;
use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Log\LoggerResolver;
use IwacSearch\Service\TypesenseClientLazy;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the IncrementalIndexer for the DrainChanges job through
 * IncrementalIndexer::create() — the same wiring the CLI drain and the rebuild
 * cutover use. The TypesenseClient stays lazy so a down Typesense never
 * blocks Omeka startup — failures propagate to the job and leave journal
 * rows pending.
 */
final class IncrementalIndexerFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): IncrementalIndexer {
        /** @var Connection $connection */
        $connection = $container->get('Omeka\Connection');
        $logger = LoggerResolver::fromContainer($container);

        return IncrementalIndexer::create(
            ops:        new CollectionOps(TypesenseClientLazy::fromContainer($container), $logger, 'incremental'),
            connection: $connection,
            // src/Service/Indexer/ → module root is three levels up.
            moduleRoot: dirname(__DIR__, 3),
            logger:     $logger,
        );
    }
}

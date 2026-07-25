<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\ItemEventListener;
use IwacSearch\Log\LoggerResolver;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * The listener needs the incremental indexer (all re-map/delete paths),
 * the DBAL connection (to capture an item set's members BEFORE the delete
 * commits — the join rows are gone by api.delete.post), and a logger for
 * the cascade-cap warning.
 */
final class ItemEventListenerFactory implements FactoryInterface
{
    /**
     * @param  mixed $requestedName
     * @param  array<string, mixed>|null $options
     */
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): ItemEventListener {
        return new ItemEventListener(
            indexer: $container->get(IncrementalIndexer::class),
            connection: $container->get('Omeka\Connection'),
            logger: LoggerResolver::fromContainer($container)
        );
    }
}

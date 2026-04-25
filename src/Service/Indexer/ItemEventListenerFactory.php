<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\ItemEventListener;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Trivial factory — the listener has one dependency. Kept as a
 * dedicated class for symmetry with the rest of IwacSearch's service
 * layer; reviewers expect to find a ProvidedFooFactory next to every
 * Foo, and adding new container-bound state later is one place
 * to edit.
 */
final class ItemEventListenerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): ItemEventListener {
        return new ItemEventListener(
            indexer: $container->get(IncrementalIndexer::class)
        );
    }
}

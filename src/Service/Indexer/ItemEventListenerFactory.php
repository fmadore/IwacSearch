<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use IwacSearch\Indexer\DrainJobHistory;
use IwacSearch\Indexer\ItemEventListener;
use IwacSearch\Job\DrainChanges;
use IwacSearch\Log\LoggerResolver;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds an ID-journaling adapter and a deferred Omeka-job dispatch callback
 * that skips the dispatch while a drain is already queued or running.
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
        $connection = $container->get('Omeka\Connection');
        return new ItemEventListener(
            connection: $connection,
            dispatch: static function () use ($container, $connection): void {
                if (!DrainJobHistory::isActive($connection)) {
                    $container->get('Omeka\Job\Dispatcher')->dispatch(DrainChanges::class);
                }
            },
            logger: LoggerResolver::fromContainer($container)
        );
    }
}

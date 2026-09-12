<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use IwacSearch\Indexer\ItemEventListener;
use IwacSearch\Log\LoggerResolver;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/** Builds an ID-journaling adapter and a deferred Omeka-job dispatch callback. */
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
            connection: $container->get('Omeka\Connection'),
            dispatch: static function () use ($container): void {
                $container->get('Omeka\Job\Dispatcher')->dispatch(\IwacSearch\Job\DrainChanges::class);
            },
            logger: LoggerResolver::fromContainer($container)
        );
    }
}

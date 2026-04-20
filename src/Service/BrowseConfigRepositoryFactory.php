<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use IwacSearch\Browse\BrowseConfigRepository;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds BrowseConfigRepository with Omeka's shared Doctrine DBAL connection.
 *
 * Single connection per container — Omeka's service manager is the right
 * scope here because the repository is stateless and thread-safe.
 */
final class BrowseConfigRepositoryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): BrowseConfigRepository
    {
        return new BrowseConfigRepository(
            connection: $container->get('Omeka\Connection')
        );
    }
}

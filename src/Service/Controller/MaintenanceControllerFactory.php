<?php
declare(strict_types=1);

namespace IwacSearch\Service\Controller;

use IwacSearch\Controller\Admin\MaintenanceController;
use IwacSearch\Indexer\SchemaLoader;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Factory for the maintenance controller.
 *
 * Reads the current `name:` field from data/schema.yaml at boot so the
 * maintenance page description and the post-dispatch flash message
 * always reflect the live schema's base collection name (iwac_v1,
 * iwac_v2, …) rather than a hardcoded literal that drifts every time
 * the schema is bumped.
 */
final class MaintenanceControllerFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): MaintenanceController {
        // src/Service/Controller/MaintenanceControllerFactory.php
        //  → module root is three levels up.
        $moduleRoot = dirname(__DIR__, 3);

        $schema = (new SchemaLoader($moduleRoot . '/data/schema.yaml'))->load();
        $baseName = is_string($schema['name'] ?? null) ? $schema['name'] : 'iwac_v1';

        return new MaintenanceController($baseName);
    }
}

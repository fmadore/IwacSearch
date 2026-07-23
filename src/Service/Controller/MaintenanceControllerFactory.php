<?php
declare(strict_types=1);

namespace IwacSearch\Service\Controller;

use IwacSearch\Controller\Admin\MaintenanceController;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Service\TypesenseClientLazy;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Factory for the maintenance controller.
 *
 * Reads the current `name:` field from data/schema.yaml — once per
 * dispatch of a maintenance route (controller factories run per request,
 * not per boot; that's fine here, the admin page is low-traffic and the
 * YAML is small) — so the page description and the post-dispatch flash
 * message always reflect the live schema's base collection name (iwac_v1,
 * iwac_v2, …) rather than a hardcoded literal that drifts every time the
 * schema is bumped.
 *
 * Also injects a lazy Typesense client + both collection aliases so the
 * page can show a live status panel (reachable? document counts?). The
 * client is lazy (TypesenseClientLazy) so a missing Docker secret or an
 * unreachable Typesense renders the page with an "unreachable" panel
 * instead of a 500.
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

        $typesense = $container->get('Config')['iwac_search']['typesense'] ?? [];

        return new MaintenanceController(
            collectionBaseName: $baseName,
            clientFactory:      TypesenseClientLazy::fromContainer($container),
            contentAlias:       $typesense['collection_alias'] ?? 'iwac_current',
            indexAlias:         $typesense['index_collection_alias'] ?? 'iwac_index_current',
        );
    }
}

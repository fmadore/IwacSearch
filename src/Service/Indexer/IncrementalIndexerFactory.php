<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\CollectionOps;
use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\OmekaSourceReader;
use IwacSearch\Log\LoggerResolver;
use IwacSearch\Service\TypesenseClientLazy;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the IncrementalIndexer with the MySQL source stack it now needs to
 * re-map a single item on save: a DBAL-backed source reader (Omeka\Connection
 * from the container), the shared entity-authority cache, the country
 * resolver, and the mapper registry. The TypesenseClient stays lazy so a
 * down Typesense never blocks Omeka startup — failures propagate to the job and leave journal rows pending.
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
        $config = $container->get('Config')['iwac_search']['typesense'] ?? [];
        $alias  = (string) ($config['collection_alias'] ?? 'iwac_current');

        /** @var Connection $connection */
        $connection = $container->get('Omeka\Connection');

        // src/Service/Indexer/ → module root is three levels up.
        $moduleRoot = dirname(__DIR__, 3);

        $reader    = new OmekaSourceReader($connection);
        $authority = new EntityAuthority();
        $countries = new CountryResolver($moduleRoot . '/data/newspaper-countries.json');

        $registry = MapperRegistry::default($authority, $countries);

        $logger = LoggerResolver::fromContainer($container);

        return new IncrementalIndexer(
            ops:             new CollectionOps(
                TypesenseClientLazy::fromContainer($container),
                $logger,
                'incremental'
            ),
            reader:          $reader,
            mappers:         $registry,
            authority:       $authority,
            collectionAlias: $alias,
            indexAlias:      (string) ($config['index_collection_alias'] ?? 'iwac_index_current'),
            logger:          $logger
        );
    }
}

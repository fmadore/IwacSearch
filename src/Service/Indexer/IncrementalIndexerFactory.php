<?php
declare(strict_types=1);

namespace IwacSearch\Service\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\Mapper\ArticleMapper;
use IwacSearch\Indexer\Mapper\AudiovisualMapper;
use IwacSearch\Indexer\Mapper\DocumentMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\Mapper\PublicationMapper;
use IwacSearch\Indexer\Mapper\ReferenceMapper;
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
 * down Typesense never blocks Omeka startup — failures surface at event time
 * and are swallowed inside the indexer's try/catch.
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

        /** @var Connection $connection */
        $connection = $container->get('Omeka\Connection');

        // src/Service/Indexer/ → module root is three levels up.
        $moduleRoot = dirname(__DIR__, 3);

        $reader    = new OmekaSourceReader($connection);
        $authority = new EntityAuthority();
        $countries = new CountryResolver($moduleRoot . '/data/newspaper-countries.json');

        $registry = new MapperRegistry([
            new ArticleMapper($authority, $countries),
            new PublicationMapper($authority, $countries),
            new DocumentMapper($authority, $countries),
            new AudiovisualMapper($authority, $countries),
            new ReferenceMapper($authority, $countries),
        ]);

        return new IncrementalIndexer(
            clientFactory:   TypesenseClientLazy::fromContainer($container),
            reader:          $reader,
            mappers:         $registry,
            authority:       $authority,
            collectionAlias: $alias,
            logger:          LoggerResolver::fromContainer($container)
        );
    }
}

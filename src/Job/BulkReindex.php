<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\CurationSync;
use IwacSearch\Indexer\EntityAuthority;
use IwacSearch\Indexer\EntityOccurrences;
use IwacSearch\Indexer\IndexReindexer;
use IwacSearch\Indexer\Mapper\ArticleMapper;
use IwacSearch\Indexer\Mapper\AudiovisualMapper;
use IwacSearch\Indexer\Mapper\DocumentMapper;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\Mapper\PhotographMapper;
use IwacSearch\Indexer\Mapper\PublicationMapper;
use IwacSearch\Indexer\Mapper\ReferenceMapper;
use IwacSearch\Indexer\OmekaSourceReader;
use IwacSearch\Indexer\Reindexer;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Background job: bulk Typesense reindex from the Omeka MySQL database.
 *
 * Wraps Reindexer::run() + IndexReindexer::run() — same code path as
 * cli/reindex.php, dispatched from the admin "Run reindex" button.
 * Long-running (5–15 min on the IWAC corpus); status at /admin/job/{id}/log.
 *
 * Construction: the TypesenseClient and the DBAL Connection come from the
 * service container (the job runs inside Omeka, so 'Omeka\Connection' is the
 * live database connection). The rest of the indexer dependencies are plain
 * POPOs instantiated inline rather than registering factories nothing else
 * consumes.
 *
 * On any throw, AbstractJob marks the job ERROR — Reindexer::run() drops the
 * half-built collection on failure, so the live alias keeps pointing at the
 * previous good collection.
 */
class BulkReindex extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);
        /** @var Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // src/Job/BulkReindex.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        // Shared, mutable authority cache: Reindexer->run() builds it from
        // MySQL, the mappers read it, IndexReindexer reads it afterwards.
        $reader      = new OmekaSourceReader($connection);
        $authority   = new EntityAuthority();
        $countries   = new CountryResolver($moduleRoot . '/data/newspaper-countries.json');
        $occurrences = new EntityOccurrences();

        $registry = new MapperRegistry([
            new ArticleMapper($authority, $countries),
            new PublicationMapper($authority, $countries),
            new DocumentMapper($authority, $countries),
            new AudiovisualMapper($authority, $countries),
            new PhotographMapper($authority, $countries),
            new ReferenceMapper($authority, $countries),
        ]);

        $reindexer = new Reindexer(
            typesense:     $typesense,
            schemaLoader:  new SchemaLoader($moduleRoot . '/data/schema.yaml'),
            reader:        $reader,
            mappers:       $registry,
            authority:     $authority,
            occurrences:   $occurrences,
            stopwordsSync: new StopwordsSync($typesense, $moduleRoot . '/data/stopwords-fr.json', $logger),
            curationSync:  new CurationSync($typesense, $logger),
            logger:        $logger
        );

        // Entity (index) collection — built on the same run from the shared
        // authority + occurrence aggregates. Independent alias swap.
        $indexReindexer = new IndexReindexer(
            typesense:    $typesense,
            schemaLoader: new SchemaLoader($moduleRoot . '/data/schema-index.yaml'),
            authority:    $authority,
            occurrences:  $occurrences,
            mapper:       new IndexEntityMapper(),
            logger:       $logger
        );

        $logger->info('IwacSearch: starting bulk reindex from Omeka job', [
            'job_id'      => $this->job->getId(),
            'module_root' => $moduleRoot,
        ]);

        try {
            $stats = $reindexer->run();
            $stats['index'] = $indexReindexer->run();
        } catch (Throwable $e) {
            $logger->error('IwacSearch: reindex failed', [
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
            // Re-throw so AbstractJob marks the job as ERROR.
            throw $e;
        }

        $logger->info('IwacSearch: reindex complete', $stats);
    }
}

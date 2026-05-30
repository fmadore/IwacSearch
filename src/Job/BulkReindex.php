<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\ApiOmekaAclLoader;
use IwacSearch\Indexer\AuthorityResolver;
use IwacSearch\Indexer\HfDatasetLoader;
use IwacSearch\Indexer\IndexReindexer;
use IwacSearch\Indexer\Mapper\ArticleMapper;
use IwacSearch\Indexer\Mapper\AudiovisualMapper;
use IwacSearch\Indexer\Mapper\DocumentMapper;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\Mapper\PublicationMapper;
use IwacSearch\Indexer\Mapper\ReferenceMapper;
use IwacSearch\Indexer\Reindexer;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Log\LoggerResolver;
use Omeka\Api\Manager as ApiManager;
use Omeka\Job\AbstractJob;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Background job: bulk Typesense reindex.
 *
 * Wraps Reindexer::run() — same code path as cli/reindex.php, dispatched
 * from the admin "Run reindex" button. Long-running (5–15 min on the
 * IWAC corpus); status visible at /admin/job/{id}/log.
 *
 * Construction: TypesenseClient comes from the service container (factory
 * wires the Docker secret + connection). The rest of the indexer
 * dependencies (mappers, authority resolver, loaders) are pure POPOs
 * with no service-container dependencies, so we instantiate them inline
 * rather than registering 6+ factories that nothing else would consume.
 *
 * On any throw, AbstractJob marks the job ERROR — Reindexer::run()
 * already drops the half-built collection on failure, so the live alias
 * keeps pointing at the previous (good) collection.
 */
class BulkReindex extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);
        /** @var ApiManager $api */
        $api = $services->get('Omeka\ApiManager');

        // src/Job/BulkReindex.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        $authority = new AuthorityResolver();
        $registry = new MapperRegistry([
            new ArticleMapper($authority),
            new PublicationMapper($authority),
            new DocumentMapper($authority),
            new AudiovisualMapper($authority),
            new ReferenceMapper($authority),
        ]);

        // Shared across both reindexers so the public-item set is fetched
        // once. ApiManager-backed loader, NOT HTTP: the container running
        // this job has no route back to islam.zmo.de — outbound HTTP to the
        // public API fails with a connection error. The in-process Api
        // Manager bypasses HTTP entirely.
        $hfLoader  = new HfDatasetLoader();
        $aclLoader = new ApiOmekaAclLoader($api, $logger);

        $reindexer = new Reindexer(
            typesense:     $typesense,
            schemaLoader:  new SchemaLoader($moduleRoot . '/data/schema.yaml'),
            hfLoader:      $hfLoader,
            mappers:       $registry,
            authority:     $authority,
            aclLoader:     $aclLoader,
            stopwordsSync: new StopwordsSync($typesense, $moduleRoot . '/data/stopwords-fr.json', $logger),
            logger:        $logger
        );

        // Entity (index) collection — built on the same run, reusing the
        // primed ACL loader. Independent alias swap.
        $indexReindexer = new IndexReindexer(
            typesense:    $typesense,
            schemaLoader: new SchemaLoader($moduleRoot . '/data/schema-index.yaml'),
            hfLoader:     $hfLoader,
            mapper:       new IndexEntityMapper(),
            aclLoader:    $aclLoader,
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

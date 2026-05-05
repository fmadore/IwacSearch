<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\AuthorityResolver;
use IwacSearch\Indexer\HfDatasetLoader;
use IwacSearch\Indexer\Mapper\ArticleMapper;
use IwacSearch\Indexer\Mapper\AudiovisualMapper;
use IwacSearch\Indexer\Mapper\DocumentMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\Mapper\PublicationMapper;
use IwacSearch\Indexer\OmekaAclLoader;
use IwacSearch\Indexer\Reindexer;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Log\LoggerResolver;
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

        $config = $services->get('Config')['iwac_search'] ?? [];
        $omekaApiUrl = (string) (
            getenv('IWAC_OMEKA_API_URL')
            ?: ($config['omeka_api_url'] ?? 'https://islam.zmo.de/api')
        );

        // src/Job/BulkReindex.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);

        $authority = new AuthorityResolver();
        $registry = new MapperRegistry([
            new ArticleMapper($authority),
            new PublicationMapper($authority),
            new DocumentMapper($authority),
            new AudiovisualMapper($authority),
        ]);

        $reindexer = new Reindexer(
            typesense:     $typesense,
            schemaLoader:  new SchemaLoader($moduleRoot . '/data/schema.yaml'),
            hfLoader:      new HfDatasetLoader(),
            mappers:       $registry,
            authority:     $authority,
            aclLoader:     new OmekaAclLoader($omekaApiUrl, $logger),
            stopwordsSync: new StopwordsSync($typesense, $moduleRoot . '/data/stopwords-fr.json', $logger),
            logger:        $logger
        );

        $logger->info('IwacSearch: starting bulk reindex from Omeka job', [
            'job_id'      => $this->job->getId(),
            'omeka_api'   => $omekaApiUrl,
            'module_root' => $moduleRoot,
        ]);

        try {
            $stats = $reindexer->run();
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

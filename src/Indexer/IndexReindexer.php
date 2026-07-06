<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Builds the INDEX (authority) collection — the entity browse surface.
 *
 * No database pass of its own: it iterates the EntityAuthority cache the
 * content {@see Reindexer} already built, merging in each entity's occurrence
 * aggregate (frequency / first–last year / countries) accumulated during the
 * content pass. So it MUST run after Reindexer::run() (which populates both
 * the shared authority and the occurrences) on the same job.
 *
 * Same safety property as Reindexer: fresh timestamped collection, atomic
 * alias swap only on success, previous collection dropped last.
 */
final class IndexReindexer
{
    private const BATCH_SIZE = 200;

    /** Shared import/alias/drop plumbing (same helper the content Reindexer uses). */
    private readonly CollectionOps $ops;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly SchemaLoader $schemaLoader,
        private readonly EntityAuthority $authority,
        private readonly EntityOccurrences $occurrences,
        private readonly IndexEntityMapper $mapper,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $aliasTarget = 'iwac_index_current'
    ) {
        $this->ops = new CollectionOps($typesense, $this->logger, 'index');
    }

    /**
     * @return array{collection: string, alias: string, indexed: int, errors: int, duration_seconds: float}
     */
    public function run(): array
    {
        $start = microtime(true);

        if ($this->authority->size() === 0) {
            $this->logger->warning('IndexReindexer: entity authority is empty — did Reindexer run first?');
        }

        $schema   = $this->schemaLoader->loadForReindex($this->aliasTarget);
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];
        $previous = $this->ops->resolveAliasTarget($alias);

        $this->logger->info('Creating new index collection', ['name' => $newName, 'alias' => $alias]);
        $createPayload = $schema;
        unset($createPayload['_alias_target'], $createPayload['_base_name']);
        $this->typesense->collections->create($createPayload);

        $indexed = 0;
        $errors  = 0;
        $batch   = [];

        try {
            foreach ($this->authority->entities() as $entity) {
                $doc = $this->mapper->map($entity, $this->occurrences->aggregate($entity['id']));
                if ($doc === null) {
                    continue;
                }
                $batch[] = $doc;
                if (count($batch) >= self::BATCH_SIZE) {
                    [$ok, $err] = $this->ops->flushBatch($newName, $batch);
                    $indexed += $ok;
                    $errors  += $err;
                    $batch = [];
                }
            }
            if ($batch !== []) {
                [$ok, $err] = $this->ops->flushBatch($newName, $batch);
                $indexed += $ok;
                $errors  += $err;
            }
        } catch (Throwable $e) {
            $this->logger->error('Index reindex failed; dropping half-built collection', [
                'collection' => $newName,
                'error'      => $e->getMessage(),
            ]);
            $this->ops->safelyDropCollection($newName);
            throw $e;
        }

        $this->logger->info('Swapping index alias', ['alias' => $alias, 'to' => $newName]);
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        if ($previous !== null && $previous !== $newName) {
            $this->logger->info('Dropping previous index collection', ['name' => $previous]);
            $this->ops->safelyDropCollection($previous);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $indexed,
            'errors'           => $errors,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

}

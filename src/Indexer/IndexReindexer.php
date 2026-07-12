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
        $this->ops->createVersioned($schema);

        try {
            [$indexed, $errors] = $this->ops->importAll($newName, $this->mapEntities(), self::BATCH_SIZE);
        } catch (Throwable $e) {
            $this->logger->error('Index reindex failed; dropping half-built collection', [
                'collection' => $newName,
                'error'      => $e->getMessage(),
            ]);
            $this->ops->safelyDropCollection($newName);
            throw $e;
        }

        // Guarded swap: refuses (keeping the previous collection live) when the
        // import was empty or mostly errors — e.g. Reindexer::run() never ran,
        // leaving the authority empty — then sweeps stale iwac_index_vN_*.
        $this->ops->promote($alias, $newName, $schema['_base_name'], $previous, $indexed, $errors);

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $indexed,
            'errors'           => $errors,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * Map every cached entity to its index document, merging in the
     * occurrence aggregate accumulated during the content pass.
     *
     * @return \Generator<array<string,mixed>>
     */
    private function mapEntities(): \Generator
    {
        foreach ($this->authority->entities() as $entity) {
            $doc = $this->mapper->map($entity, $this->occurrences->aggregate($entity['id']));
            if ($doc === null) {
                continue;
            }
            yield $doc;
        }
    }
}

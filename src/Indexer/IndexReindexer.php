<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\IwacInstance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

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
 * alias swap only on success, previous collection retained.
 */
final class IndexReindexer
{
    private const BATCH_SIZE = 200;

    public function __construct(
        // Same helper the content Reindexer uses, injected for the same
        // reason (see Reindexer) — the guarded swap is the testable seam.
        private readonly CollectionOps $ops,
        private readonly SchemaLoader $schemaLoader,
        private readonly EntityAuthority $authority,
        private readonly EntityOccurrences $occurrences,
        private readonly IndexEntityMapper $mapper,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $aliasTarget = IwacInstance::INDEX_ALIAS
    ) {
    }

    /** @var list<int> IDs of the entity documents the last run() imported. */
    private array $indexedIds = [];

    /**
     * @return array{collection: string, alias: string, indexed: int, errors: int, duration_seconds: float}
     */
    public function run(bool $promote = true): array
    {
        $this->indexedIds = [];
        $start = microtime(true);

        if ($this->authority->size() === 0) {
            $this->logger->warning('IndexReindexer: entity authority is empty — did Reindexer run first?');
        }

        $schema   = $this->schemaLoader->loadForReindex($this->aliasTarget);
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];

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

        // Every rejection blocks promotion (and drops the unpromotable build);
        // the orchestrator can defer the swap.
        if ($errors > 0) {
            $this->ops->safelyDropCollection($newName);
            throw new \RuntimeException('Reindex import rejected documents; refusing promotion.');
        }
        if ($promote) {
            $this->ops->promote($alias, $newName, $indexed, $errors);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $indexed,
            'errors'           => $errors,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * IDs of the entity documents the last run() imported — the orchestrator's
     * starting point for reconciling the entity collection after replay.
     *
     * @return list<int>
     */
    public function indexedIds(): array
    {
        return $this->indexedIds;
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
            $this->indexedIds[] = $entity['id'];
            yield $doc;
        }
    }
}

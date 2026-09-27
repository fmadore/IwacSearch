<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Mapper\AbstractMapper;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\IwacInstance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/** Strict indexing core. Jobs acknowledge only successful writes. */
final class IncrementalIndexer
{
    /**
     * Occurrence aggregates an entity document carries. They refresh on a full
     * rebuild only, so an incremental authority update reads them back from
     * the existing document and keeps them.
     */
    private const AGGREGATE_FIELDS = ['frequency', 'authored_count', 'country_ss', 'first_year', 'last_year', 'mentions_by_year_s'];

    public function __construct(
        private readonly CollectionOps $ops,
        private readonly OmekaSourceReader $reader,
        private readonly MapperRegistry $mappers,
        private readonly EntityAuthority $authority,
        private readonly string $collectionAlias = IwacInstance::CONTENT_ALIAS,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $indexAlias = IwacInstance::INDEX_ALIAS,
    ) {
    }

    /**
     * The one wiring of the incremental graph — used by the DrainChanges job
     * (via its service factory), `cli/maintenance.php drain`, and the rebuild
     * cutover, so the three cannot drift apart.
     *
     * @param string      $collection Content collection (or alias) to write.
     * @param string|null $index      Entity collection (or alias); null = content only.
     */
    public static function create(
        CollectionOps $ops,
        Connection $connection,
        string $moduleRoot,
        string $collection = IwacInstance::CONTENT_ALIAS,
        ?string $index = IwacInstance::INDEX_ALIAS,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $authority = new EntityAuthority();
        return new self(
            $ops,
            new OmekaSourceReader($connection),
            MapperRegistry::default($authority, new CountryResolver($moduleRoot . '/data/newspaper-countries.json')),
            $authority,
            $collection,
            $logger,
            $index,
        );
    }

    public function reindexItem(int $itemId): void
    {
        $this->reindexItems([$itemId]);
    }

    /**
     * @param  list<int> $itemIds
     * @return array{content: array<int, ?string>, entity: array<int, bool>} see applySnapshot()
     */
    public function reindexItems(array $itemIds): array
    {
        $outcome = ['content' => [], 'entity' => []];
        foreach (array_chunk(array_values(array_unique($itemIds)), 100) as $batch) {
            $result = $this->applySnapshot($this->snapshot($batch));
            $outcome['content'] = array_replace($outcome['content'], $result['content']);
            $outcome['entity'] = array_replace($outcome['entity'], $result['entity']);
        }
        return $outcome;
    }

    /**
     * SQL and mapping only: the consumer holds the mutation gate during this
     * short phase, then releases it before any embedding/HTTP work.
     * @param list<int> $ids
     * @return list<array{id:int, content:?array<string,mixed>, entity:?array<string,mixed>}>
     */
    public function snapshot(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $this->authority->invalidate();
        $rows = $this->reader->loadResources($ids, $this->mappers->allReadTerms());
        $linked = $ids;
        foreach ($rows as $row) {
            $values = $row['values']->publicMetadata();
            foreach (AbstractMapper::ENTITY_LINK_TERMS as $term) {
                array_push($linked, ...$values->linkedIds($term));
            }
        }
        $this->authority->ensureLoaded($this->reader, $linked);
        $entities = [];
        foreach ($this->authority->entities() as $entity) {
            $entities[$entity['id']] = $entity;
        }
        $snapshot = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $mapper = $row === null ? null : $this->mappers->forClass($row['item']['class']);
            if ($mapper !== null && $mapper->itemSetIds() !== null && array_intersect($mapper->itemSetIds(), $row['item']['item_sets']) === []) {
                $mapper = null;
            }
            $snapshot[] = ['id' => $id, 'content' => $mapper?->map($row['item'], $row['values'], $row['thumbnail']), 'entity' => $entities[$id] ?? null];
        }
        return $snapshot;
    }

    /**
     * Apply one snapshot with a constant number of requests per collection:
     * one filtered delete, one aggregate read (entities), one import.
     *
     * Order: withdrawals first (entity and content deletes), then authority
     * metadata, then content — whose import may run embedding inference.
     *
     * @param  list<array{id:int, content:?array<string,mixed>, entity:?array<string,mixed>}> $snapshot
     * @return array{content: array<int, ?string>, entity: array<int, bool>}
     *   Per ID: the content type now indexed (null = no content document),
     *   and whether an entity document now exists (only when an entity
     *   collection is maintained).
     */
    public function applySnapshot(array $snapshot): array
    {
        $outcome = ['content' => [], 'entity' => []];
        $docs = $contentDeletes = [];
        foreach ($snapshot as $change) {
            if ($change['content'] === null) {
                $contentDeletes[] = (string) $change['id'];
                $outcome['content'][$change['id']] = null;
            } else {
                $docs[] = $change['content'];
                $outcome['content'][$change['id']] = (string) ($change['content']['type_s'] ?? '');
            }
        }

        $entityDocs = $entityDeletes = [];
        if ($this->indexAlias !== null) {
            $withEntity = [];
            foreach ($snapshot as $change) {
                if ($change['entity'] !== null) {
                    $withEntity[] = (string) $change['id'];
                }
            }
            $existing = $withEntity === []
                ? []
                : $this->ops->documentsById($this->indexAlias, $withEntity, self::AGGREGATE_FIELDS);
            $mapper = new IndexEntityMapper();
            foreach ($snapshot as $change) {
                $id = (string) $change['id'];
                $prior = $existing[$id] ?? [];
                $entityDoc = $change['entity'] === null ? null : $mapper->map($change['entity'], [
                    'frequency' => (int) ($prior['frequency'] ?? 0),
                    'authored_count' => (int) ($prior['authored_count'] ?? 0),
                    'countries' => $prior['country_ss'] ?? [],
                    'first_year' => $prior['first_year'] ?? null,
                    'last_year' => $prior['last_year'] ?? null,
                ]);
                if ($entityDoc === null) {
                    $entityDeletes[] = $id;
                    $outcome['entity'][$change['id']] = false;
                    continue;
                }
                if (isset($prior['mentions_by_year_s'])) {
                    $entityDoc['mentions_by_year_s'] = $prior['mentions_by_year_s'];
                }
                $entityDocs[] = $entityDoc;
                $outcome['entity'][$change['id']] = true;
            }
            $this->ops->deleteDocuments($this->indexAlias, $entityDeletes);
        }
        $this->ops->deleteDocuments($this->collectionAlias, $contentDeletes);

        // Authority visibility/metadata is applied before potentially expensive content embeddings.
        if ($this->indexAlias !== null) {
            $this->import($this->indexAlias, $entityDocs);
        }
        $this->import($this->collectionAlias, $docs);
        $this->logger->debug('Processed index change batch', ['items' => count($snapshot)]);
        return $outcome;
    }

    /** @param list<array<string,mixed>> $docs */
    private function import(string $collection, array $docs): void
    {
        [$ok, $failed] = $this->ops->flushBatch($collection, $docs);
        if ($failed !== 0 || $ok !== count($docs)) {
            throw new RuntimeException(sprintf('Incomplete import into %s: %d accepted, %d failed.', $collection, $ok, $failed));
        }
    }

    public function deleteItem(int $itemId): void
    {
        if ($itemId > 0) {
            $this->ops->deleteDocuments($this->collectionAlias, [(string) $itemId]);
            if ($this->indexAlias !== null) {
                $this->ops->deleteDocuments($this->indexAlias, [(string) $itemId]);
            }
        }
    }
}

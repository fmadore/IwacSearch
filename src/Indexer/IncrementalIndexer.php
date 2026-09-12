<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\AbstractMapper;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/** Strict indexing core. Jobs acknowledge only successful writes. */
final class IncrementalIndexer
{
    public function __construct(
        private readonly CollectionOps $ops,
        private readonly OmekaSourceReader $reader,
        private readonly MapperRegistry $mappers,
        private readonly EntityAuthority $authority,
        private readonly string $collectionAlias = 'iwac_current',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $indexAlias = 'iwac_index_current',
    ) {
    }

    public function reindexItem(int $itemId): void
    {
        $this->reindexItems([$itemId]);
    }

    /** @param list<int> $itemIds */
    public function reindexItems(array $itemIds): void
    {
        foreach (array_chunk(array_values(array_unique($itemIds)), 100) as $batch) {
            $this->applySnapshot($this->snapshot($batch));
        }
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

    /** @param list<array{id:int, content:?array<string,mixed>, entity:?array<string,mixed>}> $snapshot */
    public function applySnapshot(array $snapshot): void
    {
        $docs = $entityDocs = [];
        foreach ($snapshot as $change) {
            $id = (string) $change['id'];
            if ($change['content'] === null) {
                $this->ops->deleteDocument($this->collectionAlias, $id);
            } else {
                $docs[] = $change['content'];
            }
            if ($this->indexAlias === null) {
                continue;
            }
            if ($change['entity'] === null) {
                $this->ops->deleteDocument($this->indexAlias, $id);
                continue;
            }
            $existing = $this->ops->document($this->indexAlias, $id) ?? [];
            $entityDoc = (new IndexEntityMapper())->map($change['entity'], [
                'frequency' => (int) ($existing['frequency'] ?? 0),
                'authored_count' => (int) ($existing['authored_count'] ?? 0),
                'countries' => $existing['country_ss'] ?? [],
                'first_year' => $existing['first_year'] ?? null,
                'last_year' => $existing['last_year'] ?? null,
            ]);
            if ($entityDoc === null) {
                $this->ops->deleteDocument($this->indexAlias, $id);
            } else {
                if (isset($existing['mentions_by_year_s'])) {
                    $entityDoc['mentions_by_year_s'] = $existing['mentions_by_year_s'];
                }
                $entityDocs[] = $entityDoc;
            }
        }
        // Authority visibility/deletion is applied before potentially expensive content embeddings.
        if ($this->indexAlias !== null) {
            $this->import($this->indexAlias, $entityDocs);
        }
        $this->import($this->collectionAlias, $docs);
        $this->logger->debug('Processed index change batch', ['items' => count($snapshot)]);
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
            $this->ops->deleteDocument($this->collectionAlias, (string) $itemId);
            if ($this->indexAlias !== null) {
                $this->ops->deleteDocument($this->indexAlias, (string) $itemId);
            }
        }
    }
}

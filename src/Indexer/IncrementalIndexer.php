<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\AbstractMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Live updates to the content collection, triggered by Omeka's api.*.post
 * events.
 *
 * Now that the indexer reads from the same Omeka database the live site
 * writes to, an item edit (or a brand-new item) can be re-mapped on the spot
 * — the full document, not just the is_public flag — and upserted into
 * Typesense within the request that changed it. This closes the old M4 gap
 * where metadata edits waited for the monthly bulk reindex.
 *
 *   - reindexItem()  — load the one item from MySQL, pick its mapper by class,
 *     resolve just its linked entities on demand, map, upsert. Covers create
 *     and update (is_public included, since it's a full re-map). An item that
 *     WAS indexed but no longer maps (its class changed to one no mapper
 *     handles) has its stale document deleted instead of left live.
 *   - reindexItems() — batched variant for the batch-update / cascade paths:
 *     one DB load, one entity resolution, one JSONL import for N items.
 *   - deleteItem()   — remove the doc when the Omeka resource is deleted.
 *
 * NOT handled here: the entity collection's occurrence aggregates (frequency /
 * year span / countries). Those are corpus-wide reverse scans, refreshed by
 * the bulk reindex; letting them drift slightly between rebuilds is fine.
 *
 * Interaction with the bulk reindex: upserts go through the alias, so an edit
 * made WHILE a reindex is running lands in the OUTGOING collection and would
 * be discarded at the swap. ReindexOrchestrator::catchUpEdits() replays those
 * edits into the new collection immediately after the swap, using this class
 * against the new collection by name. DELETES made mid-build are still lost
 * (the row is gone, so nothing can find it afterwards) — see that method for
 * why that residue is accepted.
 *
 * Resilience: every operation is wrapped in try/catch. A failed Typesense
 * call logs and swallows the error — it MUST NOT block the Omeka save the
 * user is completing, even if Typesense is down.
 */
final class IncrementalIndexer
{
    public function __construct(
        // Import / delete plumbing shared with the bulk reindexers: the JSONL
        // encoding, the per-line success tally and the "was it even there"
        // 404 rule are one implementation, not three. It holds the LAZY
        // client, so an unreachable Typesense still can't block a save.
        private readonly CollectionOps $ops,
        private readonly OmekaSourceReader $reader,
        private readonly MapperRegistry $mappers,
        private readonly EntityAuthority $authority,
        private readonly string $collectionAlias = 'iwac_current',
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Re-map one item from the database and upsert the full document.
     *
     * Skips silently when the id isn't an indexable content item (an
     * authority record, or a class no mapper handles) — those simply never
     * belong in the content collection. When the item loads but no longer
     * maps (its class was edited away from a content class), any previously
     * indexed document is deleted so a stale — possibly public — doc can't
     * outlive its mappability.
     */
    public function reindexItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $row = $this->reader->loadResources([$itemId], $this->mappers->allReadTerms())[$itemId] ?? null;
            if ($row === null) {
                return; // not an Item, or already gone — delete handles removal
            }

            $doc = $this->mapRow($row);
            if ($doc === null) {
                // No longer a mappable content item: clear any stale document
                // (deleteItem is 404-tolerant, so never-indexed ids are no-ops).
                $this->deleteItem($itemId);
                return;
            }

            [$indexed, $failed] = $this->ops->flushBatch($this->collectionAlias, [$doc]);
            if ($indexed === 0) {
                // flushBatch already logged the server's response line.
                $this->logger->warning('IwacSearch: item upsert reported failure', [
                    'item_id' => $itemId,
                    'failed'  => $failed,
                ]);
                return;
            }

            $this->logger->info('IwacSearch: item re-indexed in Typesense', [
                'item_id'   => $itemId,
                'type'      => $doc['type_s'] ?? null,
                'is_public' => $doc['is_public'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Never block the Omeka save; bulk reindex is the backstop.
            $this->logger->warning('IwacSearch: failed to re-index item in Typesense', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batched reindex for the paths that touch many items at once (batch
     * update/create, item-set delete cascade): one loadResources() round
     * trip, one entity resolution for the union of linked ids, and one JSONL
     * import — instead of reindexItem()'s 4 SQL queries + 1 HTTP call per id.
     *
     * Same semantics per item as reindexItem(), including the stale-doc
     * delete for items that no longer map.
     *
     * @param list<int> $itemIds
     */
    public function reindexItems(array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, static fn(int $id): bool => $id > 0)));
        if ($itemIds === []) {
            return;
        }
        if (count($itemIds) === 1) {
            $this->reindexItem($itemIds[0]);
            return;
        }

        try {
            $rows = $this->reader->loadResources($itemIds, $this->mappers->allReadTerms());

            // Preload the union of linked entities in one round trip; the
            // per-row ensureLoaded() inside mapRow() then hits the cache.
            $entityIds = [];
            foreach ($rows as $row) {
                $entityIds[] = $this->linkedEntityIds($row['values']);
            }
            $this->authority->ensureLoaded($this->reader, array_merge(...($entityIds ?: [[]])));

            $docs  = [];
            $stale = [];
            foreach ($itemIds as $itemId) {
                $row = $rows[$itemId] ?? null;
                if ($row === null) {
                    continue; // not an Item / already gone
                }
                $doc = $this->mapRow($row);
                if ($doc === null) {
                    $stale[] = $itemId;
                    continue;
                }
                $docs[] = $doc;
            }

            foreach ($stale as $itemId) {
                $this->deleteItem($itemId);
            }
            if ($docs === []) {
                return;
            }

            [$indexed, $failed] = $this->ops->flushBatch($this->collectionAlias, $docs);
            $this->logger->info('IwacSearch: batch re-indexed in Typesense', [
                'items'  => $indexed,
                'failed' => $failed,
            ]);
        } catch (Throwable $e) {
            // Never block the Omeka save; bulk reindex is the backstop.
            $this->logger->warning('IwacSearch: failed to batch re-index items in Typesense', [
                'items' => count($itemIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map one loadResources() row to its document, resolving only the row's
     * linked entities (rebuilding the whole authority per save would be far
     * too expensive). Null when no mapper handles the class or the mapper
     * declines the item.
     *
     * @param array{
     *     item: array{id:int,title:string,is_public:bool,class:int,item_sets:list<int>},
     *     values: PropertyValues,
     *     thumbnail: ?string
     * } $row
     * @return ?array<string,mixed>
     */
    private function mapRow(array $row): ?array
    {
        $mapper = $this->mappers->forClass($row['item']['class']);
        if ($mapper === null) {
            return null; // not a content class (authority record / unmapped class)
        }

        $this->authority->ensureLoaded($this->reader, $this->linkedEntityIds($row['values']));

        return $mapper->map($row['item'], $row['values'], $row['thumbnail']);
    }

    /**
     * Delete one document by id. Idempotent — 404 from Typesense is logged as
     * a no-op, not a failure.
     */
    public function deleteItem(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $existed = $this->ops->deleteDocument($this->collectionAlias, (string) $itemId);
            $this->logger->info(
                $existed
                    ? 'IwacSearch: item deleted from Typesense'
                    : 'IwacSearch: item was not indexed, nothing to delete',
                ['item_id' => $itemId]
            );
        } catch (Throwable $e) {
            $this->logger->warning('IwacSearch: failed to delete item from Typesense', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * The authority ids one content row links to — EVERY term
     * AbstractMapper::addAuthorityEntities() resolves, so preloading these
     * means every mapper lookup hits the cache.
     *
     * The list must stay a superset of what the mappers read. An id missing
     * here is not an error anywhere: EntityAuthority::resolveAuthorIds()
     * skips ids it has not loaded, so the row would simply be written with
     * its authorship silently dropped. That is exactly how this list fell
     * behind once already — it covered only subject + spatial while the
     * mappers had grown to resolve authorship and publisher too.
     *
     * Cheap to over-fetch: the ids are unioned across the batch and loaded in
     * one round trip, and non-authority targets are ignored on arrival.
     *
     * @return list<int>
     */
    private function linkedEntityIds(PropertyValues $values): array
    {
        $ids = [];
        foreach (AbstractMapper::ENTITY_LINK_TERMS as $term) {
            $ids[] = $values->linkedIds($term);
        }
        return array_merge(...$ids);
    }
}

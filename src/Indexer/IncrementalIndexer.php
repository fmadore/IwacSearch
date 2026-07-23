<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

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
 * Known gap: upserts go through the alias, so an edit made WHILE a bulk
 * reindex is running lands in the outgoing collection and is discarded at
 * the alias swap if the item's page had already been streamed. The next
 * save (or bulk reindex) heals it.
 *
 * Resilience: every operation is wrapped in try/catch. A failed Typesense
 * call logs and swallows the error — it MUST NOT block the Omeka save the
 * user is completing, even if Typesense is down.
 */
final class IncrementalIndexer
{
    public function __construct(
        /** @var Closure(): TypesenseClient */
        private readonly Closure $clientFactory,
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

            $response = $this->collection()->documents->import(
                json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ['action' => 'upsert']
            );
            $line = json_decode(trim((string) $response), true);
            if (is_array($line) && ($line['success'] ?? true) === false) {
                $this->logger->warning('IwacSearch: item upsert reported failure', [
                    'item_id'  => $itemId,
                    'response' => $line,
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
                $entityIds[] = $this->vrids($row['values'], 'dcterms:subject');
                $entityIds[] = $this->vrids($row['values'], 'dcterms:spatial');
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

            $jsonl = '';
            foreach ($docs as $doc) {
                $jsonl .= json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            $response = $this->collection()->documents->import($jsonl, ['action' => 'upsert']);

            $failed = 0;
            foreach (preg_split("/\r?\n/", trim((string) $response)) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && ($decoded['success'] ?? true) === false) {
                    $failed++;
                }
            }
            $this->logger->info('IwacSearch: batch re-indexed in Typesense', [
                'items'  => count($docs),
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
     *     values: array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>>,
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

        $entityIds = array_merge(
            $this->vrids($row['values'], 'dcterms:subject'),
            $this->vrids($row['values'], 'dcterms:spatial'),
        );
        $this->authority->ensureLoaded($this->reader, $entityIds);

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
            $this->collection()->documents[(string) $itemId]->delete();
            $this->logger->info('IwacSearch: item deleted from Typesense', [
                'item_id' => $itemId,
            ]);
        } catch (Throwable $e) {
            if ($this->isNotFound($e)) {
                $this->logger->info('IwacSearch: item was not indexed, nothing to delete', [
                    'item_id' => $itemId,
                ]);
                return;
            }
            $this->logger->warning('IwacSearch: failed to delete item from Typesense', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @return list<int>
     */
    private function vrids(array $values, string $term): array
    {
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            if ($v['vrid'] !== null) {
                $out[] = $v['vrid'];
            }
        }
        return $out;
    }

    private function collection(): object
    {
        // Alias name doubles as a collection name in every Typesense
        // documents/* endpoint, so we don't need to resolve it first.
        // The factory closure memoizes (TypesenseClientLazy).
        return ($this->clientFactory)()->collections[$this->collectionAlias];
    }

    /**
     * Typesense's PHP SDK wraps HTTP errors in a few exception classes; match
     * on the message / status rather than importing all of them. Good enough
     * for a "did the doc exist" check.
     */
    private function isNotFound(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'not found')
            || str_contains($msg, '404')
            || str_contains($msg, 'could not find');
    }
}

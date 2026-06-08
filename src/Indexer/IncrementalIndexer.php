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
 *   - reindexItem() — load the one item from MySQL, pick its mapper by class,
 *     resolve just its linked entities on demand, map, upsert. Covers create
 *     and update (is_public included, since it's a full re-map).
 *   - deleteItem()  — remove the doc when the Omeka resource is deleted.
 *
 * NOT handled here: the entity collection's occurrence aggregates (frequency /
 * year span / countries). Those are corpus-wide reverse scans, refreshed by
 * the bulk reindex; letting them drift slightly between rebuilds is fine.
 *
 * Resilience: every operation is wrapped in try/catch. A failed Typesense
 * call logs and swallows the error — it MUST NOT block the Omeka save the
 * user is completing, even if Typesense is down.
 */
final class IncrementalIndexer
{
    private ?TypesenseClient $cachedClient = null;

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
     * Skips silently when the id isn't an indexable content item (a
     * photograph, an authority item, or a class no mapper handles) — those
     * simply never belong in the content collection.
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

            $mapper = $this->mappers->forClass($row['item']['class']);
            if ($mapper === null) {
                return; // not a content class (photograph / authority / …)
            }

            // Resolve only THIS item's linked entities — rebuilding the whole
            // authority per save would be far too expensive.
            $entityIds = array_merge(
                $this->vrids($row['values'], 'dcterms:subject'),
                $this->vrids($row['values'], 'dcterms:spatial'),
            );
            $this->authority->ensureLoaded($this->reader, $entityIds);

            $doc = $mapper->map($row['item'], $row['values'], $row['thumbnail']);
            if ($doc === null) {
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
        return $this->client()->collections[$this->collectionAlias];
    }

    private function client(): TypesenseClient
    {
        return $this->cachedClient ??= ($this->clientFactory)();
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

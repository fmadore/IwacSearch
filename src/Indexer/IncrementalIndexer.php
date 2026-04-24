<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Live updates to Typesense triggered by Omeka's api.*.post events.
 *
 * Scope of this first pass (M4):
 *
 *   - `updateItemPublicState()`  — flip is_public when an admin toggles an
 *                                  item's visibility. Critical path: a
 *                                  just-made-private item must stop
 *                                  appearing in public search within the
 *                                  request that changed it, not "some
 *                                  time in the next 30 days when bulk
 *                                  reindex runs".
 *   - `deleteItem()`             — remove a doc when its Omeka resource
 *                                  is deleted, so we don't 404 from
 *                                  stale Typesense hits.
 *
 * NOT in scope here:
 *
 *   - Full doc replacement on metadata edits. The HF mapper pipeline is
 *     the source of truth for content fields (title, ocr_text, facets,
 *     embeddings). An on-demand Omeka → Typesense mapper would
 *     duplicate that pipeline with a different source of truth — we'd
 *     rather run the bulk reindex monthly and accept a ≤30-day lag for
 *     metadata changes than build a parallel mapper we'd have to keep
 *     in sync with the HF one. A future pass can add this if the lag
 *     becomes painful.
 *
 *   - New items. Same reason: a doc without ocr_text, embeddings, and
 *     authority joins is worse than no doc at all (it'd rank poorly
 *     and return useless hits). New items wait for bulk reindex.
 *
 * Resilience: every operation is wrapped in try/catch. A failed
 * Typesense call logs and swallows the error — it MUST NOT block the
 * Omeka save the user is completing, even if Typesense is down.
 */
final class IncrementalIndexer
{
    private ?TypesenseClient $cachedClient = null;

    public function __construct(
        /** @var Closure(): TypesenseClient */
        private readonly Closure $clientFactory,
        private readonly string $collectionAlias = 'iwac_current',
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Partial-update the is_public field on one document.
     *
     * Typesense's PATCH /collections/x/documents/<id> preserves every
     * other field; we're not re-indexing content, just flipping one flag.
     * If the doc doesn't exist yet (item never indexed, or newly created),
     * the PATCH returns 404 — we log and move on. Bulk reindex will
     * pick it up.
     */
    public function updateItemPublicState(int $itemId, bool $isPublic): void
    {
        if ($itemId <= 0) {
            return;
        }

        try {
            $this->collection()->documents[(string) $itemId]->update([
                'is_public' => $isPublic,
            ]);
            $this->logger->info('IwacSearch: is_public updated in Typesense', [
                'item_id'   => $itemId,
                'is_public' => $isPublic,
            ]);
        } catch (Throwable $e) {
            // 404 = doc not indexed yet (new item, bulk reindex pending).
            // Not an error — just an observation.
            if ($this->isNotFound($e)) {
                $this->logger->info('IwacSearch: item not in Typesense yet, skipping is_public sync', [
                    'item_id' => $itemId,
                ]);
                return;
            }
            $this->logger->warning('IwacSearch: failed to update is_public in Typesense', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete one document by id. Idempotent — 404 from Typesense is
     * logged as a no-op, not a failure.
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
     * Typesense's PHP SDK wraps HTTP errors in a few different
     * exception classes; rather than import all of them, match on the
     * error message / status. Good enough for a "did the doc exist"
     * check.
     */
    private function isNotFound(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'not found')
            || str_contains($msg, '404')
            || str_contains($msg, 'could not find');
    }
}

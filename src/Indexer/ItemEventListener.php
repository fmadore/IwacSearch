<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Response as OmekaApiResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Handlers for the api.*.post events that drive incremental indexing.
 *
 * Lives separately from Module.php so:
 *
 *   1. Module.php stays focused on lifecycle + bootstrap concerns
 *      (autoload, ACL, event-attach wiring) and doesn't carry the
 *      details of every listener body.
 *   2. The listener can be unit-tested with a mocked
 *      IncrementalIndexer — Module is awkward to construct under test
 *      because of its AbstractModule dependencies.
 *   3. Adding a future event is a method on this class plus one wire-up
 *      line in Module.php — not yet another inline method on Module.
 *
 * Events handled (all attached in Module::attachListeners):
 *
 *   ItemAdapter    api.create.post / api.update.post / api.delete.post
 *   ItemAdapter    api.batch_create.post / api.batch_update.post /
 *                  api.batch_delete.post — Omeka's batch operations hydrate
 *                  entities directly WITHOUT firing the per-item events, so
 *                  without these a batch visibility flip (admin "batch edit"
 *                  → private) would leave stale is_public docs in Typesense
 *                  until the next bulk reindex.
 *   MediaAdapter   api.create.post / api.update.post / api.delete.post —
 *                  re-map the PARENT item, because thumbnail_url (and the
 *                  iiif_manifest presence flag) derive from the item's
 *                  primary media. Direct media edits (replace file, toggle
 *                  visibility) don't fire any item event.
 *   ItemSetAdapter api.delete.pre / api.delete.post — country_ss for the
 *                  references / documents / photographs subsets derives
 *                  from per-country item-set membership, so deleting a set
 *                  must re-map its (former) members. Membership rows are
 *                  gone by .post, so .pre captures them.
 *
 * Method shapes match Laminas's `[object, 'method']` callback
 * convention so they can be passed straight to
 * `$sharedEventManager->attach(...)` without a closure wrapper.
 */
final class ItemEventListener
{
    /**
     * Cap for the item-set delete cascade, which runs inside the admin's
     * synchronous delete request (not a job). Curated country sets hold
     * thousands of members; re-mapping those inline (even batched through
     * reindexItems) would hang the request, and deleting one is a deliberate
     * restructure that warrants a bulk reindex anyway. Members beyond the
     * cap are logged, not re-mapped.
     */
    private const ITEM_SET_CASCADE_CAP = 200;

    /** @var array<int, list<int>> item-set id → member item ids captured at delete.pre */
    private array $pendingSetMembers = [];

    public function __construct(
        private readonly IncrementalIndexer $indexer,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Re-map and upsert the full document after Omeka commits an item save.
     *
     * Fires only when the DB write succeeded, so we never push a state the
     * admin didn't actually persist. Failure to reach Typesense is swallowed
     * inside the indexer — this listener never throws to the Omeka dispatcher.
     */
    public function onItemUpdate(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->reindexItem((int) $item->id());
    }

    /**
     * Index a newly-created item (full re-map). Now possible because the
     * indexer reads the same Omeka database the create just wrote to — a new
     * item gets a complete document, not the half-baked placeholder the old
     * HF-only indexing path would have produced (which is why create was not
     * wired before). Non-content items (authority records, unmapped classes)
     * are skipped inside the indexer.
     */
    public function onItemCreate(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->reindexItem((int) $item->id());
    }

    /**
     * Remove the corresponding doc from Typesense after a delete.
     *
     * Idempotent on the indexer side — a 404 from Typesense (because
     * the item was never indexed, or the bulk reindex already cleared
     * it) is logged as info, not an error.
     */
    public function onItemDelete(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->deleteItem((int) $item->id());
    }

    // ────────────────────────────────────────────────────────────────────
    // Batch operations (no per-item api.*.post events fire for these)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Re-map every item touched by a batch update. The admin "batch edit"
     * flow (add/remove item sets, flip visibility, replace values) goes
     * through Omeka's batchUpdate, which hydrates entities directly and
     * fires ONLY this event — the per-item api.update.post never happens.
     * Visibility flips make this privacy-relevant, not just cosmetic: a
     * stale is_public:true doc remains reachable through the public key.
     */
    public function onItemBatchUpdate(Event $event): void
    {
        $this->indexer->reindexItems($this->extractBatchItemIds($event));
    }

    /** Index items created in bulk (CSV Import and friends use batchCreate). */
    public function onItemBatchCreate(Event $event): void
    {
        $this->indexer->reindexItems($this->extractBatchItemIds($event));
    }

    /** Remove every doc deleted by a batch delete ("delete selected"). */
    public function onItemBatchDelete(Event $event): void
    {
        foreach ($this->extractBatchItemIds($event) as $id) {
            $this->indexer->deleteItem($id);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Media events — thumbnail_url / iiif_manifest derive from the item's
    // primary media, so a direct media edit must re-map the parent item.
    // ────────────────────────────────────────────────────────────────────

    /** Media created or updated → re-map the parent item. */
    public function onMediaWrite(Event $event): void
    {
        $itemId = $this->extractMediaParentId($event);
        if ($itemId !== null) {
            $this->indexer->reindexItem($itemId);
        }
    }

    /** Media deleted → re-map the parent item (its thumbnail may change). */
    public function onMediaDelete(Event $event): void
    {
        $itemId = $this->extractMediaParentId($event);
        if ($itemId !== null) {
            $this->indexer->reindexItem($itemId);
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Item-set deletion — membership drives country_ss on the references /
    // documents / photographs subsets (CountryResolver::forItemSets) and
    // the item_set_ids field on every content doc.
    // ────────────────────────────────────────────────────────────────────

    /**
     * BEFORE the delete commits: capture the set's member item ids, because
     * the item_item_set join rows are gone by the time api.delete.post
     * fires. Keyed by set id so an unlikely interleaving of two deletes in
     * one request can't cross wires.
     */
    public function onItemSetDeletePre(Event $event): void
    {
        $setId = $this->extractResourceId($event);
        if ($setId === null) {
            return;
        }
        try {
            /** @var list<string|int> $rows */
            $rows = $this->connection->fetchFirstColumn(
                'SELECT item_id FROM item_item_set WHERE item_set_id = ?',
                [$setId]
            );
            $this->pendingSetMembers[$setId] = array_map('intval', $rows);
        } catch (Throwable $e) {
            $this->logger->warning('IwacSearch: failed to capture item-set members before delete', [
                'item_set_id' => $setId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * AFTER the delete commits: re-map the former members so their
     * country_ss / item_set_ids reflect the removal. Capped — see
     * ITEM_SET_CASCADE_CAP.
     */
    public function onItemSetDeletePost(Event $event): void
    {
        $setId = $this->extractResourceId($event);
        if ($setId === null || !isset($this->pendingSetMembers[$setId])) {
            return;
        }
        $memberIds = $this->pendingSetMembers[$setId];
        unset($this->pendingSetMembers[$setId]);

        $overflow = count($memberIds) - self::ITEM_SET_CASCADE_CAP;
        if ($overflow > 0) {
            $this->logger->warning(
                'IwacSearch: deleted item set had more members than the inline re-map cap; '
                . 'run a bulk reindex to refresh the remainder',
                ['item_set_id' => $setId, 'members' => count($memberIds), 'skipped' => $overflow]
            );
            $memberIds = array_slice($memberIds, 0, self::ITEM_SET_CASCADE_CAP);
        }
        $this->indexer->reindexItems($memberIds);
    }

    // ────────────────────────────────────────────────────────────────────
    // Event plumbing
    // ────────────────────────────────────────────────────────────────────

    /**
     * Pull the ItemRepresentation out of an api.*.post event.
     *
     * Omeka events carry an `Omeka\Api\Response` whose `getContent()`
     * returns the representation we want. We defensively re-check the
     * shape because future Omeka versions might wrap things differently
     * — returning null on a mismatch keeps us forward-compatible.
     */
    private function extractItem(Event $event): ?ItemRepresentation
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return null;
        }
        $content = $response->getContent();
        return $content instanceof ItemRepresentation ? $content : null;
    }

    /**
     * Item ids touched by a batch operation. The REQUEST ids are
     * authoritative for update/delete (set by the caller); batch_create has
     * no request ids, so fall back to the response representations.
     * Re-mapping an id the batch ultimately skipped is harmless — the
     * re-map just reproduces the current document.
     *
     * @return list<int>
     */
    private function extractBatchItemIds(Event $event): array
    {
        $ids = [];

        $request = $event->getParam('request');
        if (is_object($request) && method_exists($request, 'getIds')) {
            foreach ((array) $request->getIds() as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }

        if ($ids === []) {
            $response = $event->getParam('response');
            if ($response instanceof OmekaApiResponse) {
                $content = $response->getContent();
                foreach (is_array($content) ? $content : [] as $representation) {
                    if ($representation instanceof ItemRepresentation) {
                        $ids[] = (int) $representation->id();
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** Parent item id of the media an api.*.post event carries, if any. */
    private function extractMediaParentId(Event $event): ?int
    {
        $response = $event->getParam('response');
        if (!$response instanceof OmekaApiResponse) {
            return null;
        }
        $content = $response->getContent();
        if (!$content instanceof MediaRepresentation) {
            return null;
        }
        try {
            $item = $content->item();
            return $item !== null ? (int) $item->id() : null;
        } catch (Throwable) {
            // A just-deleted media may no longer resolve its parent.
            return null;
        }
    }

    /**
     * Resource id from an api.delete.pre / api.delete.post event — the
     * request carries it in both phases (the response content may already
     * be gone by .post).
     */
    private function extractResourceId(Event $event): ?int
    {
        $request = $event->getParam('request');
        if (!is_object($request) || !method_exists($request, 'getId')) {
            return null;
        }
        $id = $request->getId();
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}

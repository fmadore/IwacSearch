<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Laminas\EventManager\Event;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Response as OmekaApiResponse;

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
 *   3. Adding a future event (e.g. api.create.post on Item, or item-set
 *      changes that mass-update is_public) is a method on this class
 *      plus one wire-up line in Module.php — not yet another inline
 *      method on Module.
 *
 * Method shapes match Laminas's `[object, 'method']` callback
 * convention so they can be passed straight to
 * `$sharedEventManager->attach(...)` without a closure wrapper.
 */
final class ItemEventListener
{
    public function __construct(
        private readonly IncrementalIndexer $indexer
    ) {
    }

    /**
     * Sync is_public to Typesense after Omeka commits an item save.
     *
     * Fires only when the DB write succeeded, so we never push a state
     * the admin didn't actually persist. Failure to reach Typesense is
     * swallowed inside the indexer — this listener never throws to the
     * Omeka dispatcher.
     */
    public function onItemUpdate(Event $event): void
    {
        $item = $this->extractItem($event);
        if ($item === null) {
            return;
        }
        $this->indexer->updateItemPublicState(
            (int) $item->id(),
            (bool) $item->isPublic()
        );
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
}

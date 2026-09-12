<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use Doctrine\DBAL\Connection;
use Laminas\EventManager\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/** Journal writes and dependencies while holding the short cutover gate. No Typesense I/O. */
final class ItemEventListener
{
    /** @var array<int, list<int>> */
    private array $affected = [];
    private bool $scheduled = false;
    private readonly DatabaseLock $gate;
    private readonly ChangeJournal $journal;

    /** @param Closure(): void $dispatch */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $dispatch,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->gate = new DatabaseLock($connection, 'mutation');
        $this->journal = new ChangeJournal($connection);
    }

    public function onBeforeWrite(Event $event, string $resource): void
    {
        $request = $event->getParam('request');
        if (!$this->isWrite($request)) {
            return;
        }
        $this->gate->acquire(300);
        try {
            $ids = $this->ids($request);
            $affected = $resource === 'items' ? $ids : [];
            if ($ids !== []) {
                if ($resource === 'items') {
                    // Capture before deletes remove value_resource links. Also refresh
                    // denormalized titles when ANY linked resource is edited.
                    $linked = $this->connection->fetchFirstColumn(
                        'SELECT DISTINCT resource_id FROM `value` WHERE value_resource_id IN (?)',
                        [$ids],
                        [Connection::PARAM_INT_ARRAY]
                    );
                    array_push($affected, ...array_map('intval', $linked));
                } elseif ($resource === 'media') {
                    $affected = array_map('intval', $this->connection->fetchFirstColumn(
                        'SELECT DISTINCT item_id FROM media WHERE id IN (?)',
                        [$ids],
                        [Connection::PARAM_INT_ARRAY]
                    ));
                } elseif ($resource === 'item_sets') {
                    $affected = array_map('intval', $this->connection->fetchFirstColumn(
                        'SELECT DISTINCT item_id FROM item_item_set WHERE item_set_id IN (?)',
                        [$ids],
                        [Connection::PARAM_INT_ARRAY]
                    ));
                }
            }
            // Persist tombstones/dependencies before a delete can remove them.
            // Workers snapshot only after the mutation gate has been released.
            $this->journal->append(array_values(array_unique($affected)));
            $this->affected[spl_object_id($request)] = $affected;
        } catch (Throwable $e) {
            $this->gate->release();
            throw $e;
        }
    }

    public function onAfterWrite(Event $event, string $resource): void
    {
        $request = $event->getParam('request');
        if (!$this->isWrite($request)) {
            return;
        }
        try {
            $key = spl_object_id($request);
            $ids = $this->affected[$key] ?? [];
            unset($this->affected[$key]);
            $response = $event->getParam('response');
            $content = $response?->getContent();
            foreach (is_array($content) ? $content : [$content] as $representation) {
                if (!is_object($representation)) {
                    continue;
                }
                if ($resource === 'items' && method_exists($representation, 'getId')) {
                    $ids[] = (int) $representation->getId();
                } elseif ($resource === 'media' && method_exists($representation, 'getItem')) {
                    $parent = $representation->getItem();
                    if ($parent !== null) {
                        $ids[] = (int) $parent->getId();
                    }
                } elseif ($resource === 'items' && method_exists($representation, 'id')) {
                    $ids[] = (int) $representation->id();
                } elseif ($resource === 'media' && method_exists($representation, 'item')) {
                    $parent = $representation->item();
                    if ($parent !== null) {
                        $ids[] = (int) $parent->id();
                    }
                }
            }
            $this->journal->append(array_values(array_unique($ids)));
            if ($ids !== [] && !$this->scheduled) {
                $this->scheduled = true;
                // Dispatch once after all nested/batch events have appended their IDs.
                register_shutdown_function(function (): void {
                    try {
                        ($this->dispatch)();
                    } catch (Throwable $e) {
                        $this->logger->error('IwacSearch pending changes need retry', ['error' => $e->getMessage()]);
                    }
                });
            }
        } finally {
            $this->gate->release();
        }
    }

    private function isWrite(mixed $request): bool
    {
        return is_object($request) && method_exists($request, 'getOperation')
            && in_array($request->getOperation(), ['create', 'update', 'delete', 'batch_create', 'batch_update', 'batch_delete'], true);
    }

    /** @return list<int> */
    private function ids(object $request): array
    {
        $ids = method_exists($request, 'getIds') ? (array) $request->getIds() : [];
        if ($ids === [] && method_exists($request, 'getId')) {
            $ids = [$request->getId()];
        }
        return array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    }
}

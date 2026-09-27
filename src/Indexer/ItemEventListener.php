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
    /**
     * Omeka API operations that change data. Module.php filters on this list
     * before resolving the listener, so reads never build the indexing graph.
     */
    public const WRITE_OPERATIONS = ['create', 'update', 'delete', 'batch_create', 'batch_update', 'batch_delete'];

    private bool $scheduled = false;
    private readonly WriteGate $gate;
    private readonly ChangeJournal $journal;

    /** @param Closure(): void $dispatch */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $dispatch,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?WriteGate $gate = null,
    ) {
        $this->gate = $gate ?? new WriteGate(new DatabaseLock($connection, 'mutation'));
        $this->journal = new ChangeJournal($connection);
    }

    public static function isWriteOperation(mixed $operation): bool
    {
        return in_array($operation, self::WRITE_OPERATIONS, true);
    }

    /**
     * Release gate levels left by writes that never reached their post event
     * (see WriteGate). Cheap; Module calls it on API reads once this listener
     * exists, so a job that caught a failed write does not keep the gate.
     */
    public function settle(): void
    {
        $this->gate->settle();
    }

    public function onBeforeWrite(Event $event, string $resource): void
    {
        $request = $event->getParam('request');
        if (!$this->isWrite($request)) {
            return;
        }
        $this->gate->enter($request);
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
            $affected = array_values(array_unique($affected));
            $this->journal->append($affected);
            $this->gate->remember($request, $affected);
        } catch (Throwable $e) {
            $this->gate->leave($request);
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
            // Null when the pre event was skipped (`initialize => false`):
            // then there are no captured dependencies and no gate level to give back.
            $ids = $this->gate->pending($request) ?? [];
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
            $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
            $this->journal->append($ids);
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
            $this->gate->leave($request);
        }
    }

    /** @phpstan-assert-if-true object $request */
    private function isWrite(mixed $request): bool
    {
        return is_object($request) && method_exists($request, 'getOperation')
            && self::isWriteOperation($request->getOperation());
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

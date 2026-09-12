<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Closure;

/** Bounded, serialized consumer. Failed rows remain retryable, including deletes. */
final class ChangeDrainer
{
    public function __construct(private readonly Connection $connection, private readonly IncrementalIndexer $indexer)
    {
    }
    /** @param ?Closure(): bool $stop */
    public function run(?Closure $stop = null, int $seconds = 120): int
    {
        $journal = new ChangeJournal($this->connection);
        $lock = new DatabaseLock($this->connection, 'publish');
        $mutation = new DatabaseLock($this->connection, 'mutation');
        $deadline = microtime(true) + $seconds;
        $count = 0;
        do {
            if ($stop !== null && $stop()) {
                break;
            }
            // Never hold the mutation gate while waiting for another worker's
            // HTTP import. Its worker (or the scheduled drain) will consume us.
            if (!$mutation->tryAcquire()) {
                return $count;
            }
            try {
                if (!$lock->tryAcquire()) {
                    return $count;
                }
                try {
                    $rows = $journal->pending(50);
                    $snapshot = $this->indexer->snapshot(array_column($rows, 'item_id'));
                } catch (\Throwable $e) {
                    $lock->release();
                    throw $e;
                }
            } finally {
                $mutation->release();
            }
            try {
                if ($rows === []) {
                    break;
                }
                $this->indexer->applySnapshot($snapshot);
                $journal->acknowledge(array_column($rows, 'id'));
                $count += count($rows);
            } finally {
                $lock->release();
            }
        } while (microtime(true) < $deadline);
        return $count;
    }
}

<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;

/** Durable ID-only journal. Acknowledged rows remain available to in-flight rebuilds. */
final class ChangeJournal
{
    public const TABLE = 'iwac_search_change';

    /**
     * Most pending IDs hidden from public search at query time (see
     * pendingIds()): about 2 KB of filter, which keeps a scoped key well
     * inside a request header and a snapshot's filter_by short.
     */
    public const HIDDEN_LIMIT = 250;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Create the journal and the module's other tables (see IndexingSchema),
     * which the dashboard probe below requires together.
     */
    public static function install(Connection $connection): void
    {
        IndexingSchema::install($connection);
    }

    /** Read-only readiness probe; never create tables during a page or catalog write. */
    public function assertInstalled(): void
    {
        IndexingSchema::assertInstalled($this->connection);
    }

    /** @param list<int> $ids */
    public function append(array $ids): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), 200) as $batch) {
            if ($batch === []) {
                continue;
            }
            $this->connection->executeStatement(
                'INSERT INTO iwac_search_change (item_id) VALUES ' . implode(',', array_fill(0, count($batch), '(?)')),
                $batch
            );
        }
    }

    public function watermark(): int
    {
        return (int) $this->connection->executeQuery('SELECT COALESCE(MAX(id), 0) FROM iwac_search_change')->fetchOne();
    }

    /**
     * Invalidate SSR on writes, successful replay and completed bulk
     * promotion — one round trip, since it runs on every cached SSR render.
     */
    public function cacheVersion(): string
    {
        $row = $this->connection->executeQuery(
            'SELECT (SELECT COALESCE(MAX(id), 0) FROM iwac_search_change) AS recorded,'
            . ' (SELECT COALESCE(MAX(id), 0) FROM iwac_search_change WHERE processed = 1) AS applied,'
            . " (SELECT GROUP_CONCAT(collection_name ORDER BY collection_name SEPARATOR ',') FROM iwac_search_generation WHERE status = 'live') AS generations"
        )->fetchAllAssociative()[0];
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** @return list<int> */
    public function changedSince(int $watermark): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT item_id FROM iwac_search_change WHERE id > ? ORDER BY item_id',
            [$watermark]
        ));
    }

    /** @return list<array{id:int, item_id:int}> */
    public function pending(int $limit = 200): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT id, item_id FROM iwac_search_change WHERE processed = 0 ORDER BY id LIMIT ' . max(1, min(200, $limit))
        )->fetchAllAssociative();
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'item_id' => (int) $row['item_id']], $rows);
    }

    /**
     * Acknowledge exactly the rows read, never a concurrently appended update.
     * @param list<int> $ids
     */
    public function acknowledge(array $ids): void
    {
        if ($ids !== []) {
            $this->connection->executeStatement(
                'UPDATE iwac_search_change SET processed = 1 WHERE id IN (?)',
                [$ids],
                [Connection::PARAM_INT_ARRAY]
            );
        }
    }

    /**
     * The resources whose journaled change has not reached Typesense yet,
     * newest change first, at most $limit of them; `complete` says whether
     * that is all of them.
     *
     * Public search hides these until the drain applies them — the SSR and
     * freshly minted scoped keys both exclude them — so a withdrawal or
     * privatisation is not served from a stale document in the meantime, and
     * an edit briefly drops the item from results rather than showing what
     * it no longer says. The drain normally empties the journal within
     * seconds; a backlog beyond $limit (a large batch edit, a Typesense
     * outage) is where the bound applies.
     *
     * @return array{ids: list<int>, complete: bool}
     */
    public function pendingIds(int $limit = self::HIDDEN_LIMIT): array
    {
        $limit = max(1, $limit);
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT item_id FROM ' . self::TABLE . ' WHERE processed = 0'
            . ' GROUP BY item_id ORDER BY MAX(id) DESC LIMIT ' . ($limit + 1)
        ));
        return ['ids' => array_slice($ids, 0, $limit), 'complete' => count($ids) <= $limit];
    }

    /** Whether any row awaits the drain — one index probe, for the worker's exit check. */
    public function hasPending(): bool
    {
        return $this->connection->executeQuery('SELECT 1 FROM ' . self::TABLE . ' WHERE processed = 0 LIMIT 1')->fetchOne() !== false;
    }

    /** @return array{pending:int, oldest:?string} */
    public function status(): array
    {
        $row = $this->connection->executeQuery('SELECT COUNT(*) AS pending, MIN(created) AS oldest FROM iwac_search_change WHERE processed = 0')->fetchAllAssociative()[0];
        return ['pending' => (int) $row['pending'], 'oldest' => $row['oldest']];
    }

    /** Only call while holding the rebuild lock: no active rebuild may need these rows. */
    public function prune(): void
    {
        $this->connection->executeStatement('DELETE FROM iwac_search_change WHERE processed = 1 AND created < DATE_SUB(NOW(), INTERVAL 7 DAY)');
    }
}

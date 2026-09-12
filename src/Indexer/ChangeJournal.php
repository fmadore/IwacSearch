<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;

/** Durable ID-only journal. Acknowledged rows remain available to in-flight rebuilds. */
final class ChangeJournal
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function install(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS iwac_search_change ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'item_id INT NOT NULL, processed TINYINT NOT NULL DEFAULT 0,'
            . 'created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'KEY pending (processed, id), KEY resource (item_id)'
            . ') ENGINE=InnoDB');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS iwac_search_rollback ('
            . 'alias_name VARCHAR(190) NOT NULL PRIMARY KEY, collection_name VARCHAR(190) NULL) ENGINE=InnoDB');
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

    /** Invalidate SSR on writes, successful replay and completed bulk promotion. */
    public function cacheVersion(): string
    {
        $applied = $this->connection->executeQuery('SELECT COALESCE(MAX(id), 0) FROM iwac_search_change WHERE processed = 1')->fetchOne();
        $generations = $this->connection->fetchFirstColumn('SELECT collection_name FROM iwac_search_rollback ORDER BY alias_name');
        return hash('sha256', json_encode([$this->watermark(), $applied, $generations], JSON_THROW_ON_ERROR));
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

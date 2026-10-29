<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;

/**
 * {@see GenerationLedger} in the `iwac_search_generation` table, which
 * {@see IndexingSchema::install()} creates. Timestamps are the database's
 * own clock, like the change journal's.
 */
final class DatabaseGenerationLedger implements GenerationLedger
{
    public const TABLE = 'iwac_search_generation';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function recordBuilding(string $collection, string $alias): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (collection_name, alias_name, status) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE alias_name = ?, status = ?, promoted = NULL, retired = NULL',
            [$collection, $alias, self::BUILDING, $alias, self::BUILDING]
        );
    }

    /**
     * Two statements, each atomic, ordered so that a failure between them is
     * harmless: the new rows are written first, then the others are retired
     * — never the live generation or its rollback target, which are excluded
     * by name. (No transaction: Omeka pins DBAL 2.13, and nothing here needs
     * one.) The outgoing target may predate the ledger (an install upgraded
     * from 3.26), hence the upsert.
     */
    public function recordPromotion(string $alias, string $live, ?string $previous): void
    {
        $previous = $previous === '' || $previous === $live ? null : $previous;
        $rows = '(?, ?, ?, NOW())';
        $params = [$live, $alias, self::LIVE];
        if ($previous !== null) {
            $rows .= ', (?, ?, ?, NULL)';
            array_push($params, $previous, $alias, self::PREVIOUS);
        }
        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . ' (collection_name, alias_name, status, promoted) VALUES ' . $rows
            . ' ON DUPLICATE KEY UPDATE alias_name = VALUES(alias_name), status = VALUES(status),'
            . ' promoted = COALESCE(VALUES(promoted), promoted), retired = NULL',
            $params
        );
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, retired = NOW()'
            . ' WHERE alias_name = ? AND status IN (?, ?) AND collection_name <> ? AND collection_name <> ?',
            [self::RETIRED, $alias, self::LIVE, self::PREVIOUS, $live, $previous ?? $live]
        );
    }

    public function mark(string $collection, string $status): void
    {
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, retired = IF(? = ?, NOW(), NULL) WHERE collection_name = ?',
            [$status, $status, self::RETIRED, $collection]
        );
    }

    public function forget(string $collection): void
    {
        $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE collection_name = ?', [$collection]);
    }

    public function generations(string ...$statuses): array
    {
        $sql = 'SELECT collection_name, alias_name, status FROM ' . self::TABLE;
        $rows = ($statuses === []
            ? $this->connection->executeQuery($sql . ' ORDER BY collection_name')
            : $this->connection->executeQuery(
                $sql . ' WHERE status IN (?) ORDER BY collection_name',
                [array_values($statuses)],
                [Connection::PARAM_STR_ARRAY]
            ))->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['collection_name']] = ['alias' => (string) $row['alias_name'], 'status' => (string) $row['status']];
        }
        return $out;
    }
}

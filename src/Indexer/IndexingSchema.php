<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The module's own database tables, in one place for install, upgrade, the
 * `cli/repair-schema.php` recovery and uninstall:
 *
 *   - `iwac_search_change`     the durable ID journal ({@see ChangeJournal})
 *   - `iwac_search_generation` the generation ledger ({@see DatabaseGenerationLedger})
 *   - `iwac_search_worker`     the drain worker's lease row ({@see WorkerLease})
 *
 * Plain DBAL on the connection it is given, nothing else: Omeka runs
 * Module::upgrade() while the module is still inactive, so none of the
 * module's services (the Typesense client included) can be resolved there.
 */
final class IndexingSchema
{
    /** 3.19–3.26 recorded only the outgoing alias targets; the ledger supersedes it. */
    private const LEGACY_ROLLBACK_TABLE = 'iwac_search_rollback';

    /** Idempotent: creates what is missing and preserves every existing row. */
    public static function install(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . ChangeJournal::TABLE . ' ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'item_id INT NOT NULL, processed TINYINT NOT NULL DEFAULT 0,'
            . 'created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'KEY pending (processed, id), KEY resource (item_id)'
            . ') ENGINE=InnoDB');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . DatabaseGenerationLedger::TABLE . ' ('
            . 'collection_name VARCHAR(190) NOT NULL PRIMARY KEY,'
            . 'alias_name VARCHAR(190) NOT NULL, status VARCHAR(16) NOT NULL,'
            . 'created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'promoted DATETIME NULL, retired DATETIME NULL,'
            . 'KEY alias_status (alias_name, status)'
            . ') ENGINE=InnoDB');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS ' . WorkerLease::TABLE . ' ('
            . 'name VARCHAR(32) NOT NULL PRIMARY KEY,'
            . 'requested TINYINT NOT NULL DEFAULT 0, heartbeat DATETIME NULL,'
            . 'updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB');
        $connection->executeStatement(
            'INSERT IGNORE INTO ' . WorkerLease::TABLE . ' (name, requested) VALUES (?, 0)',
            [WorkerLease::NAME]
        );
        self::migrateRollbackRecord($connection);
    }

    /** Read-only readiness probe; never create tables during a page or catalog write. */
    public static function assertInstalled(Connection $connection): void
    {
        $connection->executeQuery('SELECT id FROM ' . ChangeJournal::TABLE . ' LIMIT 0');
        $connection->executeQuery('SELECT collection_name FROM ' . DatabaseGenerationLedger::TABLE . ' LIMIT 0');
        $connection->executeQuery('SELECT name FROM ' . WorkerLease::TABLE . ' LIMIT 0');
    }

    /**
     * Drop the module's tables. Typesense collections are never touched (the
     * server may be shared), so the ledger's names are logged first: they are
     * the only record of what this install created.
     */
    public static function uninstall(Connection $connection, ?LoggerInterface $logger = null): void
    {
        try {
            $owned = $connection->fetchFirstColumn('SELECT collection_name FROM ' . DatabaseGenerationLedger::TABLE . ' ORDER BY collection_name');
            if ($owned !== [] && $logger !== null) {
                $logger->notice('IwacSearch uninstalled. The Typesense collections it created were left in place: ' . implode(', ', $owned));
            }
        } catch (Throwable) {
            // No ledger (never installed, or already dropped): nothing to report.
        }
        $connection->executeStatement('DROP TABLE IF EXISTS ' . ChangeJournal::TABLE);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . DatabaseGenerationLedger::TABLE);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . WorkerLease::TABLE);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::LEGACY_ROLLBACK_TABLE);
    }

    /**
     * Carry the 3.19–3.26 rollback record into the ledger as `previous` rows,
     * then drop it. Those are the collections the last rebuild swapped away
     * from; recording them keeps them protected until the next promotion
     * retires them like any other superseded generation.
     */
    private static function migrateRollbackRecord(Connection $connection): void
    {
        $exists = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::LEGACY_ROLLBACK_TABLE]
        )->fetchOne();
        if ($exists === 0) {
            return;
        }
        $connection->executeStatement(
            'INSERT IGNORE INTO ' . DatabaseGenerationLedger::TABLE . ' (collection_name, alias_name, status)'
            . ' SELECT collection_name, alias_name, ? FROM ' . self::LEGACY_ROLLBACK_TABLE
            . " WHERE collection_name IS NOT NULL AND collection_name <> ''",
            [GenerationLedger::PREVIOUS]
        );
        $connection->executeStatement('DROP TABLE ' . self::LEGACY_ROLLBACK_TABLE);
    }
}

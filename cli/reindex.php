<?php
declare(strict_types=1);

/**
 * Bulk reindex CLI — reads the Omeka S MySQL database directly and rebuilds
 * both Typesense collections (content + entity index).
 *
 * Usage from inside the Omeka php container:
 *   docker compose exec php php /var/www/html/modules/IwacSearch/cli/reindex.php
 *
 * Or via omeka-s-cli:
 *   docker compose -f services/omeka-cli/docker-compose.yml run --rm \
 *       omeka-cli discovery:reindex
 *
 * Env overrides: see cli/bootstrap.php (IWAC_TYPESENSE_*, IWAC_OMEKA_VENDOR),
 * plus:
 *   IWAC_OMEKA_DB_INI   default: <omeka root>/config/database.ini
 *
 * Exit codes:
 *   0  success
 *   1  reindex failed (inspect job output; previous generations are retained)
 *   2  setup error (missing composer deps, unreadable admin-key secret,
 *      missing database.ini) — bootstrap.php enforces the first two
 */

use IwacSearch\Indexer\ReindexOrchestrator;

['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot,
 'tsConfig' => $tsConfig, 'omekaVendor' => $omekaVendor] = require __DIR__ . '/bootstrap.php';

try {
    $connection = require __DIR__ . '/database.php';

    // ── Run — all indexer wiring lives in ReindexOrchestrator, shared with
    // the admin Job\BulkReindex path so the two can't drift.
    $logger->info('Starting reindex', [
        'typesense_host' => $tsConfig['host'],
        'db'             => $connection->getDatabase(),
    ]);
    $stats = (new ReindexOrchestrator($typesense, $connection, $moduleRoot, $logger))->run();
    $logger->info('Reindex complete', $stats);

    fwrite(STDOUT, json_encode($stats, JSON_PRETTY_PRINT) . "\n");
    exit(0);
} catch (Throwable $e) {
    $logger->log('error', $e->getMessage(), [
        'class' => $e::class,
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    exit(1);
}

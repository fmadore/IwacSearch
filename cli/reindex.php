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
 *   1  reindex failed (collection dropped, alias unchanged — safe state)
 *   2  setup error (missing composer deps, unreadable admin-key secret,
 *      missing database.ini) — bootstrap.php enforces the first two
 */

use Doctrine\DBAL\DriverManager;
use IwacSearch\Indexer\ReindexOrchestrator;

['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot,
 'tsConfig' => $tsConfig, 'omekaVendor' => $omekaVendor] = require __DIR__ . '/bootstrap.php';

// ── Omeka DB connection (DBAL) from database.ini ──────────────────────────
// The CLI runs outside Omeka's HTTP bootstrap, so we build the DBAL
// connection straight from Omeka's own config/database.ini rather than the
// service container (the BulkReindex job, which has the container, pulls
// 'Omeka\Connection' instead).
$omekaRoot = dirname($omekaVendor, 2); // …/vendor/autoload.php → …
$dbIni = getenv('IWAC_OMEKA_DB_INI') ?: $omekaRoot . '/config/database.ini';
if (!is_readable($dbIni)) {
    fwrite(STDERR, "ERROR: Omeka database.ini not readable at {$dbIni}. Set IWAC_OMEKA_DB_INI.\n");
    exit(2);
}

try {
    $ini = parse_ini_file($dbIni) ?: [];
    $params = [
        'driver'   => 'pdo_mysql',
        'charset'  => 'utf8mb4',
        'dbname'   => (string) ($ini['dbname'] ?? ''),
        'user'     => (string) ($ini['user'] ?? ''),
        'password' => (string) ($ini['password'] ?? ''),
    ];
    if (!empty($ini['unix_socket'])) {
        $params['unix_socket'] = (string) $ini['unix_socket'];
    } else {
        $params['host'] = (string) ($ini['host'] ?? 'localhost');
        if (!empty($ini['port'])) {
            $params['port'] = (int) $ini['port'];
        }
    }
    $connection = DriverManager::getConnection($params);

    // ── Run — all indexer wiring lives in ReindexOrchestrator, shared with
    // the admin Job\BulkReindex path so the two can't drift.
    $logger->info('Starting reindex', [
        'typesense_host' => $tsConfig['host'],
        'db'             => $params['dbname'],
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

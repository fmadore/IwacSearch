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
 * Env overrides (all optional — defaults match the IWAC-docker stack):
 *   IWAC_TYPESENSE_HOST       default: typesense
 *   IWAC_TYPESENSE_PORT       default: 8108
 *   IWAC_TYPESENSE_PROTOCOL   default: http
 *   IWAC_TYPESENSE_KEY_FILE   default: /run/secrets/typesense_api_key
 *   IWAC_OMEKA_VENDOR         default: /var/www/html/vendor/autoload.php
 *   IWAC_OMEKA_DB_INI         default: <omeka root>/config/database.ini
 *
 * Exit codes:
 *   0  success
 *   1  reindex failed (collection dropped, alias unchanged — safe state)
 *   2  setup error (missing secret, schema, composer deps, DB config)
 */

use Doctrine\DBAL\DriverManager;
use IwacSearch\Indexer\ReindexOrchestrator;
use IwacSearch\Service\TypesenseClientFactory;

// ── Bootstrap autoloader ──────────────────────────────────────────────────
$moduleRoot = dirname(__DIR__);
$autoload   = $moduleRoot . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install --no-dev' inside {$moduleRoot}.\n");
    exit(2);
}

// Omeka core vendor first — provides Laminas + PSR interfaces AND Doctrine
// DBAL (which the source reader uses). NOT bundled in the module's own
// composer.json on purpose (would collide with Omeka's versions at runtime).
// Override the path with IWAC_OMEKA_VENDOR for non-Docker dev.
$omekaVendor = getenv('IWAC_OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
if (is_readable($omekaVendor)) {
    require_once $omekaVendor;
}

require $autoload;

// ── Minimal stderr logger (PSR-3) ─────────────────────────────────────────
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        fprintf(STDERR, "[%s] %-7s %s%s\n", date('H:i:s'), strtoupper((string) $level), $message, $ctx);
    }
};

try {
    // ── Config ───────────────────────────────────────────────────────────────
    $tsConfig = [
        'host'         => getenv('IWAC_TYPESENSE_HOST')     ?: 'typesense',
        'port'         => (int) (getenv('IWAC_TYPESENSE_PORT') ?: 8108),
        'protocol'     => getenv('IWAC_TYPESENSE_PROTOCOL') ?: 'http',
        'api_key_file' => getenv('IWAC_TYPESENSE_KEY_FILE') ?: '/run/secrets/typesense_api_key',
    ];

    // ── Typesense client (factory needs a minimal Config-providing container)
    $container = new class(['iwac_search' => ['typesense' => $tsConfig]]) implements \Psr\Container\ContainerInterface {
        public function __construct(private readonly array $config) {}
        public function get(string $id): mixed
        {
            if ($id === 'Config') { return $this->config; }
            throw new RuntimeException("CLI container has no service: {$id}");
        }
        public function has(string $id): bool { return $id === 'Config'; }
    };
    $typesense = (new TypesenseClientFactory())($container, '');

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

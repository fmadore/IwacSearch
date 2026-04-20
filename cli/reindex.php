<?php
declare(strict_types=1);

/**
 * Bulk reindex CLI.
 *
 * Usage from inside the Omeka php container:
 *   docker compose exec php php /var/www/html/modules/IwacSearch/cli/reindex.php
 *
 * Or via omeka-s-cli (M0+ wrapping):
 *   docker compose -f services/omeka-cli/docker-compose.yml run --rm \
 *       omeka-cli discovery:reindex
 *
 * Exit codes:
 *   0  success
 *   1  reindex failed (collection dropped, alias unchanged — safe state)
 *   2  setup error (missing secret, schema, or composer deps)
 *
 * The script intentionally does NOT bootstrap Omeka itself — it talks
 * directly to HF and Typesense. This keeps the reindex independent of
 * Omeka being healthy and lets the same code run from a separate cron
 * sidecar later if desired.
 */

use IwacSearch\Indexer\HfDatasetLoader;
use IwacSearch\Indexer\Reindexer;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Service\TypesenseClientFactory;

// ── Bootstrap autoloader ──────────────────────────────────────────────────
$moduleRoot = dirname(__DIR__);
$autoload   = $moduleRoot . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install --no-dev' inside {$moduleRoot}.\n");
    exit(2);
}
require $autoload;

// ── Minimal stderr logger ─────────────────────────────────────────────────
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        fprintf(STDERR, "[%s] %-7s %s%s\n", date('H:i:s'), strtoupper((string) $level), $message, $ctx);
    }
};

// ── Wire dependencies (the controller normally goes through a service
// container; the CLI does it manually since it doesn't load Omeka) ────────
try {
    // Mock the container interface enough for TypesenseClientFactory to work.
    $config = [
        'iwac_search' => [
            'typesense' => [
                'host'         => getenv('IWAC_TYPESENSE_HOST') ?: 'typesense',
                'port'         => (int) (getenv('IWAC_TYPESENSE_PORT') ?: 8108),
                'protocol'     => getenv('IWAC_TYPESENSE_PROTOCOL') ?: 'http',
                'api_key_file' => getenv('IWAC_TYPESENSE_KEY_FILE') ?: '/run/secrets/typesense_api_key',
            ],
        ],
    ];

    $container = new class($config) implements \Psr\Container\ContainerInterface {
        public function __construct(private readonly array $config) {}
        public function get(string $id): mixed
        {
            if ($id === 'Config') { return $this->config; }
            throw new RuntimeException("CLI container has no service: {$id}");
        }
        public function has(string $id): bool { return $id === 'Config'; }
    };

    $typesense = (new TypesenseClientFactory())($container, '');
    $schema    = new SchemaLoader($moduleRoot . '/data/schema.yaml');
    $hf        = new HfDatasetLoader();
    $reindexer = new Reindexer($typesense, $schema, $hf, $logger);

    $logger->info('Starting reindex');
    $stats = $reindexer->run();
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

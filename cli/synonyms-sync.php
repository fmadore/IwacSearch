<?php
declare(strict_types=1);

/**
 * Synonyms-only sync CLI.
 *
 * Provisions (or refreshes) the `iwac_synonyms` global Typesense synonym
 * set from `data/synonyms-fr.json` without rebuilding the document
 * collection. Synonym expansion happens at SEARCH time, so an edited
 * group is live the moment this finishes — no reindex needed.
 *
 * The bulk reindex CLI (`cli/reindex.php`) also calls SynonymsSync as an
 * early step — running this script is equivalent to that step in
 * isolation. PUT /synonym_sets/{name} is idempotent, so running it
 * repeatedly is safe.
 *
 * Usage from inside the Omeka php container:
 *   docker compose exec php php /var/www/html/modules/IwacSearch/cli/synonyms-sync.php
 *
 * Env overrides (all optional — defaults match the IWAC-docker stack):
 *   IWAC_TYPESENSE_HOST       default: typesense
 *   IWAC_TYPESENSE_PORT       default: 8108
 *   IWAC_TYPESENSE_PROTOCOL   default: http
 *   IWAC_TYPESENSE_KEY_FILE   default: /run/secrets/typesense_api_key
 *
 * Exit codes:
 *   0  success
 *   1  sync failed
 *   2  setup error (missing secret, malformed synonyms file, composer)
 */

use IwacSearch\Indexer\SynonymsSync;
use IwacSearch\Service\TypesenseClientFactory;

$moduleRoot = dirname(__DIR__);
$autoload   = $moduleRoot . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install --no-dev' inside {$moduleRoot}.\n");
    exit(2);
}

// Omeka core vendor provides Laminas + PSR interfaces this CLI uses
// (FactoryInterface, ContainerInterface). They are NOT bundled in the
// module's own composer.json on purpose — bundling them collides with
// Omeka's loaded versions at runtime. Path is the in-container Omeka
// install; override with IWAC_OMEKA_VENDOR for non-Docker dev.
$omekaVendor = getenv('IWAC_OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
if (is_readable($omekaVendor)) {
    require_once $omekaVendor;
}

require $autoload;

$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        fprintf(STDERR, "[%s] %-7s %s%s\n", date('H:i:s'), strtoupper((string) $level), $message, $ctx);
    }
};

try {
    $tsConfig = [
        'host'         => getenv('IWAC_TYPESENSE_HOST')     ?: 'typesense',
        'port'         => (int) (getenv('IWAC_TYPESENSE_PORT') ?: 8108),
        'protocol'     => getenv('IWAC_TYPESENSE_PROTOCOL') ?: 'http',
        'api_key_file' => getenv('IWAC_TYPESENSE_KEY_FILE') ?: '/run/secrets/typesense_api_key',
    ];

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

    $sync = new SynonymsSync(
        $typesense,
        $moduleRoot . '/data/synonyms-fr.json',
        $logger
    );

    $logger->info('Syncing synonym set', ['typesense_host' => $tsConfig['host']]);
    $stats = $sync->sync();
    $logger->info('Synonyms synced', $stats);

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

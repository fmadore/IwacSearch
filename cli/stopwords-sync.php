<?php
declare(strict_types=1);

/**
 * Stopwords-only sync CLI.
 *
 * Provisions (or refreshes) the `fr_default` Typesense stopword set from
 * `data/stopwords-fr.json` without rebuilding the document collection.
 * Use this when:
 *   - The set is missing on a fresh Typesense volume (search 404s with
 *     "Could not find the stopword set named `fr_default`").
 *   - You've edited `data/stopwords-fr.json` and want the change live
 *     without paying the cost of a full bulk reindex (14K+ docs).
 *
 * The bulk reindex CLI (`cli/reindex.php`) also calls StopwordsSync as
 * its first step — running this script is equivalent to that step in
 * isolation. PUT /stopwords/{name} is idempotent, so running it
 * repeatedly is safe.
 *
 * Usage from inside the Omeka php container:
 *   docker compose exec php php /var/www/html/modules/IwacSearch/cli/stopwords-sync.php
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
 *   2  setup error (missing secret, malformed stopwords file, composer)
 */

use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Service\TypesenseClientFactory;

$moduleRoot = dirname(__DIR__);
$autoload   = $moduleRoot . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install --no-dev' inside {$moduleRoot}.\n");
    exit(2);
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

    $sync = new StopwordsSync(
        $typesense,
        $moduleRoot . '/data/stopwords-fr.json',
        $logger
    );

    $logger->info('Syncing French stopwords', ['typesense_host' => $tsConfig['host']]);
    $stats = $sync->sync();
    $logger->info('Stopwords synced', $stats);

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

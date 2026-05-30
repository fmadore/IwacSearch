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
 * Env overrides (all optional — defaults match the IWAC-docker stack):
 *   IWAC_TYPESENSE_HOST       default: typesense
 *   IWAC_TYPESENSE_PORT       default: 8108
 *   IWAC_TYPESENSE_PROTOCOL   default: http
 *   IWAC_TYPESENSE_KEY_FILE   default: /run/secrets/typesense_api_key
 *   IWAC_OMEKA_API_URL        default: https://islam.zmo.de/api
 *
 * Exit codes:
 *   0  success
 *   1  reindex failed (collection dropped, alias unchanged — safe state)
 *   2  setup error (missing secret, schema, composer deps)
 */

use IwacSearch\Indexer\AuthorityResolver;
use IwacSearch\Indexer\HfDatasetLoader;
use IwacSearch\Indexer\IndexReindexer;
use IwacSearch\Indexer\Mapper\ArticleMapper;
use IwacSearch\Indexer\Mapper\AudiovisualMapper;
use IwacSearch\Indexer\Mapper\DocumentMapper;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Indexer\Mapper\PublicationMapper;
use IwacSearch\Indexer\Mapper\ReferenceMapper;
use IwacSearch\Indexer\OmekaAclLoader;
use IwacSearch\Indexer\Reindexer;
use IwacSearch\Indexer\SchemaLoader;
use IwacSearch\Indexer\StopwordsSync;
use IwacSearch\Service\TypesenseClientFactory;

// ── Bootstrap autoloader ──────────────────────────────────────────────────
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

// ── Minimal stderr logger (PSR-3) ─────────────────────────────────────────
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        fprintf(STDERR, "[%s] %-7s %s%s\n", date('H:i:s'), strtoupper((string) $level), $message, $ctx);
    }
};

try {
    // ── Config ─────────────────────────────────────────────────────────────
    $tsConfig = [
        'host'         => getenv('IWAC_TYPESENSE_HOST')     ?: 'typesense',
        'port'         => (int) (getenv('IWAC_TYPESENSE_PORT') ?: 8108),
        'protocol'     => getenv('IWAC_TYPESENSE_PROTOCOL') ?: 'http',
        'api_key_file' => getenv('IWAC_TYPESENSE_KEY_FILE') ?: '/run/secrets/typesense_api_key',
    ];
    $omekaApiUrl = getenv('IWAC_OMEKA_API_URL') ?: 'https://islam.zmo.de/api';

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

    // ── Indexer dependencies ───────────────────────────────────────────────
    // Authority resolver is a singleton injected into every mapper. Built
    // empty here; the Reindexer builds it from the `index` subset before
    // any mapping happens.
    $authority = new AuthorityResolver();

    $registry = new MapperRegistry([
        new ArticleMapper($authority),
        new PublicationMapper($authority),
        new DocumentMapper($authority),
        new AudiovisualMapper($authority),
        new ReferenceMapper($authority),
    ]);

    // Shared across both reindexers so the public-item set is fetched once.
    $hfLoader  = new HfDatasetLoader();
    $aclLoader = new OmekaAclLoader($omekaApiUrl, $logger);

    $reindexer = new Reindexer(
        typesense:     $typesense,
        schemaLoader:  new SchemaLoader($moduleRoot . '/data/schema.yaml'),
        hfLoader:      $hfLoader,
        mappers:       $registry,
        // Same instance held by every mapper in the registry. Reindexer
        // populates it via build() in run(); mappers see the data through
        // the shared reference. The only mutable singleton in the
        // indexer; everything else is immutable after construction.
        authority:     $authority,
        aclLoader:     $aclLoader,
        stopwordsSync: new StopwordsSync($typesense, $moduleRoot . '/data/stopwords-fr.json', $logger),
        logger:        $logger
    );

    // Entity (index) collection — built on the same run, reusing the primed
    // ACL loader. Independent alias swap: a failure here cannot affect the
    // content alias already swapped by the content reindex above.
    $indexReindexer = new IndexReindexer(
        typesense:    $typesense,
        schemaLoader: new SchemaLoader($moduleRoot . '/data/schema-index.yaml'),
        hfLoader:     $hfLoader,
        mapper:       new IndexEntityMapper(),
        aclLoader:    $aclLoader,
        logger:       $logger
    );

    $logger->info('Starting reindex', [
        'typesense_host' => $tsConfig['host'],
        'omeka_api'      => $omekaApiUrl,
    ]);
    $stats = $reindexer->run();
    $stats['index'] = $indexReindexer->run();
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

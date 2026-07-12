<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the cli/ scripts (reindex, stopwords-sync,
 * synonyms-sync). Was copy-pasted ~50 lines per script; now each script
 * `require`s this and receives everything common:
 *
 *   [
 *     'logger'      => PSR-3 stderr logger,
 *     'typesense'   => configured Typesense\Client (admin key),
 *     'moduleRoot'  => absolute module directory,
 *     'tsConfig'    => resolved typesense env config,
 *     'omekaVendor' => path of the Omeka core autoloader (for database.ini
 *                      discovery in reindex.php),
 *   ]
 *
 * Env overrides (all optional — defaults match the IWAC-docker stack):
 *   IWAC_TYPESENSE_HOST       default: typesense
 *   IWAC_TYPESENSE_PORT       default: 8108
 *   IWAC_TYPESENSE_PROTOCOL   default: http
 *   IWAC_TYPESENSE_KEY_FILE   default: /run/secrets/typesense_api_key
 *   IWAC_OMEKA_VENDOR         default: /var/www/html/vendor/autoload.php
 *
 * Exits 2 (setup error) itself when the module autoloader is missing or the
 * Typesense client can't be built (unreadable admin-key secret, bad config)
 * — so the documented "2 = setup error" contract holds for every script.
 *
 * @return array{logger: \Psr\Log\LoggerInterface, typesense: \Typesense\Client, moduleRoot: string, tsConfig: array<string,mixed>, omekaVendor: string}
 */

$moduleRoot = dirname(__DIR__);
$autoload   = $moduleRoot . '/vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found. Run 'composer install --no-dev' inside {$moduleRoot}.\n");
    exit(2);
}

// Omeka core vendor first — provides Laminas + PSR interfaces (and Doctrine
// DBAL for the reindex script). NOT bundled in the module's own composer.json
// on purpose (would collide with Omeka's versions at runtime). Override the
// path with IWAC_OMEKA_VENDOR for non-Docker dev.
$omekaVendor = getenv('IWAC_OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
if (is_readable($omekaVendor)) {
    require_once $omekaVendor;
}

require $autoload;

// Minimal stderr logger (PSR-3).
$logger = new class extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        $ctx = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        fprintf(STDERR, "[%s] %-7s %s%s\n", date('H:i:s'), strtoupper((string) $level), $message, $ctx);
    }
};

$tsConfig = [
    'host'         => getenv('IWAC_TYPESENSE_HOST')     ?: 'typesense',
    'port'         => (int) (getenv('IWAC_TYPESENSE_PORT') ?: 8108),
    'protocol'     => getenv('IWAC_TYPESENSE_PROTOCOL') ?: 'http',
    'api_key_file' => getenv('IWAC_TYPESENSE_KEY_FILE') ?: '/run/secrets/typesense_api_key',
];

// Typesense client — the factory wants a minimal Config-providing container.
$container = new class(['iwac_search' => ['typesense' => $tsConfig]]) implements \Psr\Container\ContainerInterface {
    public function __construct(private readonly array $config)
    {
    }

    public function get(string $id): mixed
    {
        if ($id === 'Config') {
            return $this->config;
        }
        throw new RuntimeException("CLI container has no service: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === 'Config';
    }
};

try {
    $typesense = (new \IwacSearch\Service\TypesenseClientFactory())($container, '');
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: cannot build the Typesense client (setup): ' . $e->getMessage() . "\n");
    exit(2);
}

return [
    'logger'      => $logger,
    'typesense'   => $typesense,
    'moduleRoot'  => $moduleRoot,
    'tsConfig'    => $tsConfig,
    'omekaVendor' => $omekaVendor,
];

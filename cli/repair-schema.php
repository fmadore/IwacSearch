<?php
declare(strict_types=1);

/** Explicit, idempotent recovery for a deployment that missed the module upgrade. */
$moduleRoot = dirname(__DIR__);
$omekaVendor = getenv('IWAC_OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
try {
    if (!is_readable($omekaVendor) || !is_readable($moduleRoot . '/vendor/autoload.php')) {
        throw new RuntimeException('Omeka and module vendor/autoload.php must be installed. Set IWAC_OMEKA_VENDOR if needed.');
    }
    require_once $omekaVendor;
    require_once $moduleRoot . '/vendor/autoload.php';
    $connection = require __DIR__ . '/database.php';
    \IwacSearch\Indexer\ChangeJournal::install($connection);
    (new \IwacSearch\Indexer\ChangeJournal($connection))->assertInstalled();
    fwrite(STDOUT, "IWAC Search tables are ready. Existing rows were preserved. Complete any pending Omeka module upgrade, then run a full reindex.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

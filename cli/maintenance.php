<?php

declare(strict_types=1);
/** Run with status (default), drain, or prune. Schedule drain every minute. */
['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot, 'omekaVendor' => $omekaVendor] = require __DIR__ . '/bootstrap.php';
try {
    $connection = require __DIR__ . '/database.php';
    $journal = new \IwacSearch\Indexer\ChangeJournal($connection);
    $action = $argv[1] ?? 'status';
    if ($action === 'drain') {
        $authority = new \IwacSearch\Indexer\EntityAuthority();
        $registry = \IwacSearch\Indexer\Mapper\MapperRegistry::default($authority, new \IwacSearch\Indexer\CountryResolver($moduleRoot . '/data/newspaper-countries.json'));
        $indexer = new \IwacSearch\Indexer\IncrementalIndexer(new \IwacSearch\Indexer\CollectionOps(fn () => $typesense, $logger), new \IwacSearch\Indexer\OmekaSourceReader($connection), $registry, $authority);
        (new \IwacSearch\Indexer\ChangeDrainer($connection, $indexer))->run();
    } elseif ($action === 'prune') {
        $removed = (new \IwacSearch\Indexer\CollectionRetention($connection, $typesense))->prune();
        $logger->info('Removed old generations', ['collections' => $removed]);
    } elseif ($action !== 'status') {
        throw new \InvalidArgumentException('Usage: php cli/maintenance.php [status|drain|prune]');
    }
    echo json_encode($journal->status(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}

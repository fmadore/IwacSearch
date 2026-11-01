<?php

declare(strict_types=1);

/**
 * Run with status (default), drain, or prune. Schedule drain every minute:
 * it is the backstop for a drain worker that died or backed off after a
 * failure. It works only when it can claim the worker lease, so it never
 * runs beside a live worker.
 */

['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot, 'omekaVendor' => $omekaVendor] = require __DIR__ . '/bootstrap.php';
try {
    $connection = require __DIR__ . '/database.php';
    $journal = new \IwacSearch\Indexer\ChangeJournal($connection);
    $action = $argv[1] ?? 'status';
    if ($action === 'drain') {
        $indexer = \IwacSearch\Indexer\IncrementalIndexer::create(
            new \IwacSearch\Indexer\CollectionOps(fn () => $typesense, $logger, 'incremental'),
            $connection,
            $moduleRoot,
            logger: $logger,
        );
        $lease = new \IwacSearch\Indexer\WorkerLease($connection);
        if ($lease->claim()) {
            $applied = (new \IwacSearch\Indexer\ChangeDrainer($connection, $indexer))->work($lease);
            $logger->info('Drained the change journal', ['rows' => $applied]);
        } else {
            $logger->info('A drain worker holds the lease; it processes the journal');
        }
    } elseif ($action === 'prune') {
        $removed = (new \IwacSearch\Indexer\CollectionRetention($connection, $typesense, $logger))->prune();
        $logger->info('Removed old generations', ['collections' => $removed]);
    } elseif ($action !== 'status') {
        throw new \InvalidArgumentException('Usage: php cli/maintenance.php [status|drain|prune]');
    }
    echo json_encode($journal->status(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    $logger->error($e->getMessage());
    exit(1);
}

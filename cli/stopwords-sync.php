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
 * Env overrides: see cli/bootstrap.php (IWAC_TYPESENSE_*, IWAC_OMEKA_VENDOR).
 *
 * Exit codes:
 *   0  success
 *   1  sync failed (Typesense unreachable, malformed stopwords file, …)
 *   2  setup error (missing composer deps, unreadable admin-key secret)
 *      — enforced by bootstrap.php
 */

use IwacSearch\Indexer\StopwordsSync;

['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot,
 'tsConfig' => $tsConfig] = require __DIR__ . '/bootstrap.php';

try {
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

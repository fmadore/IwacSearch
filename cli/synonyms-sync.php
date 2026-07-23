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
 * Env overrides: see cli/bootstrap.php (IWAC_TYPESENSE_*, IWAC_OMEKA_VENDOR).
 *
 * Exit codes:
 *   0  success
 *   1  sync failed (Typesense unreachable, malformed synonyms file, …)
 *   2  setup error (missing composer deps, unreadable admin-key secret)
 *      — enforced by bootstrap.php
 */

use IwacSearch\Indexer\SynonymsSync;

['logger' => $logger, 'typesense' => $typesense, 'moduleRoot' => $moduleRoot,
 'tsConfig' => $tsConfig] = require __DIR__ . '/bootstrap.php';

try {
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

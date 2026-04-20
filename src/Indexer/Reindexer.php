<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Generator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Orchestrates a full bulk reindex.
 *
 * Flow:
 *   1. Build authority lookup from HF `index` subset (~4.7K rows, in-memory).
 *   2. Load + version the schema (e.g. iwac_v1 → iwac_v1_20260420_143015).
 *   3. Create the new collection.
 *   4. Stream each content subset through DocumentMapper, batch-import.
 *   5. Atomic-swap the iwac_current alias to point at the new collection.
 *   6. Drop the previous collection (if any).
 *
 * The alias swap is the critical safety property: the public scoped key
 * always queries `iwac_current`, so search keeps serving the OLD data
 * uninterruptedly until the swap completes. A failed reindex never affects
 * live search.
 */
final class Reindexer
{
    private const SUBSETS_TO_INDEX = ['articles']; // M0: articles only.
                                                    // M0+: add publications, documents, audiovisual.

    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly SchemaLoader $schemaLoader,
        private readonly HfDatasetLoader $hfLoader,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Run a full reindex. Returns stats for the CLI to print.
     *
     * @return array{collection: string, alias: string, indexed: int, errors: int, duration_seconds: float}
     */
    public function run(): array
    {
        $start = microtime(true);

        // ── 1. Authority lookup ──────────────────────────────────────────
        $this->logger->info('Building authority resolver from HF `index` subset');
        $authority = (new AuthorityResolver())->build($this->hfLoader->stream('index'));
        $this->logger->info('Authority resolver built', ['entities' => $authority->size()]);

        $mapper = new DocumentMapper($authority);

        // ── 2. Schema + collection ───────────────────────────────────────
        $schema   = $this->schemaLoader->loadForReindex();
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];
        $previous = $this->resolveAliasTarget($alias);

        $this->logger->info('Creating new collection', ['name' => $newName, 'alias' => $alias]);
        $createPayload = $schema;
        unset($createPayload['_alias_target'], $createPayload['_base_name']);
        $this->typesense->collections->create($createPayload);

        // ── 3. Stream + batch import ─────────────────────────────────────
        $totalIndexed = 0;
        $totalErrors  = 0;

        try {
            foreach (self::SUBSETS_TO_INDEX as $subset) {
                [$indexed, $errors] = $this->indexSubset($newName, $subset, $mapper);
                $totalIndexed += $indexed;
                $totalErrors  += $errors;
            }
        } catch (Throwable $e) {
            // Reindex failed. Drop the half-built collection so we don't
            // leave stale collections lying around. Live alias still points
            // at the previous (good) collection, so users see no impact.
            $this->logger->error('Reindex failed; dropping half-built collection', [
                'collection' => $newName,
                'error'      => $e->getMessage(),
            ]);
            $this->safelyDropCollection($newName);
            throw $e;
        }

        // ── 4. Atomic alias swap ─────────────────────────────────────────
        $this->logger->info('Swapping alias', ['alias' => $alias, 'to' => $newName]);
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        // ── 5. Drop the previous collection ──────────────────────────────
        if ($previous !== null && $previous !== $newName) {
            $this->logger->info('Dropping previous collection', ['name' => $previous]);
            $this->safelyDropCollection($previous);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $totalIndexed,
            'errors'           => $totalErrors,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * Index a single subset. @return array{0: int, 1: int} [indexed, errors]
     */
    private function indexSubset(string $collection, string $subset, DocumentMapper $mapper): array
    {
        $this->logger->info('Indexing subset', ['subset' => $subset]);

        $batch    = [];
        $indexed  = 0;
        $errors   = 0;

        foreach ($this->hfLoader->stream($subset) as $row) {
            $doc = match ($subset) {
                'articles' => $mapper->mapArticle($row),
                default    => null, // M0: only articles. Other subsets: M0+.
            };
            if ($doc === null) {
                continue;
            }
            $batch[] = $doc;

            if (count($batch) >= self::BATCH_SIZE) {
                [$ok, $err] = $this->flushBatch($collection, $batch);
                $indexed += $ok;
                $errors  += $err;
                $batch = [];
            }
        }
        if ($batch !== []) {
            [$ok, $err] = $this->flushBatch($collection, $batch);
            $indexed += $ok;
            $errors  += $err;
        }

        $this->logger->info('Subset indexed', ['subset' => $subset, 'indexed' => $indexed, 'errors' => $errors]);
        return [$indexed, $errors];
    }

    /**
     * Bulk-import a batch via the JSONL endpoint.
     *
     * @param  list<array<string,mixed>> $batch
     * @return array{0: int, 1: int}
     */
    private function flushBatch(string $collection, array $batch): array
    {
        $jsonl = '';
        foreach ($batch as $doc) {
            $jsonl .= json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        // action=upsert is idempotent — safe under retries. batch_size on the
        // server side controls how Typesense parallelizes ingestion.
        $response = $this->typesense->collections[$collection]->documents->import(
            $jsonl,
            ['action' => 'upsert', 'batch_size' => 100]
        );

        // Response is JSONL: one {"success": bool, ...} per row.
        $ok = $err = 0;
        foreach (preg_split("/\r?\n/", trim((string) $response)) as $line) {
            if ($line === '') { continue; }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['success'] ?? false)) {
                $ok++;
            } else {
                $err++;
                if ($err <= 3) {
                    // Log first few errors verbatim — silently dropping them
                    // would mask schema mismatches.
                    $this->logger->warning('Document import failed', ['response' => $row]);
                }
            }
        }
        return [$ok, $err];
    }

    /**
     * Returns the collection currently pointed at by the alias, or null if
     * the alias doesn't exist yet (first-ever reindex).
     */
    private function resolveAliasTarget(string $alias): ?string
    {
        try {
            $info = $this->typesense->aliases[$alias]->retrieve();
            return $info['collection_name'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safelyDropCollection(string $name): void
    {
        try {
            $this->typesense->collections[$name]->delete();
        } catch (Throwable $e) {
            // Logged but not raised — the new collection + alias are already
            // live, so an orphan old collection is just wasted disk.
            $this->logger->warning('Failed to drop collection', ['name' => $name, 'error' => $e->getMessage()]);
        }
    }
}

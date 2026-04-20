<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Orchestrates a full bulk reindex.
 *
 * Pure orchestration. All real work is delegated:
 *   - SchemaLoader      → schema versioning
 *   - HfDatasetLoader   → row streaming
 *   - AuthorityResolver → entity join
 *   - MapperRegistry    → row → doc per subset
 *   - OmekaAclLoader    → is_public overlay (lazy)
 *   - StopwordsSync     → ensure fr_default stopword set exists
 *
 * Flow:
 *   1. Sync stopwords (idempotent — safe under retries)
 *   2. Build authority lookup from HF `index` subset
 *   3. Prime the Omeka ACL cache
 *   4. Create the new versioned collection (e.g. iwac_v1_<UTC>)
 *   5. Stream → map → ACL-overlay → batch-import each subset
 *   6. Atomic-swap iwac_current alias → new collection
 *   7. Drop the previous collection
 *
 * Safety property: a failed reindex never affects live search. The alias
 * still points at the previous (good) collection until step 6 succeeds;
 * the half-built collection is dropped on error.
 */
final class Reindexer
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly SchemaLoader $schemaLoader,
        private readonly HfDatasetLoader $hfLoader,
        private readonly MapperRegistry $mappers,
        // The same AuthorityResolver instance is held by every mapper in
        // the registry. Reindexer populates it via build() inside run(),
        // and the mappers see the data through the shared reference.
        // This is the one mutable shared singleton in the indexer.
        private readonly AuthorityResolver $authority,
        private readonly OmekaAclLoader $aclLoader,
        private readonly StopwordsSync $stopwordsSync,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Run a full reindex.
     *
     * @return array{
     *     collection: string, alias: string,
     *     indexed: int, errors: int, public_items: int,
     *     subsets: array<string, array{indexed: int, errors: int}>,
     *     stopwords: array{set: string, locale: string, count: int},
     *     duration_seconds: float
     * }
     */
    public function run(): array
    {
        $start = microtime(true);

        // ── 1. Stopwords (Typesense-wide, not per-collection) ────────────
        $stopwordsResult = $this->stopwordsSync->sync();

        // ── 2. Authority lookup ──────────────────────────────────────────
        // Mutates the shared AuthorityResolver in-place. Mappers in the
        // registry already hold a reference and will see the new data on
        // their first map() call.
        $this->logger->info('Building authority resolver from HF `index` subset');
        $this->authority->build($this->hfLoader->stream('index'));
        $this->logger->info('Authority resolver built', ['entities' => $this->authority->size()]);

        $subsetsToIndex = array_values(array_filter(
            $this->mappers->subsets(),
            // 'index' is consumed for authority; any future "non-content"
            // subset belongs in this skip list.
            static fn(string $s): bool => $s !== 'index'
        ));
        $this->logger->info('Subsets to index', [
            'count'   => count($subsetsToIndex),
            'subsets' => $subsetsToIndex,
        ]);

        // ── 3. Omeka ACL cache (eager — fail fast if Omeka is down) ──────
        $this->aclLoader->prime();

        // ── 4. New collection ────────────────────────────────────────────
        $schema   = $this->schemaLoader->loadForReindex();
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];
        $previous = $this->resolveAliasTarget($alias);

        $this->logger->info('Creating new collection', ['name' => $newName, 'alias' => $alias]);
        $createPayload = $schema;
        unset($createPayload['_alias_target'], $createPayload['_base_name']);
        $this->typesense->collections->create($createPayload);

        // ── 5. Stream + map + overlay + import ───────────────────────────
        $totalIndexed = 0;
        $totalErrors  = 0;
        $perSubset    = [];

        try {
            foreach ($subsetsToIndex as $subset) {
                [$indexed, $errors] = $this->indexSubset($newName, $subset);
                $perSubset[$subset] = ['indexed' => $indexed, 'errors' => $errors];
                $totalIndexed += $indexed;
                $totalErrors  += $errors;
            }
        } catch (Throwable $e) {
            $this->logger->error('Reindex failed; dropping half-built collection', [
                'collection' => $newName,
                'error'      => $e->getMessage(),
            ]);
            $this->safelyDropCollection($newName);
            throw $e;
        }

        // ── 6. Atomic alias swap ─────────────────────────────────────────
        $this->logger->info('Swapping alias', ['alias' => $alias, 'to' => $newName]);
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        // ── 7. Drop the previous collection ──────────────────────────────
        if ($previous !== null && $previous !== $newName) {
            $this->logger->info('Dropping previous collection', ['name' => $previous]);
            $this->safelyDropCollection($previous);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $totalIndexed,
            'errors'           => $totalErrors,
            'public_items'     => $this->aclLoader->size(),
            'subsets'          => $perSubset,
            'stopwords'        => $stopwordsResult,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * Index one subset. Returns [indexed, errors].
     *
     * @return array{0: int, 1: int}
     */
    private function indexSubset(string $collection, string $subset): array
    {
        $this->logger->info('Indexing subset', ['subset' => $subset]);
        $mapper = $this->mappers->get($subset);

        $batch   = [];
        $indexed = 0;
        $errors  = 0;

        foreach ($this->hfLoader->stream($subset) as $row) {
            $doc = $mapper->map($row);
            if ($doc === null) {
                continue;
            }

            // ACL overlay — the mapper defaulted is_public=false; flip to
            // true if the Omeka API confirms the item is publicly visible.
            $omekaId = (int) ($doc['id'] ?? 0);
            if ($omekaId > 0 && $this->aclLoader->isPublic($omekaId)) {
                $doc['is_public'] = true;
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

        $this->logger->info('Subset indexed', [
            'subset'  => $subset,
            'indexed' => $indexed,
            'errors'  => $errors,
        ]);
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

        $response = $this->typesense->collections[$collection]->documents->import(
            $jsonl,
            ['action' => 'upsert', 'batch_size' => 100]
        );

        $ok = $err = 0;
        foreach (preg_split("/\r?\n/", trim((string) $response)) as $line) {
            if ($line === '') { continue; }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['success'] ?? false)) {
                $ok++;
            } else {
                $err++;
                if ($err <= 3) {
                    $this->logger->warning('Document import failed', ['response' => $row]);
                }
            }
        }
        return [$ok, $err];
    }

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
            $this->logger->warning('Failed to drop collection', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

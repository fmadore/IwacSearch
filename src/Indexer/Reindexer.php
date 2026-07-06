<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Orchestrates a full bulk reindex of the CONTENT collection, reading straight
 * from the Omeka S MySQL database (the HuggingFace ingestion path is gone).
 *
 * Pure orchestration. All real work is delegated:
 *   - SchemaLoader      → schema versioning
 *   - OmekaSourceReader → DBAL item streaming + value loading
 *   - EntityAuthority   → entity lookup (built from MySQL, shared with mappers)
 *   - MapperRegistry    → item → doc per subset
 *   - EntityOccurrences → accumulate per-entity metrics for the entity pass
 *   - StopwordsSync     → ensure fr_default stopword set exists
 *   - CurationSync      → ensure iwac_diversity curation set exists
 *   - SynonymsSync      → ensure iwac_synonyms synonym set exists
 *
 * Flow:
 *   1. Sync stopwords + the curation and synonym sets (idempotent)
 *   2. Build the EntityAuthority cache from the Omeka entity classes
 *   3. Create the new versioned collection (e.g. iwac_v2_<UTC>)
 *   4. Stream → map → import each content subset; record entity occurrences
 *   5. Atomic-swap iwac_current alias → new collection
 *   6. Drop the previous collection
 *
 * is_public comes straight from resource.is_public (read by the source
 * reader) — there is no ACL overlay step any more, because the database IS
 * the source of truth for visibility.
 *
 * Safety property unchanged: a failed reindex never affects live search. The
 * alias still points at the previous good collection until step 5 succeeds;
 * the half-built collection is dropped on error.
 */
final class Reindexer
{
    private const BATCH_SIZE = 200;

    /** Shared import/alias/drop plumbing (also used by IndexReindexer). */
    private readonly CollectionOps $ops;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly SchemaLoader $schemaLoader,
        private readonly OmekaSourceReader $reader,
        private readonly MapperRegistry $mappers,
        // Shared, mutable singleton: built here in run(), then read by every
        // mapper in the registry (which hold the same reference) and by the
        // IndexReindexer that runs next.
        private readonly EntityAuthority $authority,
        // Filled during the content pass; consumed by the IndexReindexer.
        private readonly EntityOccurrences $occurrences,
        private readonly StopwordsSync $stopwordsSync,
        private readonly CurationSync $curationSync,
        private readonly SynonymsSync $synonymsSync,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->ops = new CollectionOps($typesense, $this->logger, 'content');
    }

    /**
     * @return array{
     *     collection: string, alias: string,
     *     indexed: int, errors: int,
     *     entities: int,
     *     subsets: array<string, array{indexed: int, errors: int}>,
     *     stopwords: array{set: string, locale: string, count: int},
     *     curation: array{set: string, tag: string, metric: string},
     *     synonyms: array{set: string, groups: int},
     *     duration_seconds: float
     * }
     */
    public function run(): array
    {
        $start = microtime(true);

        // ── 1. Global resources (created before the collection links them) ──
        $stopwordsResult = $this->stopwordsSync->sync();
        $curationResult  = $this->curationSync->sync();
        $synonymsResult  = $this->synonymsSync->sync();

        // ── 2. Authority cache from the Omeka entity classes ────────────────
        $this->logger->info('Building entity authority from Omeka classes', [
            'classes' => EntityAuthority::CLASS_IDS,
        ]);
        $this->authority->build($this->reader);
        $this->logger->info('Entity authority built', ['entities' => $this->authority->size()]);

        // ── 3. New collection ───────────────────────────────────────────────
        $schema   = $this->schemaLoader->loadForReindex();
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];
        $previous = $this->ops->resolveAliasTarget($alias);

        $this->logger->info('Creating new collection', ['name' => $newName, 'alias' => $alias]);
        $createPayload = $schema;
        unset($createPayload['_alias_target'], $createPayload['_base_name']);
        $this->typesense->collections->create($createPayload);

        // ── 4. Stream + map + import ────────────────────────────────────────
        $totalIndexed = 0;
        $totalErrors  = 0;
        $perSubset    = [];

        try {
            foreach ($this->mappers->subsets() as $subset) {
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
            $this->ops->safelyDropCollection($newName);
            throw $e;
        }

        // ── 5. Atomic alias swap ────────────────────────────────────────────
        $this->logger->info('Swapping alias', ['alias' => $alias, 'to' => $newName]);
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        // ── 6. Drop the previous collection ─────────────────────────────────
        if ($previous !== null && $previous !== $newName) {
            $this->logger->info('Dropping previous collection', ['name' => $previous]);
            $this->ops->safelyDropCollection($previous);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $totalIndexed,
            'errors'           => $totalErrors,
            'entities'         => $this->authority->size(),
            'subsets'          => $perSubset,
            'stopwords'        => $stopwordsResult,
            'curation'         => $curationResult,
            'synonyms'         => $synonymsResult,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * Index one content subset (one or more Omeka resource classes).
     *
     * @return array{0: int, 1: int} [indexed, errors]
     */
    private function indexSubset(string $collection, string $subset): array
    {
        $mapper = $this->mappers->get($subset);
        $this->logger->info('Indexing subset', ['subset' => $subset, 'classes' => $mapper->classIds()]);

        $batch   = [];
        $indexed = 0;
        $errors  = 0;

        foreach ($this->reader->streamDocs($mapper->classIds(), $mapper->readTerms(), $mapper->itemSetIds()) as $row) {
            $doc = $mapper->map($row['item'], $row['values'], $row['thumbnail']);
            if ($doc === null) {
                continue;
            }

            // Feed the entity pass before batching (cheap, in-memory).
            $this->occurrences->record($doc);

            $batch[] = $doc;
            if (count($batch) >= self::BATCH_SIZE) {
                [$ok, $err] = $this->ops->flushBatch($collection, $batch);
                $indexed += $ok;
                $errors  += $err;
                $batch = [];
            }
        }
        if ($batch !== []) {
            [$ok, $err] = $this->ops->flushBatch($collection, $batch);
            $indexed += $ok;
            $errors  += $err;
        }

        $this->logger->info('Subset indexed', ['subset' => $subset, 'indexed' => $indexed, 'errors' => $errors]);
        return [$indexed, $errors];
    }

}

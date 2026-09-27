<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\IwacInstance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * The full rebuild: build both generations, reconcile them with the catalog,
 * then promote both aliases.
 *
 * Catalog saves wait on the mutation gate while the cutover holds it, so the
 * gate is held only for work proportional to the edits made DURING the
 * rebuild, never for work proportional to the corpus:
 *
 *   W1  watermark read with the gate briefly held (no write in flight), then
 *       the content collection is streamed and imported (Reindexer).
 *   W2  a second quiescent watermark. Unlocked, the IDs journaled since W1 are
 *       replayed, the entity authority and per-document types/occurrences are
 *       re-read from the catalog, and the entity collection is built.
 *   ▸   Gate held: only IDs journaled since W2 are replayed into BOTH new
 *       collections; expected counts are adjusted by that replay's outcome
 *       and checked against the server; both aliases are promoted.
 *
 * Why that is exact: a write takes the gate on its pre event and journals
 * again on its post event, after its data is committed. Any change after W2
 * therefore has a journal row above W2 and is replayed under the gate; any
 * ID without one was final before the post-W2 reads. Entity occurrence
 * aggregates are a rebuild-time statistic (incremental work preserves them),
 * so reading them after W2 is within contract.
 *
 * A generation that is never promoted is dropped: it can never be a
 * rollback target (those are recorded in iwac_search_rollback), and every
 * resident generation costs Typesense memory.
 */
final class ReindexOrchestrator
{
    /** How long the gate/publication locks may be waited for. */
    private const LOCK_WAIT_SECONDS = 300;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly Connection $connection,
        private readonly string $moduleRoot,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var ?\Closure(): bool */
        private readonly ?\Closure $shouldStop = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $rebuild = new DatabaseLock($this->connection, 'rebuild');
        $rebuild->acquire();
        try {
            return $this->build();
        } finally {
            $rebuild->release();
        }
    }

    /** @return array<string,mixed> */
    private function build(): array
    {
        $started = microtime(true);
        $journal = new ChangeJournal($this->connection);
        $mutation = new DatabaseLock($this->connection, 'mutation');
        $publish = new DatabaseLock($this->connection, 'publish');
        $ops = new CollectionOps(fn (): TypesenseClient => $this->typesense, $this->logger, shouldStop: $this->shouldStop);
        $contentSchema = new SchemaLoader($this->moduleRoot . '/data/schema.yaml');
        $indexSchema = new SchemaLoader($this->moduleRoot . '/data/schema-index.yaml');
        $oldContent = $ops->resolveAliasTarget(IwacInstance::CONTENT_ALIAS);
        $oldIndex = $ops->resolveAliasTarget(IwacInstance::INDEX_ALIAS);

        $buildStart = $this->quiescentWatermark($journal, $mutation);

        $reader = new OmekaSourceReader($this->connection);
        $authority = new EntityAuthority();
        $registry = MapperRegistry::default($authority, new CountryResolver($this->moduleRoot . '/data/newspaper-countries.json'));
        $stats = (new Reindexer(
            $ops,
            $contentSchema,
            $reader,
            $registry,
            $authority,
            new EntityOccurrences(),
            new StopwordsSync($this->typesense, $this->moduleRoot . '/data/stopwords-fr.json', $this->logger),
            new CurationSync($this->typesense, $this->logger),
            new SynonymsSync($this->typesense, $this->moduleRoot . '/data/synonyms-fr.json', $this->logger),
            $this->logger,
        ))->run(false);
        $content = $stats['collection'];
        $index = null;

        try {
            // ── Unlocked: everything that scales with the corpus ──────────
            $settled = $this->quiescentWatermark($journal, $mutation);
            $early = $journal->changedSince($buildStart);
            IncrementalIndexer::create($ops, $this->connection, $this->moduleRoot, $content, null, $this->logger)
                ->reindexItems($early);

            // Metadata-only read: OCR is neither loaded nor tokenised again.
            $authority->build($reader);
            $occurrences = new EntityOccurrences();
            /** @var array<int, string> $types document id → type_s */
            $types = [];
            foreach ($registry->subsets() as $subset) {
                $mapper = $registry->get($subset);
                $terms = array_values(array_diff($mapper->readTerms(), ['bibo:content', 'dcterms:tableOfContents']));
                foreach ($reader->streamDocs($mapper->classIds(), $terms, $mapper->itemSetIds(), false) as $row) {
                    $doc = $mapper->map($row['item'], $row['values'], null);
                    if ($doc !== null) {
                        $types[(int) $doc['id']] = (string) $doc['type_s'];
                        $occurrences->record($doc);
                    }
                }
            }
            $indexer = new IndexReindexer($ops, $indexSchema, $authority, $occurrences, new IndexEntityMapper(), $this->logger);
            $indexStats = $indexer->run(false);
            $index = $indexStats['collection'];
            $entityIds = array_fill_keys($indexer->indexedIds(), true);

            // ── Gate held: only what changed while the above ran ──────────
            $mutation->acquire(self::LOCK_WAIT_SECONDS);
            $gateStart = microtime(true);
            try {
                $publish->acquire(self::LOCK_WAIT_SECONDS);
                try {
                    // Deleted IDs remain in the journal after their source row is gone.
                    $late = $journal->changedSince($settled);
                    $outcome = IncrementalIndexer::create($ops, $this->connection, $this->moduleRoot, $content, $index, $this->logger)
                        ->reindexItems($late);
                    foreach ($outcome['content'] as $id => $type) {
                        if ($type === null) {
                            unset($types[$id]);
                        } else {
                            $types[$id] = $type;
                        }
                    }
                    foreach ($outcome['entity'] as $id => $present) {
                        if ($present) {
                            $entityIds[$id] = true;
                        } else {
                            unset($entityIds[$id]);
                        }
                    }

                    $expected = array_count_values($types);
                    ksort($expected);
                    $this->verifyContent($ops, $content, $expected);
                    if ($ops->documentCount($index) !== count($entityIds)) {
                        throw new RuntimeException('Entity index count mismatch.');
                    }

                    // The aliases are individual atomic operations, not a distributed
                    // transaction. Restore both on failure; never delete either build here.
                    try {
                        $ops->promote(IwacInstance::CONTENT_ALIAS, $content, array_sum($expected), 0);
                        $ops->promote(IwacInstance::INDEX_ALIAS, $index, count($entityIds), 0);
                        $this->connection->executeStatement(
                            'INSERT INTO iwac_search_rollback (alias_name, collection_name) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name)',
                            [IwacInstance::CONTENT_ALIAS, $oldContent, IwacInstance::INDEX_ALIAS, $oldIndex]
                        );
                    } catch (Throwable $e) {
                        foreach ([IwacInstance::CONTENT_ALIAS => $oldContent, IwacInstance::INDEX_ALIAS => $oldIndex] as $alias => $previous) {
                            try {
                                $ops->restoreAlias($alias, $previous);
                            } catch (Throwable $rollback) {
                                $this->logger->critical('Alias rollback failed; manual recovery required', ['alias' => $alias, 'previous' => $previous, 'error' => $rollback->getMessage()]);
                            }
                        }
                        throw $e;
                    }
                } finally {
                    $publish->release();
                }
            } finally {
                $stats['gate_held_seconds'] = round(microtime(true) - $gateStart, 2);
                $mutation->release();
            }

            $stats['indexed'] = array_sum($expected);
            $stats['verified_counts'] = $expected;
            $stats['catch_up'] = ['items' => count($late), 'unlocked_items' => count($early), 'ok' => true];
            $indexStats['indexed'] = count($entityIds);
            $stats['index'] = $indexStats;
        } catch (Throwable $e) {
            $this->discardUnpromoted($ops, array_values(array_filter([$content, $index])));
            throw $e;
        }

        $journal->prune();
        $stats['analytics'] = (new AnalyticsSync($this->typesense, $this->logger))->sync();
        $stats['duration_seconds'] = round(microtime(true) - $started, 2);
        return $stats;
    }

    /**
     * The journal watermark at a moment when no API write is between its pre
     * and post events: every change at or below it is committed.
     */
    private function quiescentWatermark(ChangeJournal $journal, DatabaseLock $mutation): int
    {
        $mutation->acquire(self::LOCK_WAIT_SECONDS);
        try {
            return $journal->watermark();
        } finally {
            $mutation->release();
        }
    }

    /**
     * The server must hold exactly the expected documents per content type:
     * the total, and one faceted count for every type (none missing, none extra).
     *
     * @param array<string, int> $expected type_s → document count, key-sorted
     */
    private function verifyContent(CollectionOps $ops, string $collection, array $expected): void
    {
        if ($ops->documentCount($collection) !== array_sum($expected)) {
            throw new RuntimeException('Final source and content index document counts disagree.');
        }
        $actual = $ops->facetCounts($collection, 'type_s');
        ksort($actual);
        if ($actual !== $expected) {
            $types = array_keys(array_diff_assoc($expected, $actual) + array_diff_assoc($actual, $expected));
            throw new RuntimeException('Source/index count mismatch for ' . implode(', ', $types));
        }
    }

    /**
     * Drop builds that did not become live. Skipped when the alias targets
     * cannot be read: a build that an alias still points at (a failed
     * rollback) must never be dropped.
     *
     * @param list<string> $names
     */
    private function discardUnpromoted(CollectionOps $ops, array $names): void
    {
        try {
            $live = [$ops->resolveAliasTarget(IwacInstance::CONTENT_ALIAS), $ops->resolveAliasTarget(IwacInstance::INDEX_ALIAS)];
        } catch (Throwable $e) {
            $this->logger->warning('Could not read alias targets; leaving unpromoted builds for retention cleanup', ['collections' => $names, 'error' => $e->getMessage()]);
            return;
        }
        foreach ($names as $name) {
            if (!in_array($name, $live, true)) {
                $this->logger->info('Dropping unpromoted build', ['collection' => $name]);
                $ops->safelyDropCollection($name);
            }
        }
    }
}

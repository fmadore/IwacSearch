<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Exception\VerificationException;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\IwacInstance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 * Every build is entered in the generation ledger before its collection is
 * created. A generation that is never promoted is dropped (it can never be a
 * rollback target, and every resident generation costs Typesense memory);
 * one a killed rebuild left behind is dropped when the next rebuild starts.
 * A promotion keeps the live generation and the one it replaced per alias,
 * and deletes the older ones it retires ({@see GenerationCleanup}).
 *
 * Before any of it, the rebuild waits (boundedly) for a Typesense that is
 * still loading ({@see TypesenseReadiness}); before promotion, the build must
 * hold at least half of every content type and of the entity total the live
 * generations hold ({@see ShrinkGuard}) unless the operator allowed a
 * smaller corpus. A stop request ends the rebuild with
 * {@see Exception\ReindexCancelledException}; every check that refuses
 * promotion throws {@see VerificationException}.
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
        // Operator override for an intended large drop (`--allow-shrink`,
        // the job's `allow_shrink` argument, the maintenance checkbox).
        private readonly bool $allowShrink = false,
        private readonly float $minRetainedRatio = ShrinkGuard::MIN_RETAINED_RATIO,
        private readonly int $readyWaitSeconds = TypesenseReadiness::WAIT_SECONDS,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        (new TypesenseReadiness($this->typesense, $this->logger, $this->readyWaitSeconds))->await($this->shouldStop);
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
        // Every stripe: no save may be between its pre and post events while
        // a watermark is read or the cutover runs (see MutationLock).
        $mutation = MutationLock::exclusive($this->connection);
        $publish = new DatabaseLock($this->connection, 'publish');
        $ledger = new DatabaseGenerationLedger($this->connection);
        $ops = new CollectionOps(fn (): TypesenseClient => $this->typesense, $this->logger, shouldStop: $this->shouldStop, ledger: $ledger);
        $cleanup = new GenerationCleanup($ops, $ledger, $this->logger);
        // We hold the rebuild lock, so any build still marked as building
        // belongs to a rebuild that died; reclaim its memory before adding ours.
        $recovered = $cleanup->recoverOrphans();
        $ops->checkCancellation();
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

            // Read before taking the gate: nothing but this rebuild moves the
            // aliases (it holds the rebuild lock), and a drain changes the
            // live counts by a handful, far inside the ratio.
            $live = $this->allowShrink ? null : $this->liveCounts($ops, $oldContent, $oldIndex);
            $ops->checkCancellation();

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
                        throw new VerificationException('Entity index count mismatch.');
                    }
                    if ($live !== null) {
                        $this->guardAgainstShrink($live, $expected, count($entityIds));
                    }

                    // The aliases are individual atomic operations, not a distributed
                    // transaction. Restore both on failure; never delete either build here.
                    try {
                        $ops->promote(IwacInstance::CONTENT_ALIAS, $content, array_sum($expected), 0);
                        $ops->promote(IwacInstance::INDEX_ALIAS, $index, count($entityIds), 0);
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
                    $this->recordPromotion($ledger, [
                        IwacInstance::CONTENT_ALIAS => [$content, $oldContent],
                        IwacInstance::INDEX_ALIAS => [$index, $oldIndex],
                    ]);
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

        $stats['allow_shrink'] = $this->allowShrink;
        $stats['generations'] = ['recovered' => $recovered, 'removed' => $this->retire($cleanup)];
        $journal->prune();
        $stats['analytics'] = (new AnalyticsSync($this->typesense, $this->logger))->sync();
        $stats['duration_seconds'] = round(microtime(true) - $started, 2);
        return $stats;
    }

    /**
     * Ledger bookkeeping for promotions that already happened. The verified
     * aliases are live, so a failure here is logged, never fatal: a build
     * left marked as building is aliased, and the next rebuild records it as
     * live; nothing is retired, so nothing is deleted on the strength of it.
     *
     * @param array<string, array{0: string, 1: ?string}> $promotions alias → [new target, previous target]
     */
    private function recordPromotion(GenerationLedger $ledger, array $promotions): void
    {
        foreach ($promotions as $alias => [$live, $previous]) {
            try {
                $ledger->recordPromotion($alias, $live, $previous);
            } catch (Throwable $e) {
                $this->logger->error('Alias promoted, but the generation ledger could not record it', ['alias' => $alias, 'collection' => $live, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Retention after a promotion, best effort: a generation that cannot be
     * deleted now stays retired and is retried by the next rebuild or prune.
     *
     * @return list<string>
     */
    private function retire(GenerationCleanup $cleanup): array
    {
        try {
            return $cleanup->removeRetired();
        } catch (Throwable $e) {
            $this->logger->warning('Retired generations could not be removed; the next rebuild or prune retries', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * The journal watermark at a moment when no API write is between its pre
     * and post events: every change at or below it is committed.
     */
    private function quiescentWatermark(ChangeJournal $journal, AdvisoryLock $mutation): int
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
            throw new VerificationException('Final source and content index document counts disagree.');
        }
        $actual = $ops->facetCounts($collection, 'type_s');
        ksort($actual);
        if ($actual !== $expected) {
            $types = array_keys(array_diff_assoc($expected, $actual) + array_diff_assoc($actual, $expected));
            throw new VerificationException('Source/index count mismatch for ' . implode(', ', array_map(
                static fn (string $type): string => sprintf('%s (catalog %d, index %d)', $type, $expected[$type] ?? 0, $actual[$type] ?? 0),
                array_map('strval', $types)
            )));
        }
    }

    /**
     * What the live generations hold — per content type, and the entity
     * total — with nothing to compare where an alias does not exist yet.
     * A live generation that cannot be faceted by `type_s` (a schema that
     * predates the field's facet) is compared by its total instead; when
     * Typesense cannot answer at all, the rebuild fails here, before the gate.
     *
     * @return array{types: ?array<string, int>, total: int, entities: ?int}
     */
    private function liveCounts(CollectionOps $ops, ?string $content, ?string $index): array
    {
        $types = [];
        $total = 0;
        if ($content !== null) {
            $total = $ops->documentCount($content);
            try {
                $types = $ops->facetCounts($content, 'type_s');
            } catch (\Typesense\Exceptions\ObjectNotFound | \Typesense\Exceptions\RequestMalformed) {
                $types = null;
            }
        }
        return ['types' => $types, 'total' => $total, 'entities' => $index === null ? null : $ops->documentCount($index)];
    }

    /**
     * @param array{types: ?array<string, int>, total: int, entities: ?int} $live
     * @param array<string, int> $expected type_s → documents in the new build
     * @throws VerificationException
     */
    private function guardAgainstShrink(array $live, array $expected, int $entities): void
    {
        [$liveTypes, $newTypes] = $live['types'] === null
            ? [['documents' => $live['total']], ['documents' => array_sum($expected)]]
            : [$live['types'], $expected];
        $problems = ShrinkGuard::problems($liveTypes, $newTypes, $live['entities'], $entities, $this->minRetainedRatio);
        if ($problems !== []) {
            throw new VerificationException(sprintf(
                'Refusing to promote a build below %d%% of the live generation (%s). Check the source data and the mappers; if the drop is intended, rebuild with --allow-shrink (CLI) or "Allow a smaller corpus" (maintenance page).',
                (int) round($this->minRetainedRatio * 100),
                implode('; ', $problems)
            ));
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

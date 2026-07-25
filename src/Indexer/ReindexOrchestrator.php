<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Wires and runs the full bulk reindex: the content collection first, then a
 * catch-up pass that replays edits made while it was building, then the
 * entity index built from the authority + occurrence aggregates the content
 * pass populated.
 *
 * ONE home for the dependency graph that cli/reindex.php and Job\BulkReindex
 * used to copy-paste (and let drift — adding a mapper or a sync step meant
 * editing two files or silently desyncing the CLI from the admin button).
 * Both entry points now differ only in how they obtain the TypesenseClient,
 * the DBAL connection, and the logger.
 */
final class ReindexOrchestrator
{
    /**
     * Items per catch-up import. Smaller than the bulk batch (200) because
     * each one costs a full value/entity load for a set of ids that is
     * usually tiny — and because a long reindex on a busy day can turn up
     * thousands, which shouldn't be loaded in one go.
     */
    private const CATCH_UP_BATCH = 100;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly Connection $connection,
        /** Module root — the directory holding data/schema.yaml etc. */
        private readonly string $moduleRoot,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @return array<string, mixed> Content-pass stats plus ['index' => entity-pass stats]
     */
    public function run(): array
    {
        // The orchestrator already holds a live client; CollectionOps takes a
        // factory because the INCREMENTAL path needs the laziness (see its
        // constructor). Wrapping it here costs one closure.
        $typesense = $this->typesense;
        $typesenseFactory = static fn(): TypesenseClient => $typesense;

        // Shared, mutable authority cache: Reindexer->run() builds it from
        // MySQL, every mapper in the registry holds the same reference, and
        // IndexReindexer reads it afterwards. EntityOccurrences is filled
        // during the content pass and consumed by the entity pass.
        $reader      = new OmekaSourceReader($this->connection);
        $authority   = new EntityAuthority();
        $countries   = new CountryResolver($this->moduleRoot . '/data/newspaper-countries.json');
        $occurrences = new EntityOccurrences();

        $registry = MapperRegistry::default($authority, $countries);

        // One CollectionOps per pass — same plumbing, different log label so
        // the content-pass and index-pass lines stay tellable apart.
        $reindexer = new Reindexer(
            ops:           new CollectionOps($typesenseFactory, $this->logger, 'content'),
            schemaLoader:  new SchemaLoader($this->moduleRoot . '/data/schema.yaml'),
            reader:        $reader,
            mappers:       $registry,
            authority:     $authority,
            occurrences:   $occurrences,
            stopwordsSync: new StopwordsSync($this->typesense, $this->moduleRoot . '/data/stopwords-fr.json', $this->logger),
            curationSync:  new CurationSync($this->typesense, $this->logger),
            synonymsSync:  new SynonymsSync($this->typesense, $this->moduleRoot . '/data/synonyms-fr.json', $this->logger),
            logger:        $this->logger
        );

        // Entity (index) collection — built on the same run from the shared,
        // now-populated authority + occurrence aggregates. Independent alias swap.
        $indexReindexer = new IndexReindexer(
            ops:          new CollectionOps($typesenseFactory, $this->logger, 'index'),
            schemaLoader: new SchemaLoader($this->moduleRoot . '/data/schema-index.yaml'),
            authority:    $authority,
            occurrences:  $occurrences,
            mapper:       new IndexEntityMapper(),
            logger:       $this->logger
        );

        // Watermark BEFORE the content pass starts — see catchUpEdits().
        $startedAt = $reader->databaseNow();

        $stats = $reindexer->run();
        $stats['catch_up'] = $this->catchUpEdits(
            $typesenseFactory,
            $reader,
            $registry,
            $authority,
            (string) $stats['collection'],
            $startedAt
        );

        $stats['index'] = $indexReindexer->run();

        // Search analytics rules (popular + no-hit queries). NON-FATAL:
        // requires server flags that may not be enabled yet — sync()
        // swallows failure and reports enabled:false in the stats.
        $stats['analytics'] = (new AnalyticsSync($this->typesense, $this->logger))->sync();

        return $stats;
    }

    /**
     * Replay the edits a bulk reindex would otherwise swallow.
     *
     * A reindex reads the corpus once, page by page, into a new collection
     * while the site stays live. An item saved during that window is upserted
     * by IncrementalIndexer through the ALIAS — which still points at the
     * OUTGOING collection — so if its page had already been streamed, the
     * edit is dropped at the swap and stays invisible until the next save or
     * the next reindex. On a corpus this size the build takes long enough
     * that a monthly reindex quietly reverting a curator's afternoon is a
     * real outcome, not a theoretical one.
     *
     * The fix: note the DATABASE's clock before the build, and once the alias
     * points at the new collection, re-index everything touched since. Edits
     * made after this query need no help — the alias they write through is
     * the new collection. The watermark is deliberately coarse: replaying an
     * item that didn't need it costs one upsert of identical content.
     *
     * WHAT THIS DOES NOT COVER: an item DELETED mid-build after its page was
     * streamed. Its row is gone, so no timestamp query can find it, and it
     * survives in the new collection as a stale document. Closing that would
     * mean dual-writing deletes into the in-flight collection (the indexer
     * would have to learn a reindex is running, across processes). The next
     * reindex clears it; until then the item is reachable only from search,
     * not from Omeka. Documented rather than fixed because the cost of the
     * machinery outweighs a rare, self-healing stale row — but it IS the
     * remaining hole.
     *
     * Failure here is non-fatal: the reindex itself already succeeded and the
     * new collection is live. A failed catch-up leaves exactly the behaviour
     * this module had before it existed.
     *
     * @param \Closure(): TypesenseClient $typesenseFactory
     * @return array{since: string, items: int, ok: bool}
     */
    private function catchUpEdits(
        \Closure $typesenseFactory,
        OmekaSourceReader $reader,
        MapperRegistry $registry,
        EntityAuthority $authority,
        string $collection,
        string $startedAt
    ): array {
        try {
            $ids = $reader->idsModifiedSince($startedAt);
            if ($ids === []) {
                return ['since' => $startedAt, 'items' => 0, 'ok' => true];
            }

            $this->logger->info('Replaying edits made during the reindex', [
                'since'      => $startedAt,
                'items'      => count($ids),
                'collection' => $collection,
            ]);

            // Targets the new collection BY NAME, not through the alias: the
            // swap has happened, but naming it directly keeps this correct
            // even if another run swaps the alias underneath us.
            $indexer = new IncrementalIndexer(
                ops:             new CollectionOps($typesenseFactory, $this->logger, 'catch-up'),
                reader:          $reader,
                mappers:         $registry,
                authority:       $authority,
                collectionAlias: $collection,
                logger:          $this->logger
            );

            foreach (array_chunk($ids, self::CATCH_UP_BATCH) as $chunk) {
                $indexer->reindexItems($chunk);
            }

            return ['since' => $startedAt, 'items' => count($ids), 'ok' => true];
        } catch (Throwable $e) {
            $this->logger->error('Catch-up pass failed; the new collection is live but may be missing edits', [
                'since' => $startedAt,
                'error' => $e->getMessage(),
            ]);
            return ['since' => $startedAt, 'items' => 0, 'ok' => false];
        }
    }
}

<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/** Build independently, reconcile under the mutation gate, then promote both aliases. */
final class ReindexOrchestrator
{
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
        $initialGate = new DatabaseLock($this->connection, 'mutation');
        $initialGate->acquire(300);
        try {
            $watermark = $journal->watermark();
        } finally {
            $initialGate->release();
        }
        $reader = new OmekaSourceReader($this->connection);
        $authority = new EntityAuthority();
        $occurrences = new EntityOccurrences();
        $registry = MapperRegistry::default($authority, new CountryResolver($this->moduleRoot . '/data/newspaper-countries.json'));
        $factory = fn (): TypesenseClient => $this->typesense;
        $ops = new CollectionOps($factory, $this->logger, shouldStop: $this->shouldStop);
        $contentSchema = new SchemaLoader($this->moduleRoot . '/data/schema.yaml');
        $indexSchema = new SchemaLoader($this->moduleRoot . '/data/schema-index.yaml');
        $oldContent = $ops->resolveAliasTarget('iwac_current');
        $oldIndex = $ops->resolveAliasTarget('iwac_index_current');
        $stats = (new Reindexer(
            $ops,
            $contentSchema,
            $reader,
            $registry,
            $authority,
            $occurrences,
            new StopwordsSync($this->typesense, $this->moduleRoot . '/data/stopwords-fr.json', $this->logger),
            new CurationSync($this->typesense, $this->logger),
            new SynonymsSync($this->typesense, $this->moduleRoot . '/data/synonyms-fr.json', $this->logger),
            $this->logger,
        ))->run(false);

        $mutation = new DatabaseLock($this->connection, 'mutation');
        $publish = new DatabaseLock($this->connection, 'publish');
        $mutation->acquire(300);
        try {
            $publish->acquire(300);
            try {
                // Pre/post API events hold mutation through journal append. Deleted
                // IDs remain here even after the corresponding source row is gone.
                $changed = $journal->changedSince($watermark);
                $indexer = new IncrementalIndexer(
                    $ops,
                    $reader,
                    $registry,
                    $authority,
                    $stats['collection'],
                    $this->logger,
                    null
                );
                $indexer->reindexItems($changed);
                $stats['catch_up'] = ['items' => count($changed), 'ok' => true];

                // Build aggregates from the final source state. Metadata-only read
                // avoids loading and tokenizing OCR a second time.
                $authority->build($reader);
                $occurrences = new EntityOccurrences();
                $counts = [];
                foreach ($registry->subsets() as $subset) {
                    $mapper = $registry->get($subset);
                    $terms = array_values(array_diff($mapper->readTerms(), ['bibo:content', 'dcterms:tableOfContents']));
                    foreach ($reader->streamDocs($mapper->classIds(), $terms, $mapper->itemSetIds(), false) as $row) {
                        $doc = $mapper->map($row['item'], $row['values'], null);
                        if ($doc !== null) {
                            $type = $doc['type_s'];
                            $counts[$type] = ($counts[$type] ?? 0) + 1;
                            $occurrences->record($doc);
                        }
                    }
                }
                $actualTotal = $ops->documentCount($stats['collection']);
                if ($actualTotal !== array_sum($counts)) {
                    throw new RuntimeException('Final source and content index document counts disagree.');
                }
                foreach ($counts as $type => $expected) {
                    $found = $this->typesense->collections[$stats['collection']]->documents->search([
                        'q' => '*', 'filter_by' => 'type_s:=' . $type, 'per_page' => 0, 'enable_analytics' => false,
                    ])['found'];
                    if ((int) $found !== $expected) {
                        throw new RuntimeException('Source/index count mismatch for ' . $type);
                    }
                }
                $stats['indexed'] = $actualTotal;
                $stats['verified_counts'] = $counts;
                $indexStats = (new IndexReindexer(
                    $ops,
                    $indexSchema,
                    $authority,
                    $occurrences,
                    new IndexEntityMapper(),
                    $this->logger
                ))->run(false);
                if ($ops->documentCount($indexStats['collection']) !== $indexStats['indexed']) {
                    throw new RuntimeException('Entity index count mismatch.');
                }
                // The aliases are individual atomic operations, not a distributed
                // transaction. Restore both on failure; never delete either build.
                try {
                    $ops->promote('iwac_current', $stats['collection'], $contentSchema->load()['name'], $oldContent, $actualTotal, 0);
                    $ops->promote('iwac_index_current', $indexStats['collection'], $indexSchema->load()['name'], $oldIndex, $indexStats['indexed'], 0);
                    $this->connection->executeStatement('INSERT INTO iwac_search_rollback (alias_name, collection_name) VALUES (?, ?), (?, ?) ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name)', ['iwac_current', $oldContent, 'iwac_index_current', $oldIndex]);
                } catch (Throwable $e) {
                    foreach (['iwac_current' => $oldContent, 'iwac_index_current' => $oldIndex] as $alias => $previous) {
                        try {
                            $ops->restoreAlias($alias, $previous);
                        } catch (Throwable $rollback) {
                            $this->logger->critical('Alias rollback failed; manual recovery required', ['alias' => $alias, 'previous' => $previous, 'error' => $rollback->getMessage()]);
                        }
                    }
                    throw $e;
                }
                $stats['index'] = $indexStats;
            } finally {
                $publish->release();
            }
        } finally {
            $mutation->release();
        }
        $journal->prune();
        $stats['analytics'] = (new AnalyticsSync($this->typesense, $this->logger))->sync();
        $stats['duration_seconds'] = round(microtime(true) - $started, 2);
        return $stats;
    }
}

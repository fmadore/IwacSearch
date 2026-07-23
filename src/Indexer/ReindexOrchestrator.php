<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use IwacSearch\Indexer\Mapper\MapperRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Typesense\Client as TypesenseClient;

/**
 * Wires and runs the full bulk reindex: the content collection first, then
 * the entity index built from the authority + occurrence aggregates the
 * content pass populated.
 *
 * ONE home for the dependency graph that cli/reindex.php and Job\BulkReindex
 * used to copy-paste (and let drift — adding a mapper or a sync step meant
 * editing two files or silently desyncing the CLI from the admin button).
 * Both entry points now differ only in how they obtain the TypesenseClient,
 * the DBAL connection, and the logger.
 */
final class ReindexOrchestrator
{
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
        // Shared, mutable authority cache: Reindexer->run() builds it from
        // MySQL, every mapper in the registry holds the same reference, and
        // IndexReindexer reads it afterwards. EntityOccurrences is filled
        // during the content pass and consumed by the entity pass.
        $reader      = new OmekaSourceReader($this->connection);
        $authority   = new EntityAuthority();
        $countries   = new CountryResolver($this->moduleRoot . '/data/newspaper-countries.json');
        $occurrences = new EntityOccurrences();

        $registry = MapperRegistry::default($authority, $countries);

        $reindexer = new Reindexer(
            typesense:     $this->typesense,
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
            typesense:    $this->typesense,
            schemaLoader: new SchemaLoader($this->moduleRoot . '/data/schema-index.yaml'),
            authority:    $authority,
            occurrences:  $occurrences,
            mapper:       new IndexEntityMapper(),
            logger:       $this->logger
        );

        $stats = $reindexer->run();
        $stats['index'] = $indexReindexer->run();

        // Search analytics rules (popular + no-hit queries). NON-FATAL:
        // requires server flags that may not be enabled yet — sync()
        // swallows failure and reports enabled:false in the stats.
        $stats['analytics'] = (new AnalyticsSync($this->typesense, $this->logger))->sync();

        return $stats;
    }
}

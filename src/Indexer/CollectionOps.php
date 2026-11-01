<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use IwacSearch\Indexer\Exception\ReindexCancelledException;
use IwacSearch\Indexer\Exception\VerificationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Shared Typesense collection plumbing for the bulk reindexers and the
 * incremental indexer: versioned-collection creation, JSONL batch import,
 * batched delete/read by ID, faceted counts, alias resolution, guarded alias
 * promotion, and safe collection drops.
 *
 * Extracted from Reindexer / IndexReindexer, where the methods had
 * become copy-paste twins (identical except for log wording). The
 * $logLabel keeps content-pass and index-pass log lines tellable apart.
 */
final class CollectionOps
{
    /** IDs per filtered delete/export request (keeps the query string short). */
    private const ID_FILTER_CHUNK = 250;

    public function __construct(
        /**
         * Lazily-resolved, memoizing client factory. The bulk reindexers
         * already hold a live client and pass a closure over it; the
         * incremental path resolves it only when a drain job actually has
         * work, so a missing secret or a down Typesense fails that job
         * (leaving journal rows pending) instead of Omeka's startup.
         *
         * @var Closure(): TypesenseClient
         */
        private readonly Closure $clientFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $logLabel = 'content',
        /** @var ?Closure(): bool */
        private readonly ?Closure $shouldStop = null,
        // The rebuild's record of what it creates: a build is entered before
        // its collection exists and forgotten once it is dropped. Null for
        // the incremental path, which never creates or drops collections.
        private readonly ?GenerationLedger $ledger = null,
    ) {
    }

    /** @throws ReindexCancelledException when the job was asked to stop */
    public function checkCancellation(): void
    {
        if ($this->shouldStop !== null && ($this->shouldStop)()) {
            throw new ReindexCancelledException('Reindex cancelled before promotion; the live generations are unchanged.');
        }
    }

    private function client(): TypesenseClient
    {
        return ($this->clientFactory)();
    }

    /**
     * Create the timestamped collection from a SchemaLoader::loadForReindex()
     * payload (strips the loader's private `_alias_target` key).
     *
     * The ledger row is written first: from then on the name is owned, so a
     * create whose response is lost after Typesense accepted it, or a process
     * killed mid-import, still leaves a record the next rebuild cleans up.
     *
     * @param array<string,mixed> $versionedSchema
     */
    public function createVersioned(array $versionedSchema): void
    {
        $alias = (string) ($versionedSchema['_alias_target'] ?? '');
        unset($versionedSchema['_alias_target']);
        $this->ledger?->recordBuilding((string) $versionedSchema['name'], $alias);
        $this->client()->collections->create($versionedSchema);
    }

    /**
     * Import a document stream in fixed-size batches, accumulating totals.
     *
     * @param  iterable<array<string,mixed>> $docs
     * @return array{0: int, 1: int} [indexed, errors]
     */
    public function importAll(string $collection, iterable $docs, int $batchSize = 200): array
    {
        $batch   = [];
        $indexed = 0;
        $errors  = 0;

        foreach ($docs as $doc) {
            $batch[] = $doc;
            if (count($batch) >= $batchSize) {
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
        return [$indexed, $errors];
    }

    /**
     * Promote only a complete, nonempty generation: any rejected document, or
     * nothing indexed at all, refuses the swap and drops the build. A
     * systemic failure (schema/field-type mismatch) rejects every document;
     * without the guard the alias would swap to an empty collection.
     *
     * Previous generations are never deleted here; the generation ledger and
     * GenerationCleanup decide what goes.
     */
    public function promote(string $alias, string $newName, int $indexed, int $errors): void
    {
        $this->checkCancellation();
        if ($indexed === 0 || $errors > 0) {
            $this->logger->error("Refusing alias swap — import health check failed ({$this->logLabel})", [
                'collection' => $newName,
                'indexed'    => $indexed,
                'errors'     => $errors,
            ]);
            $this->safelyDropCollection($newName);
            throw new VerificationException(sprintf(
                'Reindex aborted before alias swap: %d indexed, %d errors — the previous collection stays live.',
                $indexed,
                $errors
            ));
        }

        $this->logger->info("Swapping alias ({$this->logLabel})", ['alias' => $alias, 'to' => $newName]);
        $this->client()->aliases->upsert($alias, ['collection_name' => $newName]);
    }

    public function documentCount(string $collection): int
    {
        return (int) $this->client()->collections[$collection]->retrieve()['num_documents'];
    }

    /** @return array<string, mixed>|null */
    public function document(string $collection, string $id): ?array
    {
        try {
            return $this->client()->collections[$collection]->documents[$id]->retrieve();
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            return null;
        }
    }

    /**
     * Per-value document counts of one facet field, in a single request.
     *
     * @return array<string, int>
     */
    public function facetCounts(string $collection, string $field, int $maxValues = 100): array
    {
        $response = $this->client()->collections[$collection]->documents->search([
            'q' => '*',
            'facet_by' => $field,
            'max_facet_values' => $maxValues,
            'per_page' => 0,
            'enable_analytics' => false,
        ]);
        $counts = [];
        foreach ($response['facet_counts'] ?? [] as $facet) {
            if (($facet['field_name'] ?? null) !== $field) {
                continue;
            }
            foreach ($facet['counts'] ?? [] as $row) {
                $counts[(string) $row['value']] = (int) $row['count'];
            }
        }
        return $counts;
    }

    /**
     * Selected fields of many documents, keyed by id, in one request per 250
     * IDs (the export endpoint takes the same filter_by and include_fields as
     * search, without paging). Absent IDs are simply missing from the result.
     *
     * @param  list<string> $ids
     * @param  list<string> $fields
     * @return array<string, array<string, mixed>>
     */
    public function documentsById(string $collection, array $ids, array $fields): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::ID_FILTER_CHUNK) as $chunk) {
            $jsonl = $this->client()->collections[$collection]->documents->export([
                'filter_by' => self::idFilter($chunk),
                'include_fields' => implode(',', array_values(array_unique(['id', ...$fields]))),
            ]);
            foreach (preg_split("/\r?\n/", trim($jsonl)) ?: [] as $line) {
                if ($line === '') {
                    continue;
                }
                $doc = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($doc) && isset($doc['id'])) {
                    $out[(string) $doc['id']] = $doc;
                }
            }
        }
        return $out;
    }

    /**
     * Delete many documents with one filtered request per 250 IDs. IDs that
     * are not in the collection are not an error — the incremental path
     * routinely asks both collections to forget an ID only one of them holds.
     *
     * @param  list<string> $ids
     * @return int documents actually deleted
     */
    public function deleteDocuments(string $collection, array $ids): int
    {
        $this->checkCancellation();
        $deleted = 0;
        foreach (array_chunk(array_values(array_unique($ids)), self::ID_FILTER_CHUNK) as $chunk) {
            $response = $this->client()->collections[$collection]->documents->delete([
                'filter_by' => self::idFilter($chunk),
            ]);
            $deleted += (int) ($response['num_deleted'] ?? 0);
        }
        return $deleted;
    }

    /** @param list<string> $ids Omeka resource IDs (digits only, so no quoting is needed). */
    private static function idFilter(array $ids): string
    {
        foreach ($ids as $id) {
            if (preg_match('/^[0-9]+$/D', $id) !== 1) {
                throw new RuntimeException('Refusing a non-numeric document id in an id filter.');
            }
        }
        return 'id:[' . implode(',', $ids) . ']';
    }

    /** Rollback an alias without deleting either generation. */
    public function restoreAlias(string $alias, ?string $collection): void
    {
        if ($collection !== null) {
            $this->client()->aliases->upsert($alias, ['collection_name' => $collection]);
        } else {
            try {
                $this->client()->aliases[$alias]->delete();
            } catch (\Typesense\Exceptions\ObjectNotFound) {
                // First installation may not yet have an alias to restore.
            }
        }
    }

    /**
     * Bulk-import a batch via the JSONL endpoint.
     *
     * @param  list<array<string,mixed>> $batch
     * @return array{0: int, 1: int, 2: list<string>} [ok, errors, ids of the rejected documents]
     */
    public function flushBatch(string $collection, array $batch): array
    {
        $this->checkCancellation();
        if ($batch === []) {
            return [0, 0, []];
        }
        $jsonl = '';
        foreach ($batch as $doc) {
            $jsonl .= json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }

        $response = $this->client()->collections[$collection]->documents->import(
            $jsonl,
            ['action' => 'upsert']
        );

        $lines = trim((string) $response) === '' ? [] : preg_split("/\r?\n/", trim((string) $response));
        if (count($lines) !== count($batch)) {
            throw new RuntimeException('Import response count does not match submitted documents.');
        }
        $ok = $err = 0;
        $rejected = [];
        foreach ($lines as $offset => $line) {
            if ($line === '') {
                continue;
            }
            try { $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new RuntimeException('Malformed import outcome JSON.'); }
            if (!is_array($row) || !is_bool($row['success'] ?? null)) {
                throw new RuntimeException('Malformed import outcome.');
            }
            if ($row['success']) {
                $ok++;
            } else {
                $err++;
                $id = $batch[$offset]['id'] ?? null;
                if (is_string($id) || is_int($id)) {
                    $rejected[] = (string) $id;
                }
                if ($err <= 3) {
                    $this->logger->warning("Document import failed ({$this->logLabel})", ['id' => $id, 'code' => $row['code'] ?? null]);
                }
            }
        }
        return [$ok, $err, $rejected];
    }

    /** The collection an alias currently points at, or null if absent; transport failures propagate. */
    public function resolveAliasTarget(string $alias): ?string
    {
        try {
            $info = $this->client()->aliases[$alias]->retrieve();
            return $info['collection_name'] ?? null;
        } catch (\Typesense\Exceptions\ObjectNotFound) {
            return null;
        }
    }

    /**
     * Every collection an alias currently targets — the set no cleanup may
     * ever delete, whatever the ledger says. Transport failures propagate.
     *
     * @return list<string>
     */
    public function aliasTargets(): array
    {
        $aliases = $this->client()->aliases->retrieve()['aliases'] ?? null;
        if (!is_array($aliases)) {
            throw new RuntimeException('Cannot read the alias table; refusing to treat any collection as unaliased.');
        }
        $targets = [];
        foreach ($aliases as $alias) {
            if (is_array($alias) && is_string($alias['collection_name'] ?? null)) {
                $targets[] = $alias['collection_name'];
            }
        }
        return array_values(array_unique($targets));
    }

    /**
     * Drop a collection, logging (never throwing) on failure. True once it is
     * gone — deleted now, or already absent — and then its ledger row is
     * forgotten too; on failure the row stays, so a later cleanup retries.
     */
    public function safelyDropCollection(string $name): bool
    {
        try {
            $this->client()->collections[$name]->delete();
        } catch (\Typesense\Exceptions\ObjectNotFound) {
            // Already gone: what the caller wanted.
        } catch (Throwable $e) {
            $this->logger->warning("Failed to drop collection ({$this->logLabel})", [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
        try {
            $this->ledger?->forget($name);
        } catch (Throwable $e) {
            // A stale row only means a later cleanup finds the name absent.
            $this->logger->warning("Dropped collection, but could not update the generation ledger ({$this->logLabel})", [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
        return true;
    }
}

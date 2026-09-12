<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Shared Typesense collection plumbing for the two bulk reindexers:
 * versioned-collection creation, JSONL batch import, alias resolution,
 * guarded alias promotion, and safe collection drops.
 *
 * Extracted from Reindexer / IndexReindexer, where the methods had
 * become copy-paste twins (identical except for log wording). The
 * $logLabel keeps content-pass and index-pass log lines tellable apart.
 */
final class CollectionOps
{
    /**
     * promote() refuses to swap the alias when more than this share of
     * documents failed to import. A systemic failure (schema/field-type
     * mismatch) rejects every doc; without the guard the alias would swap
     * to an empty collection and the last good one would be dropped.
     */
    private const MAX_ERROR_RATIO = 0.0;

    public function __construct(
        /**
         * Lazily-resolved, memoizing client factory. The bulk reindexers
         * already hold a live client and pass a closure over it; the
         * INCREMENTAL path needs the laziness for real — it runs inside
         * Omeka's api.*.post events, where constructing a client for an
         * unreachable Typesense must not block the admin's save.
         *
         * @var Closure(): TypesenseClient
         */
        private readonly Closure $clientFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $logLabel = 'content',
        /** @var ?Closure(): bool */
        private readonly ?Closure $shouldStop = null
    ) {
    }

    private function checkCancellation(): void
    {
        if ($this->shouldStop !== null && ($this->shouldStop)()) throw new RuntimeException('Reindex cancelled.');
    }

    private function client(): TypesenseClient
    {
        return ($this->clientFactory)();
    }

    /**
     * Create the timestamped collection from a SchemaLoader::loadForReindex()
     * payload (strips the loader's private `_alias_target` / `_base_name` keys).
     *
     * @param array<string,mixed> $versionedSchema
     */
    public function createVersioned(array $versionedSchema): void
    {
        unset($versionedSchema['_alias_target'], $versionedSchema['_base_name']);
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
     * Promote only a complete, nonempty generation. Previous generations are
     * retained; CollectionRetention is the only cross-generation cleanup path.
     * $baseName and $previous remain accepted for compatibility with callers.
     */
    public function promote(
        string $alias,
        string $newName,
        string $baseName,
        ?string $previous,
        int $indexed,
        int $errors
    ): void {
        $this->checkCancellation();
        $total = $indexed + $errors;
        if ($indexed === 0 || ($total > 0 && $errors / $total > self::MAX_ERROR_RATIO)) {
            $this->logger->error("Refusing alias swap — import health check failed ({$this->logLabel})", [
                'collection' => $newName,
                'indexed'    => $indexed,
                'errors'     => $errors,
            ]);
            $this->safelyDropCollection($newName);
            throw new RuntimeException(sprintf(
                'Reindex aborted before alias swap: %d indexed, %d errors — the previous collection stays live.',
                $indexed,
                $errors
            ));
        }

        $this->logger->info("Swapping alias ({$this->logLabel})", ['alias' => $alias, 'to' => $newName]);
        $this->client()->aliases->upsert($alias, ['collection_name' => $newName]);

        // Retain previous generations for rollback. Cleanup is an explicit locked operation.
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
     * @return array{0: int, 1: int} [ok, errors]
     */
    public function flushBatch(string $collection, array $batch): array
    {
        $this->checkCancellation();
        if ($batch === []) {
            return [0, 0];
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
                if ($err <= 3) {
                    $this->logger->warning("Document import failed ({$this->logLabel})", ['id' => $batch[$offset]['id'] ?? null, 'code' => $row['code'] ?? null]);
                }
            }
        }
        return [$ok, $err];
    }

    /** False only for a typed 404. Transport and other failures propagate. */
    public function deleteDocument(string $collection, string $documentId): bool
    {
        try {
            $this->client()->collections[$collection]->documents[$documentId]->delete();
            return true;
        } catch (\Typesense\Exceptions\ObjectNotFound) {
            return false;
        }
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

    /** Drop a collection, logging (never throwing) on failure. */
    public function safelyDropCollection(string $name): void
    {
        try {
            $this->client()->collections[$name]->delete();
        } catch (Throwable $e) {
            $this->logger->warning("Failed to drop collection ({$this->logLabel})", [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

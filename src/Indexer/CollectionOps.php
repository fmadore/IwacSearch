<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

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
    private const MAX_ERROR_RATIO = 0.1;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $logLabel = 'content'
    ) {
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
        $this->typesense->collections->create($versionedSchema);
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
     * Guarded alias promotion, then cleanup.
     *
     * 1. Refuse to promote (drop the new collection, keep the alias — live
     *    search is untouched) when nothing was indexed or the error ratio
     *    exceeds MAX_ERROR_RATIO.
     * 2. Atomic-swap the alias to the new collection.
     * 3. Drop the previous alias target, plus every other collection sharing
     *    the schema's `<base>_<timestamp>` prefix — orphans left behind by a
     *    crashed or overlapping run would otherwise hold Typesense RAM forever.
     *
     * @param  string  $baseName  Schema base name (SchemaLoader `_base_name`),
     *                            e.g. `iwac_v3` — prefix for the orphan sweep.
     * @param  ?string $previous  Alias target resolved before the build began.
     * @throws RuntimeException when the import health check fails.
     */
    public function promote(
        string $alias,
        string $newName,
        string $baseName,
        ?string $previous,
        int $indexed,
        int $errors
    ): void {
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
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        if ($previous !== null && $previous !== $newName) {
            $this->logger->info("Dropping previous collection ({$this->logLabel})", ['name' => $previous]);
            $this->safelyDropCollection($previous);
        }
        $this->dropOrphans($baseName, $newName);
    }

    /**
     * Drop every collection named `<base>_<timestamp>` except the one just
     * promoted. Self-heals leaks from crashed runs (created but never swapped)
     * and overlapping runs (two builds racing for the same alias).
     */
    private function dropOrphans(string $baseName, string $keep): void
    {
        try {
            $all = $this->typesense->collections->retrieve();
        } catch (Throwable $e) {
            $this->logger->warning("Orphan sweep skipped — could not list collections ({$this->logLabel})", [
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($all as $collection) {
            $name = (string) ($collection['name'] ?? '');
            if ($name !== $keep && $name !== '' && str_starts_with($name, $baseName . '_')) {
                $this->logger->info("Dropping orphaned collection ({$this->logLabel})", ['name' => $name]);
                $this->safelyDropCollection($name);
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
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['success'] ?? false)) {
                $ok++;
            } else {
                $err++;
                if ($err <= 3) {
                    $this->logger->warning("Document import failed ({$this->logLabel})", ['response' => $row]);
                }
            }
        }
        return [$ok, $err];
    }

    /** The collection an alias currently points at, or null if unresolvable. */
    public function resolveAliasTarget(string $alias): ?string
    {
        try {
            $info = $this->typesense->aliases[$alias]->retrieve();
            return $info['collection_name'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Drop a collection, logging (never throwing) on failure. */
    public function safelyDropCollection(string $name): void
    {
        try {
            $this->typesense->collections[$name]->delete();
        } catch (Throwable $e) {
            $this->logger->warning("Failed to drop collection ({$this->logLabel})", [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

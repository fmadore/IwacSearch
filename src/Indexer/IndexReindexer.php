<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\Indexer\Mapper\IndexEntityMapper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Builds the INDEX (authority) collection — the entity browse surface.
 *
 * Deliberately separate from {@see Reindexer} (which builds the content
 * collection and hardcodes skipping the `index` subset): keeping the two
 * apart means this never touches the battle-tested content path, and a
 * failure here can't corrupt the content alias (and vice versa).
 *
 * Same safety property as Reindexer: build a fresh timestamped collection,
 * atomic-swap the `iwac_index_current` alias only on success, drop the
 * previous collection last. A failed run leaves the live alias on the
 * previous good collection and drops the half-built one.
 *
 * Run AFTER the content Reindexer in cli/reindex.php + BulkReindex, sharing
 * the already-primed ACL loader (prime() is a cached no-op the 2nd time).
 */
final class IndexReindexer
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly SchemaLoader $schemaLoader,
        private readonly HfDatasetLoader $hfLoader,
        private readonly IndexEntityMapper $mapper,
        private readonly OmekaAclLoaderInterface $aclLoader,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $aliasTarget = 'iwac_index_current'
    ) {
    }

    /**
     * @return array{collection: string, alias: string, indexed: int, errors: int, duration_seconds: float}
     */
    public function run(): array
    {
        $start = microtime(true);

        // Shared with the content reindex; cached prime() => no-op here.
        $this->aclLoader->prime();

        $schema   = $this->schemaLoader->loadForReindex($this->aliasTarget);
        $newName  = $schema['name'];
        $alias    = $schema['_alias_target'];
        $previous = $this->resolveAliasTarget($alias);

        $this->logger->info('Creating new index collection', ['name' => $newName, 'alias' => $alias]);
        $createPayload = $schema;
        unset($createPayload['_alias_target'], $createPayload['_base_name']);
        $this->typesense->collections->create($createPayload);

        $indexed = 0;
        $errors  = 0;
        $batch   = [];

        try {
            foreach ($this->hfLoader->stream('index') as $row) {
                $doc = $this->mapper->map($row);
                if ($doc === null) {
                    continue;
                }
                $oid = (int) ($doc['id'] ?? 0);
                if ($oid > 0 && $this->aclLoader->isPublic($oid)) {
                    $doc['is_public'] = true;
                }
                $batch[] = $doc;
                if (count($batch) >= self::BATCH_SIZE) {
                    [$ok, $err] = $this->flushBatch($newName, $batch);
                    $indexed += $ok;
                    $errors  += $err;
                    $batch = [];
                }
            }
            if ($batch !== []) {
                [$ok, $err] = $this->flushBatch($newName, $batch);
                $indexed += $ok;
                $errors  += $err;
            }
        } catch (Throwable $e) {
            $this->logger->error('Index reindex failed; dropping half-built collection', [
                'collection' => $newName,
                'error'      => $e->getMessage(),
            ]);
            $this->safelyDropCollection($newName);
            throw $e;
        }

        $this->logger->info('Swapping index alias', ['alias' => $alias, 'to' => $newName]);
        $this->typesense->aliases->upsert($alias, ['collection_name' => $newName]);

        if ($previous !== null && $previous !== $newName) {
            $this->logger->info('Dropping previous index collection', ['name' => $previous]);
            $this->safelyDropCollection($previous);
        }

        return [
            'collection'       => $newName,
            'alias'            => $alias,
            'indexed'          => $indexed,
            'errors'           => $errors,
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
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
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && ($row['success'] ?? false)) {
                $ok++;
            } else {
                $err++;
                if ($err <= 3) {
                    $this->logger->warning('Index document import failed', ['response' => $row]);
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
            $this->logger->warning('Failed to drop index collection', [
                'name'  => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

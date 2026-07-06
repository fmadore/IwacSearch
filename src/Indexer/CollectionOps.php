<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Shared Typesense collection plumbing for the two bulk reindexers:
 * JSONL batch import, alias resolution, and safe collection drops.
 *
 * Extracted from Reindexer / IndexReindexer, where the three methods had
 * become copy-paste twins (identical except for log wording). The
 * $logLabel keeps content-pass and index-pass log lines tellable apart.
 */
final class CollectionOps
{
    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $logLabel = 'content'
    ) {
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

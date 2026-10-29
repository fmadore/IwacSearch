<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use IwacSearch\Indexer\GenerationLedger;
use RuntimeException;

/**
 * {@see GenerationLedger} in an array, with the same promotion semantics as
 * the database implementation (whose SQL the integration lane runs).
 */
final class MemoryGenerationLedger implements GenerationLedger
{
    /** @var array<string, array{alias: string, status: string}> */
    public array $rows = [];

    /** When set, every call throws — the ledger's table missing or the database down. */
    public ?RuntimeException $failure = null;

    public function recordBuilding(string $collection, string $alias): void
    {
        $this->fail();
        $this->rows[$collection] = ['alias' => $alias, 'status' => self::BUILDING];
    }

    public function recordPromotion(string $alias, string $live, ?string $previous): void
    {
        $this->fail();
        foreach ($this->rows as $name => $row) {
            if ($row['alias'] === $alias && in_array($row['status'], [self::LIVE, self::PREVIOUS], true)
                && $name !== $live && $name !== $previous) {
                $this->rows[$name]['status'] = self::RETIRED;
            }
        }
        if ($previous !== null && $previous !== '' && $previous !== $live) {
            $this->rows[$previous] = ['alias' => $alias, 'status' => self::PREVIOUS];
        }
        $this->rows[$live] = ['alias' => $alias, 'status' => self::LIVE];
    }

    public function mark(string $collection, string $status): void
    {
        $this->fail();
        if (isset($this->rows[$collection])) {
            $this->rows[$collection]['status'] = $status;
        }
    }

    public function forget(string $collection): void
    {
        $this->fail();
        unset($this->rows[$collection]);
    }

    public function generations(string ...$statuses): array
    {
        $this->fail();
        $rows = $statuses === []
            ? $this->rows
            : array_filter($this->rows, static fn (array $row): bool => in_array($row['status'], $statuses, true));
        ksort($rows);
        return $rows;
    }

    /** @return array<string, string> collection → status, for compact assertions */
    public function statuses(): array
    {
        $out = array_map(static fn (array $row): string => $row['status'], $this->rows);
        ksort($out);
        return $out;
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

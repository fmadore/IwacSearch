<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use RuntimeException;
use Typesense\Document;
use Typesense\Documents;

/** @internal */
final class FakeDocuments extends Documents
{
    public function __construct(
        private readonly FakeTypesense $server,
        private readonly string $collection,
    ) {
    }

    /**
     * Mirrors the real JSONL import contract: one result line per input
     * line, `{"success":true}` or `{"success":false,"error":…}`.
     *
     * @param  string $documents JSONL, one document per line.
     * @param  array<string, mixed> $options
     * @return string JSONL result lines, one per input line.
     */
    public function import($documents, array $options = []): string
    {
        $lines = array_values(array_filter(
            preg_split("/\r?\n/", trim((string) $documents)) ?: [],
            static fn(string $l): bool => $l !== ''
        ));

        $out = [];
        foreach ($lines as $line) {
            /** @var array<string,mixed> $doc */
            $doc = json_decode($line, true) ?? [];
            $ok = $this->server->importDecision === null
                || ($this->server->importDecision)($doc);
            if ($ok) {
                // Upsert semantics: a document with a known id replaces it.
                $replaced = false;
                if (isset($doc['id'])) {
                    foreach ($this->server->collections[$this->collection] ?? [] as $i => $existing) {
                        if ((string) ($existing['id'] ?? '') === (string) $doc['id']) {
                            $this->server->collections[$this->collection][$i] = $doc;
                            $replaced = true;
                            break;
                        }
                    }
                }
                if (!$replaced) {
                    $this->server->collections[$this->collection][] = $doc;
                }
                $out[] = '{"success":true}';
            } else {
                $out[] = '{"success":false,"error":"Field `x` has been declared as a string"}';
            }
        }
        return $this->server->importResponse ?? implode("\n", $out);
    }

    /**
     * Filtered bulk delete. Understands only the `id:[…]` form the module
     * sends; absent IDs are not an error, as on the real server.
     *
     * @param  array<string, mixed> $queryParams
     * @return array{num_deleted: int}
     */
    public function delete(array $queryParams = []): array
    {
        $filter = (string) ($queryParams['filter_by'] ?? '');
        $this->server->filterDeletes[] = [$this->collection, $filter];
        $ids = self::idsOf($filter);
        $before = count($this->server->collections[$this->collection] ?? []);
        $this->server->collections[$this->collection] = array_values(array_filter(
            $this->server->collections[$this->collection] ?? [],
            static fn(array $doc): bool => !in_array((string) ($doc['id'] ?? ''), $ids, true)
        ));
        return ['num_deleted' => $before - count($this->server->collections[$this->collection])];
    }

    /**
     * Filtered export (JSONL), with `include_fields` projection.
     *
     * @param array<string, mixed> $queryParams
     */
    public function export(array $queryParams = []): string
    {
        $filter = (string) ($queryParams['filter_by'] ?? '');
        $this->server->exports[] = [$this->collection, $filter];
        $ids = self::idsOf($filter);
        $fields = isset($queryParams['include_fields'])
            ? explode(',', (string) $queryParams['include_fields'])
            : null;
        $lines = [];
        foreach ($this->server->collections[$this->collection] ?? [] as $doc) {
            if (!in_array((string) ($doc['id'] ?? ''), $ids, true)) {
                continue;
            }
            $lines[] = json_encode($fields === null ? $doc : array_intersect_key($doc, array_flip($fields)));
        }
        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function idsOf(string $filter): array
    {
        if (preg_match('/^id:\[([0-9,]*)\]$/D', $filter, $m) !== 1) {
            throw new RuntimeException('Fake only understands id:[…] filters, got: ' . $filter);
        }
        return $m[1] === '' ? [] : explode(',', $m[1]);
    }

    /**
     * @param  array<string, mixed> $searchParams
     * @return array<string, mixed>
     */
    public function search(array $searchParams): array
    {
        if ($this->server->searchFailure !== null) {
            throw $this->server->searchFailure;
        }
        $this->server->searches[] = $searchParams;
        return $this->server->searchResponse ?? ['found' => 0, 'hits' => []];
    }

    public function offsetGet($documentId): Document
    {
        return new FakeDocument($this->server, $this->collection, (string) $documentId);
    }

    public function offsetExists($offset): bool
    {
        return true;
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}

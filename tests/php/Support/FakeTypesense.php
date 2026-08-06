<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Closure;
use RuntimeException;
use Typesense\Aliases;
use Typesense\Client as TypesenseClient;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;

/**
 * An in-memory stand-in for a Typesense server.
 *
 * Built by hand rather than with PHPUnit mocks because the code under test
 * navigates the client's shape — `$client->collections[$name]->documents`,
 * `$client->aliases[$alias]->retrieve()` — and expressing that as nested
 * mock expectations obscures what the test is actually asserting. Here the
 * fake just IS a tiny server: collections, an alias table, and a log of what
 * was imported and dropped.
 *
 * The real `Typesense\Client` is subclassed (it is not final) so the fake
 * satisfies the production type hints, and its typed public properties are
 * replaced with fakes of the corresponding classes.
 */
final class FakeTypesense
{
    /** @var array<string, list<array<string,mixed>>> collection name → imported docs */
    public array $collections = [];

    /** @var array<string, string> alias → collection name */
    public array $aliases = [];

    /** @var list<string> collections dropped, in order */
    public array $dropped = [];

    /**
     * Per-document import outcome. Return true to accept, false to reject
     * with an error line — how a schema mismatch shows up in real life.
     *
     * @var ?Closure(array<string,mixed>): bool
     */
    public ?Closure $importDecision = null;

    /** When set, listing collections throws — exercises the orphan-sweep guard. */
    public ?RuntimeException $listFailure = null;

    /** When set, dropping a collection throws. */
    public ?RuntimeException $dropFailure = null;

    /** When set, searching throws — exercises the degrade-to-null paths. */
    public ?RuntimeException $searchFailure = null;

    /**
     * The body `documents->search()` returns. Left null, the fake answers with
     * a well-formed empty result. Set it to a `facet_counts` payload to drive
     * the facet-value lookup, or to a malformed one to exercise its guards.
     *
     * @var ?array<string, mixed>
     */
    public ?array $searchResponse = null;

    /**
     * Every search params array received, in order — so a test can assert
     * WHAT was asked for (facet_by, the is_public guard) and how often.
     *
     * @var list<array<string, mixed>>
     */
    public array $searches = [];

    /**
     * Build a Typesense-shaped `facet_counts` body from a
     * `field => [value => count]` map, so tests read as data not plumbing.
     *
     * @param  array<string, array<string, int>> $byField
     * @return array<string, mixed>
     */
    public static function facetResponse(array $byField): array
    {
        $facetCounts = [];
        foreach ($byField as $field => $values) {
            $counts = [];
            foreach ($values as $value => $count) {
                $counts[] = ['value' => (string) $value, 'count' => $count];
            }
            $facetCounts[] = ['field_name' => $field, 'counts' => $counts];
        }
        return ['found' => 0, 'hits' => [], 'facet_counts' => $facetCounts];
    }

    public function client(): TypesenseClient
    {
        return FakeClient::create($this);
    }

    /** Register an existing collection (as a prior reindex would have left it). */
    public function seedCollection(string $name): void
    {
        $this->collections[$name] ??= [];
    }
}

/**
 * @internal Companion classes — one per Typesense client surface the module
 * touches. Kept in this file because they only exist to serve FakeTypesense.
 */
final class FakeClient
{
    public static function create(FakeTypesense $server): TypesenseClient
    {
        // Bypass the real constructor: it validates node config and builds an
        // ApiCall we are about to replace anyway.
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->collections = new FakeCollections($server);
        $client->aliases = new FakeAliases($server);
        return $client;
    }
}

/** @internal */
final class FakeCollections extends Collections
{
    public function __construct(private readonly FakeTypesense $server)
    {
    }

    /**
     * @param  array<string, mixed> $schema
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function create(array $schema, array $options = []): array
    {
        $name = (string) ($schema['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('create() called without a name');
        }
        $this->server->collections[$name] = [];
        return $schema;
    }

    /** @return list<array{name: string}> */
    public function retrieve(): array
    {
        if ($this->server->listFailure !== null) {
            throw $this->server->listFailure;
        }
        return array_map(
            static fn(string $name): array => ['name' => $name],
            array_keys($this->server->collections)
        );
    }

    public function offsetGet($offset): Collection
    {
        return new FakeCollection($this->server, (string) $offset);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->server->collections[(string) $offset]);
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}

/** @internal */
final class FakeCollection extends Collection
{
    public function __construct(
        private readonly FakeTypesense $server,
        private readonly string $name,
    ) {
        $this->documents = new FakeDocuments($server, $name);
    }

    /** @return array{name: string, num_documents: int} */
    public function retrieve(): array
    {
        if (!isset($this->server->collections[$this->name])) {
            throw new RuntimeException('Not found: no collection named ' . $this->name);
        }
        return [
            'name' => $this->name,
            'num_documents' => count($this->server->collections[$this->name]),
        ];
    }

    /**
     * @param  array<string, mixed> $options
     * @return array{name: string}
     */
    public function delete(array $options = []): array
    {
        if ($this->server->dropFailure !== null) {
            throw $this->server->dropFailure;
        }
        if (!isset($this->server->collections[$this->name])) {
            throw new RuntimeException('Not found: no collection named ' . $this->name);
        }
        unset($this->server->collections[$this->name]);
        $this->server->dropped[] = $this->name;
        return ['name' => $this->name];
    }
}

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
                $this->server->collections[$this->collection][] = $doc;
                $out[] = '{"success":true}';
            } else {
                $out[] = '{"success":false,"error":"Field `x` has been declared as a string"}';
            }
        }
        return implode("\n", $out);
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

/** @internal */
final class FakeDocument extends Document
{
    public function __construct(
        private readonly FakeTypesense $server,
        private readonly string $collection,
        private readonly string $id,
    ) {
    }

    /**
     * @param  array<string, mixed> $options
     * @return array{id: string}
     */
    public function delete(array $options = []): array
    {
        $docs = $this->server->collections[$this->collection] ?? [];
        foreach ($docs as $i => $doc) {
            if ((string) ($doc['id'] ?? '') === $this->id) {
                unset($this->server->collections[$this->collection][$i]);
                $this->server->collections[$this->collection] = array_values(
                    $this->server->collections[$this->collection]
                );
                return ['id' => $this->id];
            }
        }
        throw new RuntimeException('Could not find a document with id: ' . $this->id);
    }
}

/** @internal */
final class FakeAliases extends Aliases
{
    public function __construct(private readonly FakeTypesense $server)
    {
    }

    /**
     * @param  array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    public function upsert(string $name, array $mapping): array
    {
        $this->server->aliases[$name] = (string) $mapping['collection_name'];
        return $mapping;
    }

    public function offsetGet($offset): \Typesense\Alias
    {
        return new FakeAlias($this->server, (string) $offset);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->server->aliases[(string) $offset]);
    }

    public function offsetSet($offset, $value): void
    {
    }

    public function offsetUnset($offset): void
    {
    }
}

/** @internal */
final class FakeAlias extends \Typesense\Alias
{
    public function __construct(
        private readonly FakeTypesense $server,
        private readonly string $name,
    ) {
    }

    /** @return array{name: string, collection_name: string} */
    public function retrieve(): array
    {
        if (!isset($this->server->aliases[$this->name])) {
            throw new RuntimeException('Not found: no alias named ' . $this->name);
        }
        return ['name' => $this->name, 'collection_name' => $this->server->aliases[$this->name]];
    }
}

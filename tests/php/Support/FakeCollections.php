<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use RuntimeException;
use Typesense\Collection;
use Typesense\Collections;

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
        if ($this->server->createFailure !== null) {
            throw $this->server->createFailure;
        }
        $this->server->created[] = $schema;
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

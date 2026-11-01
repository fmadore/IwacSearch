<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

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
            throw new \Typesense\Exceptions\ObjectNotFound('Not found: no alias named ' . $this->name);
        }
        return ['name' => $this->name, 'collection_name' => $this->server->aliases[$this->name]];
    }
}

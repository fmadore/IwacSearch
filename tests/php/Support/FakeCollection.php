<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Typesense\Collection;

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
            throw new \Typesense\Exceptions\ObjectNotFound('Not found: no collection named ' . $this->name);
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
            throw new \Typesense\Exceptions\ObjectNotFound('Not found: no collection named ' . $this->name);
        }
        unset($this->server->collections[$this->name]);
        $this->server->dropped[] = $this->name;
        return ['name' => $this->name];
    }
}

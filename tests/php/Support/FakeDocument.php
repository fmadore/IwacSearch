<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Typesense\Document;

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
        $this->server->singleDeletes[] = [$this->collection, $this->id];
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
        throw new \Typesense\Exceptions\ObjectNotFound('Could not find a document with id: ' . $this->id);
    }
}

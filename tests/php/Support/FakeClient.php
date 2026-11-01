<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Typesense\Client as TypesenseClient;

/**
 * @internal A real Typesense client wired to a FakeTypesense. It and the
 * other Fake* companions beside it (one per client surface the module
 * touches: health, collections, documents, aliases) only exist to serve
 * FakeTypesense.
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
        $client->health = new FakeHealth($server);
        return $client;
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use Closure;
use Psr\Container\ContainerInterface;
use Typesense\Client as TypesenseClient;

/**
 * Returns a closure that resolves the TypesenseClient service on first
 * call, suitable for passing as a `Closure(): TypesenseClient` factory
 * arg to services that want lazy client construction.
 *
 * Three IwacSearch services share the same lazy-client pattern:
 *
 *   - InitialResponseRenderer — server-side SSR; missing-secret should
 *                               render the page without an initial
 *                               response, not 500.
 *   - TypesenseSearchKeyProvider — minting scoped keys; missing-secret
 *                                  should return 503 JSON, not 500 HTML.
 *   - IncrementalIndexer — Omeka api.*.post events; Typesense being
 *                          down should never block an admin's save.
 *
 * Each used to declare:
 *
 *   clientFactory: fn(): TypesenseClient => $container->get(TypesenseClient::class),
 *
 * which is now:
 *
 *   clientFactory: TypesenseClientLazy::fromContainer($container),
 *
 * The behavioural contract — first call resolves the service, the
 * resulting client is cached by the consumer for subsequent uses,
 * and any RuntimeException from missing/unreadable Docker secrets
 * surfaces *inside* the consumer's catch site rather than at factory
 * dispatch time — is now documented in one place.
 */
final class TypesenseClientLazy
{
    /**
     * @return Closure(): TypesenseClient
     */
    public static function fromContainer(ContainerInterface $container): Closure
    {
        return fn(): TypesenseClient => $container->get(TypesenseClient::class);
    }
}

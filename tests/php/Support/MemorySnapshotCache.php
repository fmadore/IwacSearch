<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use IwacSearch\Search\SnapshotCacheInterface;

/**
 * In-memory stand-in for the APCu-backed SSR snapshot cache.
 *
 * The real one is a deliberate no-op without the extension — which CI does
 * not have — so the caching BEHAVIOUR (what gets stored, what deliberately
 * doesn't) can only be tested against an implementation that always stores.
 * That the interface exists at all is what makes this substitution possible;
 * see SnapshotCacheInterface.
 *
 * `$store` is public so a test can assert on what was NOT cached: a failed
 * render must leave the cache empty, and there is no other way to see that.
 */
final class MemorySnapshotCache implements SnapshotCacheInterface
{
    /** @var array<string, list<array<string, mixed>|null>> */
    public array $store = [];

    public function key(array $body): string
    {
        return hash('xxh128', json_encode($body) ?: '');
    }

    public function get(string $key): ?array
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $this->store[$key] = $value;
    }
}

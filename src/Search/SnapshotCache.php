<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Short-lived cache for the server-rendered first page.
 *
 * WHY THIS IS SAFE TO SHARE BETWEEN VISITORS: the SSR path always imposes
 * `is_public:=true` and excludes `ocr_text` (see
 * {@see InitialResponseRenderer}), so the snapshot contains only what any
 * anonymous visitor may see — there is no per-user variation to leak. The
 * cache key covers the entire request body, so two surfaces with different
 * filters, sorts or facets never share an entry.
 *
 * WHY THE TTL IS SHORT: the snapshot goes stale the moment a reindex swaps
 * the alias or an incremental edit lands. Thirty seconds bounds how long a
 * visitor can see a just-deleted item on a landing page, while still
 * collapsing the burst of identical requests that a popular page produces.
 * Nothing here needs to be invalidated on write — the TTL IS the invalidation
 * strategy, deliberately, because tracking which snapshots a given item edit
 * would affect costs more than it saves.
 *
 * BACKEND: APCu when the extension is loaded and enabled, otherwise nothing.
 * A filesystem cache was considered and rejected — it would need a writable
 * path, concurrency handling and its own eviction, to speed up a request that
 * already works. If APCu is absent the module simply pays the Typesense round
 * trip it pays today, which is the current behaviour and a fine floor.
 */
final class SnapshotCache implements SnapshotCacheInterface
{
    /** Namespace so entries can't collide with anything else in the APCu store. */
    private const PREFIX = 'iwac_search.ssr.';

    public function __construct(
        private readonly int $ttlSeconds = 30,
    ) {
    }

    /**
     * Cache key for a multi_search body. The whole body is hashed, so any
     * difference in collection, filter, sort, facets or page size yields a
     * different entry.
     *
     * @param array<string, mixed> $body
     */
    public function key(array $body): string
    {
        return self::PREFIX . hash('xxh128', json_encode($body, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @return list<array<string, mixed>|null>|null null = miss (or disabled).
     */
    public function get(string $key): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $ok = false;
        /** @var mixed $hit */
        $hit = apcu_fetch($key, $ok);
        return $ok && is_array($hit) ? $hit : null;
    }

    /**
     * Store a rendered snapshot set.
     *
     * Callers should only store SUCCESSFUL renders: caching a null would pin
     * a transient Typesense outage in front of every visitor for the whole
     * TTL, turning a blip into an outage.
     *
     * @param list<array<string, mixed>|null> $value
     */
    public function set(string $key, array $value): void
    {
        if ($this->enabled()) {
            apcu_store($key, $value, $this->ttlSeconds);
        }
    }

    private function enabled(): bool
    {
        return $this->ttlSeconds > 0
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Short-lived cache for the server-rendered first page.
 *
 * WHY THIS IS SAFE TO SHARE BETWEEN VISITORS: the SSR path always imposes
 * `is_public:=true` and the {@see PublicSearchPolicy} projection (see
 * {@see InitialResponseRenderer}), so the snapshot contains only what any
 * anonymous visitor may see — there is no per-user variation to leak. The
 * cache key covers the entire request body, so two surfaces with different
 * filters, sorts or facets never share an entry.
 *
 * INVALIDATION: the renderer folds a change epoch into the key
 * (ChangeJournal::cacheVersion(): the journal watermark, the applied-change
 * watermark and the promoted generations), so a recorded write, a drained
 * batch or a completed rebuild moves every surface to a fresh entry without
 * tracking which snapshots an edit affects. The short TTL is the backstop
 * for anything the epoch cannot see (direct SQL edits, a manual alias
 * change) while still collapsing the burst of identical requests that a
 * popular page produces.
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

    public function markUnavailable(int $seconds): void
    {
        if ($this->enabled()) {
            apcu_store(self::PREFIX . 'unavailable', true, max(1, $seconds));
        }
    }

    public function isUnavailable(): bool
    {
        return $this->enabled() && apcu_fetch(self::PREFIX . 'unavailable') === true;
    }

    public function enabled(): bool
    {
        return $this->ttlSeconds > 0
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}

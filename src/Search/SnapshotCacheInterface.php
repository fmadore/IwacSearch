<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Storage for server-rendered first-page snapshots.
 *
 * An interface rather than a concrete dependency because the backend is a
 * deployment choice, not a design one: {@see SnapshotCache} uses APCu (and
 * no-ops without it), but a filesystem or Redis backend would slot in
 * without the renderer knowing. It also lets the renderer's tests assert
 * caching behaviour on a build that has no APCu — which CI does not.
 */
interface SnapshotCacheInterface
{
    /**
     * Whether entries are stored at all. A disabled cache lets the renderer
     * skip computing the key — including the change epoch's SQL.
     */
    public function enabled(): bool;

    /**
     * Cache key for a multi_search body. Implementations MUST derive it from
     * the whole body, so that surfaces differing in collection, filter, sort,
     * facets or page size never share an entry.
     *
     * @param array<string, mixed> $body
     */
    public function key(array $body): string;

    /**
     * @return list<array<string, mixed>|null>|null null = miss (or disabled).
     */
    public function get(string $key): ?array;

    /**
     * @param list<array<string, mixed>|null> $value
     */
    public function set(string $key, array $value): void;

    /**
     * Remember, for $seconds, that Typesense could not be reached. A failed
     * render is never cached as a snapshot (that would pin a blip in front of
     * every visitor), but without this every page view during a slowdown
     * paid the full connection + read timeout before falling back to the
     * client — seconds per page, for a first page the client then fetched
     * anyway.
     */
    public function markUnavailable(int $seconds): void;

    /** Whether a recent render found Typesense unreachable. */
    public function isUnavailable(): bool;
}

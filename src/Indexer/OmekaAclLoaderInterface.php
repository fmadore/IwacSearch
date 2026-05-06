<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Contract for "is this Omeka item publicly visible right now?".
 *
 * Two implementations:
 *
 *   - {@see OmekaAclLoader}     — paginates the public REST API over HTTP.
 *                                 Used by cli/reindex.php (no Omeka
 *                                 service container available outside
 *                                 the HTTP request bootstrap).
 *
 *   - {@see ApiOmekaAclLoader}  — uses Omeka's in-process Api Manager.
 *                                 Used by IwacSearch\Job\BulkReindex —
 *                                 the docker container running the job
 *                                 has no route back to its own public
 *                                 DNS, so HTTP via islam.zmo.de fails
 *                                 with a connection error. The internal
 *                                 ApiManager bypasses HTTP entirely.
 *
 * Both share an identical public surface so {@see Reindexer} can take
 * either backend without caring which one.
 */
interface OmekaAclLoaderInterface
{
    /**
     * Eagerly resolve and cache the full set of public Omeka item IDs.
     * Idempotent — subsequent calls are no-ops once the cache is built.
     */
    public function prime(): void;

    /**
     * Whether the given Omeka o:id is currently public. Triggers
     * prime() lazily on first call.
     */
    public function isPublic(int $omekaId): bool;

    /**
     * Number of public IDs currently cached. 0 before prime().
     */
    public function size(): int;
}

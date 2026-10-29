<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * The record of every Typesense collection this module builds.
 *
 * Typesense keeps every collection in RAM and has no notion of who created
 * one, so before the ledger the only way to find leftovers was a name regex
 * over the whole server ({@see CollectionRetention}'s legacy sweep). The
 * ledger records a build BEFORE its collection is created, which makes three
 * things exact rather than guessed:
 *
 *   - a rebuild killed mid-import leaves a `building` row, and the next
 *     rebuild (or prune) drops that collection ({@see GenerationCleanup});
 *   - each promotion keeps exactly the live generation and the one it
 *     replaced (`previous`, the rollback target) per alias and retires the
 *     rest, which are then deleted at once instead of after seven days;
 *   - uninstall can name the collections it leaves behind.
 *
 * Whatever the ledger says, a collection that any alias targets is never
 * deleted: the alias table on the server is checked at deletion time.
 *
 * {@see DatabaseGenerationLedger} is the production implementation; the
 * interface exists so the cleanup policy can be tested without a database.
 */
interface GenerationLedger
{
    /** Recorded before the collection is created; a row left in it marks an orphan. */
    public const BUILDING = 'building';
    /** The collection an alias serves. */
    public const LIVE = 'live';
    /** The collection an alias served before the last promotion: the rollback target. */
    public const PREVIOUS = 'previous';
    /** Superseded; deleted by the next cleanup unless an alias still targets it. */
    public const RETIRED = 'retired';

    /** Record a build of $collection for $alias, before the create request is sent. */
    public function recordBuilding(string $collection, string $alias): void;

    /**
     * $live now serves $alias. $previous — the alias target it replaced, null
     * on a first install — becomes the rollback target, and every other
     * live or previous generation of $alias is retired. One transaction.
     */
    public function recordPromotion(string $alias, string $live, ?string $previous): void;

    /** Set the status of a recorded collection (no-op for an unrecorded one). */
    public function mark(string $collection, string $status): void;

    /** Drop the row of a collection that no longer exists. */
    public function forget(string $collection): void;

    /**
     * Recorded generations, optionally only those in the given statuses.
     *
     * @return array<string, array{alias: string, status: string}> collection name → row
     */
    public function generations(string ...$statuses): array;
}

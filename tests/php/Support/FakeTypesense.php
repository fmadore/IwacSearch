<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Closure;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * An in-memory stand-in for a Typesense server.
 *
 * Built by hand rather than with PHPUnit mocks because the code under test
 * navigates the client's shape — `$client->collections[$name]->documents`,
 * `$client->aliases[$alias]->retrieve()` — and expressing that as nested
 * mock expectations obscures what the test is actually asserting. Here the
 * fake just IS a tiny server: collections, an alias table, and a log of what
 * was imported and dropped.
 *
 * The real `Typesense\Client` is subclassed (it is not final) so the fake
 * satisfies the production type hints, and its typed public properties are
 * replaced with fakes of the corresponding classes.
 */
final class FakeTypesense
{
    /** @var array<string, list<array<string,mixed>>> collection name → imported docs */
    public array $collections = [];

    /** @var array<string, string> alias → collection name */
    public array $aliases = [];

    /** @var list<string> collections dropped, in order */
    public array $dropped = [];

    /** @var list<array<string, mixed>> schemas passed to collections->create(), in order */
    public array $created = [];

    /**
     * Per-document import outcome. Return true to accept, false to reject
     * with an error line — how a schema mismatch shows up in real life.
     *
     * @var ?Closure(array<string,mixed>): bool
     */
    public ?Closure $importDecision = null;
    public ?string $importResponse = null;

    /** When set, listing collections throws. */
    public ?RuntimeException $listFailure = null;

    /** When set, listing aliases throws — cleanup must then delete nothing. */
    public ?RuntimeException $aliasListFailure = null;

    /** When set, dropping a collection throws. */
    public ?RuntimeException $dropFailure = null;

    /** When set, creating a collection throws (after the fake has NOT created it). */
    public ?RuntimeException $createFailure = null;

    /**
     * What successive /health calls answer: an array is returned, a
     * throwable thrown; the last entry repeats. Empty = always ready.
     *
     * @var list<array<string, mixed>|RuntimeException>
     */
    public array $health = [];

    /** Number of /health calls received. */
    public int $healthCalls = 0;

    /** When set, searching throws — exercises the degrade-to-null paths. */
    public ?RuntimeException $searchFailure = null;

    /**
     * Every filtered (bulk) delete and export received, in order, as
     * `[collection, filter_by]` — so a test can assert request COUNTS, which
     * is the point of batching.
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $filterDeletes = [];

    /** @var list<array{0: string, 1: string}> */
    public array $exports = [];

    /**
     * Single-document deletes received, as `[collection, id]`.
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $singleDeletes = [];

    /**
     * The body `documents->search()` returns. Left null, the fake answers with
     * a well-formed empty result. Set it to a `facet_counts` payload to drive
     * the facet-value lookup, or to a malformed one to exercise its guards.
     *
     * @var ?array<string, mixed>
     */
    public ?array $searchResponse = null;

    /**
     * Every search params array received, in order — so a test can assert
     * WHAT was asked for (facet_by, the is_public guard) and how often.
     *
     * @var list<array<string, mixed>>
     */
    public array $searches = [];

    /**
     * Build a Typesense-shaped `facet_counts` body from a
     * `field => [value => count]` map, so tests read as data not plumbing.
     *
     * @param  array<string, array<string, int>> $byField
     * @return array<string, mixed>
     */
    public static function facetResponse(array $byField): array
    {
        $facetCounts = [];
        foreach ($byField as $field => $values) {
            $counts = [];
            foreach ($values as $value => $count) {
                $counts[] = ['value' => (string) $value, 'count' => $count];
            }
            $facetCounts[] = ['field_name' => $field, 'counts' => $counts];
        }
        return ['found' => 0, 'hits' => [], 'facet_counts' => $facetCounts];
    }

    public function client(): TypesenseClient
    {
        return FakeClient::create($this);
    }

    /** Register an existing collection (as a prior reindex would have left it). */
    public function seedCollection(string $name): void
    {
        $this->collections[$name] ??= [];
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Support;

/**
 * What a test double was asked to do, in order.
 *
 * Test doubles need to hand their call history back to the test that built
 * them. The obvious way — a by-reference array property on an anonymous
 * class — works at runtime but reads as write-only to a static analyser,
 * which cannot follow a reference from one object into another. Passing this
 * shared object instead keeps the same ergonomics, types the entries once,
 * and stays honestly analysable.
 *
 * Entries are keyed maps rather than positional tuples so an assertion reads
 * as `$log->entries[0]['searches']`, not `[0][1]`.
 */
final class CallLog
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    /** @param array<string, mixed> $entry */
    public function record(array $entry): void
    {
        $this->entries[] = $entry;
    }

    /** Number of calls recorded so far — also useful for numbering fakes. */
    public function count(): int
    {
        return count($this->entries);
    }
}

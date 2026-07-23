<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Accumulates, during the content reindex pass, how each authority entity is
 * referenced — so the entity collection can carry the occurrence metrics that
 * the former HF `index` subset used to precompute (frequency / first–last year
 * / the set of countries it appears in).
 *
 * The content Reindexer calls record() with every content document it builds;
 * IndexReindexer then asks aggregate() per entity. Only PUBLIC content counts
 * toward an entity's frequency — matching the historical HF figure, which was
 * computed over the public-only content subsets.
 */
final class EntityOccurrences
{
    /**
     * Per-entity accumulator. `by_year` is a year → count histogram built
     * incrementally (not a raw list of every occurrence year — a heavily
     * cited entity would otherwise hold thousands of ints for aggregate()
     * to rescan).
     *
     * @var array<int, array{count:int, by_year:array<int,int>, countries:array<string,true>}>
     */
    private array $byEntity = [];

    /**
     * Record one content document's contribution to its referenced entities.
     *
     * @param array<string, mixed> $doc a built content document
     */
    public function record(array $doc): void
    {
        if (($doc['is_public'] ?? false) !== true) {
            return; // frequency reflects public occurrences only
        }
        /** @var list<int> $ids */
        $ids = $doc['entity_ids'] ?? [];
        if ($ids === []) {
            return;
        }
        $year = $doc['pub_year'] ?? null;
        /** @var list<string> $countries */
        $countries = $doc['country_ss'] ?? [];

        foreach ($ids as $eid) {
            if (!isset($this->byEntity[$eid])) {
                $this->byEntity[$eid] = ['count' => 0, 'by_year' => [], 'countries' => []];
            }
            $this->byEntity[$eid]['count']++;
            if (is_int($year)) {
                $this->byEntity[$eid]['by_year'][$year] = ($this->byEntity[$eid]['by_year'][$year] ?? 0) + 1;
            }
            foreach ($countries as $c) {
                $this->byEntity[$eid]['countries'][$c] = true;
            }
        }
    }

    /**
     * @return array{
     *   frequency:int, countries:list<string>, first_year:?int, last_year:?int,
     *   mentions_by_year:array<int,int>
     * }
     */
    public function aggregate(int $entityId): array
    {
        $e = $this->byEntity[$entityId] ?? null;
        if ($e === null) {
            return [
                'frequency' => 0, 'countries' => [], 'first_year' => null,
                'last_year' => null, 'mentions_by_year' => [],
            ];
        }
        // Per-year occurrence counts (ascending), for the entity-card sparkline.
        $byYear = $e['by_year'];
        ksort($byYear);
        $years = array_keys($byYear);
        return [
            'frequency'  => $e['count'],
            'countries'  => array_keys($e['countries']),
            'first_year' => $years !== [] ? $years[0] : null,
            'last_year'  => $years !== [] ? $years[count($years) - 1] : null,
            'mentions_by_year' => $byYear,
        ];
    }
}

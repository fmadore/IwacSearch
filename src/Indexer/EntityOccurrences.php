<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Accumulates, during the content reindex pass, how each authority entity is
 * referenced — so the entity collection can carry the occurrence metrics the
 * HF `index` subset used to precompute (frequency / first–last year / the set
 * of countries it appears in).
 *
 * The content Reindexer calls record() with every content document it builds;
 * IndexReindexer then asks aggregate() per entity. Only PUBLIC content counts
 * toward an entity's frequency — matching the HF figure, which was computed
 * over the public-only content subsets.
 */
final class EntityOccurrences
{
    /** @var array<int, array{count:int, years:list<int>, countries:array<string,true>}> */
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
                $this->byEntity[$eid] = ['count' => 0, 'years' => [], 'countries' => []];
            }
            $this->byEntity[$eid]['count']++;
            if (is_int($year)) {
                $this->byEntity[$eid]['years'][] = $year;
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
        $years = $e['years'];
        // Per-year occurrence counts (ascending), for the entity-card sparkline.
        $byYear = [];
        foreach ($years as $y) {
            $byYear[$y] = ($byYear[$y] ?? 0) + 1;
        }
        ksort($byYear);
        return [
            'frequency'  => $e['count'],
            'countries'  => array_keys($e['countries']),
            'first_year' => $years !== [] ? min($years) : null,
            'last_year'  => $years !== [] ? max($years) : null,
            'mentions_by_year' => $byYear,
        ];
    }
}

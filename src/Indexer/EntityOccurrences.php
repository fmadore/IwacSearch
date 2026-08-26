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
 *
 * WHAT COUNTS AS AN OCCURRENCE (v3.17.0 — read this before narrowing it).
 * Every ROLE an entity can play in a document counts: subject and spatial
 * headings, authorship (dcterms:creator; bibo:authorList + bibo:editorList on
 * references), and the publisher on the two subsets where the HF pipeline
 * counts it (references and audiovisual — that is what gives a YouTube
 * channel a real figure). One item counts ONCE per entity however many roles
 * it played there, so frequency is a document count, not a role count.
 *
 * This mirrors `FREQUENCY_SOURCE_FIELDS` in the IWAC-Hugging-Face
 * `index/upload_index_hf.py`, which is the definition the published `index`
 * subset and the IWAC MCP both serve. Between v2.0.0 (the HF→MySQL migration)
 * and v3.17.0 this class counted subject + spatial ONLY while its docblock
 * still claimed HF parity: an author-only authority record rendered a bare
 * "0 mentions" on the search page directly above its own signed articles,
 * against a frequency of 8 for the same person on Hugging Face. ~3,045
 * entities were affected. Do not re-narrow this without changing HF too.
 *
 * `authored_count` is tracked separately on top — not to replace frequency,
 * but so a card can say "8 mentions · 8 as author" and a reader can tell
 * being written about from having written.
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
     * Signed-document counts — a SUBSET of the frequency above, not a rival to
     * it. Authorship only: the publisher role is deliberately excluded, since
     * a newspaper does not write its articles.
     *
     * Exists so the entity card can break the headline number down ("8
     * mentions · 8 as author") and so "most prolific author" is one sort away.
     * Reading it as the fix for the 0-mentions bug would be backwards — the
     * fix is that authorship now reaches `frequency` at all.
     *
     * @var array<int, int>
     */
    private array $authoredByEntity = [];

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
        /** @var list<int> $authorIds */
        $authorIds = $doc['author_entity_ids'] ?? [];
        foreach (array_unique($authorIds) as $aid) {
            $this->authoredByEntity[$aid] = ($this->authoredByEntity[$aid] ?? 0) + 1;
        }

        // Union of every role, deduped: an entity that is both the subject and
        // the author of one article is ONE occurrence, not two. Without the
        // dedupe, frequency would mix two quantities — how many documents
        // mention the entity, and in how many fields — and only multi-role
        // entities would be inflated, which is exactly the bug the HF
        // implementation calls out in _accumulate_term_stats.
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_merge(
            $doc['entity_ids'] ?? [],
            $authorIds,
            $doc['publisher_entity_ids'] ?? [],
        )));
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
     *   frequency:int, authored_count:int, countries:list<string>,
     *   first_year:?int, last_year:?int, mentions_by_year:array<int,int>
     * }
     */
    public function aggregate(int $entityId): array
    {
        $authored = $this->authoredByEntity[$entityId] ?? 0;
        $e = $this->byEntity[$entityId] ?? null;
        if ($e === null) {
            // An author-only entity lands here: no mentions, but the authored
            // count still has to reach the document.
            return [
                'frequency' => 0, 'authored_count' => $authored, 'countries' => [],
                'first_year' => null, 'last_year' => null, 'mentions_by_year' => [],
            ];
        }
        // Per-year occurrence counts (ascending), for the entity-card sparkline.
        $byYear = $e['by_year'];
        ksort($byYear);
        $years = array_keys($byYear);
        return [
            'frequency'  => $e['count'],
            'authored_count' => $authored,
            'countries'  => array_keys($e['countries']),
            'first_year' => $years !== [] ? $years[0] : null,
            'last_year'  => $years !== [] ? $years[count($years) - 1] : null,
            'mentions_by_year' => $byYear,
        ];
    }
}

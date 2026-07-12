<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Generator;
use Omeka\Entity\Item;

/**
 * Reads content + authority records straight from the Omeka S MySQL database
 * via Doctrine DBAL — the replacement for the Hugging Face ingestion path.
 *
 * This is the ONLY class that touches SQL. Everything above it (mappers,
 * reindexers) works on the plain arrays it yields, so the rest of the indexer
 * is transport-agnostic and unit-testable without a database.
 *
 * The query shapes are lifted from the sibling DRE-Search module's proven
 * reindexer: keyset pagination over `resource` (by id), one grouped
 * `value`-join per page for the configured property terms, and a media-table
 * lookup for thumbnails. Memory stays flat regardless of corpus size — only
 * one page (500 items) of values is held at a time.
 *
 * Omeka schema touched (read-only):
 *   resource(id, title, is_public, resource_type, resource_class_id)
 *   value(resource_id, property_id, value, uri, value_resource_id)
 *   property(id, vocabulary_id, local_name) · vocabulary(id, prefix)
 *   item_item_set(item_id, item_set_id) · media(item_id, storage_id, …)
 */
final class OmekaSourceReader
{
    /** Items read per keyset page. */
    private const PAGE = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Stream items of the given resource classes, each already enriched with
     * its grouped property values and (optionally) a thumbnail URL — so the
     * caller just maps. Value-loading is batched per keyset page, so a full
     * corpus costs ~ (rows / 500) value queries, not one per item.
     *
     * @param  list<int>      $classIds  resource_class_id allowlist (e.g. [36] for articles)
     * @param  list<string>   $terms     property terms to SELECT (e.g. ['dcterms:title', …])
     * @param  list<int>|null $itemSetIds optional item-set scope (AND membership)
     * @param  bool           $withThumbnail resolve the item's first thumbnailed media
     * @return Generator<int, array{
     *     item: array{id:int,title:string,is_public:bool,class:int,item_sets:list<int>},
     *     values: array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>>,
     *     thumbnail: ?string
     * }>
     */
    public function streamDocs(
        array $classIds,
        array $terms,
        ?array $itemSetIds = null,
        bool $withThumbnail = true,
    ): Generator {
        if ($classIds === []) {
            return;
        }
        $classList = implode(',', array_map('intval', $classIds));

        $setClause = '';
        if ($itemSetIds !== null && $itemSetIds !== []) {
            $setList = implode(',', array_map('intval', $itemSetIds));
            $setClause = " AND id IN (SELECT item_id FROM item_item_set WHERE item_set_id IN ($setList))";
        }

        // resource_class_id and the item-set list are validated ints inlined into
        // the SQL; :rt and :lastId stay the only bound params so keyset paging is
        // a stable prepared statement.
        $sql = 'SELECT id, title, is_public, resource_class_id FROM resource'
            . ' WHERE resource_type = :rt'
            . ' AND resource_class_id IN (' . $classList . ')'
            . ' AND id > :lastId'
            . $setClause
            . ' ORDER BY id ASC LIMIT ' . self::PAGE;

        $lastId = 0;
        while (true) {
            $rows = $this->connection
                ->executeQuery($sql, ['rt' => Item::class, 'lastId' => $lastId])
                ->fetchAllAssociative();
            if ($rows === []) {
                break;
            }

            $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
            $lastId = (int) end($ids);

            $valuesByItem = $this->loadValues($ids, $terms);
            $sets = $this->loadItemSets($ids);
            $thumbs = $withThumbnail ? $this->mediaThumbnails($ids) : [];

            foreach ($rows as $r) {
                $id = (int) $r['id'];
                yield [
                    'item' => [
                        'id'        => $id,
                        'title'     => (string) ($r['title'] ?? ''),
                        'is_public' => (bool) $r['is_public'],
                        'class'     => (int) $r['resource_class_id'],
                        'item_sets' => $sets[$id] ?? [],
                    ],
                    'values'    => $valuesByItem[$id] ?? [],
                    'thumbnail' => $thumbs[$id] ?? null,
                ];
            }
        }
    }

    /**
     * Load specific resources by id (resource_type = Item), each enriched with
     * its values / item-sets / thumbnail — the same shape streamDocs() yields.
     * Used by the incremental indexer (the one changed content item) and by
     * EntityAuthority's on-demand entity loading.
     *
     * @param  list<int>    $ids
     * @param  list<string> $terms
     * @return array<int, array{
     *     item: array{id:int,title:string,is_public:bool,class:int,item_sets:list<int>},
     *     values: array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>>,
     *     thumbnail: ?string
     * }>
     */
    public function loadResources(array $ids, array $terms): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }
        $rows = $this->connection->executeQuery(
            'SELECT id, title, is_public, resource_class_id FROM resource'
            . ' WHERE resource_type = :rt AND id IN (' . $idList . ')',
            ['rt' => Item::class],
        )->fetchAllAssociative();
        if ($rows === []) {
            return [];
        }

        $found = array_map(static fn(array $r): int => (int) $r['id'], $rows);
        $valuesByItem = $this->loadValues($found, $terms);
        $sets = $this->loadItemSets($found);
        $thumbs = $this->mediaThumbnails($found);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[$id] = [
                'item' => [
                    'id'        => $id,
                    'title'     => (string) ($r['title'] ?? ''),
                    'is_public' => (bool) $r['is_public'],
                    'class'     => (int) $r['resource_class_id'],
                    'item_sets' => $sets[$id] ?? [],
                ],
                'values'    => $valuesByItem[$id] ?? [],
                'thumbnail' => $thumbs[$id] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Grouped property values for a set of resource ids, limited to the given
     * terms. Returns, per resource: term → list of value rows, each carrying
     * the linked-resource id + its title (for value_resource links), the
     * literal value, the uri, and the VALUE-LEVEL visibility (`vpub`,
     * value.is_public — drives the has_fulltext flag: licensing-restricted
     * bibo:content is indexed but flagged as not publicly readable).
     * Mirrors DRE-Search::loadValues.
     *
     * @param  list<int>    $ids
     * @param  list<string> $terms
     * @return array<int, array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}>>>
     */
    public function loadValues(array $ids, array $terms): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '' || $terms === []) {
            return [];
        }
        // Escape single quotes defensively though terms are code-defined.
        $termList = "'" . implode("','", array_map(
            static fn(string $t): string => str_replace("'", "''", $t),
            $terms
        )) . "'";

        $sql = "SELECT v.resource_id AS rid, CONCAT(vo.prefix, ':', p.local_name) AS term,"
            . ' v.value_resource_id AS vrid, v.value AS val, v.uri AS turi, v.is_public AS vpub,'
            . ' t.title AS ttitle'
            . ' FROM value v'
            . ' JOIN property p ON v.property_id = p.id'
            . ' JOIN vocabulary vo ON p.vocabulary_id = vo.id'
            . ' LEFT JOIN resource t ON v.value_resource_id = t.id'
            . " WHERE v.resource_id IN ($idList)"
            . " AND CONCAT(vo.prefix, ':', p.local_name) IN ($termList)"
            // Preserve insertion order within a property (Omeka value id order).
            . ' ORDER BY v.resource_id ASC, v.id ASC';

        $out = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $rid = (int) $row['rid'];
            $out[$rid][(string) $row['term']][] = [
                'vrid'  => $row['vrid'] !== null ? (int) $row['vrid'] : null,
                'value' => $row['val'] !== null ? (string) $row['val'] : null,
                'uri'   => $row['turi'] !== null ? (string) $row['turi'] : null,
                'title' => $row['ttitle'] !== null ? (string) $row['ttitle'] : null,
                'vpub'  => (bool) $row['vpub'],
            ];
        }
        return $out;
    }

    /**
     * Item-set membership per resource id (for the 244 Sujets-vs-Notices split
     * and references' country-by-set derivation). Returns id → list of set ids.
     *
     * @param  list<int> $ids
     * @return array<int, list<int>>
     */
    public function loadItemSets(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }
        $sql = "SELECT item_id AS iid, item_set_id AS sid FROM item_item_set WHERE item_id IN ($idList)";
        $out = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $out[(int) $row['iid']][] = (int) $row['sid'];
        }
        return $out;
    }

    /**
     * First thumbnailed media derivative URL per item (lowest position). The
     * medium derivative path Omeka serves at /files/medium/<storage_id>.jpg.
     * Mirrors DRE-Search::mediaThumbnails.
     *
     * @param  list<int> $ids
     * @return array<int, string>
     */
    public function mediaThumbnails(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        if ($idList === '') {
            return [];
        }
        $sql = 'SELECT m.item_id AS iid, m.storage_id AS sid FROM media m'
            . " WHERE m.item_id IN ($idList) AND m.has_thumbnails = 1"
            . ' ORDER BY m.item_id ASC, m.position ASC, m.id ASC';

        $out = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllAssociative() as $row) {
            $iid = (int) $row['iid'];
            if (isset($out[$iid])) {
                continue; // keep the first (lowest position) only
            }
            $sid = (string) ($row['sid'] ?? '');
            if ($sid !== '') {
                $out[$iid] = '/files/medium/' . $sid . '.jpg';
            }
        }
        return $out;
    }
}

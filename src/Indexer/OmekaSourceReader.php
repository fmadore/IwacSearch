<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\ArrayParameterType;
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
     *     values: PropertyValues,
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

        // The one place that still inlines ids rather than binding an
        // ArrayParameterType list (every other query here binds): the class and
        // item-set lists are LOOP-INVARIANT, so inlining them keeps :rt and
        // :lastId the only bound params and the keyset page a single stable
        // prepared statement re-executed per page. Both are cast through
        // intval, so the inlining is safe by construction.
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
                    'values'    => PropertyValues::fromRows($valuesByItem[$id] ?? []),
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
     *     values: PropertyValues,
     *     thumbnail: ?string
     * }>
     */
    public function loadResources(array $ids, array $terms): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->executeQuery(
            'SELECT id, title, is_public, resource_class_id FROM resource'
            . ' WHERE resource_type = :rt AND id IN (:ids)',
            ['rt' => Item::class, 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
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
                'values'    => PropertyValues::fromRows($valuesByItem[$id] ?? []),
                'thumbnail' => $thumbs[$id] ?? null,
            ];
        }
        return $out;
    }

    /**
     * The DATABASE's current timestamp, as a `Y-m-d H:i:s` string.
     *
     * Deliberately not PHP's `date()`: the watermark it produces is compared
     * against `resource.modified`, which MySQL writes with its own clock. In
     * a container split (php + mysql) those clocks can disagree by seconds,
     * and a watermark that runs ahead of the database silently drops the very
     * edits it exists to catch.
     */
    public function databaseNow(): string
    {
        return (string) $this->connection->executeQuery('SELECT NOW()')->fetchOne();
    }

    /**
     * Ids of items created or modified at/after $since — the edits a bulk
     * reindex has to replay because they landed in the outgoing collection
     * while the new one was being built.
     *
     * No class filter on purpose: an item whose class was edited AWAY from a
     * content class during the build must come back too, so the caller can
     * delete the document it left behind. `modified` is NULL until a resource
     * is first edited, hence the `created` leg.
     *
     * @return list<int> ascending
     */
    public function idsModifiedSince(string $since): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT id FROM resource'
            . ' WHERE resource_type = :rt'
            . ' AND (modified >= :since OR created >= :since)'
            . ' ORDER BY id ASC',
            ['rt' => Item::class, 'since' => $since],
        )->fetchFirstColumn();

        return array_map('intval', $rows);
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
        if ($ids === [] || $terms === []) {
            return [];
        }

        $sql = "SELECT v.resource_id AS rid, CONCAT(vo.prefix, ':', p.local_name) AS term,"
            . ' v.value_resource_id AS vrid, v.value AS val, v.uri AS turi, v.is_public AS vpub,'
            . ' t.title AS ttitle'
            . ' FROM value v'
            . ' JOIN property p ON v.property_id = p.id'
            . ' JOIN vocabulary vo ON p.vocabulary_id = vo.id'
            . ' LEFT JOIN resource t ON v.value_resource_id = t.id'
            . ' WHERE v.resource_id IN (:ids)'
            . " AND CONCAT(vo.prefix, ':', p.local_name) IN (:terms)"
            // Preserve insertion order within a property (Omeka value id order).
            . ' ORDER BY v.resource_id ASC, v.id ASC';

        $params = ['ids' => $ids, 'terms' => $terms];
        $types  = ['ids' => ArrayParameterType::INTEGER, 'terms' => ArrayParameterType::STRING];

        $out = [];
        foreach ($this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative() as $row) {
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
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT item_id AS iid, item_set_id AS sid FROM item_item_set WHERE item_id IN (:ids)';
        $out = [];
        $rows = $this->connection
            ->executeQuery($sql, ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER])
            ->fetchAllAssociative();
        foreach ($rows as $row) {
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
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT m.item_id AS iid, m.storage_id AS sid FROM media m'
            . ' WHERE m.item_id IN (:ids) AND m.has_thumbnails = 1'
            . ' ORDER BY m.item_id ASC, m.position ASC, m.id ASC';

        $out = [];
        $rows = $this->connection
            ->executeQuery($sql, ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER])
            ->fetchAllAssociative();
        foreach ($rows as $row) {
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

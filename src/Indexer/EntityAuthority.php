<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * In-memory authority lookup built directly from the Omeka entity classes —
 * the MySQL-native replacement for the HF-`index`-driven AuthorityResolver.
 *
 * Two jobs, one cache:
 *
 *   1. CONTENT resolution. Content items link entities via `dcterms:subject`
 *      and `dcterms:spatial` as value_resource links. resolve() takes those
 *      target ids and buckets them by the TARGET's resource class — so a
 *      subject pointing at a foaf:Person (94) lands in persons_ss, a
 *      bibo:Event (54) in events_ss, etc. This is strictly more accurate than
 *      the old HF path, which matched on the Titre string and silently
 *      dropped anything it couldn't resolve.
 *
 *   2. ENTITY collection source. entities() yields one record per authority
 *      item for IndexReindexer to turn into the browsable `iwac_index`
 *      collection. The occurrence metrics (frequency / first–last year /
 *      countries) are NOT here — they're accumulated during the content pass
 *      and merged in by the reindexer.
 *
 * Class → bucket / type map (from the iwac-data structure reference):
 *   94  foaf:Person        → persons_ss        / "Personnes"
 *   96  foaf:Organization  → organisations_ss  / "Organisations"
 *   9   dcterms:Location   → places_ss         / "Lieux"
 *   54  bibo:Event         → events_ss         / "Événements"
 *   244 fabio:AuthorityFile→ topics_ss         / "Sujets"  (in item-set 1)
 *                          → (no content bucket)/ "Notices d'autorité" (set 267)
 *
 * "Notices d'autorité" entities are browsable in the entity collection but are
 * NOT resolved into content facets (bucket = null), matching the old HF
 * behaviour where only Sujets fed topics_ss.
 */
final class EntityAuthority
{
    public const CLASS_IDS = [94, 96, 9, 54, 244];

    /** Property terms the build needs beyond the resource.title column. */
    public const READ_TERMS = [
        'dcterms:alternative',
        'dcterms:description',
        'curation:coordinates',
        'dcterms:identifier',
    ];

    private const AUTHORITY_FILE_CLASS = 244;
    private const SET_NOTICES = 267;

    /** @var array<int, array{
     *   class:int, type:string, bucket:?string, title:string, aliases:list<string>,
     *   description:string, coordinates:string, identifier:string,
     *   thumbnail:?string, is_public:bool
     * }> */
    private array $byId = [];

    private bool $built = false;

    /**
     * Populate the cache by streaming the entity classes from MySQL.
     *
     * Idempotent: clears prior state first. The 244 Sujets-vs-Notices split
     * needs item-set membership, so it's resolved in a second batched pass
     * over the authority-file ids only.
     */
    public function build(OmekaSourceReader $reader): self
    {
        $this->byId = [];

        foreach ($reader->streamDocs(self::CLASS_IDS, self::READ_TERMS, null, true) as $doc) {
            $item = $doc['item'];
            $values = $doc['values'];

            [$type, $bucket] = $this->classDefault($item['class']);
            // Refine authority files (class 244): an item in the Notices
            // d'autorité set (267) is browsable as its own type but is NEVER a
            // content facet; everything else on 244 is a subject heading
            // (the classDefault already mapped it to Sujets/topics_ss).
            if ($item['class'] === self::AUTHORITY_FILE_CLASS
                && in_array(self::SET_NOTICES, $item['item_sets'], true)
            ) {
                $type = "Notices d'autorité";
                $bucket = null;
            }

            $this->byId[$item['id']] = [
                'class'       => $item['class'],
                'type'        => $type,
                'bucket'      => $bucket,
                'title'       => $item['title'],
                'aliases'     => $this->literals($values, 'dcterms:alternative'),
                'description' => $this->firstLiteral($values, 'dcterms:description'),
                'coordinates' => $this->firstLiteral($values, 'curation:coordinates'),
                'identifier'  => $this->firstLiteral($values, 'dcterms:identifier'),
                'thumbnail'   => $doc['thumbnail'],
                'is_public'   => $item['is_public'],
            ];
        }

        $this->built = true;
        return $this;
    }

    /**
     * Resolve a list of linked-resource ids (the value_resource_id of every
     * dcterms:subject / dcterms:spatial value on a content item) into the
     * per-bucket entity facets, the flat id list, and the alias FTS field.
     *
     * @param  list<int> $linkedIds
     * @return array{
     *   persons_ss?:list<string>, organisations_ss?:list<string>,
     *   places_ss?:list<string>, events_ss?:list<string>, topics_ss?:list<string>,
     *   entity_ids?:list<int>, entity_aliases_txt?:list<string>
     * }
     */
    public function resolve(array $linkedIds): array
    {
        if (!$this->built) {
            throw new \LogicException('EntityAuthority::resolve called before build()');
        }
        if ($linkedIds === []) {
            return [];
        }

        $buckets = [];
        $entityIds = [];
        $aliases = [];

        foreach ($linkedIds as $id) {
            $e = $this->byId[$id] ?? null;
            if ($e === null || $e['bucket'] === null) {
                continue; // unknown target, or a "Notices d'autorité" (not a facet)
            }
            $buckets[$e['bucket']][] = $e['title'];
            $entityIds[] = $id;
            foreach ($e['aliases'] as $alias) {
                $aliases[] = $alias;
            }
        }

        $out = [];
        foreach ($buckets as $field => $vals) {
            $out[$field] = array_values(array_unique($vals));
        }
        if ($entityIds !== []) {
            $out['entity_ids'] = array_values(array_unique($entityIds));
        }
        if ($aliases !== []) {
            $out['entity_aliases_txt'] = array_values(array_unique($aliases));
        }
        return $out;
    }

    /**
     * Yield every authority record for the entity collection build.
     *
     * @return iterable<int, array{
     *   id:int, type:string, title:string, aliases:list<string>, description:string,
     *   coordinates:string, identifier:string, thumbnail:?string, is_public:bool
     * }>
     */
    public function entities(): iterable
    {
        foreach ($this->byId as $id => $e) {
            yield [
                'id'          => $id,
                'type'        => $e['type'],
                'title'       => $e['title'],
                'aliases'     => $e['aliases'],
                'description' => $e['description'],
                'coordinates' => $e['coordinates'],
                'identifier'  => $e['identifier'],
                'thumbnail'   => $e['thumbnail'],
                'is_public'   => $e['is_public'],
            ];
        }
    }

    public function size(): int
    {
        return count($this->byId);
    }

    /** @return array{0:string,1:?string} [type label, content bucket] for a class. */
    private function classDefault(int $class): array
    {
        return match ($class) {
            94  => ['Personnes', 'persons_ss'],
            96  => ['Organisations', 'organisations_ss'],
            9   => ['Lieux', 'places_ss'],
            54  => ['Événements', 'events_ss'],
            // 244 is refined by item-set after the stream; default to Sujets.
            244 => ['Sujets', 'topics_ss'],
            default => ['', null],
        };
    }

    /**
     * @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @return list<string>
     */
    private function literals(array $values, string $term): array
    {
        $out = [];
        foreach ($values[$term] ?? [] as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values */
    private function firstLiteral(array $values, string $term): string
    {
        foreach ($values[$term] ?? [] as $v) {
            $s = trim((string) ($v['value'] ?? ''));
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Maps one authority record (an {@see \IwacSearch\Indexer\EntityAuthority}
 * entry) plus its occurrence aggregate into a document for the entity
 * collection (data/schema-index.yaml).
 *
 * The static fields (title, type, aliases, description, coordinates) come from
 * the entity item itself; the occurrence metrics (frequency / first–last year
 * / countries) are accumulated during the content reindex — they're a reverse
 * scan of the content items that reference this entity — and passed in here.
 *
 * Standalone (no AbstractMapper / no AuthorityResolver): an entity doesn't
 * resolve other entities. Driven by IndexReindexer.
 */
final class IndexEntityMapper
{
    private const SITE_BASE = 'https://islam.zmo.de';
    private const SITE_SLUG = 'afrique_ouest';

    /**
     * @param array{
     *   id:int, type:string, title:string, aliases:list<string>, description:string,
     *   coordinates:string, identifier:string, is_part_of:list<string>,
     *   thumbnail:?string, is_public:bool
     * } $entity
     * @param array{
     *   frequency:int, countries:list<string>, first_year:?int, last_year:?int,
     *   mentions_by_year?:array<int,int>
     * } $aggregate
     * @return array<string, mixed>|null  null = skip (no title / type)
     */
    public function map(array $entity, array $aggregate): ?array
    {
        $title = trim($entity['title']);
        if ($title === '' || $entity['type'] === '') {
            return null;
        }

        $oid = $entity['id'];
        $doc = [
            'id'            => (string) $oid,
            'title'         => $title,
            'title_txt'     => $title,
            'entity_type_s' => $entity['type'],
            'frequency'     => $aggregate['frequency'] ?? 0,
            'is_public'     => $entity['is_public'],
            'omeka_url'     => sprintf('%s/s/%s/item/%d', self::SITE_BASE, self::SITE_SLUG, $oid),
        ];

        $this->maybeAdd($doc, 'identifier',    $entity['identifier']);
        $this->maybeAdd($doc, 'thumbnail_url', $entity['thumbnail'] ?? '');
        $this->maybeAdd($doc, 'description',   $entity['description']);
        $this->maybeAdd($doc, 'coordinates',   $entity['coordinates']);

        if ($entity['aliases'] !== []) {
            $doc['entity_aliases_txt'] = $entity['aliases'];
        }

        // dcterms:isPartOf — the organisation category for organisations
        // ("Organisation islamique"), broader-entity links elsewhere.
        if (($entity['is_part_of'] ?? []) !== []) {
            $doc['is_part_of_ss'] = $entity['is_part_of'];
        }

        $countries = $aggregate['countries'] ?? [];
        if ($countries !== []) {
            $doc['country_ss'] = array_values(array_unique($countries));
        }

        $firstYear = $aggregate['first_year'] ?? null;
        $lastYear  = $aggregate['last_year'] ?? null;
        if ($firstYear !== null) {
            $doc['first_year'] = $firstYear;
        }
        if ($lastYear !== null) {
            $doc['last_year'] = $lastYear;
            // Reuse the content client's date handling: pub_year drives the
            // year slider; date (epoch of last mention) drives date:desc.
            $doc['pub_year'] = $lastYear;
            $doc['date'] = (int) gmmktime(0, 0, 0, 1, 1, $lastYear);
        }

        // Compact "year:count;…" mention histogram for the entity-card sparkline
        // (display-only; index:false in the schema). Omitted when there are no
        // dated occurrences, so the client simply renders no sparkline.
        $byYear = $aggregate['mentions_by_year'] ?? [];
        if ($byYear !== []) {
            $parts = [];
            foreach ($byYear as $year => $count) {
                $parts[] = $year . ':' . $count;
            }
            $doc['mentions_by_year_s'] = implode(';', $parts);
        }

        return $doc;
    }

    /** @param array<string, mixed> $doc */
    private function maybeAdd(array &$doc, string $key, string $value): void
    {
        if ($value !== '') {
            $doc[$key] = $value;
        }
    }
}

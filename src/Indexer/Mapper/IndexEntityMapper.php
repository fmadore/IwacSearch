<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Maps a row of the HF `index` subset (an authority record) to a document
 * for the dedicated entity collection (data/schema-index.yaml).
 *
 * Standalone — unlike the content mappers it does NOT extend AbstractMapper
 * (no AuthorityResolver dependency: entities don't resolve other entities)
 * and it is driven directly by IndexReindexer, not the MapperRegistry.
 *
 * Source fields (see the HF index subset):
 *   Titre, Titre alternatif, Type, Description, frequency, countries,
 *   first_occurrence, last_occurrence, Coordonnées, iwac_url, thumbnail.
 */
final class IndexEntityMapper
{
    /**
     * The `countries` field on the index subset spells Benin without the
     * acute accent, unlike the content `country_ss` ("Bénin"). Normalise so
     * the entity-page Country facet reads correctly in French. The other
     * five IWAC countries match already.
     */
    private const COUNTRY_NORMALISE = [
        'Benin' => 'Bénin',
    ];

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>|null  null = skip (no id / title / type)
     */
    public function map(array $row): ?array
    {
        $oid   = $this->intOr($row['o:id'] ?? null, 0);
        $title = $this->str($row['Titre'] ?? '');
        $type  = $this->str($row['Type'] ?? '');
        if ($oid === 0 || $title === '' || $type === '') {
            return null;
        }

        $doc = [
            'id'            => (string) $oid,
            'title'         => $title,
            'title_txt'     => $title,
            'entity_type_s' => $type,
            'frequency'     => $this->intOr($row['frequency'] ?? null, 0),
            'is_public'     => false, // overlaid by IndexReindexer via the ACL loader
        ];

        $this->maybeAdd($doc, 'identifier',    $this->str($row['identifier'] ?? ''));
        $this->maybeAdd($doc, 'omeka_url',     $this->str($row['iwac_url'] ?? ''));
        $this->maybeAdd($doc, 'thumbnail_url', $this->str($row['thumbnail'] ?? ''));
        $this->maybeAdd($doc, 'description',   $this->str($row['Description'] ?? ''));
        $this->maybeAdd($doc, 'coordinates',   $this->str($row['Coordonnées'] ?? ''));

        $aliases = $this->splitPipe($this->str($row['Titre alternatif'] ?? ''));
        if ($aliases !== []) {
            $doc['entity_aliases_txt'] = $aliases;
        }

        $countries = array_values(array_unique(array_map(
            static fn(string $c): string => self::COUNTRY_NORMALISE[$c] ?? $c,
            $this->splitPipe($this->str($row['countries'] ?? ''))
        )));
        if ($countries !== []) {
            $doc['country_ss'] = $countries;
        }

        $firstYear = $this->yearOf($this->str($row['first_occurrence'] ?? ''));
        $lastYear  = $this->yearOf($this->str($row['last_occurrence'] ?? ''));
        if ($firstYear !== null) {
            $doc['first_year'] = $firstYear;
        }
        if ($lastYear !== null) {
            $doc['last_year'] = $lastYear;
            // Reuse the content client's date handling: pub_year drives the
            // year slider, date (epoch of last mention) drives date:desc.
            $doc['pub_year'] = $lastYear;
            $epoch = $this->epochOf($this->str($row['last_occurrence'] ?? ''));
            if ($epoch !== null) {
                $doc['date'] = $epoch;
            }
        }

        return $doc;
    }

    // ── Helpers (mirrors AbstractMapper's, kept local to stay standalone) ─

    private function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private function intOr(mixed $v, int $default): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @return list<string> */
    private function splitPipe(string $v): array
    {
        if ($v === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $v)),
            static fn(string $s): bool => $s !== ''
        ));
    }

    private function yearOf(string $iso): ?int
    {
        return preg_match('/^(\d{4})/', $iso, $m) ? (int) $m[1] : null;
    }

    private function epochOf(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        if (preg_match('/^\d{4}$/', $iso)) {
            $iso .= '-01-01';
        } elseif (preg_match('/^\d{4}-\d{2}$/', $iso)) {
            $iso .= '-01';
        }
        $ts = strtotime($iso . ' UTC');
        return is_int($ts) ? $ts : null;
    }
}

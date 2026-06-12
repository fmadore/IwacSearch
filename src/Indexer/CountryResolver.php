<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use RuntimeException;

/**
 * Derives the `country_ss` facet, which is NOT a stored Omeka property.
 *
 * Two derivation paths, matching the HF pipeline:
 *   - Articles / publications / audiovisual: from the newspaper/publisher
 *     name (`dcterms:publisher`, a literal) via the ported country_mapper
 *     table in data/newspaper-countries.json.
 *   - References: from membership in a per-country "Références" item set
 *     (the reference records carry no newspaper).
 *
 * Output values are the accented display form (Bénin, Côte d'Ivoire, …) so
 * they match Browse\Countries::ALL and the existing country_ss facet exactly
 * — the curated /browse/{country} locked filters depend on this.
 */
final class CountryResolver
{
    /**
     * Per-country item set → country (accented), for subsets that carry no
     * newspaper: the "Références" sets (references subset), the "Documents
     * divers" sets (documents subset), and the "Photographies" sets
     * (photographs subset). The families don't overlap, so one lookup
     * serves them all.
     */
    private const COUNTRY_ITEM_SETS = [
        // Références
        2193 => 'Bénin',
        2212 => 'Burkina Faso',
        2217 => "Côte d'Ivoire",
        2222 => 'Niger',
        2225 => 'Nigeria',
        2228 => 'Togo',
        // Documents divers
        23452 => 'Bénin',
        23453 => 'Burkina Faso',
        76366 => "Côte d'Ivoire",
        26327 => 'Togo',
        // Photographies
        2192 => 'Bénin',
        2211 => 'Burkina Faso',
        2216 => "Côte d'Ivoire",
        2220 => 'Niger',
        2227 => 'Togo',
    ];

    /** @var array<string, string> normalised newspaper name → country */
    private array $byNewspaper;

    public function __construct(string $mapJsonPath)
    {
        if (!is_readable($mapJsonPath)) {
            throw new RuntimeException("Newspaper-country map not readable: {$mapJsonPath}");
        }
        $raw = json_decode((string) file_get_contents($mapJsonPath), true);
        if (!is_array($raw)) {
            throw new RuntimeException("Newspaper-country map malformed: {$mapJsonPath}");
        }
        $this->byNewspaper = [];
        foreach ($raw as $newspaper => $country) {
            // Skip the "_comment" doc key and any non-string mapping.
            if (!is_string($newspaper) || str_starts_with($newspaper, '_') || !is_string($country)) {
                continue;
            }
            $this->byNewspaper[$this->normalise($newspaper)] = $country;
        }
    }

    /**
     * Resolve countries from one or more newspaper/publisher names. Unknown
     * names yield nothing (logged as a coverage gap by the reindexer if it
     * cares) rather than a bogus facet value.
     *
     * @param  list<string> $newspapers
     * @return list<string>
     */
    public function forNewspapers(array $newspapers): array
    {
        $out = [];
        foreach ($newspapers as $name) {
            $country = $this->byNewspaper[$this->normalise($name)] ?? null;
            if ($country !== null) {
                $out[] = $country;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Resolve countries from a reference item's set memberships.
     *
     * @param  list<int> $itemSetIds
     * @return list<string>
     */
    public function forItemSets(array $itemSetIds): array
    {
        $out = [];
        foreach ($itemSetIds as $setId) {
            $country = self::COUNTRY_ITEM_SETS[$setId] ?? null;
            if ($country !== null) {
                $out[] = $country;
            }
        }
        return array_values(array_unique($out));
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }
}

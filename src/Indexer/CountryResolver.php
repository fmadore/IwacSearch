<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use IwacSearch\IwacInstance;
use RuntimeException;

/**
 * Derives the `country_ss` facet, which is NOT a stored Omeka property.
 *
 * Three derivation paths, the first two matching the historical HF pipeline's
 * country logic:
 *   - Articles / publications: from the newspaper/publisher name
 *     (`dcterms:publisher`, a literal) via the ported country_mapper
 *     table in data/newspaper-countries.json.
 *   - References / documents / photographs: from membership in a per-country
 *     item set ({@see IwacInstance::COUNTRY_ITEM_SETS}) — those records carry
 *     no newspaper.
 *   - Audiovisual: from a place authority naming a country
 *     ({@see IwacInstance::COUNTRY_PLACE_NAMES}) — recordings have neither a
 *     newspaper nor a per-country set, so `dcterms:spatial` is all they have.
 *     Deliberately NOT applied to the press subsets, where an article merely
 *     *mentioning* a neighbouring country would acquire its flag.
 *
 * Output values are whatever the `country_ss` facet stores, so they match the
 * preset locked filters exactly — Typesense filter_by is accent- and
 * case-sensitive. That is the accented display form for most of them (Bénin,
 * Côte d'Ivoire, …) but NOT for Nigeria, whose facet value is unaccented even
 * though its place authority reads "Nigéria". Translate on the way in, never
 * on the way out.
 */
final class CountryResolver
{
    /** @var array<string, string> normalised newspaper name → country */
    private array $byNewspaper;

    /** @var array<string, string> normalised place heading → country */
    private array $byPlace;

    public function __construct(string $mapJsonPath)
    {
        // Re-keyed through normalise() rather than written pre-lowercased, so
        // the lookup can never drift from the matching rule.
        $this->byPlace = [];
        foreach (IwacInstance::COUNTRY_PLACE_NAMES as $place => $country) {
            $this->byPlace[$this->normalise($place)] = $country;
        }

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
            $country = IwacInstance::COUNTRY_ITEM_SETS[$setId] ?? null;
            if ($country !== null) {
                $out[] = $country;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Resolve countries from an item's place headings (`dcterms:spatial`).
     * City-level headings ("Zaria") name no country and are skipped, so an
     * item only earns a country when it says so explicitly.
     *
     * @param  list<string> $places
     * @return list<string>
     */
    public function forPlaces(array $places): array
    {
        $out = [];
        foreach ($places as $place) {
            $country = $this->byPlace[$this->normalise($place)] ?? null;
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

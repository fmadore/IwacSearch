<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * One search "scope" a page block (or the federated page) can target.
 *
 * A preset bundles everything that distinguishes one curated surface from
 * another: which Typesense collection it queries (content vs the entity
 * index), the locked filter that pins it to a subset, the default facet
 * stack, and the default sort. It is the lightweight successor to the
 * old `iwac_browse_config` row — the same knowledge, but expressed in code
 * and resolved at block-render time instead of seeded into a MySQL table.
 *
 * `card` doubles as the collection discriminator:
 *   - 'content' → the article/document/publication collection (iwac_current)
 *   - 'entity'  → the index/authority collection (iwac_index_current)
 * The block maps `card` to the concrete alias + query_by / highlight_fields
 * (see {@see \IwacSearch\Search\SearchDefaults}) at render time.
 *
 * `legacySlug` is the old `/browse/{slug}` path segment this preset replaces,
 * kept so the Phase-C redirect shim can map a stale bookmark to the
 * equivalent `/search?f.…` (or the federated page for the entity index).
 *
 * `hideCountry` suppresses the country chip on result cards: on a scope
 * already pinned to one country (the per-country presets), repeating
 * "Burkina Faso" on every row is noise. The whole-corpus, references and
 * entity-index scopes leave it on — there the country tells the user
 * something. It only affects display; country is still a working facet
 * wherever the scope advertises it.
 */
final class Preset
{
    /**
     * @param list<string>          $facets        Schema field names rendered
     *                                             as facet groups, in display order.
     * @param array<string, string> $redirectQuery Query params the legacy
     *                                             /browse/{legacySlug} redirect appends to /search
     *                                             (e.g. ['f.country_ss' => 'Bénin']). Declared by the
     *                                             catalog next to lockedFilters so the redirect never
     *                                             has to reverse-parse the filter string.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $card,
        public readonly string $lockedFilters,
        public readonly array $facets,
        public readonly string $defaultSort,
        public readonly ?string $legacySlug = null,
        public readonly bool $hideCountry = false,
        public readonly array $redirectQuery = [],
    ) {
    }

    public function isEntity(): bool
    {
        return $this->card === PresetCatalog::CARD_ENTITY;
    }
}

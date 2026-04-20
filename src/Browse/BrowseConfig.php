<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

/**
 * Read-only DTO for one row of `iwac_browse_config`.
 *
 * One curated browse surface — slug `burkina-faso` → `/browse/burkina-faso`
 * → Svelte client mounted with `country_ss:=`Burkina Faso`` baked in as a
 * locked filter at scoped-key mint time (server-side enforcement, not a
 * UI suggestion).
 *
 * Mutation flows through `BrowseConfigRepository::save()` — this DTO is
 * constructed by the repository on read and never mutated in place.
 */
final class BrowseConfig
{
    /**
     * @param list<string> $prominentFacets Schema field names to render as
     *                                       facet groups, in display order.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $introHtml,
        public readonly string $lockedFilters,
        public readonly array $prominentFacets,
        public readonly string $defaultSort,
        public readonly int $resultsPerPage,
        public readonly int $position,
    ) {
    }

    /**
     * Project to the bootstrap shape consumed by the Svelte client. Mirrors
     * what IwacSearchBlock::render() emits, so the same App.svelte mounts
     * on both surfaces with identical behaviour — only the locked filters
     * and prominent facet list differ.
     *
     * @return array<string, mixed>
     */
    public function toBootstrap(string $collectionAlias, string $tokenEndpoint, string $searchEndpoint): array
    {
        return [
            'block_id'         => 'browse-' . ($this->id ?? 'preview'),
            'mode'             => 'full',
            'locked_filters'   => $this->lockedFilters,
            'prominent_facets' => $this->prominentFacets,
            'default_sort'     => $this->defaultSort,
            'results_per_page' => $this->resultsPerPage,
            'collection_alias' => $collectionAlias,
            'endpoints' => [
                'token'  => $tokenEndpoint,
                'search' => $searchEndpoint,
            ],
        ];
    }
}

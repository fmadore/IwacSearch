<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Builds the bootstrap config blob every IwacSearch surface hands to the
 * Svelte client.
 *
 * Three surfaces emit one: the standalone /search shell, each tab of the
 * federated /search/everything page, and every page-block instance. They used
 * to hand-assemble the array independently and had already drifted — only the
 * block emitted `card` / `hide_country`, and only the block and the federated
 * tabs emitted `query_by` / `highlight_fields`, which left /search running its
 * SSR search (PHP {@see SearchDefaults}) and its client search (the TS
 * `CONTENT_QUERY_BY_FALLBACK`) against two independently-maintained copies of
 * the same field list.
 *
 * One builder means:
 *   - `card` → collection alias → query_by / highlight_fields is decided once
 *     (the entity collection has no ocr_text / abstract / embedding, so
 *     passing content's field list at it makes Typesense 404 the request);
 *   - the endpoint stems live in one constant instead of four call sites;
 *   - a new bootstrap key reaches every surface by construction.
 *
 * The endpoint values are raw STEMS. The mount partials resolve them through
 * `basePath()` at view time, because the controller can't know the site mount
 * prefix at construction. `IwacBootstrapJson` then serialises the result.
 */
final class SurfaceBootstrap
{
    /**
     * Module-global endpoints, unprefixed. `/discovery/token` is this module's
     * own route; `/search-api/multi_search` is the nginx proxy in front of
     * Typesense (IWAC-docker). Neither is nested under a site mount.
     *
     * @var array<string, string>
     */
    public const ENDPOINT_STEMS = [
        'token'  => '/discovery/token',
        'search' => '/search-api/multi_search',
    ];

    /**
     * @param  int|string           $blockId          'standalone', 'everything-content', or a SitePageBlock id.
     * @param  string               $card             PresetCatalog::CARD_CONTENT | CARD_ENTITY — also the collection discriminator.
     * @param  list<string>         $prominentFacets  Facet fields rendered above the fold, in display order.
     * @param  ?string              $diversifyTag     Curation tag activating MMR diversification (content + text queries only).
     * @return array<string, mixed>
     */
    public static function build(
        int|string $blockId,
        string $card,
        string $contentAlias,
        string $indexAlias,
        array $prominentFacets,
        string $defaultSort,
        string $mode = 'full',
        string $lockedFilters = '',
        int $resultsPerPage = 10,
        bool $hideCountry = false,
        ?string $diversifyTag = null,
        float $diversityLambda = 0.7,
    ): array {
        $isEntity = $card === PresetCatalog::CARD_ENTITY;

        $bootstrap = [
            'block_id'         => $blockId,
            'mode'             => $mode,
            'card'             => $card,
            'locked_filters'   => $lockedFilters,
            'prominent_facets' => $prominentFacets,
            'hide_country'     => $hideCountry,
            'default_sort'     => $defaultSort,
            'results_per_page' => $resultsPerPage,
            'collection_alias' => $isEntity ? $indexAlias : $contentAlias,
            // Always advertised, on every surface, so the autocomplete can
            // federate to the entity index even from a content surface.
            'index_collection_alias' => $indexAlias,
            'query_by'         => $isEntity
                ? SearchDefaults::ENTITY_QUERY_BY
                : SearchDefaults::CONTENT_QUERY_BY,
            'highlight_fields' => $isEntity
                ? SearchDefaults::ENTITY_HIGHLIGHT_FIELDS
                : SearchDefaults::CONTENT_HIGHLIGHT_FIELDS,
            'endpoints'        => self::ENDPOINT_STEMS,
        ];

        // Result diversification (Typesense 30.2 MMR): only meaningful on the
        // content collection, and the client applies it only on a text query.
        // Curated page blocks deliberately pass null — raw relevance order.
        if ($diversifyTag !== null && !$isEntity) {
            $bootstrap['diversify_tag']    = $diversifyTag;
            $bootstrap['diversity_lambda'] = $diversityLambda;
        }

        return $bootstrap;
    }
}

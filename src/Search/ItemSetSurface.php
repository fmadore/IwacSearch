<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use IwacSearch\IwacInstance;

/**
 * The search surface on an item set's page: the set's items as the content
 * collection holds them — searchable, faceted, sorted and paged by Typesense
 * — in place of the theme's ledger of core's browse.
 *
 * The scope is `item_set_ids:=[N]`, which every content mapper emits
 * ({@see \IwacSearch\Indexer\Mapper\AbstractMapper}). Like any page block's
 * locked filter it is COSMETIC scoping, not a privacy boundary: the scoped
 * key's `is_public:=true` and field exclusions apply exactly as on /search.
 *
 * Not every set is content. The authority sets ("Sujets", "Notices
 * d'autorité"…) hold entity records, which live in the entity collection and
 * carry no item_set_ids, so a Typesense surface on them would list nothing —
 * and a set mixing indexed and unindexed classes would silently lose the
 * unindexed ones. {@see covers()} is the guard: the surface is offered only
 * when the index holds (nearly) every item core counts in the set. It also
 * sends a signed-in editor, whose core count includes private items the
 * public key can never return, back to core's ledger, which shows them.
 */
final class ItemSetSurface
{
    /**
     * The mount id — and so the URL-state prefix (`bset.q`, `bset.f.type_s`,
     * `bset.page`): disjoint from core's `page` / `sort_by` and the theme's
     * `lr_*`, which the same page carries.
     */
    public const BLOCK_ID = 'set';

    /**
     * Share of core's count the index must hold. Slack for the incremental
     * indexer's lag (an item added to the set a moment ago), never for a
     * class the index does not carry.
     */
    public const MIN_COVERAGE = 0.98;

    public const RESULTS_PER_PAGE = 20;

    /** The order the theme's ledger and Linked resources block open on too. */
    public const DEFAULT_SORT = 'date:desc';

    public static function lockedFilter(int $itemSetId): string
    {
        return 'item_set_ids:=[' . $itemSetId . ']';
    }

    /**
     * @return array<string, mixed>
     */
    public static function bootstrap(
        int $itemSetId,
        string $contentAlias = IwacInstance::CONTENT_ALIAS,
        string $indexAlias = IwacInstance::INDEX_ALIAS,
    ): array {
        return SurfaceBootstrap::build(
            blockId:         self::BLOCK_ID,
            card:            PresetCatalog::CARD_CONTENT,
            contentAlias:    $contentAlias,
            indexAlias:      $indexAlias,
            prominentFacets: SearchDefaults::CONTENT_PROMINENT_FACETS,
            defaultSort:     self::DEFAULT_SORT,
            mode:            'full',
            lockedFilters:   self::lockedFilter($itemSetId),
            resultsPerPage:  self::RESULTS_PER_PAGE,
        );
    }

    /**
     * Whether the index can stand in for core's listing of the set.
     *
     * @param ?int $found      Typesense's count for the set's default page, or
     *                         null when Typesense did not answer.
     * @param int  $coreCount  Core's count of the set's items, as the current
     *                         visitor may see them.
     */
    public static function covers(?int $found, int $coreCount): bool
    {
        if ($found === null || $found <= 0 || $coreCount <= 0) {
            return false;
        }
        return $found >= (int) ceil($coreCount * self::MIN_COVERAGE);
    }
}

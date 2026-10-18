<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Search\ItemSetSurface;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\SearchDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An item set's page lists the set through Typesense only when the index can
 * stand in for core: the authority sets live in the entity collection, which
 * carries no item_set_ids, and a signed-in editor's count includes private
 * items the public key never returns. Both must keep core's ledger.
 */
#[CoversClass(ItemSetSurface::class)]
final class ItemSetSurfaceTest extends TestCase
{
    public function testTheScopeIsTheSetsMembership(): void
    {
        self::assertSame('item_set_ids:=[2200]', ItemSetSurface::lockedFilter(2200));
    }

    public function testTheBootstrapIsAFullContentSurfaceUnderItsOwnPrefix(): void
    {
        $bootstrap = ItemSetSurface::bootstrap(2200, 'iwac_current', 'iwac_index_current');

        self::assertSame('set', $bootstrap['block_id'], 'the URL state lives under bset.');
        self::assertSame('full', $bootstrap['mode']);
        self::assertSame(PresetCatalog::CARD_CONTENT, $bootstrap['card']);
        self::assertSame('iwac_current', $bootstrap['collection_alias']);
        self::assertSame('item_set_ids:=[2200]', $bootstrap['locked_filters']);
        self::assertSame(SearchDefaults::CONTENT_PROMINENT_FACETS, $bootstrap['prominent_facets']);
        self::assertTrue(FacetCatalog::isValidSortFor(PresetCatalog::CARD_CONTENT, $bootstrap['default_sort']));
        self::assertSame(ItemSetSurface::RESULTS_PER_PAGE, $bootstrap['results_per_page']);
        self::assertArrayNotHasKey('diversify_tag', $bootstrap, 'a curated scope keeps raw order');
    }

    public function testTheIndexCoversASetItHoldsWhole(): void
    {
        self::assertTrue(ItemSetSurface::covers(1234, 1234));
        self::assertTrue(ItemSetSurface::covers(1240, 1234), 'an index a moment behind a removal still covers');
        self::assertTrue(ItemSetSurface::covers(980, 1000), "the incremental indexer's lag is tolerated");
    }

    public function testASetTheIndexDoesNotHoldKeepsCoresListing(): void
    {
        self::assertFalse(ItemSetSurface::covers(null, 1000), 'Typesense did not answer');
        self::assertFalse(ItemSetSurface::covers(0, 244), 'an authority set: no content carries its id');
        self::assertFalse(ItemSetSurface::covers(979, 1000), 'a class the index does not carry');
        self::assertFalse(ItemSetSurface::covers(40, 47), "an editor's count includes private items");
        self::assertFalse(ItemSetSurface::covers(3, 0), 'an empty set has nothing to search');
    }
}

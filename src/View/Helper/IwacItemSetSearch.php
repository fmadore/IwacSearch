<?php
declare(strict_types=1);

namespace IwacSearch\View\Helper;

use IwacSearch\Asset\SvelteAssets;
use IwacSearch\IwacInstance;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\ItemSetSurface;
use IwacSearch\Search\SearchStateQuery;
use Laminas\View\Helper\AbstractHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The search surface for an item set's page ({@see ItemSetSurface}), or ''
 * when the page should keep core's listing.
 *
 * IWAC-theme's item-set template calls it — guarded on the helper's presence,
 * like iwacSearchUrl — and renders its own ledger whenever it returns ''.
 * That is every failure: Typesense down or slow (the SSR renderer answers
 * null, and its 20 s circuit breaker keeps the next pages from waiting on
 * it), a set the index does not cover, an exception anywhere. The ledger is
 * the fallback by construction, never a broken block.
 *
 *     <?php $search = $this->iwacItemSetSearch($itemSet); ?>
 *     <?= $search !== '' ? $search : $ledger ?>
 *
 * Cost on a covered set: one Typesense multi_search (served from the shared
 * snapshot cache for most visitors) and one core count query, instead of
 * core's paged item browse and its hydrated representations.
 */
final class IwacItemSetSearch extends AbstractHelper
{
    public function __construct(
        private readonly InitialResponseRenderer $renderer,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $contentAlias = IwacInstance::CONTENT_ALIAS,
        private readonly string $indexAlias = IwacInstance::INDEX_ALIAS,
    ) {
    }

    /**
     * @param object $itemSet The item set's representation (id() is all that is read).
     */
    public function __invoke(object $itemSet): string
    {
        $view = $this->getView();
        try {
            $itemSetId = (int) $itemSet->id();
            if ($itemSetId <= 0) {
                return '';
            }
            $bootstrap = ItemSetSurface::bootstrap($itemSetId, $this->contentAlias, $this->indexAlias);

            // The default first page, rendered even when the URL carries the
            // surface's own state: its `found` is what decides coverage, and
            // it is the snapshot every other visitor of the set shares.
            $initial = $this->renderer->render($bootstrap);
            $found = is_array($initial) && is_numeric($initial['found'] ?? null) ? (int) $initial['found'] : null;
            if ($found === null) {
                return '';
            }

            // Core's count, as this visitor may see the set — the same scope
            // the theme's ledger would list (core's site browse adds the
            // site). limit, not page/per_page: those set core's one shared
            // Omeka\Paginator, which the ledger's pager would then render.
            $query = ['item_set_id' => $itemSetId, 'limit' => 1];
            $site = $view->currentSite();
            if ($site !== null) {
                $query['site_id'] = (int) $site->id();
            }
            $coreCount = (int) $view->api()->search('items', $query)->getTotalResults();
            if (!ItemSetSurface::covers($found, $coreCount)) {
                return '';
            }

            // App.svelte adopts a snapshot only for the pristine state, so a
            // URL carrying the surface's own state skips embedding it.
            if (!SearchStateQuery::carriesState((string) ($_SERVER['QUERY_STRING'] ?? ''), 'b' . ItemSetSurface::BLOCK_ID . '.')) {
                $bootstrap['initial_response'] = $initial;
            }

            SvelteAssets::injectSearchApp($view);
            return $view->partial('common/iwac-item-set-search', [
                'bootstrap' => $bootstrap,
                'block_id'  => ItemSetSurface::BLOCK_ID,
                'item_set_id' => $itemSetId,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('IwacSearch: item-set search surface fell back to the theme listing: {message}', [
                'message' => $e->getMessage(),
            ]);
            return '';
        }
    }
}

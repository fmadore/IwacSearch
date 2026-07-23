<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use IwacSearch\Indexer\CurationSync;
use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Search\PresetCatalog;
use IwacSearch\Search\SearchDefaults;
use IwacSearch\Search\TypesenseSearchKeyProvider;
use IwacSearch\Util\ExceptionMessage;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Public discovery controller.
 *
 *   GET /search             — HTML shell + Svelte bundle (standalone surface)
 *   GET /search/everything  — federated Content + Entities surface
 *   GET /discovery/token    — mints a 1h scoped key for the browser
 *   GET /browse[/:slug]     — legacy redirect to /search (the curated
 *                             browse-config system was retired)
 *
 * Same root + state script contract as IwacSearchBlock so the same
 * Svelte client mounts on every surface.
 */
class SearchController extends AbstractActionController
{
    /**
     * Raw endpoint stems — basePath() is resolved at view time by the mount
     * partials; pre-baking the stems here keeps the renderer dumb.
     *
     * @var array<string, string>
     */
    private const ENDPOINT_STEMS = [
        'token'  => '/discovery/token',
        'search' => '/search-api/multi_search',
    ];

    public function __construct(
        private readonly TypesenseSearchKeyProvider $keyProvider,
        private readonly InitialResponseRenderer $initialRenderer,
        /** @var array<string, mixed> */
        private readonly array $config = [],
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * GET /search — HTML shell. Emits the same root + state script the
     * page block uses, so the Svelte bundle treats both surfaces
     * identically.
     */
    public function indexAction(): ViewModel
    {
        $bootstrap = $this->contentBootstrap('standalone');

        // SSR the first page of results + facets so the Svelte client
        // paints real content on first frame. If Typesense is down, the
        // renderer returns null and the client falls back to its own
        // scoped-key fetch — same end-state, one extra flash.
        //
        // Skipped for deep links (?q=…, ?f.*=…, ?sort=…, …): the snapshot is
        // the default first page, which the client would immediately discard
        // and refetch — the Typesense round trip would be pure waste.
        if (!$this->requestCarriesSearchState()) {
            $initial = $this->initialRenderer->render($bootstrap);
            if ($initial !== null) {
                $bootstrap['initial_response'] = $initial;
            }
        }

        $view = new ViewModel(['bootstrap' => $bootstrap]);
        $view->setTemplate('iwac-search/search/index');
        return $view;
    }

    /**
     * The shared content-corpus bootstrap: the standalone /search shell and
     * the federated page's Content tab must stay identical, so both build on
     * this and only override per-surface keys. Notes on the choices:
     *
     *   - prominent_facets: curatorial, ordered coarse → fine. The year-range
     *     slider (DateRangeSlider.svelte) renders separately; it's not a
     *     categorical facet so it doesn't appear in the list.
     *   - diversify_tag / diversity_lambda (Typesense 30.2 MMR): on a text
     *     query, push down near-duplicate syndicated articles so one wire
     *     story doesn't fill the first page. Activates the iwac_diversity
     *     curation set (CurationSync) via curation_tags; applied client-side
     *     and only when a query is present (browse mode stays date-sorted).
     *     Curated page blocks deliberately omit this — raw relevance order.
     *   - index_collection_alias: always advertised so the autocomplete can
     *     federate to the entity index.
     *
     * @return array<string, mixed>
     */
    private function contentBootstrap(string $blockId): array
    {
        return [
            'block_id'         => $blockId,
            'mode'             => 'full',
            'locked_filters'   => '',
            'prominent_facets' => SearchDefaults::CONTENT_PROMINENT_FACETS,
            'default_sort'     => '_text_match:desc',
            'diversify_tag'    => CurationSync::TAG,
            'diversity_lambda' => 0.7,
            'results_per_page' => 10,
            'collection_alias' => $this->config['typesense']['collection_alias'] ?? 'iwac_current',
            'index_collection_alias' => $this->config['typesense']['index_collection_alias'] ?? 'iwac_index_current',
            'endpoints'        => self::ENDPOINT_STEMS,
        ];
    }

    /**
     * Whether the request URL carries client search state (urlState.ts's
     * unprefixed param set) that would make the default-first-page SSR
     * snapshot useless to the client.
     */
    private function requestCarriesSearchState(): bool
    {
        $params = $this->params()->fromQuery();
        if (!is_array($params)) {
            return false;
        }
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'f.')) {
                return true;
            }
            if (in_array($key, ['q', 'sort', 'date.from', 'date.to'], true) && (string) $value !== '') {
                return true;
            }
            if ($key === 'page' && (int) $value > 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * GET /search/everything — the federated "search everything" surface.
     *
     * Two tabs over the two collections: Content (iwac_current) and the entity
     * Index (iwac_index_current). The Svelte FederatedApp owns one shared query,
     * runs a single counts-only multi_search across both collections for the
     * tab badges (one scoped key spans every collection), and reuses the
     * per-collection App for the active tab. We SSR the first page of BOTH tabs
     * for the empty-query landing so switching tabs paints instantly; with a
     * query present the client fetches per tab (the empty-query snapshot
     * wouldn't match), so SSR is skipped.
     */
    public function everythingAction(): ViewModel
    {
        $indexAlias = $this->config['typesense']['index_collection_alias'] ?? 'iwac_index_current';
        $query      = (string) $this->params()->fromQuery('q', '');
        $defaultTab = $this->params()->fromQuery('tab') === 'entities' ? 'entities' : 'content';

        // Content tab — whole corpus, mirrors /search (incl. MMR diversification
        // of near-duplicate syndicated articles on a text query).
        $contentTab = $this->contentBootstrap('everything-content') + [
            'card'             => 'content',
            'query_by'         => SearchDefaults::CONTENT_QUERY_BY,
            'highlight_fields' => SearchDefaults::CONTENT_HIGHLIGHT_FIELDS,
        ];

        // Entity tab — the index/authority collection. Facets + sort come from
        // the shared PresetCatalog 'index' scope so the block and the federated
        // page agree (defensive fallback if the preset is ever renamed).
        $indexPreset = PresetCatalog::get('index');
        $entityTab = [
            'block_id'         => 'everything-entities',
            'mode'             => 'full',
            'card'             => 'entity',
            'locked_filters'   => '',
            'prominent_facets' => $indexPreset?->facets ?? ['entity_type_s', 'country_ss'],
            'default_sort'     => $indexPreset?->defaultSort ?? 'frequency:desc',
            'results_per_page' => 20,
            'collection_alias' => $indexAlias,
            'index_collection_alias' => $indexAlias,
            'query_by'         => SearchDefaults::ENTITY_QUERY_BY,
            'highlight_fields' => SearchDefaults::ENTITY_HIGHLIGHT_FIELDS,
            'endpoints'        => self::ENDPOINT_STEMS,
        ];

        if ($query === '') {
            $contentSsr = $this->initialRenderer->render($contentTab);
            if ($contentSsr !== null) {
                $contentTab['initial_response'] = $contentSsr;
            }
            $entitySsr = $this->initialRenderer->render($entityTab);
            if ($entitySsr !== null) {
                $entityTab['initial_response'] = $entitySsr;
            }
        }

        $bootstrap = [
            'variant'       => 'federated',
            'initial_query' => $query,
            'default_tab'   => $defaultTab,
            'tabs'          => [
                ['id' => 'content',  'bootstrap' => $contentTab],
                ['id' => 'entities', 'bootstrap' => $entityTab],
            ],
            'endpoints'     => self::ENDPOINT_STEMS,
        ];

        $view = new ViewModel(['bootstrap' => $bootstrap]);
        $view->setTemplate('iwac-search/search/everything');
        return $view;
    }

    /**
     * GET /discovery/token — mints a short-lived public scoped key.
     *
     * No CSRF check needed: the response is a key with constraints
     * already baked in (filter_by + exclude_fields + expires_at). A
     * malicious form submission can only get the same public-shaped key
     * any anonymous visitor would receive.
     *
     * Every caller receives the public-shaped key. An admin-shaped variant
     * (no exclude_fields, gated on the Omeka session) was sketched for M4
     * but never built — see docs/engineering-roadmap.md if it's ever needed.
     */
    public function tokenAction(): JsonModel
    {
        try {
            $aliasName = $this->config['typesense']['collection_alias'] ?? 'iwac_current';
            $expiresIn = (int) ($this->config['public_search_key']['expires_at_seconds'] ?? 3600);

            $minted = $this->keyProvider->mintPublicScopedKey(
                collectionAlias:  $aliasName,
                expiresInSeconds: $expiresIn
            );

            // No-store: scoped keys are short-lived and per-request; if a
            // proxy cached this, every visitor would share one key.
            $this->getResponse()->getHeaders()
                ->addHeaderLine('Cache-Control', 'no-store, max-age=0')
                ->addHeaderLine('Pragma', 'no-cache');

            return new JsonModel($minted);
        } catch (Throwable $e) {
            // The full exception chain (joined with " ← caused by: "
            // separators, bypassing Laminas's ServiceNotCreatedException
            // wrapper) goes to Omeka's log ONLY. It can name internal
            // hosts/ports and the Docker secret path, so it must never
            // reach the anonymous caller's response body.
            $this->logger->error('IwacSearch token mint failed', [
                'detail' => ExceptionMessage::chain($e),
            ]);

            $this->getResponse()->setStatusCode(Response::STATUS_CODE_503);
            return new JsonModel([
                'error'   => 'token_unavailable',
                'message' => 'Typesense scoped-key minting failed. Is the typesense service up?'
                    . ' Details are in the Omeka log.',
            ]);
        }
    }

    /**
     * GET /browse[/:slug] (+ /parcourir + the site-scoped variants).
     *
     * The curated browse-config system was retired. These routes now exist
     * only to keep old bookmarks / external links working, redirecting each
     * legacy slug to its successor surface:
     *
     *   benin … togo  → /search?f.country_ss=<country>
     *   references    → /search?f.type_s=reference
     *   index         → /search/everything?tab=entities
     *   all / unknown → /search
     *
     * Site context (and therefore the UI locale) is preserved by redirecting
     * to the site-scoped route when the request carried a site-slug.
     */
    public function browseAction(): Response
    {
        $slug   = (string) ($this->params()->fromRoute('slug') ?? '');
        $preset = $slug !== '' ? PresetCatalog::findByLegacySlug($slug) : null;

        $siteSlug = $this->params()->fromRoute('site-slug');
        $params   = $siteSlug !== null ? ['site-slug' => $siteSlug] : [];

        // The entity index maps to the federated page's Entities tab.
        if ($preset !== null && $preset->isEntity()) {
            $route = $siteSlug !== null ? 'site/iwac-search-everything' : 'iwac-search-everything';
            return $this->redirect()->toRoute($route, $params, ['query' => ['tab' => 'entities']]);
        }

        // Country / references presets re-apply their old scope as a facet on
        // /search (the client hydrates ?f.<field>= via urlState.ts) using the
        // redirectQuery the catalog declares next to each lockedFilters —
        // no reverse-parsing of the filter string. 'all' and unknown slugs
        // land on the bare /search shell.
        $query = $preset?->redirectQuery ?? [];

        $route   = $siteSlug !== null ? 'site/iwac-search' : 'iwac-search';
        $options = $query === [] ? [] : ['query' => $query];
        return $this->redirect()->toRoute($route, $params, $options);
    }
}

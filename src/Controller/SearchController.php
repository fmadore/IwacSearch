<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use IwacSearch\Browse\AllCountriesSeeder;
use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Browse\IndexSeeder;
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
 *   GET /discovery/token    — mints a 1h scoped key for the browser
 *   GET /browse             — landing page listing curated browse configs
 *   GET /browse/:slug       — one curated browse page (Svelte mounted with locked filters)
 *
 * Same root + state script contract as IwacSearchBlock so the same
 * Svelte client mounts on every surface.
 */
class SearchController extends AbstractActionController
{
    /**
     * Above-the-fold facet stack for the full content corpus. Ordered
     * coarse → fine: type, then geography, publisher, the entity authorities,
     * then the grouped sentiment trio. Shared by the standalone /search shell
     * and the federated page's Content tab so the two stay identical.
     *
     * @var list<string>
     */
    private const CONTENT_PROMINENT_FACETS = [
        'type_s',                // article | publication | document | audiovisual
        'country_ss',            // country
        'newspaper_ss',          // publisher
        'places_ss',             // locations
        'persons_ss',            // persons
        'organisations_ss',      // organisations
        'topics_ss',             // subjects
        // Sentiment trio — grouped under one collapsible section in the client.
        'gemini_polarite_ss',    // polarity
        'gemini_centralite_ss',  // centrality (of Islam/Muslims)
        'gemini_subjectivite',   // subjectivity (1–5)
    ];

    public function __construct(
        private readonly TypesenseSearchKeyProvider $keyProvider,
        private readonly BrowseConfigRepository $browseRepository,
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
        $aliasName = $this->config['typesense']['collection_alias'] ?? 'iwac_current';

        $bootstrap = [
            'block_id'         => 'standalone',
            'mode'             => 'full',
            'locked_filters'   => '',
            // Curatorial choice, ordered coarse → fine. The year range
            // slider (DateRangeSlider.svelte) renders separately; it's not a
            // categorical facet so it doesn't appear in this list. Shared with
            // the federated Content tab via the class const.
            'prominent_facets' => self::CONTENT_PROMINENT_FACETS,
            'default_sort'     => '_text_match:desc',
            // Diversify the standalone /search results (Typesense 30.2 MMR):
            // on a text query, push down near-duplicate syndicated articles
            // so one wire story doesn't fill the first page. Activates the
            // iwac_diversity curation set (CurationSync) via curation_tags;
            // applied client-side and only when a query is present (browse
            // mode stays date-sorted). Curated /browse pages and page blocks
            // deliberately omit this — they keep raw relevance order.
            'diversify_tag'    => CurationSync::TAG,
            'diversity_lambda' => 0.7,
            'results_per_page' => 10,
            'collection_alias' => $aliasName,
            // Entity collection — lets the autocomplete federate to it.
            'index_collection_alias' => $this->config['typesense']['index_collection_alias'] ?? 'iwac_index_current',
            'endpoints' => [
                // basePath() is set by the renderer at view time; we'd
                // prefer to compute these in the view, but pre-baking them
                // here keeps the renderer dumb.
                'token'  => '/discovery/token',
                'search' => '/search-api/multi_search',
            ],
        ];

        // SSR the first page of results + facets so the Svelte client
        // paints real content on first frame. If Typesense is down, the
        // renderer returns null and the client falls back to its own
        // scoped-key fetch — same end-state, one extra flash.
        $initial = $this->initialRenderer->render($bootstrap);
        if ($initial !== null) {
            $bootstrap['initial_response'] = $initial;
        }

        $view = new ViewModel(['bootstrap' => $bootstrap]);
        $view->setTemplate('iwac-search/search/index');
        return $view;
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
        $contentAlias = $this->config['typesense']['collection_alias'] ?? 'iwac_current';
        $indexAlias   = $this->config['typesense']['index_collection_alias'] ?? 'iwac_index_current';
        $query        = (string) $this->params()->fromQuery('q', '');
        $defaultTab   = $this->params()->fromQuery('tab') === 'entities' ? 'entities' : 'content';

        // Raw stems; common/iwac-federated-mount resolves basePath at view time.
        $endpoints = [
            'token'  => '/discovery/token',
            'search' => '/search-api/multi_search',
        ];

        // Content tab — whole corpus, mirrors /search (incl. MMR diversification
        // of near-duplicate syndicated articles on a text query).
        $contentTab = [
            'block_id'         => 'everything-content',
            'mode'             => 'full',
            'card'             => 'content',
            'locked_filters'   => '',
            'prominent_facets' => self::CONTENT_PROMINENT_FACETS,
            'default_sort'     => '_text_match:desc',
            'diversify_tag'    => CurationSync::TAG,
            'diversity_lambda' => 0.7,
            'results_per_page' => 10,
            'collection_alias' => $contentAlias,
            'index_collection_alias' => $indexAlias,
            'query_by'         => SearchDefaults::CONTENT_QUERY_BY,
            'highlight_fields' => SearchDefaults::CONTENT_HIGHLIGHT_FIELDS,
            'endpoints'        => $endpoints,
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
            'endpoints'        => $endpoints,
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
            'endpoints'     => $endpoints,
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
     * M4 will read the Omeka session here and return an admin-shaped key
     * (no exclude_fields) for authenticated admin users.
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
            $detail = ExceptionMessage::chain($e);
            // Belt-and-suspenders: also log the full chain to Omeka's
            // log file so ops can grep `journalctl` / `omeka.log` when
            // the response body has been swallowed by an intermediate
            // tool (a paste, a curl pipe, an error-tracking aggregator
            // that truncates at 256 chars). Same string shipped to the
            // browser, just a second sink.
            $this->logger->error('IwacSearch token mint failed', ['detail' => $detail]);

            $this->getResponse()->setStatusCode(Response::STATUS_CODE_503);
            return new JsonModel([
                'error'   => 'token_unavailable',
                'message' => 'Typesense scoped-key minting failed. Is the typesense service up?',
                // The full chain, joined with " ← caused by: " separators,
                // bypasses Laminas's ServiceNotCreatedException wrapper so
                // the response carries the actual root cause (e.g.
                // "Connection refused" from the SDK's HTTP client).
                'detail'  => $detail,
            ]);
        }
    }

    /**
     * GET /browse[/:slug]
     *
     * Without a slug (or with an unknown slug) — the curated landing grid
     * was removed (the browse pages live in the site navigation), so this
     * redirects to the all-countries page: the broadest browse entry point.
     * Old /browse and /browse/<stale-slug> links keep working.
     *
     * With a known slug — load the matching config row, render the same
     * root + state script that /search uses, with locked filters and
     * curated facet picks baked into the bootstrap. The Svelte client
     * doesn't know it's on a curated page; it just sees a different
     * bootstrap.
     */
    public function browseAction(): ViewModel|Response
    {
        $slug = $this->params()->fromRoute('slug');
        $aliasName = $this->config['typesense']['collection_alias'] ?? 'iwac_current';

        $config = ($slug === null || $slug === '')
            ? null
            : $this->browseRepository->findBySlug((string) $slug);

        if ($config === null) {
            // No landing grid anymore — route /browse and any unknown slug to
            // the all-countries page, on whichever route the request matched
            // (global /browse|/parcourir or the site-scoped variant), so the
            // visitor stays in their language site. The slug !== 'all' guard
            // prevents a redirect loop in the degenerate case where the
            // all-countries config itself has been deleted — then fall back
            // to the standalone /search shell.
            if ((string) $slug !== AllCountriesSeeder::SLUG) {
                $routeMatch = $this->getEvent()->getRouteMatch();
                return $this->redirect()->toRoute(
                    $routeMatch?->getMatchedRouteName() ?? 'iwac-parcourir',
                    array_filter(
                        [
                            'site-slug' => $this->params()->fromRoute('site-slug'),
                            'slug'      => AllCountriesSeeder::SLUG,
                        ],
                        static fn ($v): bool => $v !== null
                    )
                );
            }
            return $this->redirect()->toRoute('iwac-search');
        }

        // The Index page targets the SEPARATE entity collection and renders
        // entity cards; every other browse page is content.
        $isEntity   = ($config->slug === IndexSeeder::SLUG);
        $indexAlias = $this->config['typesense']['index_collection_alias'] ?? 'iwac_index_current';

        $bootstrap = $config->toBootstrap(
            collectionAlias:      $isEntity ? $indexAlias : $aliasName,
            tokenEndpoint:        '/discovery/token',
            searchEndpoint:       '/search-api/multi_search',
            card:                 $isEntity ? 'entity' : 'content',
            queryBy:              $isEntity ? SearchDefaults::ENTITY_QUERY_BY : null,
            highlightFields:      $isEntity ? SearchDefaults::ENTITY_HIGHLIGHT_FIELDS : null,
            // Always advertise the entity collection so the autocomplete on
            // content surfaces can federate to it.
            indexCollectionAlias: $indexAlias,
        );

        // Curated browse pages are the biggest SSR win: the corpus is
        // already filtered by locked_filters, so inlining the first page
        // is cheap and users see their country's items instantly.
        $initial = $this->initialRenderer->render($bootstrap);
        if ($initial !== null) {
            $bootstrap['initial_response'] = $initial;
        }

        $view = new ViewModel([
            'config'    => $config,
            'bootstrap' => $bootstrap,
        ]);
        $view->setTemplate('iwac-search/search/browse');
        return $view;
    }
}

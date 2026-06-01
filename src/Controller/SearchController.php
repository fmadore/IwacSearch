<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use IwacSearch\Browse\AllCountriesSeeder;
use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Browse\IndexSeeder;
use IwacSearch\Search\InitialResponseRenderer;
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
            // slider (DateRangeSlider.svelte) renders separately; it's
            // not a categorical facet so it doesn't appear in this list.
            'prominent_facets' => [
                'type_s',                // article | publication | document | audiovisual
                'country_ss',            // country
                'newspaper_ss',          // publisher
                'places_ss',             // locations
                'persons_ss',            // persons
                'organisations_ss',      // organisations
                'topics_ss',             // subjects
                // Sentiment trio — grouped under one collapsible section
                // in the client (Gemini model; chatgpt/mistral available).
                'gemini_polarite_ss',    // polarity
                'gemini_centralite_ss',  // centrality (of Islam/Muslims)
                'gemini_subjectivite',   // subjectivity (1–5)
            ],
            'default_sort'     => '_text_match:desc',
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

<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use IwacSearch\Service\TypesenseSearchKeyProvider;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Throwable;

/**
 * Public discovery controller.
 *
 *   GET /search             — HTML shell + Svelte bundle (standalone surface)
 *   GET /discovery/token    — mints a 1h scoped key for the browser
 *   GET /browse[/:slug]     — curated browse pages (M3 placeholder)
 *
 * Same root + state script contract as IwacSearchBlock so the same
 * Svelte client mounts on either surface.
 */
class SearchController extends AbstractActionController
{
    public function __construct(
        private readonly TypesenseSearchKeyProvider $keyProvider,
        /** @var array<string, mixed> */
        private readonly array $config = []
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
            'prominent_facets' => ['country_ss', 'newspaper_ss', 'date_decade_ss'],
            'default_sort'     => '_text_match:desc',
            'results_per_page' => 10,
            'collection_alias' => $aliasName,
            'endpoints' => [
                // basePath() is set by the renderer at view time; we'd
                // prefer to compute these in the view, but pre-baking them
                // here keeps the renderer dumb.
                'token'  => '/discovery/token',
                'search' => '/search-api/multi_search',
            ],
        ];

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
            $this->getResponse()->setStatusCode(Response::STATUS_CODE_503);
            return new JsonModel([
                'error'   => 'token_unavailable',
                'message' => 'Typesense scoped-key minting failed. Is the typesense service up?',
                // Surface the underlying message for ops; not sensitive.
                'detail'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /browse[/:slug] — curated browse page (M3 placeholder).
     */
    public function browseAction(): ViewModel
    {
        $slug = $this->params()->fromRoute('slug');
        $view = new ViewModel(['slug' => $slug, 'placeholder' => true]);
        $view->setTemplate('iwac-search/search/browse');
        return $view;
    }
}

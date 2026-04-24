<?php
declare(strict_types=1);

namespace IwacSearch\Controller\Admin;

use IwacSearch\Browse\BrowseConfig;
use IwacSearch\Browse\BrowseConfigRepository;
use IwacSearch\Browse\FacetCatalog;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use RuntimeException;
use Throwable;

/**
 * Admin CRUD for curated browse pages (M3.5).
 *
 * Two action shapes:
 *
 *   browseAction      GET  /admin/iwac-search/browse-config
 *     Renders the PHTML shell that mounts the Svelte admin bundle.
 *     Everything interactive happens through the JSON endpoints below.
 *
 *   apiListAction     GET    /admin/iwac-search/browse-config/api
 *                     POST   /admin/iwac-search/browse-config/api       (create)
 *   apiItemAction     GET    /admin/iwac-search/browse-config/api/:id
 *                     PATCH  /admin/iwac-search/browse-config/api/:id   (update)
 *                     DELETE /admin/iwac-search/browse-config/api/:id
 *
 * All JSON responses follow the same envelope:
 *   success → { data: <payload> }
 *   failure → { error: <code>, message: <short>, detail?: <long> }
 *
 * ACL is enforced by Module::onBootstrap — editors + site-admins +
 * global-admins may browse; only global-admins may write. The routes
 * live under `admin/`, so Omeka's authentication filter already
 * guarantees a logged-in user.
 */
class BrowseConfigController extends AbstractActionController
{
    public function __construct(
        private readonly BrowseConfigRepository $repository
    ) {
    }

    // ── HTML shell ─────────────────────────────────────────────────

    /**
     * Render the admin panel with its initial state already inlined.
     *
     * The full list of browse configs is fetched server-side from
     * iwac_browse_config (one MySQL round trip) and baked into the
     * bootstrap JSON so the Svelte app paints its table on first
     * frame — no /api fetch, no spinner. Same pattern as FacetedBrowse
     * and other admin surfaces that have a bounded, small dataset.
     *
     * Mutations go through the JSON endpoints below; the app refetches
     * after each write to stay consistent (cheap — all-configs is a
     * single SELECT with no joins).
     */
    public function browseAction(): ViewModel
    {
        $initialConfigs = array_map(
            fn (BrowseConfig $c): array => $this->serialise($c),
            $this->repository->findAll()
        );

        $view = new ViewModel([
            'bootstrap' => [
                'endpoints' => [
                    // Literal 0 is a placeholder — Svelte substitutes the
                    // real id at call time via `endpoints.item.replace('0', id)`.
                    'list' => $this->url()->fromRoute('admin/iwac-search/browse-config-api-list'),
                    'item' => $this->url()->fromRoute(
                        'admin/iwac-search/browse-config-api-item',
                        ['id' => 0]
                    ),
                ],
                'catalog' => [
                    'facets' => FacetCatalog::facetableFieldsList(),
                    'sorts'  => FacetCatalog::sortOptionsList(),
                ],
                'configs' => $initialConfigs,
                // CSRF: the admin session cookie is the primary gate. The
                // token echoed here is required on every mutation (as an
                // X-CSRF-Token header) so that a drive-by POST from an
                // embedded iframe on an unrelated site can't reuse the
                // admin's session.
                'csrf_token' => $this->freshCsrfToken(),
            ],
        ]);
        $view->setTemplate('iwac-search/admin/browse-config/browse');
        return $view;
    }

    // ── JSON API — collection endpoint ─────────────────────────────

    public function apiListAction(): JsonModel
    {
        $method = strtoupper($this->getRequest()->getMethod());
        try {
            return match ($method) {
                'GET'  => $this->okList(),
                'POST' => $this->create(),
                default => $this->methodNotAllowed(['GET', 'POST']),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    // ── JSON API — item endpoint ───────────────────────────────────

    public function apiItemAction(): JsonModel
    {
        $id = (int) $this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            return $this->badRequest('invalid_id', 'Route parameter `id` must be a positive integer.');
        }

        $method = strtoupper($this->getRequest()->getMethod());
        try {
            return match ($method) {
                'GET'    => $this->okOne($id),
                'PATCH'  => $this->update($id),
                'DELETE' => $this->destroy($id),
                default  => $this->methodNotAllowed(['GET', 'PATCH', 'DELETE']),
            };
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    // ── CRUD ───────────────────────────────────────────────────────

    private function okList(): JsonModel
    {
        $configs = array_map(
            fn (BrowseConfig $c): array => $this->serialise($c),
            $this->repository->findAll()
        );
        return new JsonModel(['data' => $configs]);
    }

    private function okOne(int $id): JsonModel
    {
        $config = $this->repository->findById($id);
        if ($config === null) {
            return $this->notFound($id);
        }
        return new JsonModel(['data' => $this->serialise($config)]);
    }

    private function create(): JsonModel
    {
        $this->assertCsrf();
        $payload = $this->readJsonBody();
        $config = $this->hydrate(null, $payload);

        if ($this->repository->existsBySlug($config->slug)) {
            return $this->conflict('slug_taken', sprintf('Slug `%s` already exists.', $config->slug));
        }

        $saved = $this->repository->save($config);
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_201);
        return new JsonModel(['data' => $this->serialise($saved)]);
    }

    private function update(int $id): JsonModel
    {
        $this->assertCsrf();
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return $this->notFound($id);
        }

        $payload = $this->readJsonBody();
        $config = $this->hydrate($id, $payload, $existing);

        if ($this->repository->existsBySlug($config->slug, $id)) {
            return $this->conflict('slug_taken', sprintf('Slug `%s` already used by another config.', $config->slug));
        }

        $saved = $this->repository->save($config);
        return new JsonModel(['data' => $this->serialise($saved)]);
    }

    private function destroy(int $id): JsonModel
    {
        $this->assertCsrf();
        $existing = $this->repository->findById($id);
        if ($existing === null) {
            return $this->notFound($id);
        }
        $this->repository->delete($id);
        return new JsonModel(['data' => ['id' => $id, 'deleted' => true]]);
    }

    // ── Serialisation / hydration ──────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function serialise(BrowseConfig $c): array
    {
        return [
            'id'                => $c->id,
            'slug'              => $c->slug,
            'title'             => $c->title,
            'intro_html'        => $c->introHtml,
            'locked_filters'    => $c->lockedFilters,
            'prominent_facets'  => $c->prominentFacets,
            'default_sort'      => $c->defaultSort,
            'results_per_page'  => $c->resultsPerPage,
            'position'          => $c->position,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrate(?int $id, array $payload, ?BrowseConfig $existing = null): BrowseConfig
    {
        $slug = trim((string) ($payload['slug'] ?? $existing?->slug ?? ''));
        if ($slug === '') {
            throw new RuntimeException('slug is required.');
        }
        // Uniform-case slugs to match CountrySeeder convention + the slug regex.
        $slug = strtolower($slug);

        $title = trim((string) ($payload['title'] ?? $existing?->title ?? ''));
        if ($title === '') {
            throw new RuntimeException('title is required.');
        }

        $sort = (string) ($payload['default_sort'] ?? $existing?->defaultSort ?? 'date:desc');
        if (!FacetCatalog::isValidSort($sort)) {
            throw new RuntimeException(sprintf('default_sort `%s` is not one of the allowed values.', $sort));
        }

        $perPage = (int) ($payload['results_per_page'] ?? $existing?->resultsPerPage ?? 10);
        if ($perPage < 1 || $perPage > 50) {
            throw new RuntimeException('results_per_page must be between 1 and 50.');
        }

        $facets = FacetCatalog::normaliseFacets(
            is_array($payload['prominent_facets'] ?? null)
                ? $payload['prominent_facets']
                : ($existing?->prominentFacets ?? [])
        );

        return new BrowseConfig(
            id:              $id ?? $existing?->id,
            slug:            $slug,
            title:           $title,
            introHtml:       (string) ($payload['intro_html']     ?? $existing?->introHtml      ?? ''),
            lockedFilters:   (string) ($payload['locked_filters'] ?? $existing?->lockedFilters  ?? ''),
            prominentFacets: $facets,
            defaultSort:     $sort,
            resultsPerPage:  $perPage,
            position:        (int) ($payload['position'] ?? $existing?->position ?? 0),
        );
    }

    // ── Request helpers ────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $raw = (string) $this->getRequest()->getContent();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Request body must be a JSON object.');
        }
        return $decoded;
    }

    private function freshCsrfToken(): string
    {
        // Tie the token to the current admin session so it rotates
        // naturally on sign-out / sign-in. Good enough for M3.5; a
        // shorter-lived per-form token is a future hardening pass.
        /** @var \Laminas\Session\SessionManager $sessionManager */
        $sessionManager = $this->getEvent()->getApplication()
            ->getServiceManager()->get('Omeka\Session');
        $container = new \Laminas\Session\Container('IwacSearchAdmin', $sessionManager);
        if (empty($container->csrf)) {
            $container->csrf = bin2hex(random_bytes(32));
        }
        return (string) $container->csrf;
    }

    private function assertCsrf(): void
    {
        $submitted = $this->getRequest()->getHeader('X-Csrf-Token');
        $submittedValue = $submitted ? (string) $submitted->getFieldValue() : '';
        if ($submittedValue === '' || !hash_equals($this->freshCsrfToken(), $submittedValue)) {
            throw new RuntimeException('CSRF token missing or invalid. Reload the page and retry.');
        }
    }

    // ── JSON error envelopes ───────────────────────────────────────

    /**
     * @param list<string> $allowed
     */
    private function methodNotAllowed(array $allowed): JsonModel
    {
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_405);
        $this->getResponse()->getHeaders()->addHeaderLine('Allow', implode(', ', $allowed));
        return new JsonModel([
            'error'   => 'method_not_allowed',
            'message' => 'Method not allowed for this endpoint.',
            'detail'  => 'Allowed: ' . implode(', ', $allowed),
        ]);
    }

    private function badRequest(string $code, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_400);
        return new JsonModel(['error' => $code, 'message' => $message]);
    }

    private function conflict(string $code, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_409);
        return new JsonModel(['error' => $code, 'message' => $message]);
    }

    private function notFound(int $id): JsonModel
    {
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_404);
        return new JsonModel([
            'error'   => 'not_found',
            'message' => sprintf('Browse config id=%d not found.', $id),
        ]);
    }

    private function failure(Throwable $e): JsonModel
    {
        $this->getResponse()->setStatusCode(Response::STATUS_CODE_422);
        return new JsonModel([
            'error'   => 'validation_failed',
            'message' => $e->getMessage(),
        ]);
    }
}

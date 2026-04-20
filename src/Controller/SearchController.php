<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * Public discovery controller.
 *
 * In M0 every action is a placeholder so the route table is real and the
 * module is activatable. The Svelte bundle, scoped-key minting, and browse
 * config rendering all land in later milestones.
 */
class SearchController extends AbstractActionController
{
    public function __construct(
        // Service deps come in via the controller factory in M1+:
        //   private TypesenseClientInterface $typesense,
        //   private array $config,
    ) {
    }

    /**
     * GET /search — serves the HTML shell that bootstraps the Svelte client.
     * In M0 returns a plain placeholder; the real shell lands in M1 along with
     * the asset pipeline.
     */
    public function indexAction(): ViewModel
    {
        $view = new ViewModel();
        $view->setTemplate('iwac-search/search/index');
        $view->setVariables([
            'placeholder' => true,
            // M1: $view->setVariable('initial_state', json_encode([...]));
        ]);
        return $view;
    }

    /**
     * GET /discovery/token — mints a short-lived Typesense scoped key.
     *
     * Public scoped keys carry both `filter_by: is_public:=true` AND
     * `exclude_fields: ocr_text` — these are belt-and-suspenders security
     * controls, not UX dials. Admin sessions get a key without exclude_fields.
     *
     * Wired up in M1.
     */
    public function tokenAction(): JsonModel
    {
        // Placeholder. Returns a 503 so callers fail loudly instead of silently
        // querying with an empty key.
        $this->getResponse()->setStatusCode(503);
        return new JsonModel([
            'error' => 'Token endpoint not yet implemented (see roadmap M1).',
        ]);
    }

    /**
     * GET /browse[/:slug] — curated browse page (M3).
     *
     * Without slug: lists all browse configs.
     * With slug:   loads the named iwac_browse_config row, passes its locked
     *              filters + facet list into the Svelte shell.
     */
    public function browseAction(): ViewModel
    {
        $slug = $this->params()->fromRoute('slug');
        $view = new ViewModel();
        $view->setTemplate('iwac-search/search/browse');
        $view->setVariable('slug', $slug);
        $view->setVariable('placeholder', true);
        return $view;
    }
}

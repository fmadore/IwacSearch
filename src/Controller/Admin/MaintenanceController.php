<?php
declare(strict_types=1);

namespace IwacSearch\Controller\Admin;

use Closure;
use IwacSearch\Form\MaintenanceForm;
use IwacSearch\Job\BulkReindex;
use IwacSearch\Job\SyncStopwords;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Admin maintenance page for the IwacSearch module.
 *
 * Two actions, both backed by Omeka jobs so neither blocks the request
 * and both leave an audit trail at /admin/job/{id}/log:
 *
 *   indexAction          GET   /admin/iwac-search/maintenance
 *     Renders the page with two POST forms.
 *
 *   reindexAction        POST  /admin/iwac-search/maintenance/reindex
 *     Dispatches IwacSearch\Job\BulkReindex (~5–15 min).
 *     Equivalent to running cli/reindex.php inside the php container.
 *
 *   syncStopwordsAction  POST  /admin/iwac-search/maintenance/sync-stopwords
 *     Dispatches IwacSearch\Job\SyncStopwords (~1 s).
 *     Equivalent to running cli/stopwords-sync.php.
 *
 * ACL: editor + site-admin + global-admin (granted in Module::onBootstrap).
 * The /admin/ parent route already enforces authentication, so the ACL
 * grant just narrows which logged-in roles can reach the page.
 */
class MaintenanceController extends AbstractActionController
{
    /**
     * @param string $collectionBaseName Base collection name from
     *   data/schema.yaml (e.g. "iwac_v1"). Each reindex builds a
     *   timestamped variant — `${baseName}_YYYYMMDD_HHMMSS` — and
     *   atomic-swaps the iwac_current alias to it. We surface the
     *   base name in the page description and flash messages so the
     *   prose stays accurate after every schema bump.
     */
    /**
     * @param Closure(): TypesenseClient|null $clientFactory Lazy client so a
     *   missing Docker secret / unreachable Typesense degrades to an
     *   "unreachable" status panel rather than a 500.
     */
    public function __construct(
        private readonly string $collectionBaseName = 'iwac_v1',
        private readonly ?Closure $clientFactory = null,
        private readonly string $contentAlias = 'iwac_current',
        private readonly string $indexAlias = 'iwac_index_current',
    ) {
    }

    private ?TypesenseClient $cachedClient = null;

    /** Resolve the lazy client once; null if unconfigured/unreachable. */
    private function client(): ?TypesenseClient
    {
        if ($this->clientFactory === null) {
            return null;
        }
        if ($this->cachedClient !== null) {
            return $this->cachedClient;
        }
        try {
            return $this->cachedClient = ($this->clientFactory)();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Render the maintenance page.
     *
     * Two POST forms, each carrying its own CSRF token (re-issued per
     * render via Laminas\Form\Element\Csrf), wrapping a single submit
     * button. Posting redirects to one of the action handlers below.
     *
     * Also passes the live `collectionBaseName` to the view so the
     * description prose can spell out the actual collection name
     * the reindex will build, instead of hardcoding "iwac_v1".
     */
    public function indexAction(): ViewModel
    {
        $reindexForm = $this->getForm(MaintenanceForm::class);
        $stopwordsForm = $this->getForm(MaintenanceForm::class);

        return new ViewModel([
            'reindexForm'         => $reindexForm,
            'stopwordsForm'       => $stopwordsForm,
            'collectionBaseName'  => $this->collectionBaseName,
            'statuses'            => $this->collectStatuses(),
        ]);
    }

    /**
     * One status row per Typesense collection (content + entity index):
     * whether it's reachable and its live document count. `documents` is null
     * when Typesense is unreachable, 0 when the server is up but the
     * collection was never built. Mirrors DRE-Search's maintenance probe.
     *
     * @return list<array{alias: string, label: string, reachable: bool, documents: ?int, error: ?string}>
     */
    private function collectStatuses(): array
    {
        $client = $this->client();
        $targets = [
            ['alias' => $this->contentAlias, 'label' => 'Content (articles, documents, publications, references)'],
            ['alias' => $this->indexAlias,   'label' => 'Entity index (people, places, organisations, topics…)'],
        ];

        $rows = [];
        foreach ($targets as $target) {
            $row = [
                'alias'     => $target['alias'],
                'label'     => $target['label'],
                'reachable' => false,
                'documents' => null,
                'error'     => null,
            ];

            if ($client !== null) {
                try {
                    $info = $client->collections[$target['alias']]->retrieve();
                    $row['reachable'] = true;
                    $row['documents'] = isset($info['num_documents']) ? (int) $info['num_documents'] : null;
                } catch (Throwable $e) {
                    // The collection may simply not exist yet (never reindexed).
                    // Probe health to tell "server down" from "collection absent".
                    try {
                        $client->health->retrieve();
                        $row['reachable'] = true;
                        $row['documents'] = 0;
                    } catch (Throwable $inner) {
                        $row['error'] = $inner->getMessage();
                    }
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * POST: dispatch a BulkReindex job, flash a link to the job log,
     * redirect back to the maintenance page.
     */
    public function reindexAction(): Response
    {
        return $this->dispatchJob(
            BulkReindex::class,
            sprintf(
                'Bulk reindex queued — reads fresh data from the Omeka database, builds a fresh %s_<timestamp> collection alongside the live one, and atomic-swaps the iwac_current alias once verification passes.',
                $this->collectionBaseName
            )
        );
    }

    /**
     * POST: dispatch a SyncStopwords job. Quick (<1 s); the redirect
     * round-trip arrives faster than the user can blink.
     */
    public function syncStopwordsAction(): Response
    {
        return $this->dispatchJob(
            SyncStopwords::class,
            'Stopwords sync queued — refreshes the fr_default set from data/stopwords-fr.json.'
        );
    }

    /**
     * Shared dispatch path: validate the form (= validate CSRF), dispatch
     * the job, attach a flash message linking to the job log, redirect
     * back to the maintenance page.
     *
     * @param class-string $jobClass
     */
    private function dispatchJob(string $jobClass, string $description): Response
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            // GETting the dispatch URL directly = land on the form page.
            return $this->redirect()->toRoute('admin/iwac-search/maintenance');
        }

        $form = $this->getForm(MaintenanceForm::class);
        $form->setData($request->getPost()->toArray());
        if (!$form->isValid()) {
            $this->messenger()->addError('Invalid form submission. Please reload the page and try again.');
            return $this->redirect()->toRoute('admin/iwac-search/maintenance');
        }

        $job = $this->jobDispatcher()->dispatch($jobClass);

        $jobUrl = $this->url()->fromRoute(
            'admin/id',
            ['controller' => 'job', 'id' => $job->getId()]
        );
        $message = new Message(
            '%1$s Track progress: %2$sjob #%3$d%4$s',
            $description,
            sprintf('<a href="%s">', htmlspecialchars($jobUrl, ENT_QUOTES, 'UTF-8')),
            $job->getId(),
            '</a>'
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);

        return $this->redirect()->toRoute('admin/iwac-search/maintenance');
    }
}

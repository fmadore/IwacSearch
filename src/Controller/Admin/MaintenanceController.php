<?php
declare(strict_types=1);

namespace IwacSearch\Controller\Admin;

use IwacSearch\Form\MaintenanceForm;
use IwacSearch\Job\BulkReindex;
use IwacSearch\Job\SyncStopwords;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Stdlib\Message;

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
     * Render the maintenance page.
     *
     * Two POST forms, each carrying its own CSRF token (re-issued per
     * render via Laminas\Form\Element\Csrf), wrapping a single submit
     * button. Posting redirects to one of the action handlers below.
     */
    public function indexAction(): ViewModel
    {
        $reindexForm = $this->getForm(MaintenanceForm::class);
        $stopwordsForm = $this->getForm(MaintenanceForm::class);

        return new ViewModel([
            'reindexForm'   => $reindexForm,
            'stopwordsForm' => $stopwordsForm,
        ]);
    }

    /**
     * POST: dispatch a BulkReindex job, flash a link to the job log,
     * redirect back to the maintenance page.
     */
    public function reindexAction(): Response
    {
        return $this->dispatchJob(
            BulkReindex::class,
            'Bulk reindex queued — pulls fresh data from HuggingFace, rebuilds the iwac_v1 collection, and atomic-swaps the live alias.'
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

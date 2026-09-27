<?php

declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\ChangeDrainer;
use IwacSearch\Indexer\ChangeJournal;
use IwacSearch\Indexer\IncrementalIndexer;
use Omeka\Job\AbstractJob;

/**
 * Applies pending journal rows to Typesense for up to two minutes, then
 * chains another run while a backlog it made progress on remains. Saves
 * dispatch it only when no drain is already active (see DrainJobHistory).
 */
final class DrainChanges extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        $processed = (new ChangeDrainer($connection, $services->get(IncrementalIndexer::class)))->run(fn (): bool => $this->shouldStop());
        if ($processed > 0 && !$this->shouldStop() && (new ChangeJournal($connection))->status()['pending'] > 0) {
            $services->get('Omeka\Job\Dispatcher')->dispatch(self::class);
        }
    }
}

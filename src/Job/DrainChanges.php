<?php

declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\ChangeDrainer;
use IwacSearch\Indexer\ChangeJournal;
use IwacSearch\Indexer\IncrementalIndexer;
use Omeka\Job\AbstractJob;

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

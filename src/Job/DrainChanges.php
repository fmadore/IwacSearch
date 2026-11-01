<?php

declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\ChangeDrainer;
use IwacSearch\Indexer\IncrementalIndexer;
use IwacSearch\Indexer\WorkerLease;
use Omeka\Job\AbstractJob;

/**
 * The coalesced drain worker: applies journal rows to Typesense, looping
 * while saves keep asking for work, and hands a long backlog over to a
 * successor after ChangeDrainer::WORK_SECONDS. Saves dispatch it only when
 * they win the worker lease (see Indexer\WorkerLease); the maintenance
 * page's retry dispatches it directly.
 */
final class DrainChanges extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');
        (new ChangeDrainer($connection, $services->get(IncrementalIndexer::class)))->work(
            new WorkerLease($connection),
            fn (): bool => $this->shouldStop(),
            static fn (): mixed => $services->get('Omeka\Job\Dispatcher')->dispatch(self::class),
        );
    }
}

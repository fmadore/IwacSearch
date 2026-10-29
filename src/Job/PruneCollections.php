<?php

declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\CollectionRetention;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Typesense\Client;

/** The maintenance page's cleanup button (see CollectionRetention). */
final class PruneCollections extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);
        $removed = (new CollectionRetention($services->get('Omeka\Connection'), $services->get(Client::class), $logger))->prune();
        $logger->info('IwacSearch: removed old index generations', ['collections' => $removed]);
    }
}

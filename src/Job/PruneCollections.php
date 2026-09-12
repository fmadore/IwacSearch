<?php

declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\CollectionRetention;
use Omeka\Job\AbstractJob;
use Typesense\Client;

final class PruneCollections extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        (new CollectionRetention($services->get('Omeka\Connection'), $services->get(Client::class)))->prune();
    }
}

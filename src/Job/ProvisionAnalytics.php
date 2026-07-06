<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\AnalyticsSync;
use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Background job: provision the search-analytics rules + destination
 * collections (popular queries / no-hit queries).
 *
 * The bulk reindex also runs AnalyticsSync as its last step, but that
 * sync is deliberately non-fatal — if the Typesense server was started
 * without --enable-search-analytics, the reindex logs a warning and moves
 * on. This job exists so an operator who has just enabled the server
 * flags can provision analytics WITHOUT waiting for (or paying for) the
 * next full reindex — and, unlike the in-reindex pass, it FAILS LOUDLY
 * (job status ERROR) when the server still refuses, because here the
 * operator's intent is analytics itself.
 */
class ProvisionAnalytics extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);

        $logger->info('IwacSearch: provisioning search analytics from Omeka job', [
            'job_id' => $this->job->getId(),
        ]);

        $result = (new AnalyticsSync($typesense, $logger))->sync();

        if (!$result['enabled']) {
            throw new RuntimeException(
                'Analytics provisioning failed — is the Typesense server running with '
                . '--enable-search-analytics=true and --analytics-dir set? Cause: '
                . ($result['error'] ?? 'unknown')
            );
        }

        $logger->info('IwacSearch: analytics provisioned', $result);
    }
}

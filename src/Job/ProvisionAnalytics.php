<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Indexer\AnalyticsSync;
use Psr\Log\LoggerInterface;
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
 * operator's intent is analytics itself. Hence the explicit throw on
 * `enabled: false`: the sync reports rather than raises, so the job has to
 * turn the report into the failure the operator is asking for.
 */
class ProvisionAnalytics extends AbstractTypesenseJob
{
    protected function label(): string
    {
        return 'search analytics provisioning';
    }

    protected function operate(
        TypesenseClient $typesense,
        string $moduleRoot,
        LoggerInterface $logger
    ): array {
        $result = (new AnalyticsSync($typesense, $logger))->sync();

        if (!$result['enabled']) {
            throw new RuntimeException(
                'Analytics provisioning failed — is the Typesense server running with '
                . '--enable-search-analytics=true and --analytics-dir set? Cause: '
                . ($result['error'] ?? 'unknown')
            );
        }

        return $result;
    }
}

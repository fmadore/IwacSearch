<?php
declare(strict_types=1);

namespace IwacSearch\Job;

use IwacSearch\Log\LoggerResolver;
use Omeka\Job\AbstractJob;
use Psr\Log\LoggerInterface;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Shared skeleton for every IwacSearch background job.
 *
 * All four jobs (bulk reindex, stopwords sync, synonyms sync, analytics
 * provisioning) did the same six things around one line of real work:
 * resolve the logger, resolve the Typesense client, compute the module root,
 * log a "starting" line carrying the job id, run the operation inside a
 * try/catch that logs and rethrows (so AbstractJob marks the job ERROR), and
 * log the returned stats. Only {@see operate()} and {@see label()} differed.
 *
 * Rethrowing rather than swallowing is the contract: a job exists so the
 * operator can see the outcome at /admin/job/{id}/log, which means a failure
 * must reach Omeka as a failure. (Individual operations may still choose to
 * be non-fatal internally — AnalyticsSync reports `enabled: false` rather
 * than throwing — and their job decides whether that counts as an error.)
 */
abstract class AbstractTypesenseJob extends AbstractJob
{
    /**
     * Human-readable name of what this job does, used in the log lines
     * ("starting <label>", "<label> failed", "<label> complete").
     */
    abstract protected function label(): string;

    /**
     * The actual work.
     *
     * @param  string $moduleRoot Absolute module directory — the `data/`
     *                            payloads (stopwords, synonyms, schemas) hang
     *                            off it. Resolved once, here, instead of a
     *                            `dirname(__DIR__, 2)` in every job.
     * @return array<string, mixed> Stats to log on success.
     */
    abstract protected function operate(
        TypesenseClient $typesense,
        string $moduleRoot,
        LoggerInterface $logger
    ): array;

    final public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger   = LoggerResolver::fromContainer($services);

        /** @var TypesenseClient $typesense */
        $typesense = $services->get(TypesenseClient::class);

        // src/Job/<Job>.php → module root is two levels up.
        $moduleRoot = dirname(__DIR__, 2);
        $label      = $this->label();

        $logger->info("IwacSearch: starting {$label} from Omeka job", [
            'job_id'      => $this->job->getId(),
            'module_root' => $moduleRoot,
        ]);

        try {
            $stats = $this->operate($typesense, $moduleRoot, $logger);
        } catch (Throwable $e) {
            $logger->error("IwacSearch: {$label} failed", [
                'class'   => $e::class,
                'message' => $e->getMessage(),
            ]);
            // Re-throw so AbstractJob marks the job ERROR.
            throw $e;
        }

        $logger->info("IwacSearch: {$label} complete", $stats);
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Log;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Single source of truth for "give me a PSR-3 logger from the Omeka SL".
 *
 * Replaces this duplicated three-liner in every factory that takes a
 * logger:
 *
 *   $logger = $container->has('Omeka\Logger')
 *       ? new OmekaPsrLogger($container->get('Omeka\Logger'))
 *       : new NullLogger();
 *
 * Three benefits over inlining:
 *
 *   1. The wrap rule (Omeka returns a Laminas\Log\Logger; we need
 *      psr/log 3.x; OmekaPsrLogger bridges) is documented in one place.
 *   2. If we ever want to switch to a structured logger or add
 *      contextual metadata (request id, user id), it's one change here.
 *   3. Factories read the same line, so reviewers don't have to
 *      double-check that all three call sites still match.
 */
final class LoggerResolver
{
    /**
     * Pull the Omeka logger from the container if available, wrapping
     * it for PSR-3 consumers. Falls back to NullLogger when the
     * service isn't bound (e.g. CLI scripts or test harnesses that
     * skip the full bootstrap).
     */
    public static function fromContainer(ContainerInterface $container): LoggerInterface
    {
        if (!$container->has('Omeka\Logger')) {
            return new NullLogger();
        }
        return new OmekaPsrLogger($container->get('Omeka\Logger'));
    }
}

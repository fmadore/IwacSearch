<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Waits for a Typesense that is still starting.
 *
 * A server that has just (re)started answers "Not Ready or Lagging" while it
 * loads its collections from disk — with the IWAC corpus and its embeddings,
 * that takes a while. A rebuild started then (the admin button right after a
 * container restart, a scheduled run after a deploy) failed on its first
 * request. The rebuild now polls /health first, bounded and cancellable, and
 * proceeds either way: a real outage still surfaces as a rebuild failure,
 * with the server's own error.
 */
final class TypesenseReadiness
{
    /** How long a rebuild waits for the server before trying anyway. */
    public const WAIT_SECONDS = 120;

    private const POLL_SECONDS = 3;

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param ?Closure(int): void $sleep test seam; defaults to sleep()
     * @param ?Closure(): int     $clock test seam; defaults to time()
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $waitSeconds = self::WAIT_SECONDS,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * True once /health reports ok; false when the wait ran out or was
     * stopped first.
     *
     * @param ?Closure(): bool $shouldStop
     */
    public function await(?Closure $shouldStop = null): bool
    {
        $deadline = ($this->clock)() + max(0, $this->waitSeconds);
        $warned = false;
        while (true) {
            try {
                if (($this->client->health->retrieve()['ok'] ?? false) === true) {
                    return true;
                }
            } catch (Throwable) {
                // Still loading, or not listening yet: poll again.
            }
            if (($this->clock)() >= $deadline || ($shouldStop !== null && $shouldStop())) {
                if ($warned) {
                    $this->logger->warning('Typesense is still not ready; starting the rebuild anyway');
                }
                return false;
            }
            if (!$warned) {
                $this->logger->info('Waiting for Typesense to finish loading before the rebuild', ['max_seconds' => $this->waitSeconds]);
                $warned = true;
            }
            ($this->sleep)(self::POLL_SECONDS);
        }
    }
}

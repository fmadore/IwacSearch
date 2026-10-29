<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * What GET /discovery/health reports: can the module reach Typesense, and how
 * far behind is the index?
 *
 * Built for an uptime monitor polling an anonymous endpoint, so it is cheap
 * and says nothing an outsider could use:
 *
 *   - Typesense: one GET /health through the admin client's node and key,
 *     but on a client of its own with short timeouts ({@see TIMEOUT_SECONDS}
 *     to connect and to answer, no retry; the page client allows 2 s + 4 s),
 *     so a hung server fails the probe quickly rather than tying up a PHP
 *     worker for as long as a page render may wait. Not routed through the SSR
 *     renderer's circuit breaker either: a monitor wants the server's state
 *     now, not the breaker's memory of it.
 *   - The change journal: the number of recorded changes not yet applied and
 *     the age of the oldest — indexing is eventually consistent, and a
 *     growing backlog is how a stuck drain shows from outside.
 *
 * No host, port, key, collection name or exception text is ever part of the
 * result; a failure is logged with its detail and reported as a bare false.
 * Never throws.
 */
final class HealthProbe
{
    /** Connect and read timeout of the probe's own Typesense client. */
    public const TIMEOUT_SECONDS = 1.5;

    /**
     * @param ?Closure(): TypesenseClient $clientFactory A client on the admin
     *     node, built with TIMEOUT_SECONDS. Null (or one that throws, as a
     *     missing Docker secret does) reports Typesense unreachable.
     * @param ?Closure(): array{pending: int, oldest_seconds: ?int} $backlog The
     *     journal's pending count and the age of its oldest pending change, in
     *     seconds (null when nothing is pending). Null, or one that throws,
     *     reports the journal unreadable.
     */
    public function __construct(
        private readonly ?Closure $clientFactory,
        private readonly ?Closure $backlog,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{ok: bool, typesense: array{reachable: bool}, changes: array{readable: bool, pending: ?int, oldest_pending_seconds: ?int}}
     */
    public function check(): array
    {
        $reachable = $this->typesenseReachable();
        $backlog = $this->readBacklog();

        return [
            // Both are needed for search to be right: without Typesense
            // nothing renders, and without the journal no edit reaches it.
            'ok' => $reachable && $backlog !== null,
            'typesense' => ['reachable' => $reachable],
            'changes' => [
                'readable' => $backlog !== null,
                'pending' => $backlog['pending'] ?? null,
                'oldest_pending_seconds' => $backlog['oldest_seconds'] ?? null,
            ],
        ];
    }

    private function typesenseReachable(): bool
    {
        if ($this->clientFactory === null) {
            return false;
        }
        try {
            $health = ($this->clientFactory)()->health->retrieve();
            return ($health['ok'] ?? false) === true;
        } catch (Throwable $e) {
            $this->logger->warning('IwacSearch health: Typesense is unreachable', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return array{pending: int, oldest_seconds: ?int}|null */
    private function readBacklog(): ?array
    {
        if ($this->backlog === null) {
            return null;
        }
        try {
            $backlog = ($this->backlog)();
            return [
                'pending' => max(0, $backlog['pending']),
                'oldest_seconds' => $backlog['oldest_seconds'] === null ? null : max(0, $backlog['oldest_seconds']),
            ];
        } catch (Throwable $e) {
            $this->logger->warning('IwacSearch health: the change journal could not be read', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Ledger-driven deletion of the collections this module built and no longer
 * needs. Callers hold the rebuild lock, so no build is in flight: a
 * `building` row then belongs to a rebuild that died.
 *
 * Fail closed throughout: a collection any alias targets is never deleted
 * (the server's alias table is read once per call, and when it cannot be
 * read nothing is deleted), and a failed drop keeps its row for next time.
 */
final class GenerationCleanup
{
    public function __construct(
        private readonly CollectionOps $ops,
        private readonly GenerationLedger $ledger,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Drop the builds a killed or crashed rebuild left behind. One an alias
     * targets (a cutover whose rollback failed) serves search, so it is
     * recorded as live instead.
     *
     * @return list<string> collections dropped
     */
    public function recoverOrphans(): array
    {
        $dropped = [];
        $orphans = $this->ledger->generations(GenerationLedger::BUILDING);
        $aliased = $orphans === [] ? [] : $this->aliased();
        if ($aliased === null) {
            return [];
        }
        foreach (array_keys($orphans) as $name) {
            if (isset($aliased[$name])) {
                $this->logger->warning('An unfinished build is served by an alias; recording it as live', ['collection' => $name]);
                $this->ledger->mark($name, GenerationLedger::LIVE);
                continue;
            }
            if ($this->ops->safelyDropCollection($name)) {
                $this->logger->info('Dropped a build left by an interrupted rebuild', ['collection' => $name]);
                $dropped[] = $name;
            }
        }
        return $dropped;
    }

    /**
     * Retention: drop every retired generation no alias targets. A promotion
     * retires all but the live generation and its rollback target, so this
     * keeps exactly those two per alias.
     *
     * @return list<string> collections dropped
     */
    public function removeRetired(): array
    {
        $dropped = [];
        $retired = $this->ledger->generations(GenerationLedger::RETIRED);
        $aliased = $retired === [] ? [] : $this->aliased();
        if ($aliased === null) {
            return [];
        }
        foreach (array_keys($retired) as $name) {
            if (isset($aliased[$name])) {
                // Another alias (another install, a manual one) still uses it.
                continue;
            }
            if ($this->ops->safelyDropCollection($name)) {
                $this->logger->info('Dropped a retired generation', ['collection' => $name]);
                $dropped[] = $name;
            }
        }
        return $dropped;
    }

    /** @return array<string, true>|null alias targets, or null when they cannot be read */
    private function aliased(): ?array
    {
        try {
            return array_fill_keys($this->ops->aliasTargets(), true);
        } catch (Throwable $e) {
            $this->logger->warning('Could not read alias targets; generation cleanup skipped', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

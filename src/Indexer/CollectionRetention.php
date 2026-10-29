<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use IwacSearch\IwacInstance;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Typesense\Client;

/**
 * Explicit maintenance (`cli/maintenance.php prune`, the admin cleanup
 * button); shares the rebuild lock and protects every alias target. Besides
 * old generations, it trims the processed journal rows and the completed
 * drain-job history, which otherwise grow with every save when full rebuilds
 * are rare.
 *
 * Generations are removed in two passes:
 *
 *   1. Ledger-owned ones ({@see GenerationCleanup}): builds an interrupted
 *      rebuild left behind, and retired generations a promotion could not
 *      delete. A promotion normally deletes those itself.
 *   2. Collections the ledger never recorded that follow the module's naming
 *      scheme — builds from before 3.27. The old rule applies to them: older
 *      than seven days, and while the ledger has no rollback target for
 *      their alias, the newest of them is kept as one.
 *
 * The ledger's live and previous generations are never candidates, and
 * neither is any collection an alias targets.
 */
final class CollectionRetention
{
    private const LEGACY_NAME = '/^(iwac_(?:index_)?v[0-9]+)_([0-9]{8}_[0-9]{6})(?:_[a-f0-9]{12})?$/D';

    public function __construct(
        private readonly Connection $connection,
        private readonly Client $client,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @return list<string> */
    public function prune(): array
    {
        $lock = new DatabaseLock($this->connection, 'rebuild');
        $lock->acquire();
        try {
            (new ChangeJournal($this->connection))->prune();
            DrainJobHistory::prune($this->connection);

            $ledger = new DatabaseGenerationLedger($this->connection);
            $ops = new CollectionOps(fn (): Client => $this->client, $this->logger, 'retention', ledger: $ledger);
            $cleanup = new GenerationCleanup($ops, $ledger, $this->logger);
            $removed = [...$cleanup->recoverOrphans(), ...$cleanup->removeRetired()];

            return [...$removed, ...$this->pruneUnrecorded($ops, $ledger)];
        } finally {
            $lock->release();
        }
    }

    /** @return list<string> */
    private function pruneUnrecorded(CollectionOps $ops, GenerationLedger $ledger): array
    {
        $active = array_fill_keys($ops->aliasTargets(), true);
        $recorded = $ledger->generations();
        $hasRollback = [];
        foreach ($recorded as $row) {
            if ($row['status'] === GenerationLedger::PREVIOUS) {
                $hasRollback[$row['alias']] = true;
            }
        }
        $groups = [];
        foreach ($this->client->collections->retrieve() as $collection) {
            $name = (string) $collection['name'];
            if (preg_match(self::LEGACY_NAME, $name, $m) !== 1 || isset($active[$name]) || isset($recorded[$name])) {
                continue;
            }
            $alias = str_starts_with($name, 'iwac_index_') ? IwacInstance::INDEX_ALIAS : IwacInstance::CONTENT_ALIAS;
            $groups[$alias][] = ['name' => $name, 'stamp' => $m[2]];
        }
        $removed = [];
        foreach ($groups as $alias => $group) {
            usort($group, static fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));
            if (!isset($hasRollback[$alias])) {
                array_shift($group); // Without a recorded rollback target, the newest one is it.
            }
            foreach ($group as $entry) {
                $date = \DateTimeImmutable::createFromFormat('!Ymd_His', $entry['stamp'], new \DateTimeZone('UTC'));
                if ($date === false || $date->getTimestamp() > time() - 7 * 86400) {
                    continue;
                }
                $this->client->collections[$entry['name']]->delete();
                $removed[] = $entry['name'];
            }
        }
        return $removed;
    }
}

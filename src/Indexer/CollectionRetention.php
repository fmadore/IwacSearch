<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use Typesense\Client;

/** Explicit maintenance; shares the rebuild lock and protects every alias target. */
final class CollectionRetention
{
    public function __construct(private readonly Connection $connection, private readonly Client $client)
    {
    }
    /** @return list<string> */
    public function prune(): array
    {
        $lock = new DatabaseLock($this->connection, 'rebuild');
        $lock->acquire();
        try {
            $active = array_column($this->client->aliases->retrieve()['aliases'], 'collection_name');
            // Record of the actual outgoing targets survives newer failed builds.
            $active = array_merge($active, $this->connection->fetchFirstColumn('SELECT collection_name FROM iwac_search_rollback WHERE collection_name IS NOT NULL'));
            $groups = [];
            foreach ($this->client->collections->retrieve() as $collection) {
                $name = $collection['name'];
                if (preg_match('/^(iwac_(?:index_)?v[0-9]+)_([0-9]{8}_[0-9]{6})(?:_[a-f0-9]{12})?$/D', $name, $m) !== 1 || in_array($name, $active, true)) {
                    continue;
                }
                $groups[str_starts_with($name, 'iwac_index_') ? 'index' : 'content'][] = ['name' => $name, 'stamp' => $m[2]];
            }
            $removed = [];
            foreach ($groups as $group) {
                usort($group, static fn (array $a, array $b): int => strcmp($b['stamp'], $a['stamp']));
                array_shift($group); // Always retain the newest inactive generation for rollback.
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
        } finally {
            $lock->release();
        }
    }
}

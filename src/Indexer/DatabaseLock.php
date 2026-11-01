<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** MySQL advisory lock shared by HTTP requests, jobs, and the CLI. */
final class DatabaseLock implements AdvisoryLock
{
    private int $depth = 0;
    private readonly string $name;

    /**
     * The lock name is namespaced by database, so two installs sharing a
     * MySQL server never contend. Pass $database when building several locks
     * at once (the mutation stripes) to read it only once.
     */
    public function __construct(private readonly Connection $connection, string $purpose, ?string $database = null)
    {
        $database ??= self::database($connection);
        $this->name = 'iwac_search:' . substr(hash('sha256', $database), 0, 16) . ':' . $purpose;
    }

    /** The current database's name, the namespace of every lock. */
    public static function database(Connection $connection): string
    {
        return (string) $connection->executeQuery('SELECT DATABASE()')->fetchOne();
    }

    public function acquire(int $seconds = 0): void
    {
        if (!$this->tryAcquire($seconds)) {
            throw new RuntimeException('IwacSearch operation already running: ' . $this->name);
        }
    }

    public function tryAcquire(int $seconds = 0): bool
    {
        if ($this->depth > 0) {
            $this->depth++;
            return true;
        }
        $result = $this->connection->executeQuery('SELECT GET_LOCK(?, ?)', [$this->name, $seconds])->fetchOne();
        if ($result === null || $result === false) {
            throw new RuntimeException('Database advisory locking is unavailable.');
        }
        if ((int) $result !== 1) {
            return false;
        }
        $this->depth = 1;
        return true;
    }

    public function __destruct()
    {
        if ($this->depth > 0) {
            $this->depth = 1;
            try {
                $this->release();
            } catch (\Throwable) { /* Connection close releases the lock. */
            }
        }
    }

    public function release(): void
    {
        if ($this->depth > 0 && --$this->depth === 0) {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(?)', [$this->name])->fetchOne();
        }
    }
}

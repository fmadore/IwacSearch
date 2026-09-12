<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** MySQL advisory lock shared by HTTP requests, jobs, and the CLI. */
final class DatabaseLock
{
    private int $depth = 0;
    private readonly string $name;

    public function __construct(private readonly Connection $connection, string $purpose)
    {
        $database = (string) $connection->executeQuery('SELECT DATABASE()')->fetchOne();
        $this->name = 'iwac_search:' . substr(hash('sha256', $database), 0, 16) . ':' . $purpose;
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

<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use IwacSearch\Indexer\AdvisoryLock;

/**
 * MySQL's named locks, in memory: a lock is owned by one session at a time
 * and is re-entrant within it (counted, like GET_LOCK since MySQL 5.7). Each
 * "session" stands for one PHP process's connection, so tests can model
 * several processes contending without a database.
 */
final class FakeLockServer
{
    /** @var array<string, array{session: string, count: int}> */
    public array $owners = [];

    public function lock(string $name, string $session): AdvisoryLock
    {
        return new FakeNamedLock($this, $name, $session);
    }

    /** @return list<AdvisoryLock> one lock per stripe name, for one session */
    public function stripes(string $session, int $count = 8): array
    {
        $locks = [];
        for ($i = 0; $i < $count; $i++) {
            $locks[] = $this->lock('mutation:' . $i, $session);
        }
        return $locks;
    }

    /** @return list<string> lock names $session holds */
    public function heldBy(string $session): array
    {
        return array_keys(array_filter($this->owners, static fn (array $owner): bool => $owner['session'] === $session));
    }
}

/** @internal */
final class FakeNamedLock implements AdvisoryLock
{
    public function __construct(
        private readonly FakeLockServer $server,
        private readonly string $name,
        private readonly string $session,
    ) {
    }

    public function acquire(int $seconds = 0): void
    {
        if (!$this->tryAcquire($seconds)) {
            throw new \RuntimeException('lock busy: ' . $this->name);
        }
    }

    /** A wait cannot end in a single-threaded test: a busy lock stays busy. */
    public function tryAcquire(int $seconds = 0): bool
    {
        $owner = $this->server->owners[$this->name] ?? null;
        if ($owner !== null && $owner['session'] !== $this->session) {
            return false;
        }
        $this->server->owners[$this->name] = ['session' => $this->session, 'count' => ($owner['count'] ?? 0) + 1];
        return true;
    }

    public function release(): void
    {
        $owner = $this->server->owners[$this->name] ?? null;
        if ($owner === null || $owner['session'] !== $this->session) {
            throw new \RuntimeException('released a lock this session does not hold: ' . $this->name);
        }
        if ($owner['count'] <= 1) {
            unset($this->server->owners[$this->name]);
        } else {
            $this->server->owners[$this->name]['count']--;
        }
    }
}

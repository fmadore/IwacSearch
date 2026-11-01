<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use IwacSearch\Indexer\AdvisoryLock;

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

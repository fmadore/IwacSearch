<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Remembers which mounted search-parent keys already passed scope
 * validation, so `/discovery/token` does not list every key on the Typesense
 * server (an admin `GET /keys`) on each mint.
 *
 * Safe to cache: a Typesense key's actions and collections are immutable, so
 * a key that validated once keeps validating until it is deleted. Entries are
 * keyed by a hash of the key value AND the configured scope, so rotating the
 * secret file or changing the scope revalidates at once. The TTL bounds how
 * long a key deleted on the server keeps passing the check (its searches
 * already fail with 401 regardless).
 *
 * APCu when available (shared across PHP-FPM requests); otherwise it only
 * remembers within this instance — the previous behaviour, validating per
 * request.
 */
final class ValidatedKeyMemo
{
    private const PREFIX = 'iwac_search.parent_key_ok.';

    /** @var array<string, true> */
    private array $local = [];

    public function __construct(private readonly int $ttlSeconds = 600)
    {
    }

    public function has(string $fingerprint): bool
    {
        if (isset($this->local[$fingerprint])) {
            return true;
        }
        if (!$this->apcu()) {
            return false;
        }
        $ok = false;
        apcu_fetch(self::PREFIX . $fingerprint, $ok);
        return $ok;
    }

    public function remember(string $fingerprint): void
    {
        $this->local[$fingerprint] = true;
        if ($this->apcu()) {
            apcu_store(self::PREFIX . $fingerprint, true, $this->ttlSeconds);
        }
    }

    private function apcu(): bool
    {
        return $this->ttlSeconds > 0 && function_exists('apcu_enabled') && apcu_enabled();
    }
}

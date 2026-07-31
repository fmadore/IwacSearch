<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use Closure;
use IwacSearch\Util\ExceptionMessage;
use Omeka\Settings\SettingsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Issues short-lived Typesense scoped search keys for the public client.
 *
 * Two-tier key model:
 *
 *   1. ADMIN key  — read from /run/secrets/typesense_api_key by
 *      TypesenseClientFactory. Full read/write. Never reaches the browser
 *      or the database. Used only by PHP-side indexer and key bootstrap.
 *
 *   2. SEARCH-ONLY parent key — created ONCE in Typesense via the admin
 *      key, restricted to documents:search over $collectionScope.
 *      Cached in Omeka settings (`iwac_search_typesense_search_key`)
 *      because Typesense never echoes a key value back after creation.
 *      Still never sent to the browser directly — only used to sign
 *      scoped keys.
 *
 *   3. SCOPED key  — minted client-side by HMAC-signing a small JSON
 *      payload (filter_by + exclude_fields + expires_at) with the
 *      parent search-only key. The browser receives THIS, valid for
 *      $expiresInSeconds (default 1h).
 *
 * Production hardening: instead of caching the search-only key in Omeka
 * settings, mount it as a second Docker secret (e.g.
 * /run/secrets/typesense_search_key) and override
 * resolveSearchOnlyKey() to read from that path. Settings storage is
 * convenient for dev but the value is plaintext in the database.
 */
final class TypesenseSearchKeyProvider
{
    private const SETTINGS_KEY    = 'iwac_search_typesense_search_key';
    private const KEY_DESCRIPTION = 'IwacSearch public search-only parent (auto-created)';

    /**
     * Collection scope the parent key is created with when the deployment
     * doesn't configure one. `*` = every collection, present and future.
     *
     * @var list<string>
     */
    public const DEFAULT_COLLECTION_SCOPE = ['*'];

    /**
     * The tightened scope this module would like to run with, offered as a
     * ready-made value for `iwac_search.public_search_key.collections`.
     *
     * It names both aliases AND the versioned collections behind them
     * (`iwac_v*` / `iwac_index_v*`, matched by Typesense's trailing-`*`
     * prefix rule), because the Typesense docs don't say whether a key scope
     * is matched against the alias the client requests or the collection the
     * alias resolves to. Covering both makes the answer moot. What it leaves
     * out is the point: the analytics collections, which hold visitor query
     * logs and are today reachable by any public scoped key.
     *
     * Not the default because getting a key scope wrong takes down public
     * search entirely, and this module cannot verify the match semantics
     * without a live container. Set it in config, reload, search once — if
     * the scope is wrong the key is re-minted by reverting the config line,
     * with no code deploy and no manual settings surgery (see
     * {@see settingsKey()}).
     *
     * @var list<string>
     */
    public const TIGHTENED_COLLECTION_SCOPE = [
        'iwac_current',
        'iwac_index_current',
        'iwac_v*',
        'iwac_index_v*',
    ];

    public function __construct(
        // Lazily-resolved, memoizing client factory (TypesenseClientLazy):
        // construction is deferred to first use so any missing Docker secret
        // / unreachable Typesense surfaces inside mintPublicScopedKey(),
        // where SearchController::tokenAction can catch and return a clean
        // JSON 503 (instead of a 500 HTML page from an exception escaping
        // the Laminas dispatcher).
        /** @var Closure(): TypesenseClient */
        private readonly Closure $clientFactory,
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional override file for production deployments that prefer
        // not to keep the search-only key in the database.
        private readonly string $searchKeyFile = '/run/secrets/typesense_search_key',
        // Collections the parent key may search. Changing this re-mints the
        // key on next use (the settings slot is keyed by the scope), so an
        // operator can try TIGHTENED_COLLECTION_SCOPE and roll back with a
        // single config line.
        /** @var list<string> */
        private readonly array $collectionScope = self::DEFAULT_COLLECTION_SCOPE,
    ) {
    }

    /**
     * Mint a short-lived scoped key for the public client.
     *
     * Public constraints (belt-and-suspenders, see roadmap "Security model"):
     *   - filter_by: is_public:=true   — only public docs ever returned
     *   - exclude_fields: ocr_text,toc_txt — full bodies never ship, highlights only
     *   - expires_at                   — defaults to now+1h
     *
     * @return array{key: string, expires_at: int, host: string, collection: string}
     */
    public function mintPublicScopedKey(
        string $collectionAlias = 'iwac_current',
        int $expiresInSeconds = 3600,
        string $browserHost = '/search-api'
    ): array {
        $parent    = $this->resolveSearchOnlyKey();
        $expiresAt = time() + max(60, $expiresInSeconds);

        $scoped = ($this->clientFactory)()->keys->generateScopedSearchKey($parent, [
            'filter_by'      => 'is_public:=true',
            'exclude_fields' => 'ocr_text,toc_txt',
            'expires_at'     => $expiresAt,
        ]);

        return [
            'key'        => $scoped,
            'expires_at' => $expiresAt,
            'host'       => $browserHost,
            'collection' => $collectionAlias,
        ];
    }

    /**
     * Returns the search-only parent key, bootstrapping it on first use.
     *
     * Resolution order:
     *   1. /run/secrets/typesense_search_key (production override)
     *   2. Cached value in Omeka settings (default path)
     *   3. Create a new key in Typesense, cache, return
     */
    private function resolveSearchOnlyKey(): string
    {
        if (is_readable($this->searchKeyFile)) {
            $fromFile = trim((string) file_get_contents($this->searchKeyFile));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        $cached = $this->settings->get($this->settingsKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->bootstrapSearchOnlyKey();
    }

    /**
     * Settings slot holding the cached parent key.
     *
     * The slot name carries a hash of the collection scope, so a key minted
     * under one scope is never reused under another: change the config and
     * the next request mints a fresh key with the new scope; change it back
     * and the original key is found again, still valid. This replaces the
     * old manual ritual of editing a constant to invalidate the cache, which
     * only worked if whoever tightened the scope remembered to do it — and
     * silently kept serving a wide-scope key if they didn't.
     *
     * The default scope keeps the bare, historical slot name so existing
     * installs don't re-mint on upgrade for a scope that hasn't changed.
     */
    private function settingsKey(): string
    {
        $scope = $this->collectionScope;
        sort($scope); // order is not part of the scope's meaning
        if ($scope === self::DEFAULT_COLLECTION_SCOPE) {
            return self::SETTINGS_KEY;
        }
        return self::SETTINGS_KEY . '_' . substr(hash('xxh128', implode(',', $scope)), 0, 12);
    }

    private function bootstrapSearchOnlyKey(): string
    {
        $this->logger->info('Bootstrapping Typesense search-only parent key', [
            'collections' => $this->collectionScope,
        ]);

        try {
            $response = ($this->clientFactory)()->keys->create([
                'description' => self::KEY_DESCRIPTION,
                // documents:search is the ONLY allowed action — anything
                // else (e.g. documents:create) would let scoped keys derived
                // from this one perform writes if a downstream signing call
                // forgot to constrain them.
                'actions'     => ['documents:search'],
                // Scope defaults to '*': scoped keys derived from this one
                // can address ANY collection, including the analytics ones
                // holding visitor query logs. Today those are protected only
                // incidentally, by the scoped filter `is_public:=true`
                // erroring on collections that lack the field — which is a
                // side effect, not a control. TIGHTENED_COLLECTION_SCOPE is
                // the intended value; see its docblock for why it isn't the
                // default and how to switch safely.
                'collections' => $this->collectionScope,
            ]);
        } catch (Throwable $e) {
            // Flatten the whole exception chain into the message so
            // tokenAction's 503 JSON `detail` field exposes the root
            // cause (e.g. Laminas wraps `ServiceNotCreatedException`
            // around the real "secret file not readable" or
            // "connection refused" error — surfacing only the wrapper
            // forces ops to dig into log files that may not exist).
            throw new RuntimeException(
                'Failed to bootstrap Typesense search-only key: ' . ExceptionMessage::chain($e),
                0,
                $e
            );
        }

        $value = $response['value'] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(
                'Typesense did not return a key value on creation — cannot proceed'
            );
        }

        // Persist immediately. Typesense will not echo this value back
        // again on subsequent /keys calls — only metadata.
        $this->settings->set($this->settingsKey(), $value);
        $this->logger->info('Search-only key cached in Omeka settings', [
            'key_id' => $response['id'] ?? null,
        ]);

        return $value;
    }
}

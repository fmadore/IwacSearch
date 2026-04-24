<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use Closure;
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
 *      key, restricted to documents:search across all collections.
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
     * Lazily-resolved Typesense client, cached per instance. The factory
     * closure is invoked on first use — deferring construction so any
     * missing Docker secret / unreachable Typesense surfaces inside
     * mintPublicScopedKey(), where SearchController::tokenAction can
     * catch and return a clean JSON 503 (instead of a 500 HTML page
     * from an exception escaping the Laminas dispatcher).
     */
    private ?TypesenseClient $cachedClient = null;

    public function __construct(
        /** @var Closure(): TypesenseClient */
        private readonly Closure $clientFactory,
        private readonly SettingsInterface $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional override file for production deployments that prefer
        // not to keep the search-only key in the database.
        private readonly string $searchKeyFile = '/run/secrets/typesense_search_key'
    ) {
    }

    private function client(): TypesenseClient
    {
        return $this->cachedClient ??= ($this->clientFactory)();
    }

    /**
     * Mint a short-lived scoped key for the public client.
     *
     * Public constraints (belt-and-suspenders, see roadmap "Security model"):
     *   - filter_by: is_public:=true   — only public docs ever returned
     *   - exclude_fields: ocr_text     — full OCR never ships, highlights only
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

        $scoped = $this->client()->keys->generateScopedSearchKey($parent, [
            'filter_by'      => 'is_public:=true',
            'exclude_fields' => 'ocr_text',
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

        $cached = $this->settings->get(self::SETTINGS_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->bootstrapSearchOnlyKey();
    }

    private function bootstrapSearchOnlyKey(): string
    {
        $this->logger->info('Bootstrapping Typesense search-only parent key');

        try {
            $response = $this->client()->keys->create([
                'description' => self::KEY_DESCRIPTION,
                // documents:search is the ONLY allowed action — anything
                // else (e.g. documents:create) would let scoped keys derived
                // from this one perform writes if a downstream signing call
                // forgot to constrain them.
                'actions'     => ['documents:search'],
                // '*' covers iwac_current today and any future collections.
                // Tighten to ['iwac_current'] if you want stricter scoping.
                'collections' => ['*'],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Failed to bootstrap Typesense search-only key: ' . $e->getMessage(),
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
        $this->settings->set(self::SETTINGS_KEY, $value);
        $this->logger->info('Search-only key cached in Omeka settings', [
            'key_id' => $response['id'] ?? null,
        ]);

        return $value;
    }
}

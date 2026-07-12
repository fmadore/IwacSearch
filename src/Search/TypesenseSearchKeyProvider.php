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
        private readonly string $searchKeyFile = '/run/secrets/typesense_search_key'
    ) {
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

        $scoped = ($this->clientFactory)()->keys->generateScopedSearchKey($parent, [
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
            $response = ($this->clientFactory)()->keys->create([
                'description' => self::KEY_DESCRIPTION,
                // documents:search is the ONLY allowed action — anything
                // else (e.g. documents:create) would let scoped keys derived
                // from this one perform writes if a downstream signing call
                // forgot to constrain them.
                'actions'     => ['documents:search'],
                // '*' means scoped keys derived from this one can address ANY
                // collection — including future ones (e.g. the analytics
                // collections, which hold visitor query logs). Today that is
                // blocked only incidentally: the scoped filter `is_public:=true`
                // errors on collections lacking the field. Tightening this to
                // ['iwac_current', 'iwac_index_current'] is the right move but
                // MUST be verified on the live container first — the Typesense
                // docs don't spell out whether key scopes match the requested
                // alias name or the resolved collection name (same caveat as
                // the analytics rules, see ROADMAP.md). When tightening, bump
                // SETTINGS_KEY so cached wide-scope keys are re-minted.
                'collections' => ['*'],
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
        $this->settings->set(self::SETTINGS_KEY, $value);
        $this->logger->info('Search-only key cached in Omeka settings', [
            'key_id' => $response['id'] ?? null,
        ]);

        return $value;
    }
}

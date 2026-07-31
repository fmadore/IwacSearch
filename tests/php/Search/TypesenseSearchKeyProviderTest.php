<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\TypesenseSearchKeyProvider;
use IwacSearch\Tests\Support\CallLog;
use Omeka\Settings\SettingsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;
use Typesense\Keys;

/**
 * This class is the module's privacy boundary: everything the browser is
 * allowed to see is decided here. The assertions that matter are therefore
 * the constraints baked into the scoped key (they are hardcoded on purpose —
 * no config may loosen them), and the cache behaviour around the parent key,
 * where the failure mode is silent: a key minted under a wide scope being
 * reused after someone tightened the scope would look exactly like success.
 */
#[CoversClass(TypesenseSearchKeyProvider::class)]
final class TypesenseSearchKeyProviderTest extends TestCase
{
    /** Schemas passed to keys->create(), in order. */
    private CallLog $created;

    /** generateScopedSearchKey() calls: ['key' => parent, 'params' => …]. */
    private CallLog $signed;

    protected function setUp(): void
    {
        $this->created = new CallLog();
        $this->signed  = new CallLog();
    }

    /** @param list<string>|null $scope */
    private function provider(
        FakeSettings $settings,
        ?array $scope = null,
        string $searchKeyFile = '/nonexistent/typesense_search_key'
    ): TypesenseSearchKeyProvider {
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->keys = new class ($this->created, $this->signed) extends Keys {
            public function __construct(private CallLog $created, private CallLog $signed)
            {
            }

            /**
             * @param  array<string, mixed> $schema
             * @return array<string, mixed>
             */
            public function create(array $schema): array
            {
                $this->created->record($schema);
                // Numbered off the shared log, not a per-instance counter:
                // several tests build more than one provider and each gets
                // its own fake client, but the keys must stay distinguishable.
                $n = $this->created->count();
                return ['id' => $n, 'value' => 'parent-key-' . $n];
            }

            /** @param array<string, mixed> $parameters */
            public function generateScopedSearchKey(string $key, array $parameters): string
            {
                $this->signed->record(['key' => $key, 'params' => $parameters]);
                return 'scoped(' . $key . ')';
            }
        };

        return new TypesenseSearchKeyProvider(
            clientFactory:   static fn(): TypesenseClient => $client,
            settings:        $settings,
            searchKeyFile:   $searchKeyFile,
            collectionScope: $scope ?? TypesenseSearchKeyProvider::DEFAULT_COLLECTION_SCOPE,
        );
    }

    // ---- the constraints that make the key safe to hand to a browser ----

    public function testScopedKeyAlwaysCarriesBothPrivacyConstraints(): void
    {
        $this->provider(new FakeSettings())->mintPublicScopedKey();

        $params = $this->signed->entries[0]['params'];
        self::assertSame('is_public:=true', $params['filter_by']);
        self::assertSame('ocr_text,toc_txt', $params['exclude_fields']);
    }

    public function testScopedKeyExpires(): void
    {
        $before = time();
        $minted = $this->provider(new FakeSettings())->mintPublicScopedKey(expiresInSeconds: 600);

        self::assertGreaterThanOrEqual($before + 600, $minted['expires_at']);
        self::assertSame($minted['expires_at'], $this->signed->entries[0]['params']['expires_at']);
    }

    /**
     * A caller asking for a 1-second key would otherwise mint something that
     * expires before the page finishes loading.
     */
    public function testExpiryIsFloored(): void
    {
        $before = time();
        $minted = $this->provider(new FakeSettings())->mintPublicScopedKey(expiresInSeconds: 1);

        self::assertGreaterThanOrEqual($before + 60, $minted['expires_at']);
    }

    public function testMintedPayloadCarriesAliasAndBrowserHost(): void
    {
        $minted = $this->provider(new FakeSettings())->mintPublicScopedKey(
            collectionAlias: 'iwac_index_current',
            browserHost: '/search-api'
        );

        self::assertSame('iwac_index_current', $minted['collection']);
        self::assertSame('/search-api', $minted['host']);
        self::assertSame('scoped(parent-key-1)', $minted['key']);
    }

    // ---- parent key resolution ----

    public function testParentKeyIsCreatedOnceAndCached(): void
    {
        $settings = new FakeSettings();
        $provider = $this->provider($settings);

        $provider->mintPublicScopedKey();
        $provider->mintPublicScopedKey();

        self::assertCount(1, $this->created->entries, 'second mint must reuse the cached parent key');
        self::assertSame('parent-key-1', $settings->get('iwac_search_typesense_search_key'));
    }

    public function testParentKeyIsSearchOnly(): void
    {
        $this->provider(new FakeSettings())->mintPublicScopedKey();

        self::assertSame(['documents:search'], $this->created->entries[0]['actions']);
    }

    public function testSecretFileWinsOverSettings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'iwac');
        self::assertIsString($file);
        file_put_contents($file, "  key-from-secret-file\n");

        $settings = new FakeSettings(['iwac_search_typesense_search_key' => 'key-from-settings']);
        try {
            $this->provider($settings, searchKeyFile: $file)->mintPublicScopedKey();
        } finally {
            unlink($file);
        }

        self::assertSame([], $this->created->entries, 'a mounted secret must not trigger a bootstrap');
        self::assertSame('key-from-secret-file', $this->signed->entries[0]['key']);
    }

    public function testEmptySecretFileFallsBackToSettings(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'iwac');
        self::assertIsString($file);
        file_put_contents($file, "\n");

        $settings = new FakeSettings(['iwac_search_typesense_search_key' => 'key-from-settings']);
        try {
            $this->provider($settings, searchKeyFile: $file)->mintPublicScopedKey();
        } finally {
            unlink($file);
        }

        self::assertSame('key-from-settings', $this->signed->entries[0]['key']);
    }

    // ---- collection scope ----

    public function testDefaultScopeIsWideAndUsesTheHistoricalSettingsSlot(): void
    {
        $settings = new FakeSettings();
        $this->provider($settings)->mintPublicScopedKey();

        self::assertSame(['*'], $this->created->entries[0]['collections']);
        self::assertSame(
            ['iwac_search_typesense_search_key'],
            array_keys($settings->all()),
            'existing installs must not re-mint on upgrade'
        );
    }

    public function testConfiguredScopeIsPassedToTypesense(): void
    {
        $scope = TypesenseSearchKeyProvider::TIGHTENED_COLLECTION_SCOPE;
        $this->provider(new FakeSettings(), $scope)->mintPublicScopedKey();

        self::assertSame($scope, $this->created->entries[0]['collections']);
    }

    /**
     * The whole point of hashing the scope into the settings slot: a key
     * minted while the scope was `*` must never be served after someone
     * tightened it, or the tightening is a no-op nobody notices.
     */
    public function testTighteningTheScopeReMintsTheParentKey(): void
    {
        $settings = new FakeSettings();
        $this->provider($settings)->mintPublicScopedKey();
        $this->provider($settings, TypesenseSearchKeyProvider::TIGHTENED_COLLECTION_SCOPE)
            ->mintPublicScopedKey();

        self::assertCount(2, $this->created->entries);
        self::assertSame(['*'], $this->created->entries[0]['collections']);
        self::assertSame('parent-key-2', $this->signed->entries[1]['key']);
    }

    /** Reverting the config finds the old key again — rollback needs no surgery. */
    public function testRevertingTheScopeReusesTheOriginalKey(): void
    {
        $settings = new FakeSettings();
        $this->provider($settings)->mintPublicScopedKey();
        $this->provider($settings, TypesenseSearchKeyProvider::TIGHTENED_COLLECTION_SCOPE)
            ->mintPublicScopedKey();
        $this->provider($settings)->mintPublicScopedKey();

        self::assertCount(2, $this->created->entries, 'the reverted scope must hit its cached key');
        self::assertSame('parent-key-1', $this->signed->entries[2]['key']);
    }

    /** Order is presentation, not meaning — reordering the list is not a new scope. */
    public function testScopeOrderDoesNotAffectTheCacheSlot(): void
    {
        $settings = new FakeSettings();
        $this->provider($settings, ['iwac_current', 'iwac_index_current'])->mintPublicScopedKey();
        $this->provider($settings, ['iwac_index_current', 'iwac_current'])->mintPublicScopedKey();

        self::assertCount(1, $this->created->entries);
    }

    // ---- failure modes ----

    public function testCreateFailureIsWrappedWithTheFullChain(): void
    {
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->keys = new class extends Keys {
            public function __construct()
            {
            }

            /**
             * @param  array<string, mixed> $schema
             * @return array<string, mixed>
             */
            public function create(array $schema): array
            {
                throw new RuntimeException('connection refused', 0, new RuntimeException('no route'));
            }
        };

        $provider = new TypesenseSearchKeyProvider(
            clientFactory: static fn(): TypesenseClient => $client,
            settings:      new FakeSettings(),
            searchKeyFile: '/nonexistent/typesense_search_key',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/connection refused.*no route/s');
        $provider->mintPublicScopedKey();
    }

    /** A key that was never returned must not be cached as the empty string. */
    public function testValuelessCreateResponseIsRejected(): void
    {
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->keys = new class extends Keys {
            public function __construct()
            {
            }

            /**
             * @param  array<string, mixed> $schema
             * @return array<string, mixed>
             */
            public function create(array $schema): array
            {
                return ['id' => 7];
            }
        };

        $settings = new FakeSettings();
        $provider = new TypesenseSearchKeyProvider(
            clientFactory: static fn(): TypesenseClient => $client,
            settings:      $settings,
            searchKeyFile: '/nonexistent/typesense_search_key',
        );

        try {
            $provider->mintPublicScopedKey();
            self::fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('did not return a key value', $e->getMessage());
        }
        self::assertSame([], $settings->all());
    }
}

/** In-memory Omeka settings. */
final class FakeSettings implements SettingsInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /**
     * Signatures mirror Omeka's untyped SettingsInterface — a parameter type
     * cannot be narrowed in an implementation, so the types live in PHPDoc.
     *
     * @param  mixed $id
     * @param  mixed $value
     * @return void
     */
    public function set($id, $value)
    {
        $this->values[(string) $id] = $value;
    }

    /**
     * @param  mixed $id
     * @param  mixed $default
     * @return mixed
     */
    public function get($id, $default = null)
    {
        return $this->values[(string) $id] ?? $default;
    }

    /**
     * @param  mixed $id
     * @return void
     */
    public function delete($id)
    {
        unset($this->values[(string) $id]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}

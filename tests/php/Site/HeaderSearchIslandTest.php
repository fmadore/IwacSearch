<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Site;

use IwacSearch\Site\HeaderSearchIsland;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The header typeahead's config island, printed on every public page. It
 * replaced an executable inline script, the one block that kept the site
 * from a `script-src 'self'` CSP; what is pinned here is that the island is
 * inert and well formed, and that header.ts looks for the same id.
 */
#[CoversClass(HeaderSearchIsland::class)]
final class HeaderSearchIslandTest extends TestCase
{
    public function testTheJsonCannotCloseItsScriptElement(): void
    {
        $json = HeaderSearchIsland::json([
            'endpoints' => ['token' => '/discovery/token', 'search' => '/search-api/multi_search'],
            'locale' => 'fr',
            'stylesheet' => '/modules/IwacSearch/asset/dist/iwac-search-header.css?v=3.29.0&x=</script><!--',
        ]);

        self::assertNotNull($json);
        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        // …while the values read back unchanged.
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('/modules/IwacSearch/asset/dist/iwac-search-header.css?v=3.29.0&x=</script><!--', $decoded['stylesheet']);
        self::assertSame('/discovery/token', $decoded['endpoints']['token']);
    }

    public function testTheClientReadsTheSameId(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/src/svelte/header.ts');

        self::assertStringContainsString(
            "CONFIG_ISLAND_ID = '" . HeaderSearchIsland::ID . "'",
            $header
        );
    }

    public function testTheModulePrintsAnInertIslandAndNoInlineScript(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 3) . '/Module.php');

        self::assertStringContainsString("'application/json'", $module);
        self::assertStringContainsString("'noescape' => true", $module);
        self::assertStringNotContainsString('window.IWAC_HEADER_SEARCH', $module);
    }
}

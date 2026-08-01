<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Controller\LegacySearchController;
use IwacSearch\Search\LegacySearchRedirect;
use Laminas\Router\Http\Literal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacySearchRedirectTest extends TestCase
{
    public function testBareCanonicalUrlIsPreservedWithoutUsefulQueryText(): void
    {
        self::assertSame(
            '/s/westafrica/search/everything',
            LegacySearchRedirect::targetUrl(
                '/s/westafrica/search/everything',
                '  ',
                ['not' => 'scalar'],
            )
        );
    }

    /**
     * @param mixed $query
     * @param mixed $fulltextSearch
     */
    #[DataProvider('queryProvider')]
    public function testUsefulLegacyQueryIsMappedToTypesenseQuery(
        mixed $query,
        mixed $fulltextSearch,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            LegacySearchRedirect::targetUrl(
                '/s/afrique_ouest/recherche/tout',
                $query,
                $fulltextSearch,
            )
        );
    }

    /** @return iterable<string, array{mixed, mixed, string}> */
    public static function queryProvider(): iterable
    {
        yield 'q takes precedence' => [
            '  islam & politique  ',
            'ignored',
            '/s/afrique_ouest/recherche/tout?q=islam%20%26%20politique',
        ];
        yield 'blank q falls back to fulltext_search' => [
            '',
            '  Côte d’Ivoire  ',
            '/s/afrique_ouest/recherche/tout?q=C%C3%B4te%20d%E2%80%99Ivoire',
        ];
        yield 'non-scalar q falls back to fulltext_search' => [
            ['invalid'],
            'Ghana',
            '/s/afrique_ouest/recherche/tout?q=Ghana',
        ];
    }

    public function testCanonicalParametersAndFragmentArePreserved(): void
    {
        self::assertSame(
            '/search/everything?tab=content&q=ramadan#results',
            LegacySearchRedirect::targetUrl(
                '/search/everything?tab=content#results',
                'ramadan',
                null,
            )
        );
    }

    public function testAllLegacyPublicSearchRoutesUseTheRedirectController(): void
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 3) . '/config/module.config.php';
        $routes = $config['router']['routes']['site']['child_routes'];

        $expected = [
            'iwac-legacy-item-search' => '/item/search',
            'iwac-legacy-item-set-search' => '/item-set/search',
            'iwac-legacy-media-search' => '/media/search',
        ];

        foreach ($expected as $name => $path) {
            self::assertArrayHasKey($name, $routes);
            self::assertSame(Literal::class, $routes[$name]['type']);
            self::assertSame($path, $routes[$name]['options']['route']);
            self::assertSame(1000, $routes[$name]['priority']);
            self::assertSame(
                LegacySearchController::class,
                $routes[$name]['options']['defaults']['controller']
            );
            self::assertSame(
                'redirect',
                $routes[$name]['options']['defaults']['action']
            );
        }
    }
}

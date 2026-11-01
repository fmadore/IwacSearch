<?php

declare(strict_types=1);

namespace IwacSearch\Tests\View;

use IwacSearch\View\Helper\IwacSearchUrl;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The action of the theme's header search form: the federated surface of
 * the visitor's own language site, and the global one off-site. The theme
 * renders it on every page, so every failure must still yield a working
 * URL, never an exception.
 */
#[CoversClass(IwacSearchUrl::class)]
final class IwacSearchUrlTest extends TestCase
{
    /**
     * @param string|RuntimeException $locale what iwacLocale() answers
     */
    private static function urlFor(?object $site, string|RuntimeException $locale = 'fr', bool $routeFails = false): string
    {
        $view = new class ($site, $locale, $routeFails) extends PhpRenderer {
            public function __construct(
                private readonly ?object $site,
                private readonly string|RuntimeException $locale,
                private readonly bool $routeFails,
            ) {
            }

            public function currentSite(): ?object
            {
                return $this->site;
            }

            public function iwacLocale(): string
            {
                if ($this->locale instanceof RuntimeException) {
                    throw $this->locale;
                }
                return $this->locale;
            }

            /** @param array<string, mixed> $params */
            public function url(string $name, array $params): string
            {
                if ($this->routeFails) {
                    throw new RuntimeException('route not registered');
                }
                return '/omeka/' . $name . '?' . http_build_query($params);
            }

            public function basePath(string $file): string
            {
                return '/omeka' . $file;
            }
        };
        $helper = new IwacSearchUrl();
        $helper->setView($view);
        return $helper();
    }

    private static function site(string $slug): object
    {
        return new class ($slug) {
            public function __construct(private readonly string $slug)
            {
            }

            public function slug(): string
            {
                return $this->slug;
            }
        };
    }

    public function testEachLanguageSiteLandsOnItsOwnSurface(): void
    {
        self::assertSame(
            '/omeka/site/iwac-search-everything?site-slug=westafrica',
            self::urlFor(self::site('westafrica'), 'en'),
        );
        self::assertSame(
            '/omeka/site/iwac-recherche-tout?site-slug=afrique_ouest',
            self::urlFor(self::site('afrique_ouest'), 'fr'),
        );
    }

    public function testOffSiteIsTheGlobalSurface(): void
    {
        self::assertSame('/omeka/search/everything', self::urlFor(null));
    }

    public function testEveryFailureStillYieldsAWorkingUrl(): void
    {
        self::assertSame(
            '/omeka/site/iwac-recherche-tout?site-slug=westafrica',
            self::urlFor(self::site('westafrica'), new RuntimeException('no locale helper')),
            'a missing locale helper defaults to French',
        );
        self::assertSame('/omeka/search/everything', self::urlFor(self::site('westafrica'), 'en', true), 'a route that fails');

        // Outside a view there is no base path to resolve against.
        self::assertSame('/search/everything', (new IwacSearchUrl())());
    }
}

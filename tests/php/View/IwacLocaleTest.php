<?php

declare(strict_types=1);

namespace IwacSearch\Tests\View;

use IwacSearch\IwacInstance;
use IwacSearch\View\Helper\IwacLocale;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The page locale every mount stamps into its bootstrap (and so every string,
 * number and plural the client renders). Read from the site slug, because the
 * SiteRepresentation does not expose the locale setting; French whenever in
 * doubt — the primary audience, and the global routes have no site at all.
 */
#[CoversClass(IwacLocale::class)]
final class IwacLocaleTest extends TestCase
{
    private static function localeFor(?object $site, bool $throws = false): string
    {
        $view = new class ($site, $throws) extends PhpRenderer {
            public function __construct(private readonly ?object $site, private readonly bool $throws)
            {
            }

            public function currentSite(): ?object
            {
                if ($this->throws) {
                    throw new RuntimeException('no site helper');
                }
                return $this->site;
            }
        };
        $helper = new IwacLocale();
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

    public function testTheEnglishSiteIsEnglish(): void
    {
        self::assertSame('en', self::localeFor(self::site(IwacInstance::SITE_SLUG_EN)));
        self::assertSame('en', self::localeFor(self::site('WestAfrica')));
    }

    public function testTheFrenchSiteIsFrench(): void
    {
        self::assertSame('fr', self::localeFor(self::site(IwacInstance::SITE_SLUG_FR)));
    }

    public function testNoSiteOrABrokenHelperFallsBackToFrench(): void
    {
        self::assertSame('fr', self::localeFor(null));
        self::assertSame('fr', self::localeFor(null, true));
    }
}

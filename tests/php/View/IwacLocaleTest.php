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
 * number and plural the client renders). Read from the site's `locale`
 * setting, then the site slug; French whenever in doubt — the primary
 * audience, and the global routes have no site at all.
 */
#[CoversClass(IwacLocale::class)]
final class IwacLocaleTest extends TestCase
{
    private static function localeFor(?object $site, bool $throws = false, mixed $setting = null): string
    {
        $view = new class ($site, $throws, $setting) extends PhpRenderer {
            public function __construct(
                private readonly ?object $site,
                private readonly bool $throws,
                private readonly mixed $setting,
            ) {
            }

            public function siteSetting(string $id): mixed
            {
                return $id === 'locale' ? $this->setting : null;
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

    public function testTheSiteLocaleSettingWinsOverTheSlug(): void
    {
        // A staging copy of the English site under another slug read French.
        self::assertSame('en', self::localeFor(self::site('staging'), setting: 'en_US'));
        self::assertSame('fr', self::localeFor(self::site(IwacInstance::SITE_SLUG_EN), setting: 'fr'));
    }

    public function testAnEmptyOrForeignSettingFallsBackToTheSlug(): void
    {
        self::assertSame('en', self::localeFor(self::site(IwacInstance::SITE_SLUG_EN), setting: ''));
        self::assertSame('en', self::localeFor(self::site(IwacInstance::SITE_SLUG_EN), setting: 'de_DE'));
        self::assertSame('fr', self::localeFor(self::site('staging'), setting: null));
    }

    public function testNoSiteOrABrokenHelperFallsBackToFrench(): void
    {
        self::assertSame('fr', self::localeFor(null));
        self::assertSame('fr', self::localeFor(null, true));
    }
}

<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Site;

use Closure;
use IwacSearch\Site\BlockLayout\IntroHtml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The block template prints intro_html RAW, and page-edit rights are far
 * broader than global admin: every value must have been through the purifier
 * once — at save, by the upgrade migration, or at render for a legacy block
 * neither has touched.
 */
#[CoversClass(IntroHtml::class)]
final class IntroHtmlTest extends TestCase
{
    /** @var list<string> what the stand-in purifier was asked to clean */
    private array $calls = [];

    /** @return Closure(string): string */
    private function purify(): Closure
    {
        return function (string $html): string {
            $this->calls[] = $html;
            return strip_tags($html, '<p><em>');
        };
    }

    public function testALegacyUnflaggedIntroIsPurifiedAtRender(): void
    {
        $html = IntroHtml::forRender(['intro_html' => '<p>Hi</p><script>alert(1)</script>'], $this->purify());

        self::assertSame('<p>Hi</p>alert(1)', $html);
        self::assertCount(1, $this->calls);
    }

    public function testAFlaggedIntroIsPrintedAsStoredWithoutRePurifying(): void
    {
        $data = ['intro_html' => '<p>Already clean</p>', IntroHtml::PURIFIED_FLAG => true];

        self::assertSame('<p>Already clean</p>', IntroHtml::forRender($data, $this->purify()));
        self::assertSame([], $this->calls);
    }

    public function testAnEmptyIntroNeverWakesThePurifier(): void
    {
        self::assertSame('', IntroHtml::forRender([], $this->purify()));
        self::assertSame([], $this->calls);
    }

    public function testTheMigrationPurifiesAndFlagsAnUnflaggedBlock(): void
    {
        $migrated = IntroHtml::migrated(
            ['intro_html' => '<em>x</em><img src=x onerror=1>', 'mode' => 'full'],
            $this->purify()
        );

        self::assertSame(
            ['intro_html' => '<em>x</em>', 'mode' => 'full', IntroHtml::PURIFIED_FLAG => true],
            $migrated
        );
    }

    public function testTheMigrationLeavesAFlaggedBlockAlone(): void
    {
        self::assertNull(IntroHtml::migrated(
            [IntroHtml::PURIFIED_FLAG => true, 'intro_html' => '<b>raw?</b>'],
            $this->purify()
        ));
        self::assertSame([], $this->calls);
    }

    public function testTheMigrationFlagsABlockWithNoIntroWithoutPurifying(): void
    {
        self::assertSame(
            ['intro_html' => '', IntroHtml::PURIFIED_FLAG => true],
            IntroHtml::migrated([], $this->purify())
        );
        self::assertSame([], $this->calls);
    }
}

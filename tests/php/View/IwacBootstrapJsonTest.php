<?php

declare(strict_types=1);

namespace IwacSearch\Tests\View;

use IwacSearch\View\Helper\IwacBootstrapJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bootstrap blob is printed inside an inline <script type="application/json">,
 * so its encoding IS an XSS boundary: a stray `</script>` in any string field
 * (a block title, a locked filter, an SSR'd hit) must not close the tag.
 */
#[CoversClass(IwacBootstrapJson::class)]
final class IwacBootstrapJsonTest extends TestCase
{
    /** The JSON hex escape of $text under one flag, without the quotes. */
    private static function hex(string $text, int $flag): string
    {
        return substr((string) json_encode($text, $flag), 1, -1);
    }

    public function testCannotCloseTheScriptTag(): void
    {
        $json = (new IwacBootstrapJson())(['title' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringContainsString(self::hex('</script>', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES), $json);
    }

    public function testEscapesAmpersandsAndQuotes(): void
    {
        $json = (new IwacBootstrapJson())(['q' => "a & b 'c' \"d\""]);

        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringContainsString(self::hex('&', JSON_HEX_AMP), $json);
        self::assertStringContainsString(self::hex("'", JSON_HEX_APOS), $json);
        self::assertStringContainsString(self::hex('"', JSON_HEX_QUOT), $json);
    }

    /** Diacritics, Arabic and paths stay legible on the wire — and still round-trip. */
    public function testKeepsUnicodeAndSlashesReadableAndRoundTrips(): void
    {
        $title = 'Côte d' . "\u{2019}" . 'Ivoire ' . "\u{2014} \u{627}\u{644}\u{625}\u{633}\u{644}\u{627}\u{645}";
        $payload = ['title' => $title, 'endpoint' => '/search-api/multi_search'];
        $json = (new IwacBootstrapJson())($payload);

        self::assertStringContainsString($title, $json);
        self::assertStringContainsString('/search-api/multi_search', $json);
        self::assertSame($payload, json_decode($json, true));
    }

    /** An unencodable value yields an empty object, never a fatal or a half-written blob. */
    public function testFallsBackToAnEmptyObject(): void
    {
        self::assertSame('{}', (new IwacBootstrapJson())(['bad' => INF]));
    }
}

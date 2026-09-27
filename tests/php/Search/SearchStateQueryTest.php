<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\SearchStateQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server skips the first-page SSR when the URL already carries client
 * state. It reads the raw query string because PHP turns `f.country_ss` into
 * `f_country_ss` in $_GET — which is how every filter deep link used to slip
 * past the check and pay for a snapshot the client then threw away.
 */
#[CoversClass(SearchStateQuery::class)]
final class SearchStateQueryTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function cases(): iterable
    {
        yield 'bare landing' => ['', '', false];
        yield 'query' => ['q=ramadan', '', true];
        yield 'empty query' => ['q=', '', false];
        yield 'blank query' => ['q=%20', '', false];
        yield 'filter (dotted key)' => ['f.country_ss=B%C3%A9nin', '', true];
        yield 'encoded dot' => ['f%2Ecountry_ss=Niger', '', true];
        yield 'year range' => ['date.from=1990', '', true];
        yield 'sort' => ['sort=date%3Aasc', '', true];
        yield 'page size' => ['per=50', '', true];
        yield 'first page' => ['page=1', '', false];
        yield 'second page' => ['page=2', '', true];
        yield 'view only' => ['view=gallery', '', false];
        yield 'unrelated param' => ['utm_source=x', '', false];
        yield "a block's own state" => ['b12.q=ramadan', 'b12.', true];
        yield "another block's state" => ['b7.q=ramadan', 'b12.', false];
        yield 'host page params ignored by a block' => ['q=ramadan&page=3', 'b12.', false];
        yield "a block's filter" => ['x=1&b12.f.type_s=article', 'b12.', true];
    }

    #[DataProvider('cases')]
    public function testDetectsClientState(string $raw, string $prefix, bool $expected): void
    {
        self::assertSame($expected, SearchStateQuery::carriesState($raw, $prefix));
    }
}

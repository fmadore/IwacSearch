<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\FilterExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An editor's locked filter runs with the ADMIN key in the server-side
 * render, behind `is_public:=true && …`. What matters is that no accepted
 * fragment can close that group and attach a branch the guard misses.
 */
#[CoversClass(FilterExpression::class)]
final class FilterExpressionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function safeFilters(): iterable
    {
        yield 'empty' => [''];
        yield 'the form placeholder' => ['date:>=946684800'];
        yield 'a preset scope' => ['country_ss:=`Bénin` && type_s:!=reference'];
        yield 'a grouped OR' => ['(type_s:=article || type_s:=document) && country_ss:=`Togo`'];
        yield 'parentheses inside a quoted value' => ['newspaper_ss:=`La Croix (Bénin)`'];
        yield 'an unbalanced parenthesis inside a quoted value' => ['newspaper_ss:=`a) || (b`'];
        yield 'a field merely containing the word' => ['is_publication_s:=true'];
    }

    #[DataProvider('safeFilters')]
    public function testStructurallySafeFiltersAreAccepted(string $filter): void
    {
        self::assertNull(FilterExpression::problem($filter));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeFilters(): iterable
    {
        // The leak: `is_public:=true && (type_s:=article) || (type_s:=document)`.
        yield 'a group closed early' => ['type_s:=article) || (type_s:=document'];
        yield 'a trailing close' => ['type_s:=article) || type_s:=document'];
        yield 'an unclosed group' => ['(type_s:=article'];
        yield 'an unterminated quote' => ['newspaper_ss:=`Sidwaya'];
        yield 'a quote hiding a close' => ['newspaper_ss:=`a` ) || (`b`'];
        yield 'the visibility field' => ['is_public:=false'];
        yield 'the visibility field in an OR' => ['(type_s:=article || is_public:=false)'];
        yield 'an escaped backtick' => ['newspaper_ss:=`a\\` || (type_s:=document) || x:=`b`'];
        yield 'too long' => [str_repeat('a', FilterExpression::MAX_LENGTH + 1)];
        yield 'invalid UTF-8' => ["newspaper_ss:=`\xC3`"];
    }

    #[DataProvider('unsafeFilters')]
    public function testFiltersThatCouldEscapeThePublicGuardAreRefused(string $filter): void
    {
        self::assertNotNull(FilterExpression::problem($filter));
    }
}

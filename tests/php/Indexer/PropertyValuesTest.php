<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\PropertyValues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The value-extraction primitives every mapper builds on. Omeka stores a
 * value three ways at once (literal / uri / linked resource), so "which one
 * is THE value" differs per field — and getting it wrong produces a document
 * that indexes fine and is simply missing or wrong in search results.
 */
#[CoversClass(PropertyValues::class)]
final class PropertyValuesTest extends TestCase
{
    /**
     * @param array{vrid?:?int,value?:?string,uri?:?string,title?:?string,vpub?:bool} $row
     * @return array{vrid:?int,value:?string,uri:?string,title:?string,vpub:bool}
     */
    private static function row(array $row): array
    {
        return $row + ['vrid' => null, 'value' => null, 'uri' => null, 'title' => null, 'vpub' => true];
    }

    public function testDisplaysPrefersTheLinkedTitleOverTheLiteral(): void
    {
        $v = PropertyValues::fromRows(['dcterms:subject' => [
            self::row(['vrid' => 7, 'title' => 'Islam', 'value' => 'ignored']),
            self::row(['value' => 'Tabaski']),
        ]]);

        self::assertSame(['Islam', 'Tabaski'], $v->displays('dcterms:subject'));
    }

    public function testDisplaysTrimsDedupesAndPreservesOrder(): void
    {
        $v = PropertyValues::fromRows(['dcterms:creator' => [
            self::row(['value' => '  Awa Traoré ']),
            self::row(['value' => 'Jean Dupont']),
            self::row(['value' => 'Awa Traoré']),
        ]]);

        self::assertSame(['Awa Traoré', 'Jean Dupont'], $v->displays('dcterms:creator'));
    }

    public function testDisplaysSkipsBlankValues(): void
    {
        $v = PropertyValues::fromRows(['dcterms:creator' => [
            self::row(['value' => '   ']),
            self::row(['title' => '']),
            self::row(['value' => 'Awa']),
        ]]);

        self::assertSame(['Awa'], $v->displays('dcterms:creator'));
    }

    public function testLiteralsIgnoreLinkedResourceTitles(): void
    {
        $v = PropertyValues::fromRows(['dcterms:alternative' => [
            self::row(['vrid' => 7, 'title' => 'A linked title']),
            self::row(['value' => 'A literal']),
        ]]);

        self::assertSame(['A literal'], $v->literals('dcterms:alternative'));
    }

    public function testPublicLiteralsExcludePrivateValues(): void
    {
        $v = PropertyValues::fromRows(['dcterms:description' => [
            self::row(['value' => 'private note', 'vpub' => false]),
            self::row(['value' => 'public description']),
        ]]);

        self::assertSame(
            ['private note', 'public description'],
            $v->literals('dcterms:description')
        );
        self::assertSame(['public description'], $v->publicLiterals('dcterms:description'));
        self::assertSame('public description', $v->firstPublicLiteral('dcterms:description'));
    }

    public function testFirstScalarFallsBackToTheUriButFirstLiteralDoesNot(): void
    {
        // dcterms:identifier is catalogued both ways; a URI identifier must
        // still reach the document.
        $v = PropertyValues::fromRows(['dcterms:identifier' => [
            self::row(['uri' => 'https://example.org/x']),
        ]]);

        self::assertSame('https://example.org/x', $v->firstScalar('dcterms:identifier'));
        self::assertSame('', $v->firstLiteral('dcterms:identifier'));
    }

    public function testFirstScalarPrefersTheLiteralWhenBothArePresent(): void
    {
        $v = PropertyValues::fromRows(['dcterms:date' => [
            self::row(['value' => '1994-03', 'uri' => 'https://example.org/1994']),
        ]]);

        self::assertSame('1994-03', $v->firstScalar('dcterms:date'));
    }

    public function testLinkedIdsReturnOnlyResourceTargetsAndKeepRepeats(): void
    {
        // Not deduped on purpose: a repeated link is a repeated occurrence,
        // and EntityOccurrences counts them.
        $v = PropertyValues::fromRows(['dcterms:subject' => [
            self::row(['vrid' => 11]),
            self::row(['value' => 'a literal, not a link']),
            self::row(['vrid' => 11]),
            self::row(['vrid' => 12]),
        ]]);

        self::assertSame([11, 11, 12], $v->linkedIds('dcterms:subject'));
    }

    public function testRowsExposeValueLevelVisibility(): void
    {
        // has_fulltext reads `vpub` to tell "OCR exists" from "OCR is
        // publicly readable" — the one consumer that needs raw rows.
        $v = PropertyValues::fromRows(['bibo:content' => [
            self::row(['value' => 'restricted OCR', 'vpub' => false]),
        ]]);

        self::assertFalse($v->rows('bibo:content')[0]['vpub']);
    }

    public function testMissingTermsYieldEmptyResultsRatherThanErrors(): void
    {
        $v = PropertyValues::none();

        self::assertSame([], $v->displays('dcterms:subject'));
        self::assertSame([], $v->literals('dcterms:subject'));
        self::assertSame([], $v->publicLiterals('dcterms:subject'));
        self::assertSame([], $v->linkedIds('dcterms:subject'));
        self::assertSame([], $v->rows('dcterms:subject'));
        self::assertSame('', $v->firstDisplay('dcterms:subject'));
        self::assertSame('', $v->firstLiteral('dcterms:subject'));
        self::assertSame('', $v->firstPublicLiteral('dcterms:subject'));
        self::assertSame('', $v->firstScalar('dcterms:subject'));
        self::assertFalse($v->has('dcterms:subject'));
    }

    public function testHasReportsPresence(): void
    {
        $v = PropertyValues::fromRows([
            'dcterms:subject' => [self::row(['value' => 'x'])],
            'dcterms:spatial' => [],
        ]);

        self::assertTrue($v->has('dcterms:subject'));
        self::assertFalse($v->has('dcterms:spatial'));
    }
}

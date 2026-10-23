<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\ExternalIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bare identifiers behind a card's Wikidata and DOI links. A wrong
 * reading here is a dead outbound link on every card that carries it.
 */
#[CoversClass(ExternalIds::class)]
final class ExternalIdsTest extends TestCase
{
    public function testEverySpellingOfAWikidataEntityReadsAsItsQid(): void
    {
        self::assertSame('Q141697087', ExternalIds::wikidataQid(['https://www.wikidata.org/wiki/Q141697087']));
        self::assertSame('Q216920', ExternalIds::wikidataQid(['http://www.wikidata.org/entity/Q216920']));
        self::assertSame('Q216920', ExternalIds::wikidataQid(['https://m.wikidata.org/wiki/q216920/']));
        // The first entity wins; anything else on the property is skipped.
        self::assertSame('Q2', ExternalIds::wikidataQid(['https://viaf.org/viaf/1', 'https://www.wikidata.org/wiki/Q2', 'https://www.wikidata.org/wiki/Q3']));
    }

    public function testOnlyAnEntityCountsAsAReconciliation(): void
    {
        self::assertSame('', ExternalIds::wikidataQid(['https://www.wikidata.org/wiki/Property:P31']));
        self::assertSame('', ExternalIds::wikidataQid(['https://www.wikidata.org/wiki/Lexeme:L1']));
        self::assertSame('', ExternalIds::wikidataQid(['https://example.org/wiki/Q1']));
        self::assertSame('', ExternalIds::wikidataQid([]));
    }

    public function testTheDoiIsReducedToItsBareForm(): void
    {
        $doi = '10.48734/akofena.n018.vol.1.13.2025';
        self::assertSame($doi, ExternalIds::doi(['https://doi.org/' . $doi]));
        self::assertSame($doi, ExternalIds::doi(['http://dx.doi.org/' . $doi]));
        self::assertSame($doi, ExternalIds::doi(['doi: ' . $doi]));
        self::assertSame($doi, ExternalIds::doi(['  ' . $doi . ' ']));
        // A doi.org address is decoded; a typed DOI is taken as written.
        self::assertSame('10.1002/(SICI)1097-4571', ExternalIds::doi(['https://doi.org/10.1002/%28SICI%291097-4571']));
        self::assertSame('10.1000/a%20b', ExternalIds::doi(['10.1000/a%20b']));
    }

    public function testTheFirstCandidateThatIsADoiWins(): void
    {
        self::assertSame('10.1000/182', ExternalIds::doi(['DOI', 'https://example.org/x', '10.1000/182']));
        self::assertSame('', ExternalIds::doi(['en cours', 'https://example.org/10.1000/182']));
        self::assertSame('', ExternalIds::doi([]));
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\RetiredQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetiredQueryTest extends TestCase
{
    public function testCurrentSurfaceQueriesAreServedNotRedirected(): void
    {
        self::assertNull(RetiredQuery::redirectFor([]));
        self::assertNull(RetiredQuery::redirectFor(['q' => 'pèlerinage']));
        self::assertNull(RetiredQuery::redirectFor(['f.country_ss' => 'Benin', 'sort' => 'date:desc']));
    }

    public function testPaginationAloneIsNotRetired(): void
    {
        // Both surfaces use ?page=, so it cannot be the signal on its own.
        self::assertNull(RetiredQuery::redirectFor(['page' => '3']));
    }

    /** @param array<string,mixed> $query */
    #[DataProvider('retiredQueryProvider')]
    public function testRetiredParametersRedirectToTheBareShell(array $query): void
    {
        self::assertSame([], RetiredQuery::redirectFor($query));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function retiredQueryProvider(): array
    {
        return [
            // As PHP parses ?facet%5Bdcterms_type_ss%5D%5B9%5D=Article de presse
            'facet array'       => [['facet' => ['dcterms_type_ss' => [9 => 'Article de presse']]]],
            'legacy sort'       => [['sort_by' => 'title', 'sort_order' => 'asc']],
            'resource property' => [['resource_property' => 'items:321-306', 'page' => '6']],
            'facet with page'   => [['facet' => ['dcterms_type_ss' => [0 => 'Document']], 'page' => '2']],
        ];
    }

    public function testAnEmptyFacetKeyStillCountsAsRetired(): void
    {
        // ?facet= with nothing after it is the same dead surface.
        self::assertSame([], RetiredQuery::redirectFor(['facet' => '']));
    }

    public function testTheVisitorsOwnWordsSurviveTheRedirect(): void
    {
        self::assertSame(
            ['q' => 'pèlerinage'],
            RetiredQuery::redirectFor(['q' => '  pèlerinage  ', 'sort_by' => 'title'])
        );
    }

    public function testAnUnusableQueryTextIsDropped(): void
    {
        self::assertSame([], RetiredQuery::redirectFor(['q' => '   ', 'facet' => ['x' => ['a']]]));
        self::assertSame([], RetiredQuery::redirectFor(['q' => ['array'], 'facet' => ['x' => ['a']]]));
    }
}

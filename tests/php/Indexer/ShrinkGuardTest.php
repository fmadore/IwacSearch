<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\ShrinkGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A healthy import can still be a disaster: a mapper that suddenly matches
 * nothing yields a clean, smaller build. The guard compares every content
 * type and the entity total with the live generation before promotion.
 */
#[CoversClass(ShrinkGuard::class)]
final class ShrinkGuardTest extends TestCase
{
    private const LIVE = ['article' => 12000, 'photograph' => 600, 'reference' => 1400];

    public function testAComparableBuildPasses(): void
    {
        self::assertSame([], ShrinkGuard::problems(self::LIVE, ['article' => 12010, 'photograph' => 590, 'reference' => 1400], 4000, 4010));
    }

    public function testALostContentTypeIsRefusedEvenWhenTheTotalBarelyMoves(): void
    {
        self::assertSame(
            ['photograph: 600 → 0'],
            ShrinkGuard::problems(self::LIVE, ['article' => 12000, 'reference' => 1400], 4000, 4000)
        );
    }

    public function testHalfIsTheFloorNotAFailure(): void
    {
        self::assertSame([], ShrinkGuard::problems(['article' => 1000], ['article' => 500], null, 0));
        self::assertSame(['article: 1000 → 499'], ShrinkGuard::problems(['article' => 1000], ['article' => 499], null, 0));
    }

    public function testAShrunkEntityCollectionIsRefused(): void
    {
        self::assertSame(['entities: 4000 → 1000'], ShrinkGuard::problems(self::LIVE, self::LIVE, 4000, 1000));
    }

    public function testAFirstInstallHasNothingToCompareWith(): void
    {
        self::assertSame([], ShrinkGuard::problems([], ['article' => 3], null, 2));
    }

    public function testANewContentTypeOrAnEmptyLiveTypeIsNeverAProblem(): void
    {
        self::assertSame([], ShrinkGuard::problems(['article' => 10, 'audiovisual' => 0], ['article' => 10, 'document' => 5], 0, 0));
    }

    public function testTheRatioIsAParameter(): void
    {
        self::assertSame(['article: 1000 → 800'], ShrinkGuard::problems(['article' => 1000], ['article' => 800], null, 0, 0.9));
    }
}

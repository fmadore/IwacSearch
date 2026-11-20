<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Log;

use IwacSearch\Log\OmekaPsrLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Laminas' logger does not interpolate PSR-3 placeholders, so
 * IwacItemSetSearch's "…fell back to the theme listing: {message}" reached
 * Omeka's log with a literal `{message}` and no cause.
 */
#[CoversClass(OmekaPsrLogger::class)]
final class OmekaPsrLoggerTest extends TestCase
{
    public function testPlaceholdersTakeScalarAndStringableContextValues(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'iwac_v12';
            }
        };

        self::assertSame(
            'fell back: Connection refused (attempt 2, retry true) on iwac_v12',
            OmekaPsrLogger::interpolate(
                'fell back: {message} (attempt {n}, retry {retry}) on {collection}',
                ['message' => 'Connection refused', 'n' => 2, 'retry' => true, 'collection' => $stringable],
            ),
        );
    }

    public function testUnknownKeysAndNonScalarValuesStayAsWritten(): void
    {
        self::assertSame(
            'ids {ids}, {missing}',
            OmekaPsrLogger::interpolate('ids {ids}, {missing}', ['ids' => [1, 2]]),
        );
    }
}

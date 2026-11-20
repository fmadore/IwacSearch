<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use IwacSearch\Search\PublicSearchPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * SECURITY.md tells a reporter what the public scoped key withholds. It had
 * fallen behind the policy (it omitted `embedding`), so the two are now held
 * together here.
 */
#[CoversClass(PublicSearchPolicy::class)]
final class PublicSearchPolicyTest extends TestCase
{
    public function testSecurityMdListsEveryExcludedField(): void
    {
        $excluded = PublicSearchPolicy::parameters()['exclude_fields'];
        self::assertIsString($excluded);
        $security = (string) file_get_contents(dirname(__DIR__, 3) . '/SECURITY.md');

        self::assertStringContainsString("`exclude_fields: {$excluded}`", $security);
    }
}

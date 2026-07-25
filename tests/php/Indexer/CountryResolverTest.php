<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\CountryResolver;
use IwacSearch\IwacInstance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `country_ss` is not a stored Omeka property — it is derived, and it is the
 * facet the entire per-country browse experience hangs off. A value that
 * doesn't match the facet spelling EXACTLY silently drops the item out of
 * that country's scope, because Typesense filter_by is accent- and
 * case-sensitive.
 */
#[CoversClass(CountryResolver::class)]
final class CountryResolverTest extends TestCase
{
    private const MAP = __DIR__ . '/../../../data/newspaper-countries.json';

    private function resolver(): CountryResolver
    {
        return new CountryResolver(self::MAP);
    }

    public function testAKnownNewspaperResolvesToItsAccentedCountry(): void
    {
        self::assertSame(['Bénin'], $this->resolver()->forNewspapers(['La Nation']));
    }

    public function testTheLookupIsCaseAndWhitespaceInsensitive(): void
    {
        // Catalogue entries are hand-typed; the lookup normalises so a stray
        // capital or trailing space doesn't lose the country.
        self::assertSame(['Bénin'], $this->resolver()->forNewspapers(['  LA NATION ']));
    }

    public function testUnknownNewspapersYieldNothingRatherThanAGuess(): void
    {
        self::assertSame([], $this->resolver()->forNewspapers(['Some Unknown Paper']));
        self::assertSame([], $this->resolver()->forNewspapers([]));
    }

    public function testSeveralNewspapersDedupeToTheirDistinctCountries(): void
    {
        $out = $this->resolver()->forNewspapers(['La Nation', 'La Nation', 'Unknown']);

        self::assertSame(['Bénin'], $out);
    }

    public function testItemSetMembershipResolvesForSubsetsWithNoNewspaper(): void
    {
        // References / documents / photographs carry no publisher, so the
        // per-country set family is their only signal.
        self::assertSame(['Bénin'], $this->resolver()->forItemSets([2193]));
        self::assertSame(['Burkina Faso'], $this->resolver()->forItemSets([23453]));
        self::assertSame(['Togo'], $this->resolver()->forItemSets([2227]));
    }

    public function testUnrelatedItemSetsResolveToNothing(): void
    {
        self::assertSame([], $this->resolver()->forItemSets([999999]));
        self::assertSame([], $this->resolver()->forItemSets([]));
    }

    public function testItemSetsDedupeAcrossTheThreeSetFamilies(): void
    {
        // The Références / Documents divers / Photographies families each
        // have a Bénin set; an item in two of them is still one country.
        self::assertSame(['Bénin'], $this->resolver()->forItemSets([2193, 23452, 2192]));
    }

    public function testEveryCountryItemSetNamesACountryThePresetsAlsoKnow(): void
    {
        // If these two lists disagree, an item gets a country_ss value no
        // country scope filters on — it becomes unreachable by browsing.
        $presetCountries = array_map(
            static fn(\IwacSearch\Search\Preset $p): string => $p->label,
            array_filter(
                \IwacSearch\Search\PresetCatalog::all(),
                static fn(\IwacSearch\Search\Preset $p): bool => $p->redirectQuery !== []
                    && isset($p->redirectQuery['f.country_ss'])
            )
        );

        foreach (array_unique(IwacInstance::COUNTRY_ITEM_SETS) as $country) {
            self::assertContains(
                $country,
                $presetCountries,
                "item-set country '{$country}' has no matching country preset"
            );
        }
    }

    public function testAMissingMapFileFailsLoudlyAtConstruction(): void
    {
        // Better a failed reindex than a corpus silently indexed with no
        // country facet at all.
        $this->expectException(RuntimeException::class);
        new CountryResolver('/nonexistent/newspaper-countries.json');
    }

    public function testAMalformedMapFileFailsLoudly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iwac') ?: '';
        file_put_contents($path, 'not json');

        try {
            $this->expectException(RuntimeException::class);
            new CountryResolver($path);
        } finally {
            @unlink($path);
        }
    }

    public function testDocumentationKeysInTheMapAreIgnored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iwac') ?: '';
        file_put_contents($path, json_encode([
            '_comment' => 'Not a newspaper',
            'La Nation' => 'Bénin',
            'Bad Entry' => ['not', 'a', 'string'],
        ]));

        try {
            $resolver = new CountryResolver($path);
            self::assertSame(['Bénin'], $resolver->forNewspapers(['La Nation']));
            self::assertSame([], $resolver->forNewspapers(['_comment', 'Bad Entry']));
        } finally {
            @unlink($path);
        }
    }
}

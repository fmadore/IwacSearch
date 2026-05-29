<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Seeds one curated browse page per IWAC country.
 *
 * Idempotent — checks for an existing slug before inserting. Re-running
 * the seeder never overwrites edits made via the (M3+) admin UI.
 *
 * The 6 countries are the canonical IWAC corpus boundary (see the shared
 * {@see Countries} list — the single place a country name lives in code).
 * Titles + intros are localized at render time by {@see BrowseContent};
 * the values stored here are an English fallback only.
 */
final class CountrySeeder
{
    /**
     * Default facet list for country browse pages. Drops `country_ss`
     * (it's locked) and keeps `language_ss` since Nigeria mixes English
     * and French content. The three sentiment facets render together
     * under one collapsible "Sentiment" group in the client.
     *
     * Public so Module::upgrade can re-apply it to already-seeded rows.
     *
     * @var list<string>
     */
    public const DEFAULT_FACETS = [
        'type_s',
        'newspaper_ss',
        'language_ss',
        'places_ss',
        'persons_ss',
        'organisations_ss',
        'topics_ss',
        'gemini_polarite_ss',
        'gemini_centralite_ss',
        'gemini_subjectivite',
    ];

    public function __construct(
        private readonly BrowseConfigRepository $repository,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @return array{seeded: int, skipped: int, slugs: list<string>}
     */
    public function seed(): array
    {
        $seeded = 0;
        $skipped = 0;
        $slugs = [];

        foreach (Countries::ALL as $position => $country) {
            $slug = $country['slug'];
            if ($this->repository->existsBySlug($slug)) {
                $this->logger->info('Skipping existing browse config', ['slug' => $slug]);
                $skipped++;
                continue;
            }

            $config = new BrowseConfig(
                id:               null,
                slug:             $slug,
                // Stored English fallback; localized at render by BrowseContent.
                title:            $country['name'],
                introHtml:        sprintf(
                    '<p>Documents about Islam and Muslim public life in %s — news articles, '
                    . 'Islamic periodicals, audiovisual materials, and primary sources from the '
                    . 'Islam West Africa Collection.</p>',
                    htmlspecialchars($country['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                ),
                // Backticks escape spaces and the apostrophe in Côte d'Ivoire.
                // Public scoped key adds `is_public:=true && exclude_fields:ocr_text`
                // on top — locked filters chain with `&&`.
                lockedFilters:    sprintf('country_ss:=`%s`', $country['name']),
                prominentFacets:  self::DEFAULT_FACETS,
                defaultSort:      'date:desc',  // newest-first feels right for country landing pages
                resultsPerPage:   10,
                position:         $position,
            );

            $this->repository->save($config);
            $slugs[] = $slug;
            $seeded++;
            $this->logger->info('Seeded browse config', [
                'slug'  => $slug,
                'title' => $country['name'],
            ]);
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'slugs' => $slugs];
    }
}

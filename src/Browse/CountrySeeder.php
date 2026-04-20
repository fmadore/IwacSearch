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
 * The 6 countries are the canonical IWAC corpus boundary, baked into the
 * dataset since inception (see iwac-dataset skill). New countries should
 * be added here AND introduced to the indexer's controlled vocabulary in
 * the same PR — they're the only place a country name appears in code.
 */
final class CountrySeeder
{
    /**
     * Country canonical name (matches `country_ss` field values exactly,
     * including diacritics — Typesense filter_by is case-sensitive on
     * facet fields) → public slug → display title.
     */
    private const COUNTRIES = [
        // Order = display order on /browse landing.
        ['name' => 'Bénin',           'slug' => 'benin',          'title' => 'Bénin'],
        ['name' => 'Burkina Faso',    'slug' => 'burkina-faso',   'title' => 'Burkina Faso'],
        ['name' => "Côte d'Ivoire",   'slug' => 'cote-divoire',   'title' => "Côte d'Ivoire"],
        ['name' => 'Niger',           'slug' => 'niger',          'title' => 'Niger'],
        ['name' => 'Nigeria',         'slug' => 'nigeria',        'title' => 'Nigeria'],
        ['name' => 'Togo',            'slug' => 'togo',           'title' => 'Togo'],
    ];

    /**
     * Default facet list for country browse pages. Drops `country_ss`
     * (it's locked) and adds `language_ss` since Nigeria mixes English
     * and French content.
     */
    private const DEFAULT_FACETS = [
        'type_s',
        'newspaper_ss',
        'language_ss',
        'places_ss',
        'persons_ss',
        'organisations_ss',
        'topics_ss',
        'gemini_polarite_ss',
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

        foreach (self::COUNTRIES as $position => $country) {
            $slug = $country['slug'];
            if ($this->repository->existsBySlug($slug)) {
                $this->logger->info('Skipping existing browse config', ['slug' => $slug]);
                $skipped++;
                continue;
            }

            $config = new BrowseConfig(
                id:               null,
                slug:             $slug,
                title:            $country['title'],
                introHtml:        sprintf(
                    '<p>Documents about Islam and Muslim public life in %s — articles, '
                    . 'magazine issues, audiovisual materials, and primary documents from the '
                    . 'Islam West Africa Collection.</p>',
                    htmlspecialchars($country['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
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
                'title' => $country['title'],
            ]);
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'slugs' => $slugs];
    }
}

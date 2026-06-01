<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Seeds the "all countries" curated browse page — a single entry point
 * into the whole corpus, with no country lock, so a visitor can browse
 * everything and narrow by the Country facet.
 *
 * Distinct seeder (like {@see ReferencesSeeder}) so toggling the page is a
 * one-line change in Module::install/upgrade without touching the
 * per-country logic. Idempotent — the existsBySlug() guard never clobbers
 * admin edits on re-run.
 */
final class AllCountriesSeeder
{
    public const SLUG = BrowseContent::ALL_SLUG; // 'all'

    /**
     * Country first (the natural slicer for an all-corpus page — a visitor
     * browsing "all countries" narrows by place before format), then Type and
     * the usual stack, then the grouped sentiment facets.
     *
     * @var list<string>
     */
    public const DEFAULT_FACETS = [
        'country_ss',
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
     * @return array{seeded: int, skipped: int, slug: string}
     */
    public function seed(): array
    {
        if ($this->repository->existsBySlug(self::SLUG)) {
            $this->logger->info('Skipping existing all-countries browse config', ['slug' => self::SLUG]);
            return ['seeded' => 0, 'skipped' => 1, 'slug' => self::SLUG];
        }

        // Position -1 sorts it ABOVE the country pages (0..5) on the
        // landing list — it's the broadest entry point.
        $config = new BrowseConfig(
            id:               null,
            slug:             self::SLUG,
            // Title + intro are localized at render time by BrowseContent;
            // these stored values are an English fallback only.
            title:            'All countries',
            introHtml:        '<p>Browse the entire Islam West Africa Collection across all '
                . 'countries. Use the Country facet to narrow to one place.</p>',
            lockedFilters:    '', // no country lock — the public key still adds is_public:=true
            prominentFacets:  self::DEFAULT_FACETS,
            defaultSort:      'date:desc',
            resultsPerPage:   10,
            position:         -1,
        );

        $this->repository->save($config);
        $this->logger->info('Seeded all-countries browse config', ['slug' => self::SLUG]);

        return ['seeded' => 1, 'skipped' => 0, 'slug' => self::SLUG];
    }
}

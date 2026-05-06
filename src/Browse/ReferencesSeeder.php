<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Seeds a single curated browse page for bibliographic references.
 *
 * Lives at /browse/references with type_s:=reference locked. Distinct
 * from {@see CountrySeeder} (which seeds the six country pages) so
 * adding/removing the references page is a one-line toggle in
 * Module::install without touching the country logic.
 *
 * Idempotent — `existsBySlug()` guard prevents overwriting admin edits
 * on re-install.
 */
final class ReferencesSeeder
{
    private const SLUG  = 'references';
    private const TITLE = 'Bibliographic references';

    /**
     * Facet stack tuned for academic literature: reference_type first
     * (the natural slicer — "show me only theses"), then authorship,
     * then geographic + thematic context. `country_ss` stays in because
     * references often cover multiple countries (pipe-separated source
     * field) so users will want to narrow to e.g. Burkina-Faso-only
     * scholarship.
     */
    private const DEFAULT_FACETS = [
        'reference_type_ss',
        'language_ss',
        'country_ss',
        'topics_ss',
        'persons_ss',
        'places_ss',
        'organisations_ss',
        'date_decade_ss',
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
            $this->logger->info('Skipping existing references browse config', ['slug' => self::SLUG]);
            return ['seeded' => 0, 'skipped' => 1, 'slug' => self::SLUG];
        }

        // Position 100 puts the references page after the country pages
        // (which run 0..5 from CountrySeeder) without colliding with
        // future inserts in either bucket.
        $config = new BrowseConfig(
            id:               null,
            slug:             self::SLUG,
            title:            self::TITLE,
            introHtml:        '<p>Academic and bibliographic references on Islam '
                . 'and Muslim public life in West Africa — journal articles, books, '
                . 'theses, book chapters, reports, and other secondary literature. '
                . 'Use the Reference type facet to narrow by genre.</p>',
            // type_s:=reference is the discriminator set by ReferenceMapper;
            // the public scoped key still adds is_public:=true on top.
            lockedFilters:    'type_s:=reference',
            prominentFacets:  self::DEFAULT_FACETS,
            defaultSort:      'date:desc',
            resultsPerPage:   10,
            position:         100,
        );

        $this->repository->save($config);
        $this->logger->info('Seeded references browse config', ['slug' => self::SLUG]);

        return ['seeded' => 1, 'skipped' => 0, 'slug' => self::SLUG];
    }
}

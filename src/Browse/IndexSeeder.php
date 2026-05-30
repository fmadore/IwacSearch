<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Seeds the Index browse page — the curated surface over the entity
 * collection (persons, places, organisations, events, subjects, authority
 * notices). Distinct from the content browse pages: it targets the second
 * Typesense collection (iwac_index_current), faceted by entity type and
 * sorted by occurrence frequency.
 *
 * The slug is recognised by SearchController, which points the bootstrap at
 * the entity collection and switches the client into 'entity' card mode.
 *
 * Idempotent — existsBySlug() guard never clobbers admin edits on re-run.
 */
final class IndexSeeder
{
    public const SLUG = 'index';

    /**
     * Entity type is the primary slicer; country narrows to one corpus.
     * frequency is the default sort (handled separately), not a facet.
     *
     * @var list<string>
     */
    public const DEFAULT_FACETS = [
        'entity_type_s',
        'country_ss',
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
            $this->logger->info('Skipping existing index browse config', ['slug' => self::SLUG]);
            return ['seeded' => 0, 'skipped' => 1, 'slug' => self::SLUG];
        }

        // Position 6 = right after the six country pages (0..5), before the
        // references page (100).
        $config = new BrowseConfig(
            id:               null,
            slug:             self::SLUG,
            // Localized at render by BrowseContent; English fallback here.
            title:            'Index',
            introHtml:        '<p>Browse the people, places, organisations, events and topics '
                . 'that appear across the Islam West Africa Collection, ranked by how often '
                . 'they are mentioned.</p>',
            // No locked filter — the entity collection holds only entities.
            lockedFilters:    '',
            prominentFacets:  self::DEFAULT_FACETS,
            // "Most mentioned first" — frequency is the entity collection's
            // default_sorting_field too.
            defaultSort:      'frequency:desc',
            resultsPerPage:   20,
            position:         6,
        );

        $this->repository->save($config);
        $this->logger->info('Seeded index browse config', ['slug' => self::SLUG]);

        return ['seeded' => 1, 'skipped' => 0, 'slug' => self::SLUG];
    }
}

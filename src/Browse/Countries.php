<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

/**
 * Canonical IWAC country list — the single source of truth shared by the
 * {@see CountrySeeder} (which seeds one browse page per country) and
 * {@see BrowseContent} (which localizes the page title + intro at render
 * time). Keeping the list here means a new country is a one-line change
 * in one place rather than a sweep across the seeder and the localizer.
 *
 * The 6 countries are the canonical IWAC corpus boundary, baked into the
 * dataset since inception (see the iwac-dataset skill).
 */
final class Countries
{
    /**
     * @var list<array{name: string, slug: string, prep: string}>
     *
     * - `name`  matches the `country_ss` facet value EXACTLY, diacritics
     *           included (Typesense filter_by is case + accent sensitive).
     * - `slug`  the public path segment (`/browse/benin`).
     * - `prep`  the French preposition for the intro sentence
     *           ("au Bénin", "en Côte d'Ivoire", "au Togo"). English always
     *           uses "in %s".
     */
    public const ALL = [
        // Order = display order on the browse landing.
        ['name' => 'Bénin',           'slug' => 'benin',        'prep' => 'au'],
        ['name' => 'Burkina Faso',    'slug' => 'burkina-faso', 'prep' => 'au'],
        ['name' => "Côte d'Ivoire",   'slug' => 'cote-divoire', 'prep' => 'en'],
        ['name' => 'Niger',           'slug' => 'niger',        'prep' => 'au'],
        ['name' => 'Nigeria',         'slug' => 'nigeria',      'prep' => 'au'],
        ['name' => 'Togo',            'slug' => 'togo',         'prep' => 'au'],
    ];

    /**
     * Look up a country entry by its public slug.
     *
     * @return array{name: string, slug: string, prep: string}|null
     */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::ALL as $country) {
            if ($country['slug'] === $slug) {
                return $country;
            }
        }
        return null;
    }

    /** @return list<string> Public slugs, in display order. */
    public static function slugs(): array
    {
        return array_map(static fn(array $c): string => $c['slug'], self::ALL);
    }
}

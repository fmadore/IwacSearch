<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

/**
 * Localizes the title + intro of a curated browse page at render time.
 *
 * Why not store the localized prose in the DB: the IWAC site runs a French
 * (/s/afrique_ouest) and an English (/s/westafrica) site off the same
 * browse-config rows. Storing one language's prose would be wrong on the
 * other site. Instead the seeded "system" pages (the six countries, the
 * references page, the all-countries page) are identified by slug and
 * their text is generated from per-locale templates here; admin-authored
 * custom pages fall back to whatever title/intro the admin stored.
 *
 * Pure data + string building — no services, no I/O.
 */
final class BrowseContent
{
    public const ALL_SLUG        = 'all';
    public const REFERENCES_SLUG = 'references';

    /**
     * @return array{title: string, introHtml: string}
     */
    public static function localize(BrowseConfig $config, string $locale): array
    {
        $locale = $locale === 'en' ? 'en' : 'fr';
        $slug   = $config->slug;

        $country = Countries::bySlug($slug);
        if ($country !== null) {
            return [
                // Country names are proper nouns — identical in both locales.
                'title'     => $country['name'],
                'introHtml' => self::countryIntro($country, $locale),
            ];
        }

        if ($slug === self::REFERENCES_SLUG) {
            return [
                'title'     => $locale === 'en' ? 'Bibliographic references' : 'Références bibliographiques',
                'introHtml' => self::referencesIntro($locale),
            ];
        }

        if ($slug === self::ALL_SLUG) {
            return [
                'title'     => $locale === 'en' ? 'All countries' : 'Tous les pays',
                'introHtml' => self::allIntro($locale),
            ];
        }

        // Admin-authored custom page — use the stored values verbatim.
        return ['title' => $config->title, 'introHtml' => $config->introHtml];
    }

    /**
     * @param array{name: string, slug: string, prep: string} $country
     */
    private static function countryIntro(array $country, string $locale): string
    {
        $name = self::esc($country['name']);
        if ($locale === 'en') {
            return sprintf(
                '<p>Documents about Islam and Muslim public life in %s — news articles, '
                . 'Islamic periodicals, audiovisual materials, and primary sources from the '
                . 'Islam West Africa Collection.</p>',
                $name
            );
        }
        return sprintf(
            "<p>Documents sur l'islam et la vie publique musulmane %s %s — articles de presse, "
            . 'périodiques islamiques, documents audiovisuels et sources primaires de la '
            . "Collection Islam Afrique de l'Ouest.</p>",
            $country['prep'],
            $name
        );
    }

    private static function referencesIntro(string $locale): string
    {
        if ($locale === 'en') {
            return '<p>Academic and bibliographic references on Islam and Muslim public life '
                . 'in West Africa — journal articles, books, theses, book chapters, reports, '
                . 'and other secondary literature. Use the Reference type facet to narrow by '
                . 'genre.</p>';
        }
        return "<p>Références universitaires et bibliographiques sur l'islam et la vie publique "
            . 'musulmane en Afrique de l\'Ouest — articles de revue, ouvrages, thèses, chapitres '
            . 'de livre, rapports et autre littérature secondaire. Utilisez le filtre Type de '
            . 'référence pour préciser par genre.</p>';
    }

    private static function allIntro(string $locale): string
    {
        if ($locale === 'en') {
            return '<p>Browse the entire Islam West Africa Collection across all countries — '
                . 'news articles, Islamic periodicals, audiovisual materials, and primary '
                . 'sources. Use the Country facet to narrow to one place.</p>';
        }
        return "<p>Parcourez l'ensemble de la Collection Islam Afrique de l'Ouest, tous pays "
            . 'confondus — articles de presse, périodiques islamiques, documents audiovisuels '
            . 'et sources primaires. Utilisez le filtre Pays pour vous concentrer sur un seul '
            . 'pays.</p>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

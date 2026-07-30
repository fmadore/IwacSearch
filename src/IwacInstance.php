<?php
declare(strict_types=1);

namespace IwacSearch;

/**
 * Everything this module assumes about the Omeka install it indexes.
 *
 * Resource class ids, item-set ids, the public site base and slugs are all
 * INSTANCE data — they change if the IWAC catalogue is renumbered, migrated,
 * or reused elsewhere, and none of them can be derived from the search schema.
 * They used to be spread across eight files (one `classIds()` per mapper,
 * `EntityAuthority::CLASS_IDS`, `ReferenceMapper::CLASS_LABELS`,
 * `CountryResolver::COUNTRY_ITEM_SETS`, `Mapper\SiteUrls`, `IwacLocale`), so
 * there was no single place to answer "what does this module expect of the
 * database?" — the first question on any migration.
 *
 * This class is that place. It is pure data: no services, no I/O, no
 * behaviour, so it stays trivially readable and safe to reference from
 * anywhere (including the view helpers). The one instance-specific payload
 * that does NOT live here is the newspaper→country table, which is large
 * enough to warrant its own file (data/newspaper-countries.json) — the same
 * externalisation principle, different medium.
 *
 * Verify against the live install with:
 *   SELECT id, local_name FROM resource_class WHERE id IN (…);
 *   SELECT id, title FROM resource WHERE resource_type LIKE '%ItemSet' AND id IN (…);
 */
final class IwacInstance
{
    // ────────────────────────────────────────────────────────────────────
    // Content resource classes — one per indexed subset
    // ────────────────────────────────────────────────────────────────────

    /** bibo:Article — digitised newspaper articles. */
    public const CLASS_ARTICLE = 36;

    /** bibo:Issue — Islamic magazines / journals captured at issue level. */
    public const CLASS_PUBLICATION = 60;

    /** bibo:Document — letters, communiqués, sermons, leaflets, reports. */
    public const CLASS_DOCUMENT = 49;

    /** bibo:AudioVisualDocument — audio / video recordings. */
    public const CLASS_AUDIOVISUAL = 38;

    /** bibo:Image — fieldwork photography (1:1 with resource template 15). */
    public const CLASS_PHOTOGRAPH = 58;

    /**
     * The nine bibliographic reference classes → their French type label
     * (mirrors o:resource_class), surfaced as `reference_type_ss`.
     *
     * @var array<int, string>
     */
    public const REFERENCE_CLASS_LABELS = [
        35  => 'Article de revue',
        43  => 'Chapitre',
        88  => 'Thèse',
        40  => 'Livre',
        82  => 'Rapport',
        178 => 'Compte rendu',
        77  => 'Communication',
        52  => 'Ouvrage collectif',
        305 => 'Article de blog',
    ];

    /**
     * Book chapters. Singled out because IWAC catalogues a chapter's
     * containing-book title in dcterms:alternative rather than
     * dcterms:isPartOf (verified live; the IWAC-SEO CitationMeta relies on
     * the same convention).
     */
    public const CLASS_CHAPTER = 43;

    // ────────────────────────────────────────────────────────────────────
    // Authority (entity) resource classes
    // ────────────────────────────────────────────────────────────────────

    public const CLASS_PERSON       = 94;  // foaf:Person
    public const CLASS_ORGANISATION = 96;  // foaf:Organization
    public const CLASS_PLACE        = 9;   // dcterms:Location
    public const CLASS_EVENT        = 54;  // bibo:Event
    public const CLASS_AUTHORITY_FILE = 244; // fabio:AuthorityFile

    /**
     * Every class the entity collection is built from.
     *
     * @var list<int>
     */
    public const ENTITY_CLASSES = [
        self::CLASS_PERSON,
        self::CLASS_ORGANISATION,
        self::CLASS_PLACE,
        self::CLASS_EVENT,
        self::CLASS_AUTHORITY_FILE,
    ];

    // ────────────────────────────────────────────────────────────────────
    // Item sets
    // ────────────────────────────────────────────────────────────────────

    /**
     * "Notices d'autorité". Authority files (class 244) in this set are
     * browsable entities in their own right but NEVER resolve into a content
     * facet; everything else on 244 is a subject heading (Sujets → topics_ss).
     */
    public const SET_NOTICES_AUTORITE = 267;

    /**
     * Per-country item set → country name (accented display form), for the
     * subsets that carry no newspaper: the "Références", "Documents divers"
     * and "Photographies" set families. The families don't overlap, so one
     * lookup serves them all.
     *
     * Names must match the `country_ss` facet value EXACTLY — Typesense
     * filter_by is accent- and case-sensitive, and the preset locked filters
     * are built from {@see \IwacSearch\Search\PresetCatalog}.
     *
     * @var array<int, string>
     */
    public const COUNTRY_ITEM_SETS = [
        // Références
        2193 => 'Bénin',
        2212 => 'Burkina Faso',
        2217 => "Côte d'Ivoire",
        2222 => 'Niger',
        2225 => 'Nigeria',
        2228 => 'Togo',
        // Documents divers
        23452 => 'Bénin',
        23453 => 'Burkina Faso',
        76366 => "Côte d'Ivoire",
        26327 => 'Togo',
        // Photographies
        2192 => 'Bénin',
        2211 => 'Burkina Faso',
        2216 => "Côte d'Ivoire",
        2220 => 'Niger',
        2227 => 'Togo',
    ];

    // ────────────────────────────────────────────────────────────────────
    // Place authorities that name a country
    // ────────────────────────────────────────────────────────────────────

    /**
     * Place-authority title (as catalogued in `dcterms:spatial`) → the
     * `country_ss` facet value. The last-resort country signal, for subsets
     * with neither a newspaper nor a per-country item set — audiovisual,
     * whose 45 Nigerian recordings sit in topical sets ("Collection de
     * sermons islamiques sur vidéo") and name their country only here.
     *
     * The keys are the FRENCH place headings and the values the facet
     * spelling, so this table is also where the two disagree: the place
     * authority is `Nigéria`, the facet value is `Nigeria`. Both spellings
     * are listed because a heading may be recatalogued either way; matching
     * is lowercased but NOT accent-folded, so each form must be explicit.
     *
     * @var array<string, string>
     */
    public const COUNTRY_PLACE_NAMES = [
        'Bénin'         => 'Bénin',
        'Benin'         => 'Bénin',
        'Burkina Faso'  => 'Burkina Faso',
        "Côte d'Ivoire" => "Côte d'Ivoire",
        "Cote d'Ivoire" => "Côte d'Ivoire",
        'Niger'         => 'Niger',
        'Nigéria'       => 'Nigeria',
        'Nigeria'       => 'Nigeria',
        'Togo'          => 'Togo',
    ];

    // ────────────────────────────────────────────────────────────────────
    // Public site
    // ────────────────────────────────────────────────────────────────────

    /** Public base URL, used to build the canonical item + IIIF manifest links. */
    public const SITE_BASE = 'https://islam.zmo.de';

    /**
     * Canonical (French) site slug. Indexed `omeka_url` values point here;
     * the English UI swaps the slug in the theme, not in the index.
     */
    public const SITE_SLUG_FR = 'afrique_ouest';

    /** English site slug — drives the fr/en UI-locale heuristic. */
    public const SITE_SLUG_EN = 'westafrica';

    private function __construct()
    {
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Per-collection search parameters, in one place so the client
 * (typesense.ts), the SSR renderer, and every bootstrap builder agree.
 *
 * Two collections, two field sets:
 *   - CONTENT_* — the article/document/publication/audiovisual/reference
 *     collection (data/schema.yaml). Full FTS + semantic embedding.
 *   - ENTITY_*  — the index/authority collection (data/schema-index.yaml).
 *     No OCR, no abstract, no embedding — query/highlight only the fields
 *     that exist there, or Typesense 404s on the missing field names.
 */
final class SearchDefaults
{
    /**
     * Content keyword fields, ordered title-ish → body → metadata. Beyond
     * recall, every keyword field here doubles as MATCH ATTRIBUTION: the
     * client reads the per-field highlights to tell the user WHY a hit
     * matched ("found in subject / author / alternative title / spatial").
     * subjects_ss + places_ss make tag-only matches (no OCR mention) findable;
     * creator_ss / publisher_s / book_title_s power the references surface
     * (search by author, journal, book title, publisher). `embedding` last —
     * hybrid semantic recall, ignored for highlights.
     */
    public const CONTENT_QUERY_BY        = 'title_txt,alt_title_txt,ocr_text,abstract,'
        . 'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt,embedding';
    public const CONTENT_HIGHLIGHT_FIELDS = 'title_txt,alt_title_txt,ocr_text,abstract,'
        . 'creator_ss,subjects_ss,places_ss,publisher_s,book_title_s,entity_aliases_txt';

    public const ENTITY_QUERY_BY         = 'title_txt,entity_aliases_txt';
    public const ENTITY_HIGHLIGHT_FIELDS = 'title_txt';

    /**
     * Above-the-fold facet stack for the full content corpus, ordered
     * coarse → fine: type, then geography, publisher, the entity
     * authorities, then the grouped sentiment trio.
     *
     * ONE list, three consumers: the standalone /search shell, the
     * federated page's Content tab (both via
     * SearchController::contentBootstrap) and the page-block form's default
     * for new custom blocks (IwacSearchBlock::form) — the block default had
     * silently drifted from the controller copy before it was shared.
     *
     * NOTE: PresetCatalog's per-scope stacks (CONTENT_ALL_FACETS etc.)
     * differ deliberately — presets are curated scopes, not the corpus-wide
     * default (e.g. the 'all' preset leads with Country and adds Language).
     *
     * @var list<string>
     */
    public const CONTENT_PROMINENT_FACETS = [
        'type_s',                // article | publication | document | audiovisual
        'has_fulltext',          // full text publicly readable (primary sources only)
        'country_ss',            // country
        'newspaper_ss',          // publisher
        'places_ss',             // locations
        'persons_ss',            // persons
        'organisations_ss',      // organisations
        'topics_ss',             // subjects
        // Sentiment trio — grouped under one collapsible section in the
        // client. Named for the annotating model (gemini-3-flash-preview),
        // not the vendor slot; see data/schema.yaml.
        'gemini_3_flash_preview_polarite_ss',    // polarity
        'gemini_3_flash_preview_centralite_ss',  // centrality (of Islam/Muslims)
        'gemini_3_flash_preview_subjectivite',   // subjectivity (1–5)
    ];
}

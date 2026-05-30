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
    public const CONTENT_QUERY_BY        = 'title_txt,ocr_text,abstract,entity_aliases_txt,embedding';
    public const CONTENT_HIGHLIGHT_FIELDS = 'title_txt,ocr_text';

    public const ENTITY_QUERY_BY         = 'title_txt,entity_aliases_txt';
    public const ENTITY_HIGHLIGHT_FIELDS = 'title_txt';
}

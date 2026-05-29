<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Documents subset (~26 rows) — heterogeneous miscellaneous written
 * documents: official letters, communiqués, sermons, leaflets,
 * manuscripts, reports, posters, etc.
 *
 * Has OCR but no AI sentiment, no LDA topics, no lexical metrics. The
 * row-level `type` field discriminates the document type within this
 * heterogeneous bucket — we pass it through as a free-text tag rather
 * than mapping to a controlled facet.
 */
final class DocumentMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'documents';
    }

    protected function typeTag(): string
    {
        return 'document';
    }

    public function map(array $row): ?array
    {
        $doc = $this->buildBase($row);
        if ($doc === null) {
            return null;
        }

        $this->addCommonFacets($doc, $row);
        $this->addAuthorityEntities($doc, $row);
        $this->addDateFields($doc, $row);
        $this->addBodyFields($doc, $row);
        $this->addDescription($doc, $row);

        return $doc;
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Publications subset (~1,501 rows) — Islamic magazines / journals
 * captured at the issue level (bibo:Issue).
 *
 * Same Omeka template as articles but split off by RDF class. Carries
 * full OCR + table-of-contents, but does NOT have the three-model AI
 * sentiment fields (those are computed per-article in the Omeka
 * pipeline, not per-issue).
 */
final class PublicationMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'publications';
    }

    protected function typeTag(): string
    {
        return 'publication';
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
        // No AI sentiment for issues — intentionally skipped.

        return $doc;
    }
}

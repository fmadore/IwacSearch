<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Audiovisual subset (~45 rows) — audio/video, primarily Nigerian.
 *
 * No OCR, no AI sentiment, no LDA topics. Just identity + facets +
 * authority-resolved entities + date.
 */
final class AudiovisualMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'audiovisual';
    }

    protected function typeTag(): string
    {
        return 'audiovisual';
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
        $this->addDescription($doc, $row);
        // No body, no sentiment.

        return $doc;
    }
}

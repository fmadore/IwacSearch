<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Publications (bibo:Issue, class 60) — Islamic magazines / journals captured
 * at the issue level. Full OCR, but no AI sentiment and no AI summary (those
 * are computed per-article upstream, not per-issue). country_ss derives from
 * the publisher (magazine title) via the same newspaper→country map.
 */
final class PublicationMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'publications';
    }

    public function classIds(): array
    {
        return [IwacInstance::CLASS_PUBLICATION];
    }

    protected function typeTag(): string
    {
        return 'publication';
    }

    public function readTerms(): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_TERMS,
            self::BODY_TERMS,
        )));
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addBodyFields($doc, $values);

        return $doc;
    }
}

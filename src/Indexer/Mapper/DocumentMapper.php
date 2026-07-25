<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Documents (bibo:Document, class 49) — miscellaneous written documents
 * (letters, communiqués, sermons, leaflets, reports, …). OCR + AI summary,
 * no sentiment. Carries no newspaper, so country falls back to the per-country
 * "Documents divers" item set when the publisher→country lookup finds nothing.
 */
final class DocumentMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'documents';
    }

    public function classIds(): array
    {
        return [IwacInstance::CLASS_DOCUMENT];
    }

    protected function typeTag(): string
    {
        return 'document';
    }

    public function readTerms(): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_TERMS,
            self::BODY_TERMS,
            self::DESCRIPTION_TERMS,
        )));
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        // Documents rarely carry a newspaper; fall back to the country item set.
        if (!isset($doc['country_ss'])) {
            $this->maybeAddList($doc, 'country_ss', $this->countries->forItemSets($item['item_sets']));
        }
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addBodyFields($doc, $values);
        $this->addDescription($doc, $values);

        return $doc;
    }
}

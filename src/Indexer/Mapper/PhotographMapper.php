<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Photographs (bibo:Image, class 58 — 1:1 with resource template 15
 * "Photographie", verified live: 30 items on both selectors). Fieldwork
 * photography: title, creator (linked person), date, subject, spatial
 * coverage, rights, identifier. No OCR, no AI sentiment/summary, no
 * publisher — country derives from the per-country "Photographies"
 * item sets (see CountryResolver::COUNTRY_ITEM_SETS).
 */
final class PhotographMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'photographs';
    }

    public function classIds(): array
    {
        return [IwacInstance::CLASS_PHOTOGRAPH];
    }

    protected function typeTag(): string
    {
        return 'photograph';
    }

    public function readTerms(): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_TERMS,
            self::DESCRIPTION_TERMS,
        )));
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        // Photographs carry no publisher, so country comes from the
        // per-country "Photographies" item set.
        if (!isset($doc['country_ss'])) {
            $this->maybeAddList($doc, 'country_ss', $this->countries->forItemSets($item['item_sets']));
        }
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addDescription($doc, $values);
        // No bibo:content → an honest has_fulltext=false, keeping the
        // "Full text available" facet counts complete.
        $this->addBodyFields($doc, $values);

        return $doc;
    }
}

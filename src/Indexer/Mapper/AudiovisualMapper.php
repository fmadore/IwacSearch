<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Audiovisual (bibo:AudioVisualDocument, class 38) — audio/video recordings,
 * primarily Nigerian. No OCR, no sentiment: identity + facets + entities +
 * date + AI summary.
 *
 * Note: most AV is Nigerian, and Nigerian outlets aren't in the
 * newspaper→country map (Nigeria is barely present in the press subsets
 * upstream), so country_ss is often empty here — matching the sparse country
 * coverage of the HF audiovisual subset.
 */
final class AudiovisualMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'audiovisual';
    }

    public function classIds(): array
    {
        return [38];
    }

    protected function typeTag(): string
    {
        return 'audiovisual';
    }

    public function readTerms(): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_TERMS,
            self::DESCRIPTION_TERMS,
        )));
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addDescription($doc, $values);

        return $doc;
    }
}

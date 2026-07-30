<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Audiovisual (bibo:AudioVisualDocument, class 38) — audio/video recordings,
 * primarily Nigerian. No OCR, no sentiment: identity + facets + entities +
 * date + AI summary.
 *
 * Country needs its own fallback here. Recordings carry a producer rather
 * than a newspaper ("Daarul Hadeethis Salafiyyah" for 44 of the 47), and
 * Nigerian outlets are absent from the newspaper→country map, so the
 * publisher path resolves nothing; nor do the topical sets they sit in
 * ("Enregistrements audio", "Collection de sermons islamiques sur vidéo")
 * appear in the per-country set families. Their `dcterms:spatial` DOES name
 * the country (45 of 47 are catalogued under the "Nigéria" place authority),
 * so that is the signal we read. Without it every Nigerian recording indexed
 * with no country_ss at all and the whole /browse/nigeria scope came back
 * empty, since per-country references are excluded from country presets.
 */
final class AudiovisualMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'audiovisual';
    }

    public function classIds(): array
    {
        return [IwacInstance::CLASS_AUDIOVISUAL];
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

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        // Recordings have no newspaper and no per-country set; the place
        // heading is the only country signal (see the class docblock).
        if (!isset($doc['country_ss'])) {
            $this->maybeAddList(
                $doc,
                'country_ss',
                $this->countries->forPlaces($values->displays('dcterms:spatial'))
            );
        }
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addDescription($doc, $values);
        // Recordings carry no bibo:content, so this resolves to an honest
        // has_fulltext=false — keeping the "Full text available" facet
        // counts complete across the primary-source subsets.
        $this->addBodyFields($doc, $values);

        return $doc;
    }
}

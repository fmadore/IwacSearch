<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Articles (bibo:Article, class 36) — digitised newspaper articles.
 *
 * Richest subset: full OCR, three-model AI sentiment, original-source URL,
 * AI summary. (LDA topic labels were HF-only and are intentionally dropped.)
 */
final class ArticleMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'articles';
    }

    public function classIds(): array
    {
        return [36];
    }

    protected function typeTag(): string
    {
        return 'article';
    }

    public function readTerms(): array
    {
        return array_values(array_unique(array_merge(
            self::COMMON_TERMS,
            self::BODY_TERMS,
            self::DESCRIPTION_TERMS,
            self::SENTIMENT_TERMS,
        )));
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->maybeAdd($doc, 'source_url', $this->firstScalar($values, 'fabio:hasURL'));
        $this->addCommonFacets($doc, $values);
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addBodyFields($doc, $values);
        $this->addDescription($doc, $values);
        $this->addAiSentiment($doc, $values);

        return $doc;
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Articles subset (~12,287 rows) — digitized newspaper articles.
 *
 * Richest subset: full OCR, three-model AI sentiment, LDA topic labels,
 * lexical metrics, original-source URL.
 */
final class ArticleMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'articles';
    }

    protected function typeTag(): string
    {
        return 'article';
    }

    public function map(array $row): ?array
    {
        $doc = $this->buildBase($row);
        if ($doc === null) {
            return null;
        }

        // Articles only — original publisher URL
        $this->maybeAdd($doc, 'source_url', $this->str($row['URL'] ?? ''));

        $this->addCommonFacets($doc, $row);
        $this->addAuthorityEntities($doc, $row);
        $this->addDateFields($doc, $row);
        $this->addBodyFields($doc, $row);
        $this->addDescription($doc, $row);
        $this->addAiSentiment($doc, $row);

        return $doc;
    }
}

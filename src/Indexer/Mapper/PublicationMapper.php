<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Publications (bibo:Issue, class 60) — Islamic magazines / journals captured
 * at the issue level. Full OCR plus the curated table of contents, but no AI
 * sentiment/summary (those are computed per-article upstream, not per-issue).
 * country_ss derives from the publisher (magazine title) via the same
 * newspaper→country map.
 */
final class PublicationMapper extends AbstractMapper
{
    /** Keep the display payload small; the full value remains in toc_txt. */
    private const ABSTRACT_MAX_CHARS = 600;

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
            ['dcterms:tableOfContents'],
        )));
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): array
    {
        $values = $values->publicMetadata();
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        $this->addCommonFacets($doc, $values);
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);
        $this->addBodyFields($doc, $values);
        $this->addTableOfContents($doc, $values, $item['template']);

        return $doc;
    }

    /**
     * Index every curated ToC value as one searchable blob and reuse a short
     * excerpt as the public card body. A future per-entry parser can coexist
     * with this field; preserving the source blob keeps that migration open.
     *
     * On the templates where the ToC is a model's per-page summary
     * ({@see IwacInstance::AI_TABLE_OF_CONTENTS_TEMPLATES}), the excerpt — and
     * a toc_txt highlight shown in its place — is flagged `abstract_ai`.
     *
     * @param array<string, mixed> $doc
     */
    private function addTableOfContents(array &$doc, PropertyValues $values, ?int $template): void
    {
        $toc = implode("\n\n", $values->publicLiterals('dcterms:tableOfContents'));
        if ($toc === '') {
            return;
        }

        $doc['toc_txt'] = $toc;
        $doc['abstract'] = mb_strlen($toc, 'UTF-8') <= self::ABSTRACT_MAX_CHARS
            ? $toc
            : rtrim(mb_substr($toc, 0, self::ABSTRACT_MAX_CHARS - 1, 'UTF-8')) . '…';
        if ($template !== null && in_array($template, IwacInstance::AI_TABLE_OF_CONTENTS_TEMPLATES, true)) {
            $doc['abstract_ai'] = true;
        }
    }
}

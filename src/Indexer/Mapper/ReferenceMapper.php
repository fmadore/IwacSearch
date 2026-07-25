<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * References — bibliographic citations across 9 RDF classes. Unlike the
 * primary-source subsets these are catalogued with bibliographic vocab:
 *
 *   - author  ← `bibo:authorList`  (NOT dcterms:creator)
 *   - editor  ← `bibo:editorList`
 *   - venue   ← `dcterms:publisher` (journal title or book publisher)
 *   - abstract← `dcterms:abstract`  (the real, human-written abstract — there
 *               is no OCR and no AI summary here)
 *   - volume/issue/pages/doi/edition ← `bibo:*`
 *   - country ← per-country "Références" item set (no newspaper)
 *   - reference_type_ss ← the resource CLASS, mapped to its French label
 *
 * `type_s = "reference"` keeps them filterable as one bucket against the
 * primary subsets; `reference_type_ss` narrows within.
 */
final class ReferenceMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'references';
    }

    public function classIds(): array
    {
        return array_keys(IwacInstance::REFERENCE_CLASS_LABELS);
    }

    protected function typeTag(): string
    {
        return 'reference';
    }

    public function readTerms(): array
    {
        return [
            'dcterms:identifier', 'dcterms:alternative', 'dcterms:language',
            'dcterms:publisher', 'dcterms:subject', 'dcterms:spatial',
            'dcterms:date', 'fabio:hasURL',
            'bibo:authorList', 'bibo:editorList', 'bibo:doi', 'bibo:volume',
            'bibo:issue', 'bibo:pageStart', 'bibo:pageEnd', 'bibo:edition',
            'dcterms:abstract', 'dcterms:isPartOf',
        ];
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        // ── Authorship (bibo:authorList, not dcterms:creator) ──────────────
        $creators = $values->displays('bibo:authorList');
        $this->maybeAddList($doc, 'creator_ss', $creators);
        if ($creators !== []) {
            $this->maybeAdd($doc, 'creator_sort', $this->authorSortKey($creators[0]));
        }
        $this->maybeAddList($doc, 'language_ss', $values->displays('dcterms:language'));

        // ── Country from the per-country Références item set ────────────────
        $this->maybeAddList($doc, 'country_ss', $this->countries->forItemSets($item['item_sets']));

        // ── Reference type from the RDF class ───────────────────────────────
        $label = IwacInstance::REFERENCE_CLASS_LABELS[$item['class']] ?? '';
        if ($label !== '') {
            $doc['reference_type_ss'] = [$label];
        }

        // ── Bibliographic detail (citation line + journal/publisher facet) ──
        $this->maybeAdd($doc, 'publisher_s',  $values->firstDisplay('dcterms:publisher'));
        // IWAC catalogues a chapter's containing-book title in
        // dcterms:ALTERNATIVE (verified live; dcterms:isPartOf is empty on
        // chapters — same convention the IWAC-SEO CitationMeta relies on).
        // isPartOf stays as a fallback for records catalogued the other way.
        if ($item['class'] === IwacInstance::CLASS_CHAPTER) {
            $book = $values->firstDisplay('dcterms:alternative');
            if ($book === '') {
                $book = $values->firstDisplay('dcterms:isPartOf');
            }
            $this->maybeAdd($doc, 'book_title_s', $book);
        } else {
            $this->maybeAdd($doc, 'book_title_s', $values->firstDisplay('dcterms:isPartOf'));
        }
        $this->maybeAdd($doc, 'edition_s',    $values->firstLiteral('bibo:edition'));
        $this->maybeAddList($doc, 'editor_ss', $values->displays('bibo:editorList'));
        $this->maybeAdd($doc, 'volume_s', $values->firstLiteral('bibo:volume'));
        $this->maybeAdd($doc, 'issue_s',  $values->firstLiteral('bibo:issue'));
        $this->maybeAdd($doc, 'pages_s', $this->pageRange(
            $values->firstLiteral('bibo:pageStart'),
            $values->firstLiteral('bibo:pageEnd'),
        ));

        // ── Body = the real abstract; outbound links ────────────────────────
        $this->maybeAdd($doc, 'abstract',   $values->firstLiteral('dcterms:abstract'));
        $this->maybeAdd($doc, 'source_url', $values->firstScalar('fabio:hasURL'));
        $this->maybeAdd($doc, 'doi',        $values->firstScalar('bibo:doi'));

        // ── Shared: authority entities + dates ──────────────────────────────
        $this->addAuthorityEntities($doc, $values);
        $this->addDateFields($doc, $values);

        return $doc;
    }

    /** Format a page range for display: "185–209" (en dash), or a single page. */
    private function pageRange(string $start, string $end): string
    {
        if ($start === '' && $end === '') {
            return '';
        }
        if ($start !== '' && $end !== '' && $start !== $end) {
            return $start . '–' . $end;
        }
        return $start !== '' ? $start : $end;
    }
}

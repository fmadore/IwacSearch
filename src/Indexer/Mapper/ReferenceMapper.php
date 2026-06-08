<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

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
    /** The 9 reference classes → their French type label (o:resource_class). */
    private const CLASS_LABELS = [
        35  => 'Article de revue',
        43  => 'Chapitre',
        88  => 'Thèse',
        40  => 'Livre',
        82  => 'Rapport',
        178 => 'Compte rendu',
        77  => 'Communication',
        52  => 'Ouvrage collectif',
        305 => 'Article de blog',
    ];

    public function subsetName(): string
    {
        return 'references';
    }

    public function classIds(): array
    {
        return array_keys(self::CLASS_LABELS);
    }

    protected function typeTag(): string
    {
        return 'reference';
    }

    public function readTerms(): array
    {
        return [
            'dcterms:identifier', 'dcterms:language', 'dcterms:publisher',
            'dcterms:subject', 'dcterms:spatial', 'dcterms:date', 'fabio:hasURL',
            'bibo:authorList', 'bibo:editorList', 'bibo:doi', 'bibo:volume',
            'bibo:issue', 'bibo:pageStart', 'bibo:pageEnd', 'bibo:edition',
            'dcterms:abstract', 'dcterms:isPartOf',
        ];
    }

    public function map(array $item, array $values, ?string $thumbnailUrl): ?array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        // ── Authorship (bibo:authorList, not dcterms:creator) ──────────────
        $creators = $this->disp($values, 'bibo:authorList');
        $this->maybeAddList($doc, 'creator_ss', $creators);
        if ($creators !== []) {
            $this->maybeAdd($doc, 'creator_sort', $this->authorSortKey($creators[0]));
        }
        $this->maybeAddList($doc, 'language_ss', $this->disp($values, 'dcterms:language'));

        // ── Country from the per-country Références item set ────────────────
        $this->maybeAddList($doc, 'country_ss', $this->countries->forItemSets($item['item_sets']));

        // ── Reference type from the RDF class ───────────────────────────────
        $label = self::CLASS_LABELS[$item['class']] ?? '';
        if ($label !== '') {
            $doc['reference_type_ss'] = [$label];
        }

        // ── Bibliographic detail (display-only citation line) ───────────────
        $this->maybeAdd($doc, 'publisher_s',  $this->firstDisp($values, 'dcterms:publisher'));
        $this->maybeAdd($doc, 'book_title_s', $this->firstDisp($values, 'dcterms:isPartOf'));
        $this->maybeAdd($doc, 'edition_s',    $this->firstLiteral($values, 'bibo:edition'));
        $this->maybeAddList($doc, 'editor_ss', $this->disp($values, 'bibo:editorList'));
        $this->maybeAdd($doc, 'volume_s', $this->firstLiteral($values, 'bibo:volume'));
        $this->maybeAdd($doc, 'issue_s',  $this->firstLiteral($values, 'bibo:issue'));
        $this->maybeAdd($doc, 'pages_s', $this->pageRange(
            $this->firstLiteral($values, 'bibo:pageStart'),
            $this->firstLiteral($values, 'bibo:pageEnd'),
        ));

        // ── Body = the real abstract; outbound links ────────────────────────
        $this->maybeAdd($doc, 'abstract',   $this->firstLiteral($values, 'dcterms:abstract'));
        $this->maybeAdd($doc, 'source_url', $this->firstScalar($values, 'fabio:hasURL'));
        $this->maybeAdd($doc, 'doi',        $this->firstScalar($values, 'bibo:doi'));

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

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * References subset (~864 rows) — bibliographic citations.
 *
 * Heterogeneous content: 9 RDF classes (Article de revue · Chapitre ·
 * Thèse · Livre · Rapport · Communication · Compte rendu · Ouvrage
 * collectif · Article de blog). The dedicated `reference_type_ss`
 * facet lets users narrow within the subset; `type_s = "reference"`
 * keeps them filterable as a single bucket against primary-source
 * subsets (article / publication / document / audiovisual).
 *
 * Notable shape differences from the primary-source subsets:
 *   - No OCR. The `abstract` field carries the body text we'd FTS-search.
 *   - No AI sentiment, no LDA topics, no embeddings.
 *   - `URL` is the original publisher URL (DOI page, journal site, etc.);
 *     `iwac_url` is the IWAC item page. Both kept.
 *   - `country` is pipe-separated (academic literature often discusses
 *     multiple countries). The `addCommonFacets()` helper in
 *     AbstractMapper splits this into the country_ss[] facet uniformly
 *     across every subset, so `Niger|Nigeria` becomes two facet values.
 *   - `language` carries French labels ("Français", "Anglais", ...) rather
 *     than ISO codes. Kept as-is to match the source data; the references
 *     browse page lives on its own slug so the value mismatch with other
 *     subsets only matters in cross-subset searches.
 *
 * Fill rates (from the May 2026 parquet):
 *   - 100% have title, author, pub_date
 *   - ~51% have abstract (median 1170 chars, p90 ~2300, max ~9300)
 *   - DOI fill is partial — kept as a stored, non-indexed link target.
 *
 * @see references/subsets-schema.md (iwac-dataset skill) — full field list
 */
final class ReferenceMapper extends AbstractMapper
{
    public function subsetName(): string
    {
        return 'references';
    }

    protected function typeTag(): string
    {
        return 'reference';
    }

    public function map(array $row): ?array
    {
        $doc = $this->buildBase($row);
        if ($doc === null) {
            return null;
        }

        // ── Authorship + provenance ────────────────────────────────────
        // References use `author`, `language`, and `country` the same way
        // as primary subsets — pipe-separated multi-values, all split
        // through addCommonFacets() into their respective *_ss[] facets.
        // Newspaper is irrelevant for references; the helper just no-ops
        // on the empty / missing column.
        $this->addCommonFacets($doc, $row);

        // ── Reference type (the 9 RDF classes) ─────────────────────────
        // Stored as a single-value string[] so it parallels country_ss /
        // newspaper_ss in the facet UI. `type` (the finer 12-value
        // sub-classification — `Mémoire de licence`, `Working paper`, …)
        // is intentionally NOT indexed; it adds noise without enough
        // facet leverage at 864 docs total.
        $resourceClass = $this->str($row['o:resource_class'] ?? '');
        if ($resourceClass !== '') {
            $doc['reference_type_ss'] = [$resourceClass];
        }

        // ── Body text ──────────────────────────────────────────────────
        // Abstract is the FTS body — distinct field from ocr_text because
        // we want it visible in public results (see schema.yaml comment).
        $this->maybeAdd($doc, 'abstract', $this->str($row['abstract'] ?? ''));

        // ── Outbound link target ───────────────────────────────────────
        // Stored, not indexed. `URL` is the original publisher / DOI page
        // (when present) — handy for "follow the citation" UX in the
        // result panel without polluting the FTS index.
        $this->maybeAdd($doc, 'source_url', $this->str($row['URL'] ?? ''));
        $this->maybeAdd($doc, 'doi',        $this->str($row['doi'] ?? ''));

        // ── Authority entities + dates (shared with every subset) ──────
        $this->addAuthorityEntities($doc, $row);
        $this->addDateFields($doc, $row);

        // No OCR body, no AI sentiment, no LDA — references don't carry
        // any of those upstream.

        return $doc;
    }
}

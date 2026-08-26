<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\IwacInstance;
use IwacSearch\Indexer\PropertyValues;

/**
 * Audiovisual (bibo:AudioVisualDocument, class 38) — audio/video records.
 *
 * ONE CLASS, TWO POPULATIONS since August 2026, and the second one is now the
 * bulk of it:
 *
 *   template 19 — 47 DVDs/CDs deposited with the project, mostly Nigerian,
 *                 with a real media file and (sometimes) a transcript.
 *   template 23 — 1,099 videos ingested from public YouTube channels
 *                 (RTB, CERFI, L'Autregard, Burkina Info — all Burkina Faso
 *                 so far), each with a canonical watch URL, a public channel
 *                 description, an ISO-8601 duration, and a media that carries
 *                 NO file (Omeka's youtube ingester stores only thumbnail
 *                 derivatives).
 *
 * The mapper is class-based, so the second population arrived on its own; what
 * it needed was fidelity. Hence, beyond the common fields:
 *
 *   - `dcterms:publisher` lands in `channel_ss`, NOT `newspaper_ss`. It is a
 *     broadcaster or a YouTube channel — rendering it under a facet labelled
 *     "Journal / Newspaper" stated something false. The country derivation
 *     from the publisher name is unchanged (see addCommonFacets).
 *   - `fabio:hasURL` becomes `source_url`, so a card can offer the canonical
 *     watch link ALONGSIDE the IWAC item (never instead of it).
 *   - type / medium / extent / rights become the normalised media fields.
 *   - no IIIF manifest for web-hosted records: their media has no file, so the
 *     manifest resolves 200 with ZERO canvases. The thumbnail is real and is
 *     kept.
 *
 * Country needs its own fallback here. The deposited recordings carry a
 * producer rather than a newspaper ("Daarul Hadeethis Salafiyyah" for 44 of
 * the 47), Nigerian outlets are absent from the newspaper→country map, and the
 * topical sets they sit in ("Enregistrements audio", "Collection de sermons
 * islamiques sur vidéo") are not per-country either. Their `dcterms:spatial`
 * DOES name the country (45 of 47 under the "Nigéria" place authority), and so
 * does every YouTube record ("Burkina Faso"), so that is the signal we read.
 * Without it every recording indexed with no country_ss at all and the whole
 * /browse/nigeria scope came back empty, since per-country references are
 * excluded from country presets.
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
            // Transcripts. Sparse (a separate opt-in pass, ~5% of records in
            // August 2026) and growing — the older "recordings carry no
            // bibo:content" assumption cost the ones that DO have a
            // transcript their full-text search entirely.
            self::BODY_TERMS,
            self::DESCRIPTION_TERMS,
            self::MEDIA_TERMS,
        )));
    }

    public function map(array $item, PropertyValues $values, ?string $thumbnailUrl): array
    {
        $doc = $this->buildBase($item, $values, $thumbnailUrl);

        // Set before addMediaFields — the platform derivation reads it to tell
        // YouTube from any other web host.
        $this->maybeAdd($doc, 'source_url', $values->firstScalar('fabio:hasURL'));

        $this->addCommonFacets($doc, $values, 'channel_ss');
        // Neither a newspaper nor a per-country set; the place heading is the
        // remaining country signal (see the class docblock).
        if (!isset($doc['country_ss'])) {
            $this->maybeAddList(
                $doc,
                'country_ss',
                $this->countries->forPlaces($values->displays('dcterms:spatial'))
            );
        }
        // countPublisher: the producing channel is an occurrence here (HF
        // counts it), which is what gives a YouTube channel a real figure
        // instead of the 0 it showed while only subject/spatial counted.
        $this->addAuthorityEntities($doc, $values, ['dcterms:creator'], true);
        $this->addDateFields($doc, $values);
        $this->addDescription($doc, $values);
        // Records with a transcript get a real ocr_text + has_fulltext; the
        // rest an honest has_fulltext=false, keeping the "Full text available"
        // facet counts complete across the primary-source subsets.
        $this->addBodyFields($doc, $values);
        $this->addMediaFields($doc, $values);

        // A YouTube media has no stored file, so the item's IIIF manifest
        // resolves with zero canvases — a link to nothing. buildBase emits one
        // for anything thumbnailed (the right rule for scanned material), so
        // withdraw it here rather than shipping a manifest with nothing to
        // page through. `thumbnail_url` stays: the poster frame is real.
        if (in_array($doc['media_platform_s'] ?? '', ['youtube', 'web'], true)) {
            unset($doc['iiif_manifest']);
        }

        return $doc;
    }
}

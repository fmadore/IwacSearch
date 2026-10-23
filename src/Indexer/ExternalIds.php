<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * The two external identifiers a result card links out to, reduced to their
 * bare form so the card (and the exports) never have to guess a spelling:
 *
 *   wikidata_id ← a `dcterms:identifier` URI on wikidata.org  → "Q141697087"
 *   doi         ← `bibo:doi`, a doi.org URI or a typed DOI    → "10.48734/…"
 *
 * IWAC catalogues both as URI values labelled with the bare identifier — on
 * references, on the journals and publishers reconciled with them, and on the
 * index entities. The same reading is done by IWAC-theme's ExternalIdentifier
 * helper for the item page; keep the two patterns in step.
 */
final class ExternalIds
{
    private const WIKIDATA = '~^https?://(?:www\.|m\.)?wikidata\.org/(?:wiki|entity)/(?:Special:EntityPage/)?(Q[1-9][0-9]*)/?$~i';

    private const DOI = '~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)?(10\.[0-9]{4,9}/\S+)$~i';

    /**
     * The first Wikidata entity among a value list's URIs, or ''. Only an
     * entity (Q…) counts: a property or lexeme page is not a reconciliation.
     *
     * @param list<string> $uris
     */
    public static function wikidataQid(array $uris): string
    {
        foreach ($uris as $uri) {
            if (preg_match(self::WIKIDATA, trim($uri), $m)) {
                return strtoupper($m[1]);
            }
        }
        return '';
    }

    /**
     * The first candidate that reads as a DOI, as the bare `10.…` string, or
     * ''. A doi.org address is percent-decoded, so the card prints the DOI a
     * reader would cite rather than its URL encoding.
     *
     * @param list<string> $candidates
     */
    public static function doi(array $candidates): string
    {
        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if (preg_match(self::DOI, $raw, $m)) {
                return preg_match('~^https?://~i', $raw) ? rawurldecode($m[1]) : $m[1];
            }
        }
        return '';
    }
}

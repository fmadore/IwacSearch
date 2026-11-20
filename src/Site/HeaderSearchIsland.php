<?php

declare(strict_types=1);

namespace IwacSearch\Site;

/**
 * The header typeahead's configuration, printed on every public page as a
 * JSON data island: `<script type="application/json" id="…">{…}</script>`.
 *
 * It used to be an inline script assigning `window.IWAC_HEADER_SEARCH` and
 * swapping the header stylesheet in. That one executable inline block on
 * every page was what stood between the site and a `script-src 'self'`
 * Content-Security-Policy; a data island is never executed, so no policy
 * has to allow it (IwacVisualizations moved to islands for the same reason).
 * It also stays in the <head>: the theme's MoveBlockingHeadScripts carries
 * executable inline blocks to the end of <body>, but leaves data islands
 * where they are. header.ts reads it by {@see ID} and loads the stylesheet
 * itself.
 */
final class HeaderSearchIsland
{
    /** The island's element id; header.ts reads the same constant. */
    public const ID = 'iwac-header-search-config';

    /**
     * The island's JSON, or null when the config cannot be encoded.
     *
     * JSON_HEX_TAG turns `<` and `>` into \u003C / \u003E, so no value can
     * close the <script> element ("</script>") or open a comment in it;
     * JSON_HEX_AMP does the same for `&`. The parsed values are unchanged.
     *
     * @param array{endpoints: array<string, string>, locale: string, stylesheet: ?string} $config
     */
    public static function json(array $config): ?string
    {
        $json = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        return $json === false ? null : $json;
    }
}

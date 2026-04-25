<?php
declare(strict_types=1);

namespace IwacSearch\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * Encodes the bootstrap config blob with the exact flag set every IwacSearch
 * mount surface uses inside its inline `<script type="application/json">`.
 *
 *   - JSON_UNESCAPED_SLASHES    keep `/path/segments/` legible
 *   - JSON_UNESCAPED_UNICODE    keep diacritics + Arabic readable on the wire
 *   - JSON_HEX_TAG              `<` becomes `<` so a stray `</script>`
 *                               in a string can't break out of the script tag
 *   - JSON_HEX_AMP              `&` becomes `&` (defence in depth)
 *   - JSON_HEX_APOS / HEX_QUOT  any quote characters can't bust out either
 *
 * The flag combination is the security-relevant part of the contract — once
 * a regression is shipped (one PHTML accidentally drops JSON_HEX_TAG), the
 * Svelte client mostly works but a malicious string field in the bootstrap
 * could inject a payload. Centralising in one helper means future view
 * templates can never get the spell wrong.
 *
 * Keep this helper free of any rendering / HTML — it's a pure encode.
 */
final class IwacBootstrapJson extends AbstractHelper
{
    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /**
     * @param array<string, mixed> $bootstrap
     */
    public function __invoke(array $bootstrap): string
    {
        $encoded = json_encode($bootstrap, self::FLAGS);
        // json_encode returns false on failure (e.g. a Resource handle in
        // the payload). The bootstrap is built from arrays of scalars +
        // arrays so failure is unreachable in practice; emit '{}' rather
        // than throwing because a broken bootstrap should still let the
        // page render and show a clean client-side error message.
        return $encoded === false ? '{}' : $encoded;
    }
}

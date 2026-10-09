<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use Closure;

/**
 * The rules that keep a search block's intro HTML from being stored XSS.
 *
 * The block template prints `intro_html` RAW, and page-edit rights are far
 * broader than global admin, so every path that reaches the template must
 * have gone through the purifier exactly once:
 *
 *   - saved through the form   → purified and flagged at save (onHydrate);
 *   - saved before that existed → purified and flagged by the upgrade
 *                                 migration (IwacSearchBlock::purifyStoredIntros);
 *   - neither yet               → purified at render, every time.
 *
 * Pulled out of IwacSearchBlock — which extends Omeka's AbstractBlockLayout
 * and so cannot be loaded outside Omeka — so the boundary can be unit-tested
 * (tests/php/Site/IntroHtmlTest.php). The purifier is passed as a callable:
 * `fn(string $html): string`.
 */
final class IntroHtml
{
    /** Block-data key recording that intro_html was purified when stored. */
    public const PURIFIED_FLAG = 'intro_html_purified';

    /**
     * The intro as the template may print it: a flagged value as stored, an
     * unflagged (legacy) value through the purifier.
     *
     * @param array<string, mixed> $data
     * @param Closure(string): string $purify
     */
    public static function forRender(array $data, Closure $purify): string
    {
        $intro = (string) ($data['intro_html'] ?? '');
        if ($intro === '' || !empty($data[self::PURIFIED_FLAG])) {
            return $intro;
        }
        return $purify($intro);
    }

    /**
     * The migrated block data for a stored block, or null when it is already
     * flagged (nothing to write).
     *
     * @param array<string, mixed> $data
     * @param Closure(string): string $purify
     * @return array<string, mixed>|null
     */
    public static function migrated(array $data, Closure $purify): ?array
    {
        if (!empty($data[self::PURIFIED_FLAG])) {
            return null;
        }
        $intro = (string) ($data['intro_html'] ?? '');
        $data['intro_html'] = $intro === '' ? '' : $purify($intro);
        $data[self::PURIFIED_FLAG] = true;
        return $data;
    }
}

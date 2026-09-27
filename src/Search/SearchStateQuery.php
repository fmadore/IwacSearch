<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Whether a request URL carries the Svelte client's search state
 * (urlState.ts) — in which case a server-rendered snapshot of the DEFAULT
 * first page is wasted work: App.svelte adopts the snapshot only for the
 * pristine state and otherwise refetches.
 *
 * Works on the RAW query string on purpose. PHP rewrites dots in parameter
 * names to underscores when it builds $_GET, so `?f.country_ss=Bénin`
 * reaches Laminas' fromQuery() as `f_country_ss`, and `b12.q` as `b12_q`.
 * Reading the parsed array therefore missed every filter and year-range
 * link — including the country landing pages the legacy /browse/{country}
 * redirect produces.
 */
final class SearchStateQuery
{
    /** Scalar keys whose non-empty value means non-default state. */
    private const STATE_KEYS = ['q', 'sort', 'date.from', 'date.to', 'per'];

    /**
     * @param string $rawQuery The URL's query string, undecoded (no leading `?`).
     * @param string $prefix   '' for /search; `b{blockId}.` for a full-mode page block.
     */
    public static function carriesState(string $rawQuery, string $prefix = ''): bool
    {
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            $name = substr($key, strlen($prefix));
            $value = urldecode($rawValue);
            if (str_starts_with($name, 'f.')) {
                return true;
            }
            if (in_array($name, self::STATE_KEYS, true) && trim($value) !== '') {
                return true;
            }
            if ($name === 'page' && (int) $value > 1) {
                return true;
            }
        }
        return false;
    }
}

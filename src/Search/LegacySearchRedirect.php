<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Pure URL/query policy for legacy Omeka advanced-search redirects.
 *
 * Kept outside the controller so query migration is testable without
 * bootstrapping Omeka or installing a second copy of its Laminas runtime.
 */
final class LegacySearchRedirect
{
    public static function targetUrl(
        string $target,
        mixed $query,
        mixed $fulltextSearch
    ): string {
        $queryText = self::nonEmptyScalar($query)
            ?? self::nonEmptyScalar($fulltextSearch);

        if ($queryText === null) {
            return $target;
        }

        // iwacSearchUrl currently returns a path without a query string, but
        // preserve any future canonical parameters instead of replacing them.
        $fragment = '';
        $fragmentAt = strpos($target, '#');
        if ($fragmentAt !== false) {
            $fragment = substr($target, $fragmentAt);
            $target = substr($target, 0, $fragmentAt);
        }

        $separator = str_contains($target, '?') ? '&' : '?';
        if (str_ends_with($target, '?') || str_ends_with($target, '&')) {
            $separator = '';
        }

        return $target
            . $separator
            . http_build_query(['q' => $queryText], '', '&', PHP_QUERY_RFC3986)
            . $fragment;
    }

    private static function nonEmptyScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

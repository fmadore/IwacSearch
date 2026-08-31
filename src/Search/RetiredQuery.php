<?php
declare(strict_types=1);

namespace IwacSearch\Search;

/**
 * Query parameters belonging to the search surface retired in 3.0.0, and what
 * survives a redirect off them.
 *
 * The pre-3.0 surface filtered with `facet[dcterms_type_ss][9]=Article de
 * presse` and sorted with `sort_by` / `sort_order`. The Svelte client reads
 * `f.<field>=` and `sort=`, so those URLs now resolve to the bare shell — a 200
 * that looks like a distinct page to a crawler. Google indexed ~1,300 of them
 * and still lists them in Search Console; each permutation is a separate row.
 * Answering with a permanent redirect collapses the whole crawl space onto one
 * URL, which is the only thing that gets them out of the index.
 *
 * The old facet *values* are French `dcterms:type` labels ("Article de presse")
 * and the new field holds slugs (`type_s: article`), so there is no faithful
 * translation between them — the filters are dropped rather than guessed at.
 * A text query is a different matter: it is the one thing the visitor typed, so
 * it is carried across.
 *
 * Pure policy, kept out of the controller so it is testable without an Omeka
 * bootstrap (see phpunit.xml on why the controllers themselves are not).
 */
final class RetiredQuery
{
    /**
     * Parameters only the retired surface ever emitted. `facet` is the bare
     * key on purpose: PHP parses `facet[dcterms_type_ss][9]=…` into a nested
     * array under it, so the brackets never reach $_GET as part of the name.
     *
     * `page` is deliberately absent — both surfaces use it.
     */
    private const RETIRED = ['facet', 'sort_by', 'sort_order', 'resource_property'];

    /**
     * The query to redirect to, or null when this request is not a retired one
     * and should be served normally. An empty array means "the bare shell".
     *
     * @param array<string,mixed> $query
     * @return array<string,string>|null
     */
    public static function redirectFor(array $query): ?array
    {
        foreach (self::RETIRED as $key) {
            if (array_key_exists($key, $query)) {
                return self::survivors($query);
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,string>
     */
    private static function survivors(array $query): array
    {
        $text = $query['q'] ?? null;
        if (!is_scalar($text)) {
            return [];
        }
        $text = trim((string) $text);
        return $text === '' ? [] : ['q' => $text];
    }
}

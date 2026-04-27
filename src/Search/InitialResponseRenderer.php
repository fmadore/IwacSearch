<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use Closure;
use IwacSearch\Browse\FacetCatalog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Server-side pre-render for the first page of a public search surface.
 *
 * The Svelte client normally mints a scoped key (roundtrip 1) and then
 * runs a search (roundtrip 2) before it has anything to display. For
 * curated browse pages and page blocks with locked filters, that's two
 * wasted flashes of spinner for a query the server could trivially do
 * itself. This renderer makes the PHP dispatch call Typesense directly
 * with the admin key, bakes the response into the bootstrap JSON, and
 * lets the Svelte client seed its `response` state on mount.
 *
 * Trade-offs that drove the design:
 *
 *  1. **Admin key, never browser-reachable.** The public scoped key
 *     carries `filter_by:is_public:=true` and `exclude_fields:ocr_text`
 *     as hard constraints. The SSR path must impose the same constraints
 *     explicitly (see `applyPublicConstraints()`), because the admin key
 *     bypasses them.
 *
 *  2. **Lazy client construction.** Same pattern as
 *     TypesenseSearchKeyProvider — if the Docker secret is missing or
 *     Typesense is unreachable, the SSR returns null (no crash) and the
 *     page degrades to the client-side fetch flow. The Svelte client
 *     will still show a search-unavailable error if Typesense is truly
 *     down, but the page itself renders.
 *
 *  3. **Pass-through of the Typesense response shape.** The browser
 *     client already handles that exact shape (see src/svelte/lib/types.ts
 *     → IwacSearchResponse). No conversion, no middleware — we hand
 *     the client what it would have gotten from its own fetch, minus
 *     the roundtrip.
 */
final class InitialResponseRenderer
{
    private ?TypesenseClient $cachedClient = null;

    public function __construct(
        /** @var Closure(): TypesenseClient */
        private readonly Closure $clientFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $defaultCollection = 'iwac_current'
    ) {
    }

    /**
     * Run the first-page search for a given surface's bootstrap config.
     *
     * Returns:
     *   - The Typesense multi_search[0] response as an associative array
     *     (directly embeddable into bootstrap JSON, directly consumable
     *     by the Svelte App.svelte `response` state).
     *   - null if Typesense is unreachable, the admin key is missing, or
     *     the response shape is unexpected. Caller must handle null by
     *     emitting the bootstrap without `initial_response` — the client
     *     will then fall back to its own scoped-key fetch.
     *
     * @param array<string, mixed> $bootstrap
     * @return array<string, mixed>|null
     */
    public function render(array $bootstrap): ?array
    {
        $collection    = (string) ($bootstrap['collection_alias'] ?? $this->defaultCollection);
        $lockedFilters = (string) ($bootstrap['locked_filters'] ?? '');
        $perPage       = (int) ($bootstrap['results_per_page'] ?? 10);
        /** @var list<string> $facets */
        $facets        = array_values(array_filter(
            (array) ($bootstrap['prominent_facets'] ?? []),
            'is_string'
        ));
        $sort          = (string) ($bootstrap['default_sort'] ?? 'date:desc');

        // Empty-query browse mode → q=*, sort by date for a sane default
        // ordering (text_match is meaningless without a query). Mirrors
        // the Svelte client's empty-q behaviour in typesense.ts so the
        // SSR'd first page matches what the client would render.
        $q = '*';
        if ($sort === '_text_match:desc' || $sort === '') {
            $sort = 'date:desc';
        }

        $filterBy = $this->applyPublicConstraints($lockedFilters);

        $body = [
            'searches' => [[
                'collection'            => $collection,
                'q'                     => $q,
                'query_by'              => 'title_txt,ocr_text,entity_aliases_txt,embedding',
                'stopwords'             => 'fr_default',
                'filter_by'             => $filterBy,
                'sort_by'               => $sort,
                'page'                  => 1,
                'per_page'              => max(1, min(50, $perPage)),
                // Drop OCR from the payload — same hard rule the scoped
                // key enforces for the live client. Keeps the inlined
                // JSON lean and avoids leaking OCR fulltext into the HTML.
                'exclude_fields'        => 'ocr_text,embedding',
                'highlight_fields'      => 'title_txt',
                'highlight_full_fields' => 'title_txt',
                'snippet_threshold'     => 30,
                'facet_by'              => $facets === [] ? null : implode(',', $this->dedupeFacets($facets)),
                'max_facet_values'      => 50,
                'limit_hits'            => 250,
            ]],
        ];
        // Remove null keys that Typesense rejects.
        $body['searches'][0] = array_filter(
            $body['searches'][0],
            static fn ($v): bool => $v !== null
        );

        try {
            $response = $this->client()->multiSearch->perform($body);
        } catch (Throwable $e) {
            // Recover from a missing stopword set on the Typesense
            // server: drop the `stopwords` field and retry once. Stopwords
            // are an enhancement (filter "le", "la", "des" out of matches)
            // — never a correctness requirement — so degrading gracefully
            // beats a blank SSR page that falls through to a client-side
            // fetch which would hit the same error. Operator should
            // provision the set via `discovery:reindex` or
            // `cli/stopwords-sync.php`.
            if (stripos($e->getMessage(), 'stopword set') !== false) {
                $this->logger->warning(
                    'IwacSearch SSR: Typesense stopword set missing; retrying without stopwords. Run discovery:reindex (or cli/stopwords-sync.php) to provision.',
                    ['error' => $e->getMessage()]
                );
                unset($body['searches'][0]['stopwords']);
                try {
                    $response = $this->client()->multiSearch->perform($body);
                } catch (Throwable $retryError) {
                    $this->logger->warning('IwacSearch SSR: Typesense multi_search still failing after stopwords drop', [
                        'error'      => $retryError->getMessage(),
                        'collection' => $collection,
                    ]);
                    return null;
                }
            } else {
                // Never fail the page render because Typesense is flaky.
                // The Svelte client will still try on its own and surface
                // a concrete error via /discovery/token if the problem
                // persists.
                $this->logger->warning('IwacSearch SSR: Typesense multi_search failed, falling back to client-side fetch', [
                    'error'      => $e->getMessage(),
                    'collection' => $collection,
                ]);
                return null;
            }
        }

        $first = $response['results'][0] ?? null;
        if (!is_array($first)) {
            $this->logger->warning('IwacSearch SSR: unexpected Typesense response shape', [
                'keys' => is_array($response) ? array_keys($response) : 'non-array',
            ]);
            return null;
        }

        // Typesense sometimes returns per-search errors inside the
        // results array (HTTP 200 but `error` set). Treat those as
        // soft-fallback: let the client retry, don't SSR the error.
        if (isset($first['error'])) {
            $this->logger->warning('IwacSearch SSR: Typesense returned per-search error', [
                'error' => (string) $first['error'],
            ]);
            return null;
        }

        // Belt-and-suspenders: any well-formed success response has a
        // `hits` array. If it's missing (unknown future error shape, or
        // a typesense-php upgrade that changes the contract), fall back
        // to the client-side fetch path rather than emitting a half-baked
        // initial_response that crashes the Svelte client mid-render
        // with `Cannot read properties of undefined (reading 'length')`.
        if (!isset($first['hits']) || !is_array($first['hits'])) {
            $this->logger->warning('IwacSearch SSR: Typesense response missing hits[]', [
                'keys' => array_keys($first),
            ]);
            return null;
        }

        return $first;
    }

    /**
     * Compose the full filter_by for the server-side search:
     *
     *   - `is_public:=true` is ALWAYS prepended (the admin key would
     *     otherwise return non-public docs)
     *   - The surface's `locked_filters` come next (already validated
     *     by the admin CRUD or seeded by CountrySeeder)
     *
     * The two are joined with `&&`. If locked_filters is empty we still
     * emit the public guard alone.
     */
    private function applyPublicConstraints(string $lockedFilters): string
    {
        $parts = ['is_public:=true'];
        $trimmed = trim($lockedFilters);
        if ($trimmed !== '') {
            $parts[] = $trimmed;
        }
        return implode(' && ', $parts);
    }

    /**
     * Drop facet fields that aren't in the catalog — a safety net
     * against stale bootstrap configs that still reference a field
     * removed from schema.yaml. Typesense would 400 the whole request
     * for one bad name; we'd rather render with fewer facets than not
     * render at all.
     *
     * @param list<string> $fields
     * @return list<string>
     */
    private function dedupeFacets(array $fields): array
    {
        $seen = [];
        $out = [];
        foreach ($fields as $field) {
            if (isset($seen[$field])) {
                continue;
            }
            if (!isset(FacetCatalog::FACETABLE_FIELDS[$field])) {
                continue;
            }
            $seen[$field] = true;
            $out[] = $field;
        }
        return $out;
    }

    private function client(): TypesenseClient
    {
        return $this->cachedClient ??= ($this->clientFactory)();
    }
}

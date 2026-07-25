<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use Closure;
use IwacSearch\Browse\FacetCatalog;
use IwacSearch\Indexer\StopwordsSync;
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
    public function __construct(
        // Lazily-resolved, memoizing client factory (TypesenseClientLazy) —
        // a missing secret / down Typesense surfaces inside render()'s
        // catch, so the page still renders (without an initial response).
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
        // Surface-specific query_by — the entity collection lacks
        // ocr_text/abstract/embedding, so it passes its own. Even in browse
        // mode (q=*) Typesense validates query_by field names, so this must
        // match the target collection's schema.
        $queryBy       = (string) ($bootstrap['query_by'] ?? SearchDefaults::CONTENT_QUERY_BY);

        // Empty-query browse mode → q=*, sort by date for a sane default
        // ordering (text_match is meaningless without a query). Mirrors
        // the Svelte client's empty-q behaviour in typesense.ts so the
        // SSR'd first page matches what the client would render.
        $q = '*';
        if ($sort === '_text_match:desc' || $sort === '') {
            $sort = 'date:desc';
        }
        // creator_sort is an optional scalar field; Typesense errors on an
        // optional sort field without an explicit missing-values rule. Push
        // author-less docs last, mirroring the client (typesense.ts) so an
        // author-sorted custom block can SSR instead of falling back.
        if (str_starts_with($sort, 'creator_sort:')) {
            $sort = str_replace('creator_sort:', 'creator_sort(missing_values:last):', $sort);
        }

        $filterBy = $this->applyPublicConstraints($lockedFilters);

        $body = [
            'searches' => [[
                'collection'            => $collection,
                'q'                     => $q,
                'query_by'              => $queryBy,
                // Same set the bulk reindex PUTs and the scoped key names —
                // one constant, so a rename can't leave the SSR path pointing
                // at a set that no longer exists.
                'stopwords'             => StopwordsSync::SET_NAME,
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
                // normaliseFacets drops anything not in the catalog — a safety
                // net against a stale bootstrap still naming a field removed
                // from schema.yaml, which Typesense would 400 the whole
                // request over. Rendering with fewer facets beats not
                // rendering. Same normaliser the block form applies on save.
                'facet_by'              => $facets === []
                    ? null
                    : (implode(',', FacetCatalog::normaliseFacets($facets)) ?: null),
                'max_facet_values'      => 50,
                // No limit_hits — the live client pages through all matches
                // (Typesense default is uncapped); the SSR only renders page 1,
                // so this just keeps the two request shapes consistent.
            ]],
        ];
        // Remove null keys that Typesense rejects.
        $body['searches'][0] = array_filter(
            $body['searches'][0],
            static fn ($v): bool => $v !== null
        );

        return $this->performFirstSearch($body, $collection);
    }

    /**
     * Run the multi_search and validate results[0], retrying ONCE without
     * the `stopwords` param when the server reports the stopword set
     * missing. Typesense surfaces that error two ways — an HTTP-level
     * throw, or HTTP 200 with the error embedded in results[0].error — so
     * both paths funnel through the same retry. Stopwords are an
     * enhancement (filter "le", "la", "des" out of matches), never a
     * correctness requirement, so degrading gracefully beats a blank SSR
     * page. Operator should provision the set via `discovery:reindex` or
     * `cli/stopwords-sync.php`.
     *
     * @param array{searches: array<int, array<string, mixed>>} $body
     * @return array<string, mixed>|null results[0] on success, null = let
     *   the client fall back to its own scoped-key fetch.
     */
    private function performFirstSearch(array $body, string $collection): ?array
    {
        // Two attempts max: the retry drops `stopwords`, so a stopword
        // error cannot recur on the second pass.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $canRetry = $attempt === 0 && isset($body['searches'][0]['stopwords']);

            try {
                $response = ($this->clientFactory)()->multiSearch->perform($body);
            } catch (Throwable $e) {
                if ($canRetry && $this->isStopwordError($e->getMessage())) {
                    $this->logStopwordRetry($e->getMessage());
                    unset($body['searches'][0]['stopwords']);
                    continue;
                }
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

            $first = $response['results'][0] ?? null;
            if (!is_array($first)) {
                $this->logger->warning('IwacSearch SSR: unexpected Typesense response shape', [
                    'keys' => is_array($response) ? array_keys($response) : 'non-array',
                ]);
                return null;
            }

            // Per-search errors ride inside the results array (HTTP 200
            // but `error` set). A stopword error here gets the same
            // one-shot retry; anything else is a soft-fallback — let the
            // client retry, don't SSR the error.
            $error = $first['error'] ?? null;
            if (is_string($error) && $canRetry && $this->isStopwordError($error)) {
                $this->logStopwordRetry($error);
                unset($body['searches'][0]['stopwords']);
                continue;
            }
            if ($error !== null) {
                $this->logger->warning('IwacSearch SSR: Typesense returned per-search error', [
                    'error' => (string) $error,
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
        return null;
    }

    private function isStopwordError(string $message): bool
    {
        return stripos($message, 'stopword set') !== false;
    }

    private function logStopwordRetry(string $error): void
    {
        $this->logger->warning(
            'IwacSearch SSR: Typesense stopword set missing; retrying without stopwords. Run discovery:reindex (or cli/stopwords-sync.php) to provision.',
            ['error' => $error]
        );
    }

    /**
     * Compose the full filter_by for the server-side search:
     *
     *   - `is_public:=true` is ALWAYS prepended (the admin key would
     *     otherwise return non-public docs)
     *   - The surface's `locked_filters` come next (a PresetCatalog scope
     *     or a custom page-block filter)
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

}

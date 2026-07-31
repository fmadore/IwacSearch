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
 *     carries `filter_by:is_public:=true` and
 *     `exclude_fields:ocr_text,toc_txt`
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
        private readonly string $defaultCollection = 'iwac_current',
        // Short-lived, shared across visitors — safe because every snapshot
        // is public-only by construction. See SnapshotCache.
        private readonly ?SnapshotCacheInterface $cache = null,
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
        $results = $this->renderMany([$bootstrap]);
        return $results[0];
    }

    /**
     * SSR several surfaces in ONE Typesense round trip.
     *
     * The federated page needs both tabs pre-rendered, and used to call
     * render() twice — two sequential HTTP round trips inside a single PHP
     * dispatch, where multi_search was built to take a list. Returns one
     * entry per input bootstrap, in order, each either the response array or
     * null (that surface falls back to the client-side fetch).
     *
     * A per-search error nulls only ITS OWN entry: one tab failing to
     * pre-render must not cost the other tab its snapshot.
     *
     * @param  list<array<string, mixed>> $bootstraps
     * @return list<array<string, mixed>|null>
     */
    public function renderMany(array $bootstraps): array
    {
        if ($bootstraps === []) {
            return [];
        }

        $searches = array_map(fn(array $b): array => $this->buildSearch($b), $bootstraps);
        $collections = array_map(
            fn(array $b): string => (string) ($b['collection_alias'] ?? $this->defaultCollection),
            $bootstraps
        );
        $body = ['searches' => array_values($searches)];

        // Every anonymous visitor of a landing page produces the identical
        // request, so the burst collapses to one Typesense round trip per TTL.
        $cacheKey = $this->cache?->key($body);
        if ($cacheKey !== null) {
            $hit = $this->cache?->get($cacheKey);
            if ($hit !== null) {
                return $hit;
            }
        }

        $results = $this->performSearches($body, $collections);

        // Only cache a fully successful render: storing a null would pin a
        // transient Typesense blip in front of every visitor for the whole TTL.
        if ($cacheKey !== null && !in_array(null, $results, true)) {
            $this->cache?->set($cacheKey, $results);
        }

        return $results;
    }

    /**
     * One surface's multi_search sub-search body.
     *
     * @param  array<string, mixed> $bootstrap
     * @return array<string, mixed>
     */
    private function buildSearch(array $bootstrap): array
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

        $search = [
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
                // Drop full body fields from the payload — same hard rule
                // the scoped key enforces for the live client. Highlights
                // still ship, but the inlined JSON stays lean.
                'exclude_fields'        => 'ocr_text,toc_txt,embedding',
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
        ];

        // Remove null keys that Typesense rejects.
        return array_filter($search, static fn($v): bool => $v !== null);
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
    /**
     * Run the multi_search and validate each result independently, retrying
     * ONCE without `stopwords` when the server reports the set missing.
     *
     * Typesense surfaces that two ways — an HTTP-level throw, or HTTP 200
     * with the error embedded in a result — so both funnel through the same
     * retry. Stopwords are an enhancement (filtering "le", "la", "des" out of
     * matches), never a correctness requirement, so degrading gracefully
     * beats a blank SSR. The operator should provision the set via
     * `discovery:reindex` or `cli/stopwords-sync.php`.
     *
     * A transport failure nulls EVERY entry (nothing came back); a per-search
     * problem nulls only its own, so one bad surface can't cost a sibling its
     * snapshot.
     *
     * @param  array{searches: list<array<string, mixed>>} $body
     * @param  list<string> $collections  One collection name per sub-search, for logging.
     * @return list<array<string, mixed>|null>
     */
    private function performSearches(array $body, array $collections): array
    {
        $count = count($body['searches']);
        $none  = array_fill(0, $count, null);

        // Two attempts max: the retry drops `stopwords` from every
        // sub-search, so a stopword error cannot recur on the second pass.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $canRetry = $attempt === 0 && $this->usesStopwords($body);

            try {
                $response = ($this->clientFactory)()->multiSearch->perform($body);
            } catch (Throwable $e) {
                if ($canRetry && $this->isStopwordError($e->getMessage())) {
                    $this->logStopwordRetry($e->getMessage());
                    $body = $this->withoutStopwords($body);
                    continue;
                }
                // Never fail the page render because Typesense is flaky. The
                // Svelte client will still try on its own and surface a
                // concrete error via /discovery/token if it persists.
                $this->logger->warning(
                    'IwacSearch SSR: Typesense multi_search failed, falling back to client-side fetch',
                    ['error' => $e->getMessage(), 'collections' => $collections]
                );
                return $none;
            }

            $results = is_array($response['results'] ?? null) ? $response['results'] : null;
            if ($results === null) {
                $this->logger->warning('IwacSearch SSR: unexpected Typesense response shape', [
                    'keys' => is_array($response) ? array_keys($response) : 'non-array',
                ]);
                return $none;
            }

            // A stopword error in ANY sub-search retries the whole body —
            // they all carry the same set, so they would all fail the same way.
            if ($canRetry) {
                foreach ($results as $result) {
                    $error = is_array($result) ? ($result['error'] ?? null) : null;
                    if (is_string($error) && $this->isStopwordError($error)) {
                        $this->logStopwordRetry($error);
                        $body = $this->withoutStopwords($body);
                        continue 2;
                    }
                }
            }

            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = $this->validateResult($results[$i] ?? null, $collections[$i] ?? '');
            }
            return $out;
        }
        return $none;
    }

    /**
     * One sub-search result, or null if it can't be handed to the client.
     *
     * @param  mixed $result
     * @return array<string, mixed>|null
     */
    private function validateResult(mixed $result, string $collection): ?array
    {
        if (!is_array($result)) {
            $this->logger->warning('IwacSearch SSR: missing result for a sub-search', [
                'collection' => $collection,
            ]);
            return null;
        }

        // Per-search errors ride inside the results array (HTTP 200 but
        // `error` set). Anything not stopword-related is a soft fallback —
        // let the client retry, don't SSR the error.
        $error = $result['error'] ?? null;
        if ($error !== null) {
            $this->logger->warning('IwacSearch SSR: Typesense returned per-search error', [
                'error'      => (string) $error,
                'collection' => $collection,
            ]);
            return null;
        }

        // Belt-and-suspenders: any well-formed success response has a `hits`
        // array. If it's missing (unknown future error shape, or a
        // typesense-php upgrade that changes the contract), fall back to the
        // client-side fetch rather than emitting a half-baked
        // initial_response that crashes the Svelte client mid-render with
        // `Cannot read properties of undefined (reading 'length')`.
        if (!isset($result['hits']) || !is_array($result['hits'])) {
            $this->logger->warning('IwacSearch SSR: Typesense response missing hits[]', [
                'keys'       => array_keys($result),
                'collection' => $collection,
            ]);
            return null;
        }

        return $result;
    }

    /**
     * @param  array{searches: list<array<string, mixed>>} $body
     */
    private function usesStopwords(array $body): bool
    {
        foreach ($body['searches'] as $search) {
            if (isset($search['stopwords'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param  array{searches: list<array<string, mixed>>} $body
     * @return array{searches: list<array<string, mixed>>}
     */
    private function withoutStopwords(array $body): array
    {
        foreach ($body['searches'] as $i => $search) {
            unset($search['stopwords']);
            $body['searches'][$i] = $search;
        }
        return $body;
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

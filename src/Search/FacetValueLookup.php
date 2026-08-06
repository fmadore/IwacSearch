<?php
declare(strict_types=1);

namespace IwacSearch\Search;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Reads the live facet values of the content collection, so the page-block
 * admin form can offer real check-boxes instead of asking an editor to
 * hand-type Typesense syntax.
 *
 * Only the OPEN vocabularies need this — newspaper titles, languages, and the
 * categorical sentiment labels are data, not enums this codebase declares, and
 * they grow with the corpus. The closed enums (type_s, the countries, the 1–5
 * subjectivity scale) come from {@see ScopeFilters::staticOptions()} and are
 * available even with no index at all.
 *
 * Three properties this has to hold, all for the same reason — the block form
 * renders inside Omeka's page-edit screen, which must never 500 because the
 * search index is having a bad day:
 *
 *  1. **Degrades to null, never throws.** A missing Docker secret or an
 *     unreachable Typesense returns null; the form then renders the static
 *     pickers plus a notice, and the raw `locked_filters` escape hatch is
 *     still there. Same lazy-client contract as
 *     {@see InitialResponseRenderer} and TypesenseSearchKeyProvider.
 *  2. **One round trip per request.** Omeka calls BlockLayout::form() once per
 *     block on the page plus once for the "add block" template, so the result
 *     is memoized on the instance (including the null).
 *  3. **Public-only counts.** The admin key sees everything; the block it is
 *     configuring will not. Filtering on `is_public:=true` keeps the picker
 *     honest — no newspaper offered that would yield an empty public block.
 */
final class FacetValueLookup
{
    /**
     * Typesense caps `max_facet_values` server-side (250 by default). Asking
     * for the cap and reporting when we hit it beats silently offering a
     * truncated list that reads as complete.
     */
    public const MAX_VALUES = 250;

    /** @var array<string, list<array{value: string, count: int}>>|null */
    private ?array $memo = null;

    private bool $attempted = false;

    public function __construct(
        /** @var ?Closure(): TypesenseClient Lazy, memoizing — see TypesenseClientLazy. */
        private readonly ?Closure $clientFactory = null,
        private readonly string $contentAlias = 'iwac_current',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Facet values + document counts for the given fields, ordered by count
     * descending (Typesense's own order — the values an editor is most likely
     * to want to curate are the ones with the most material behind them).
     *
     * Returns null when the index can't be reached, which the caller must
     * treat as "unknown", NOT as "no values" — the difference decides whether
     * the form shows an empty list or an explanation.
     *
     * @param  list<string> $fields
     * @return array<string, list<array{value: string, count: int}>>|null
     */
    public function counts(array $fields): ?array
    {
        if ($this->attempted) {
            return $this->memo;
        }
        $this->attempted = true;

        if ($this->clientFactory === null || $fields === []) {
            return null;
        }

        try {
            /** @var mixed $response */
            $response = ($this->clientFactory)()
                ->collections[$this->contentAlias]
                ->documents
                ->search([
                    'q'                => '*',
                    // q=* matches everything, so query_by never affects the
                    // result — but Typesense still validates the field names
                    // against the collection, so this passes the same
                    // drift-guarded constant the SSR browse path does rather
                    // than naming a field that a schema bump could retire.
                    'query_by'         => SearchDefaults::CONTENT_QUERY_BY,
                    'filter_by'        => 'is_public:=true',
                    // Facet-only query: we want facet_counts, not hits.
                    'per_page'         => 1,
                    'include_fields'   => 'id',
                    'facet_by'         => implode(',', $fields),
                    'max_facet_values' => self::MAX_VALUES,
                ]);
        } catch (Throwable $e) {
            // The page-edit screen still has to render. The editor loses the
            // live pickers, not the form.
            $this->logger->warning(
                'IwacSearch block form: could not read facet values from Typesense; falling back to static pickers',
                ['error' => $e->getMessage(), 'collection' => $this->contentAlias]
            );
            return null;
        }

        return $this->memo = $this->parse($response);
    }

    /**
     * Whether a field's option list was truncated by {@see MAX_VALUES}.
     * The form says so rather than pretending the list is exhaustive.
     *
     * @param array<string, list<array{value: string, count: int}>>|null $counts
     */
    public static function isTruncated(?array $counts, string $field): bool
    {
        return isset($counts[$field]) && count($counts[$field]) >= self::MAX_VALUES;
    }

    /**
     * Pull `facet_counts` out of a Typesense search response.
     *
     * Shape: `facet_counts: [{field_name, counts: [{value, count}, …]}, …]`.
     * Anything that doesn't match is skipped rather than trusted — a
     * typesense-php upgrade that changes the contract should cost the pickers
     * their live values, not raise a TypeError in the admin.
     *
     * @return array<string, list<array{value: string, count: int}>>|null
     */
    private function parse(mixed $response): ?array
    {
        $facetCounts = is_array($response) ? ($response['facet_counts'] ?? null) : null;
        if (!is_array($facetCounts)) {
            $this->logger->warning('IwacSearch block form: unexpected Typesense response shape', [
                'keys' => is_array($response) ? array_keys($response) : 'non-array',
            ]);
            return null;
        }

        $out = [];
        foreach ($facetCounts as $facet) {
            if (!is_array($facet)) {
                continue;
            }
            $field = $facet['field_name'] ?? null;
            $counts = $facet['counts'] ?? null;
            if (!is_string($field) || !is_array($counts)) {
                continue;
            }

            $values = [];
            foreach ($counts as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $value = $entry['value'] ?? null;
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $values[] = ['value' => $value, 'count' => (int) ($entry['count'] ?? 0)];
            }
            $out[$field] = $values;
        }

        return $out;
    }
}

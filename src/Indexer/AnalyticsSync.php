<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent provisioner for Typesense search analytics: two rules that
 * aggregate what visitors actually type into the public search —
 *
 *   iwac_popular_queries — queries that returned results (popularity count)
 *   iwac_nohits_queries  — queries that returned NOTHING. The curator
 *                          goldmine: these reveal transliteration variants
 *                          and topics users expect that the index misses,
 *                          feeding data/synonyms-fr.json and cataloguing.
 *
 * Each rule aggregates into its own destination collection (created here
 * with the mandatory q/count schema). Destination collections are
 * PERSISTENT — never versioned, never dropped by the reindex — so history
 * accumulates across schema bumps. The maintenance page reads them back
 * with a plain search (sort_by count:desc).
 *
 * NON-FATAL BY DESIGN: analytics requires server-side flags
 * (--enable-search-analytics=true --analytics-dir=… — an IWAC-docker
 * change, see ROADMAP.md). Until those land, rule creation fails; sync()
 * catches everything, logs a warning, and reports enabled:false so a bulk
 * reindex NEVER fails over an optional observability feature.
 *
 * The rules bind to the ALIAS name (iwac_current): every search path in
 * this module addresses the alias, and alias-bound rules survive the
 * reindex alias swaps without re-pointing. (Verify capture on the live
 * container once the server flags land — noted in ROADMAP.md.)
 */
final class AnalyticsSync
{
    public const POPULAR_RULE = 'iwac_popular_queries';
    public const NOHITS_RULE  = 'iwac_nohits_queries';

    /** Destination collections share their rule's name — one concept, one label. */
    public const POPULAR_COLLECTION = 'iwac_popular_queries';
    public const NOHITS_COLLECTION  = 'iwac_nohits_queries';

    /** Max aggregated queries Typesense keeps per rule. */
    private const RULE_LIMIT = 1000;

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $sourceCollection = 'iwac_current'
    ) {
    }

    /**
     * @return array{enabled: bool, rules: list<string>, error: ?string}
     */
    public function sync(): array
    {
        try {
            $this->ensureDestination(self::POPULAR_COLLECTION);
            $this->ensureDestination(self::NOHITS_COLLECTION);

            $this->upsertRule([
                'name'       => self::POPULAR_RULE,
                'type'       => 'popular_queries',
                'collection' => $this->sourceCollection,
                'event_type' => 'search',
                'params'     => [
                    'destination_collection'  => self::POPULAR_COLLECTION,
                    'limit'                   => self::RULE_LIMIT,
                    // Aggregate live search traffic automatically (no
                    // client-side event calls needed). Stores what users
                    // actually typed — typos included, which is the point.
                    'capture_search_requests' => true,
                    'expand_query'            => false,
                ],
            ]);
            $this->upsertRule([
                'name'       => self::NOHITS_RULE,
                'type'       => 'nohits_queries',
                'collection' => $this->sourceCollection,
                'event_type' => 'search',
                'params'     => [
                    'destination_collection'  => self::NOHITS_COLLECTION,
                    'limit'                   => self::RULE_LIMIT,
                    'capture_search_requests' => true,
                ],
            ]);

            $this->logger->info('Analytics rules provisioned', [
                'rules'  => [self::POPULAR_RULE, self::NOHITS_RULE],
                'source' => $this->sourceCollection,
            ]);
            return [
                'enabled' => true,
                'rules'   => [self::POPULAR_RULE, self::NOHITS_RULE],
                'error'   => null,
            ];
        } catch (Throwable $e) {
            // Optional feature: never fail the caller. The usual cause is
            // the server running without --enable-search-analytics.
            $this->logger->warning(
                'Analytics provisioning skipped — enable search analytics on the Typesense '
                . 'server (--enable-search-analytics=true --analytics-dir=…) and re-run. Cause: '
                . $e->getMessage()
            );
            return ['enabled' => false, 'rules' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a destination collection when absent. `q` + `count` are the
     * mandatory analytics fields. NEVER dropped or rebuilt here — these
     * accumulate history across reindexes.
     */
    private function ensureDestination(string $name): void
    {
        try {
            $this->typesense->collections[$name]->retrieve();
            return; // exists — keep the accumulated data
        } catch (Throwable) {
            // fall through to create
        }
        $this->typesense->collections->create([
            'name'   => $name,
            'fields' => [
                ['name' => 'q',     'type' => 'string'],
                ['name' => 'count', 'type' => 'int32'],
            ],
        ]);
        $this->logger->info('Created analytics destination collection', ['name' => $name]);
    }

    /**
     * PUT /analytics/rules/{name} — create-or-replace semantics, matching
     * the sync-* siblings (stopwords/curation/synonyms).
     *
     * @param array<string, mixed> $rule
     */
    private function upsertRule(array $rule): void
    {
        // @phpstan-ignore-next-line  analytics is the v6 client accessor
        $this->typesense->analytics->rules()[$rule['name']]->update($rule);
    }
}

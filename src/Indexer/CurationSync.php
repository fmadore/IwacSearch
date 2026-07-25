<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent uploader for the `iwac_diversity` global curation set.
 *
 * Typesense 30.2 introduced result diversification via Maximum Marginal
 * Relevance (MMR), but — unlike a plain search parameter — it is driven by
 * a curation set, not passed inline. The recipe is:
 *
 *   1. Create a GLOBAL curation set (this class) holding ONE tag-only rule
 *      that carries a `diversity.similarity_metric`.
 *   2. LINK it to the collection via `curation_sets: [iwac_diversity]` in
 *      data/schema.yaml (flows straight through SchemaLoader → create).
 *   3. ACTIVATE it per-search by passing `curation_tags: diversify` (the
 *      Svelte client does this on text queries only — see typesense.ts).
 *
 * The similarity metric is `vector_distance` on the `embedding` field: two
 * documents are "similar" when their semantic vectors are close. For a
 * press archive this is exactly the right axis — it pushes down the
 * near-identical syndicated copies of the same wire story that would
 * otherwise crowd the first page, while leaving genuinely distinct results
 * untouched. `diversity_lambda` (sent at search time) tunes the
 * relevance↔diversity balance; the metric here defines what "similar" means.
 *
 * Idempotent: PUT /curation_sets/{name} replaces the set. Safe to call on
 * every reindex; cheap enough to do so. Created BEFORE the collection in
 * Reindexer::run() so the schema's `curation_sets` link resolves.
 *
 * Requires typesense/typesense-php ^6 (the `curationSets` resource is the
 * v30 client API; v5 had no such accessor).
 */
final class CurationSync
{
    /** Global curation set name. Keep in sync with data/schema.yaml `curation_sets`. */
    public const SET_NAME = 'iwac_diversity';

    /** Tag a search passes via `curation_tags` to activate diversification. */
    public const TAG = 'diversify';

    /** The float[] vector field MMR measures document-to-document similarity on. */
    private const SIMILARITY_FIELD = 'embedding';

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Upsert the diversification curation set.
     *
     * @return array{set: string, tag: string, metric: string}
     */
    public function sync(): array
    {
        $this->logger->info('Syncing diversification curation set', [
            'set'    => self::SET_NAME,
            'tag'    => self::TAG,
            'metric' => self::SIMILARITY_FIELD . ':vector_distance',
        ]);

        // A tag-only rule: no `query`/`match`, so it never curates by
        // itself — it only fires when a search opts in with
        // `curation_tags: diversify`. The `diversity` block is the whole
        // point; there are no includes/excludes.
        $payload = [
            'items' => [
                [
                    'id'   => 'diversify-content',
                    'rule' => [
                        'tags' => [self::TAG],
                    ],
                    'diversity' => [
                        'similarity_metric' => [
                            [
                                'field'  => self::SIMILARITY_FIELD,
                                'method' => 'vector_distance',
                                'weight' => 1.0,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $this->typesense->curationSets->upsert(self::SET_NAME, $payload);
        } catch (Throwable $e) {
            // Surface the root cause: this almost always means the server
            // predates v30 (no /curation_sets endpoint) or the client is
            // still on typesense-php v5. Either way the operator needs to
            // see the real error, not a generic wrapper.
            throw new RuntimeException(
                'Failed to upsert the iwac_diversity curation set. '
                . 'Requires Typesense >= 30 and typesense/typesense-php ^6. Cause: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        return [
            'set'    => self::SET_NAME,
            'tag'    => self::TAG,
            'metric' => self::SIMILARITY_FIELD . ':vector_distance',
        ];
    }
}

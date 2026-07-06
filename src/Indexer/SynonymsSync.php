<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Idempotent uploader for the `iwac_synonyms` global synonym set.
 *
 * Typesense 30 made synonyms top-level resources (synonym SETS) shared
 * between collections. The recipe mirrors CurationSync:
 *
 *   1. Create/refresh the GLOBAL set from data/synonyms-fr.json (this class).
 *   2. LINK it to the content collection via `synonym_sets: [iwac_synonyms]`
 *      in data/schema.yaml (flows through SchemaLoader → collections.create).
 *   3. Expansion then happens automatically on every search — no client
 *      parameter needed.
 *
 * What lives in the set: transliteration variants of Arabic loanwords the
 * West African francophone press spells a dozen ways (cheikh/sheikh/shaykh,
 * Tidjaniyya/Tijaniyya, El Hadj/Alhaji…). Entity-name variants (RCI → Radio
 * Côte d'Ivoire) are NOT synonyms — they ride on entity_aliases_txt.
 *
 * Idempotent: PUT /synonym_sets/{name} replaces the whole set. Safe (and
 * cheap) to call on every reindex; also invocable standalone via
 * cli/synonyms-sync.php or the admin "Sync synonyms" button, because a
 * synonym edit must not require rebuilding 14K+ docs.
 *
 * Requires typesense/typesense-php ^6 (the `synonymSets` resource is the
 * v30 client API) and Typesense >= 30.
 */
final class SynonymsSync
{
    /** Global synonym set name. Keep in sync with data/schema.yaml `synonym_sets`. */
    public const SET_NAME = 'iwac_synonyms';

    public function __construct(
        private readonly TypesenseClient $typesense,
        private readonly string $synonymsJsonPath,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * Upsert the synonym set from the JSON file.
     *
     * @return array{set: string, groups: int}
     */
    public function sync(): array
    {
        if (!is_readable($this->synonymsJsonPath)) {
            throw new RuntimeException("Synonyms file not readable: {$this->synonymsJsonPath}");
        }

        $payload = json_decode((string) file_get_contents($this->synonymsJsonPath), true);
        if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
            throw new RuntimeException(
                "Synonyms file malformed (missing 'items' array): {$this->synonymsJsonPath}"
            );
        }

        $items = [];
        foreach ($payload['items'] as $i => $item) {
            if (
                !is_array($item)
                || !is_string($item['id'] ?? null)
                || !is_array($item['synonyms'] ?? null)
                || $item['synonyms'] === []
            ) {
                throw new RuntimeException(
                    "Synonyms file item #{$i} malformed (needs 'id' string + non-empty 'synonyms' array): "
                    . $this->synonymsJsonPath
                );
            }
            $entry = [
                'id'       => $item['id'],
                'synonyms' => array_values(array_map('strval', $item['synonyms'])),
            ];
            // Optional one-way root ("root" maps synonyms → root only).
            if (is_string($item['root'] ?? null) && $item['root'] !== '') {
                $entry['root'] = $item['root'];
            }
            $items[] = $entry;
        }

        $this->logger->info('Syncing synonym set', [
            'set'    => self::SET_NAME,
            'groups' => count($items),
        ]);

        try {
            // @phpstan-ignore-next-line  synonymSets is the v6 client accessor
            $this->typesense->synonymSets->upsert(self::SET_NAME, ['items' => $items]);
        } catch (Throwable $e) {
            // Almost always means the server predates v30 (no /synonym_sets
            // endpoint) or the client is still on typesense-php v5.
            throw new RuntimeException(
                'Failed to upsert the iwac_synonyms synonym set. '
                . 'Requires Typesense >= 30 and typesense/typesense-php ^6. Cause: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        return [
            'set'    => self::SET_NAME,
            'groups' => count($items),
        ];
    }
}

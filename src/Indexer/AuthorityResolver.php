<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Pre-resolves the controlled-vocabulary join between content items and
 * authority records.
 *
 * The IWAC HF dataset has an `index` subset (4,697 rows) that acts as the
 * controlled vocabulary for the `subject` and `spatial` fields on articles
 * / publications / references. Each entity has:
 *   - Titre        canonical name (the join key)
 *   - Titre alternatif  pipe-separated aliases
 *   - Type          Personnes | Organisations | Lieux | Événements | Sujets | Notices d'autorité
 *   - o:id          stable Omeka ID for outbound links
 *
 * Doing this join per query inside Typesense would mean N lookups per
 * search. Doing it once at index time means facets are a single round trip.
 *
 * Expected upstream: HfDatasetLoader::stream('index') iterable.
 */
final class AuthorityResolver
{
    /**
     * Maps Type → bucket name in our Typesense schema. Anything not in this
     * map is dropped (currently: "Notices d'autorité").
     */
    private const TYPE_TO_BUCKET = [
        'Personnes'    => 'persons_ss',
        'Organisations'=> 'organisations_ss',
        'Lieux'        => 'places_ss',
        'Événements'   => 'events_ss',
        'Sujets'       => 'topics_ss',
    ];

    /** @var array<string, array{type: string, bucket: string, o_id: int, aliases: list<string>}> */
    private array $byTitre = [];

    /** @var array<string, string> alias → canonical Titre */
    private array $aliasToTitre = [];

    private bool $built = false;

    /**
     * Build the lookup table from an iterable of HF `index` rows.
     *
     * Idempotent: clears prior state before populating, so repeated build()
     * calls (e.g. across multiple reindex runs sharing one resolver
     * instance via dependency injection) don't accumulate.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function build(iterable $rows): self
    {
        $this->byTitre      = [];
        $this->aliasToTitre = [];

        foreach ($rows as $row) {
            $titre = trim((string) ($row['Titre'] ?? ''));
            $type  = trim((string) ($row['Type']  ?? ''));
            $oid   = (int)         ($row['o:id']  ?? 0);

            if ($titre === '' || !isset(self::TYPE_TO_BUCKET[$type]) || $oid === 0) {
                continue;
            }

            // Parse aliases first so we can store them with the entry.
            $aliases = [];
            $aliasField = (string) ($row['Titre alternatif'] ?? '');
            if ($aliasField !== '') {
                foreach (explode('|', $aliasField) as $alias) {
                    $alias = trim($alias);
                    if ($alias !== '' && $alias !== $titre) {
                        $aliases[] = $alias;
                    }
                }
            }
            $aliases = array_values(array_unique($aliases));

            $bucket = self::TYPE_TO_BUCKET[$type];
            $this->byTitre[$titre] = [
                'type'    => $type,
                'bucket'  => $bucket,
                'o_id'    => $oid,
                'aliases' => $aliases,
            ];

            // Reverse-index aliases for input matching. Don't shadow a
            // canonical title that happens to also be someone's alias.
            foreach ($aliases as $alias) {
                if (!isset($this->byTitre[$alias]) && !isset($this->aliasToTitre[$alias])) {
                    $this->aliasToTitre[$alias] = $titre;
                }
            }
        }

        $this->built = true;
        return $this;
    }

    /**
     * Resolve a pipe-separated `subject` or `spatial` string to per-bucket
     * arrays of names + a flat list of entity IDs + a flat list of every
     * known alternative spelling.
     *
     * Returns an array keyed by Typesense field name, suitable for
     * splat-merging into the document being built.
     *
     * The `entity_aliases_txt` bucket exists so end-users can search by
     * alternative spellings: typing "RCI" finds docs about "Radio Côte
     * d'Ivoire", typing "AOF" finds docs about "Afrique-Occidentale
     * française". Aliases are NOT pushed into the canonical *_ss facet
     * fields — those stay clean for faceting.
     *
     * @return array{
     *     persons_ss?: list<string>,
     *     organisations_ss?: list<string>,
     *     places_ss?: list<string>,
     *     events_ss?: list<string>,
     *     topics_ss?: list<string>,
     *     entity_ids?: list<int>,
     *     entity_aliases_txt?: list<string>
     * }
     */
    public function resolve(?string $pipeJoined): array
    {
        if (!$this->built) {
            // Caller forgot to build() — fail loudly rather than silently
            // returning empty arrays, which would produce broken facets.
            throw new \LogicException('AuthorityResolver::resolve called before build()');
        }

        if ($pipeJoined === null || $pipeJoined === '') {
            return [];
        }

        $buckets    = ['persons_ss' => [], 'organisations_ss' => [], 'places_ss' => [], 'events_ss' => [], 'topics_ss' => []];
        $entityIds  = [];
        $aliases    = [];

        foreach (explode('|', $pipeJoined) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            // Direct hit, then alias lookup
            $entry = $this->byTitre[$token] ?? null;
            if ($entry === null && isset($this->aliasToTitre[$token])) {
                $entry = $this->byTitre[$this->aliasToTitre[$token]] ?? null;
            }

            if ($entry !== null) {
                $buckets[$entry['bucket']][] = $token;
                $entityIds[] = $entry['o_id'];
                foreach ($entry['aliases'] as $alias) {
                    $aliases[] = $alias;
                }
            }
            // Else: unresolved string. Currently dropped silently. Future
            // M0+ task: emit a counter so the reindex CLI can report
            // unresolved-token rates as a data-quality signal.
        }

        // Strip empty buckets and dedupe
        $out = array_filter($buckets, static fn(array $v): bool => $v !== []);
        if ($entityIds !== []) {
            $out['entity_ids'] = array_values(array_unique($entityIds));
        }
        if ($aliases !== []) {
            $out['entity_aliases_txt'] = array_values(array_unique($aliases));
        }
        return $out;
    }

    public function size(): int
    {
        return count($this->byTitre);
    }
}

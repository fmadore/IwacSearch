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

    /** @var array<string, array{type: string, bucket: string, o_id: int}> */
    private array $byTitre = [];

    /** @var array<string, string> alias → canonical Titre */
    private array $aliasToTitre = [];

    private bool $built = false;

    /**
     * Build the lookup table from an iterable of HF `index` rows.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function build(iterable $rows): self
    {
        foreach ($rows as $row) {
            $titre = trim((string) ($row['Titre'] ?? ''));
            $type  = trim((string) ($row['Type']  ?? ''));
            $oid   = (int)         ($row['o:id']  ?? 0);

            if ($titre === '' || !isset(self::TYPE_TO_BUCKET[$type]) || $oid === 0) {
                continue;
            }

            $bucket = self::TYPE_TO_BUCKET[$type];
            $this->byTitre[$titre] = [
                'type'   => $type,
                'bucket' => $bucket,
                'o_id'   => $oid,
            ];

            $aliases = (string) ($row['Titre alternatif'] ?? '');
            if ($aliases !== '') {
                foreach (explode('|', $aliases) as $alias) {
                    $alias = trim($alias);
                    if ($alias !== '' && !isset($this->byTitre[$alias])) {
                        $this->aliasToTitre[$alias] = $titre;
                    }
                }
            }
        }

        $this->built = true;
        return $this;
    }

    /**
     * Resolve a pipe-separated `subject` or `spatial` string to per-bucket
     * arrays of names + a flat list of entity IDs.
     *
     * Returns an array keyed by Typesense field name, suitable for
     * splat-merging into the document being built.
     *
     * @return array{
     *     persons_ss?: list<string>,
     *     organisations_ss?: list<string>,
     *     places_ss?: list<string>,
     *     events_ss?: list<string>,
     *     topics_ss?: list<string>,
     *     entity_ids?: list<int>
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
            }
            // Else: unresolved string. Currently dropped silently. Future
            // M0+ task: emit a counter so the reindex CLI can report
            // unresolved-token rates as a data-quality signal.
        }

        // Strip empty buckets and dedupe entity IDs
        $out = array_filter($buckets, static fn(array $v): bool => $v !== []);
        if ($entityIds !== []) {
            $out['entity_ids'] = array_values(array_unique($entityIds));
        }
        return $out;
    }

    public function size(): int
    {
        return count($this->byTitre);
    }
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use IwacSearch\Indexer\CountryResolver;
use IwacSearch\Indexer\EntityAuthority;
use RuntimeException;

/**
 * Resolves an Omeka-derived content subset name to its mapper.
 *
 * Why a registry instead of a match block in Reindexer:
 *   - Adding a new subset = drop a new MapperInterface in this dir, then
 *     register it in default() — both the bulk and the incremental
 *     pipelines pick it up from there
 *   - The registry knows which subsets are indexable; Reindexer asks
 *     "which subsets do I iterate?" via subsets() instead of a const
 *   - Tests can swap in fakes via the constructor
 */
final class MapperRegistry
{
    /**
     * The canonical content-mapper set, shared by the bulk pipeline
     * (ReindexOrchestrator) and the incremental one (IncrementalIndexerFactory).
     * ONE registration point — a mapper added here is guaranteed to behave
     * identically in both; the two wiring sites used to hand-copy this list
     * and could silently drift.
     */
    public static function default(EntityAuthority $authority, CountryResolver $countries): self
    {
        return new self([
            new ArticleMapper($authority, $countries),
            new PublicationMapper($authority, $countries),
            new DocumentMapper($authority, $countries),
            new AudiovisualMapper($authority, $countries),
            new PhotographMapper($authority, $countries),
            new ReferenceMapper($authority, $countries),
        ]);
    }

    /** @var array<string, MapperInterface> subset name → mapper */
    private readonly array $mappers;

    /**
     * @param iterable<MapperInterface> $mappers
     */
    public function __construct(iterable $mappers)
    {
        $byName = [];
        foreach ($mappers as $mapper) {
            $name = $mapper->subsetName();
            if (isset($byName[$name])) {
                throw new RuntimeException("Duplicate mapper for subset: {$name}");
            }
            $byName[$name] = $mapper;
        }
        if ($byName === []) {
            throw new RuntimeException('MapperRegistry: at least one mapper required');
        }
        $this->mappers = $byName;
    }

    public function get(string $subset): MapperInterface
    {
        if (!isset($this->mappers[$subset])) {
            throw new RuntimeException("No mapper registered for subset: {$subset}");
        }
        return $this->mappers[$subset];
    }

    /** @return list<string> */
    public function subsets(): array
    {
        return array_keys($this->mappers);
    }

    /**
     * The mapper whose resource classes include $classId, or null if none
     * handles it (e.g. an authority item — not content).
     */
    public function forClass(int $classId): ?MapperInterface
    {
        foreach ($this->mappers as $mapper) {
            if (in_array($classId, $mapper->classIds(), true)) {
                return $mapper;
            }
        }
        return null;
    }

    /**
     * Union of every mapper's readTerms() — what the incremental indexer must
     * load for one item before it knows which mapper (and term subset) applies.
     *
     * @return list<string>
     */
    public function allReadTerms(): array
    {
        $terms = [];
        foreach ($this->mappers as $mapper) {
            foreach ($mapper->readTerms() as $term) {
                $terms[$term] = true;
            }
        }
        return array_keys($terms);
    }
}

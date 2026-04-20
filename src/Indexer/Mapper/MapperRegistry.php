<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

use RuntimeException;

/**
 * Resolves an HF subset name to its mapper.
 *
 * Why a registry instead of a match block in Reindexer:
 *   - Adding a new subset = drop a new MapperInterface in this dir, no
 *     edit to Reindexer required
 *   - The registry knows which subsets are indexable; Reindexer asks
 *     "which subsets do I iterate?" via subsets() instead of a const
 *   - Tests can swap in fakes via the constructor
 */
final class MapperRegistry
{
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

    public function has(string $subset): bool
    {
        return isset($this->mappers[$subset]);
    }

    /** @return list<string> */
    public function subsets(): array
    {
        return array_keys($this->mappers);
    }
}

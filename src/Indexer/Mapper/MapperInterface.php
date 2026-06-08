<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Maps one Omeka item (with its grouped property values) to one Typesense
 * document for the content collection.
 *
 * One implementation per content subset (articles, publications, documents,
 * audiovisual, references). A subset is now defined by its Omeka resource
 * CLASS id(s) — the same split key the HF pipeline used — not an HF config
 * name. The reindexer reads the classes via OmekaSourceReader, loads exactly
 * the property terms readTerms() declares, and calls map().
 *
 * Implementations return null for rows to skip (e.g. missing id) rather than
 * throwing — the reindex is bulk and resilient to a few bad rows.
 *
 * is_public is taken from the item (resource.is_public) directly; there is no
 * longer an ACL overlay step.
 */
interface MapperInterface
{
    /** Canonical subset name, for logging / progress reporting. */
    public function subsetName(): string;

    /**
     * Omeka resource class id(s) this subset reads (e.g. [36] for articles,
     * the nine bibliographic classes for references).
     *
     * @return list<int>
     */
    public function classIds(): array;

    /**
     * Optional item-set scope ANDed onto the class filter. Null for subsets
     * scoped by class alone (the common case).
     *
     * @return list<int>|null
     */
    public function itemSetIds(): ?array;

    /**
     * Every Omeka property term the reindexer must SELECT for this subset.
     *
     * @return list<string>
     */
    public function readTerms(): array;

    /**
     * Convert one Omeka item to one Typesense document.
     *
     * @param  array{id:int,title:string,is_public:bool,class:int} $item
     * @param  array<string, list<array{vrid:?int,value:?string,uri:?string,title:?string}>> $values
     * @param  ?string $thumbnailUrl  first thumbnailed-media derivative URL, or null
     * @return array<string,mixed>|null  null = skip this item
     */
    public function map(array $item, array $values, ?string $thumbnailUrl): ?array;
}

<?php
declare(strict_types=1);

namespace IwacSearch\Indexer\Mapper;

/**
 * Maps one row of an HF dataset subset to one Typesense document.
 *
 * One implementation per subset (articles, publications, documents,
 * audiovisual). Subset name is canonical and matches the HF dataset
 * config key — the MapperRegistry uses it as the lookup key and the
 * Reindexer uses it to drive HfDatasetLoader.
 *
 * Implementations should return null for rows that should be skipped
 * (e.g. missing o:id) rather than throwing — the indexer is bulk and
 * resilient to a few bad rows, but log noisy errors via the calling
 * Reindexer.
 *
 * Mappers do NOT enforce is_public — they default it to false. The
 * Reindexer overlays the authoritative ACL state from Omeka API
 * before import. Defaulting closed is the safe choice if overlay
 * fails.
 */
interface MapperInterface
{
    /**
     * The HF dataset subset this mapper handles.
     * Examples: "articles", "publications", "documents", "audiovisual".
     */
    public function subsetName(): string;

    /**
     * Convert one HF row to one Typesense document.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>|null  null = skip this row
     */
    public function map(array $row): ?array;
}

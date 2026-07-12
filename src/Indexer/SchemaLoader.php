<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads data/schema.yaml and converts it to a Typesense create-collection
 * payload.
 *
 * The YAML is the source of truth — everything (indexer mapping, scoped-key
 * field allowlist, Svelte facet panel) derives from it.
 */
final class SchemaLoader
{
    public function __construct(
        private readonly string $schemaPath
    ) {
    }

    /**
     * @return array{name: string, fields: array, default_sorting_field?: string, token_separators?: array, symbols_to_index?: array}
     */
    public function load(): array
    {
        if (!is_readable($this->schemaPath)) {
            throw new RuntimeException("Schema file not readable: {$this->schemaPath}");
        }

        $parsed = Yaml::parseFile($this->schemaPath);
        if (!is_array($parsed) || !isset($parsed['name'], $parsed['fields'])) {
            throw new RuntimeException(
                "Schema file is malformed (must have 'name' and 'fields' keys): {$this->schemaPath}"
            );
        }

        return $parsed;
    }

    /**
     * Build a versioned schema for an alias-swap reindex.
     *
     * Naming convention: the schema file declares the *base* name (e.g.
     * iwac_v1). For each reindex we suffix it with a UTC timestamp so we
     * can build a fresh collection without touching the live one, then
     * swap the iwac_current alias atomically.
     *
     * Example: iwac_v1 → iwac_v1_20260420_143015
     */
    public function loadForReindex(string $aliasTarget = 'iwac_current'): array
    {
        $schema = $this->load();
        $base   = $schema['name'];
        $stamp  = gmdate('Ymd_His');
        $schema['name'] = "{$base}_{$stamp}";
        $schema['_alias_target'] = $aliasTarget;
        $schema['_base_name']    = $base;
        return $schema;
    }
}

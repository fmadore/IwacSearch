<?php
declare(strict_types=1);

namespace IwacSearch\Tests\Indexer;

use IwacSearch\Indexer\SchemaLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * "Schema is the contract" (CLAUDE.md). The loader turns the YAML into a
 * Typesense create-collection payload and mints the timestamped name each
 * reindex builds under before the alias swap.
 */
#[CoversClass(SchemaLoader::class)]
final class SchemaLoaderTest extends TestCase
{
    private const CONTENT = __DIR__ . '/../../../data/schema.yaml';
    private const ENTITY = __DIR__ . '/../../../data/schema-index.yaml';

    public function testBothShippedSchemasLoadAndDeclareFields(): void
    {
        foreach ([self::CONTENT, self::ENTITY] as $path) {
            $schema = (new SchemaLoader($path))->load();

            self::assertIsString($schema['name']);
            self::assertNotEmpty($schema['fields'], $path);
        }
    }

    public function testTheContentSchemaKeepsItsGlobalSetLinkages(): void
    {
        // These link the collection to the global synonym / curation sets the
        // sync steps PUT before it is created; losing them silently disables
        // synonym expansion and MMR diversification.
        $schema = (new SchemaLoader(self::CONTENT))->load();

        self::assertContains('iwac_synonyms', $schema['synonym_sets'] ?? []);
        self::assertContains('iwac_diversity', $schema['curation_sets'] ?? []);
    }

    public function testContentV5IndexesPublicationTablesOfContents(): void
    {
        $schema = (new SchemaLoader(self::CONTENT))->load();
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column($schema['fields'], null, 'name');
        /** @var array{type:string,stem:bool,optional:bool} $toc */
        $toc = $fields['toc_txt'];
        /** @var array{embed:array{from:list<string>}} $embedding */
        $embedding = $fields['embedding'];

        // The collection NAME is the deployment contract: a schema change that
        // reaches production without a bump silently keeps serving the old
        // collection. v7 added the audiovisual fields asserted below.
        self::assertSame('iwac_v8', $schema['name']);
        self::assertSame('string', $toc['type']);
        self::assertTrue($toc['stem']);
        self::assertTrue($toc['optional']);
        self::assertContains('toc_txt', $embedding['embed']['from']);
    }

    public function testContentV7DeclaresTheAudiovisualFields(): void
    {
        $schema = (new SchemaLoader(self::CONTENT))->load();
        /** @var array<string, array<string, mixed>> $fields */
        $fields = array_column($schema['fields'], null, 'name');

        foreach (['channel_ss', 'media_kind_s', 'media_platform_s', 'rights_s'] as $name) {
            self::assertArrayHasKey($name, $fields, $name);
            self::assertTrue($fields[$name]['facet'] ?? false, "{$name} must be facetable");
        }

        // duration_seconds is sortable and range-filterable but deliberately
        // NOT a facet — ~900 distinct running times is a wall, not a filter.
        self::assertSame('int32', $fields['duration_seconds']['type']);
        self::assertTrue($fields['duration_seconds']['sort'] ?? false);
        self::assertFalse($fields['duration_seconds']['facet'] ?? false);
    }

    public function testTheReindexNameIsTheBaseNamePlusAUtcTimestamp(): void
    {
        $schema = (new SchemaLoader(self::CONTENT))->loadForReindex();
        $base = (new SchemaLoader(self::CONTENT))->load()['name'];

        self::assertMatchesRegularExpression(
            '/^' . preg_quote($base, '/') . '_\d{8}_\d{6}$/',
            $schema['name']
        );
        // The orphan sweep matches on `<base>_`, so the separator matters.
        self::assertSame($base, $schema['_base_name']);
    }

    public function testTheAliasTargetDefaultsToTheContentAliasAndIsOverridable(): void
    {
        self::assertSame(
            'iwac_current',
            (new SchemaLoader(self::CONTENT))->loadForReindex()['_alias_target']
        );
        self::assertSame(
            'iwac_index_current',
            (new SchemaLoader(self::ENTITY))->loadForReindex('iwac_index_current')['_alias_target']
        );
    }

    public function testAMissingSchemaFileFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        (new SchemaLoader('/nonexistent/schema.yaml'))->load();
    }

    public function testASchemaWithoutNameOrFieldsIsRejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iwac') ?: '';
        file_put_contents($path, "name: iwac_v1\n");

        try {
            $this->expectException(RuntimeException::class);
            (new SchemaLoader($path))->load();
        } finally {
            @unlink($path);
        }
    }
}

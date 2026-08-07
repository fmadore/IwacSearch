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

        self::assertSame('iwac_v6', $schema['name']);
        self::assertSame('string', $toc['type']);
        self::assertTrue($toc['stem']);
        self::assertTrue($toc['optional']);
        self::assertContains('toc_txt', $embedding['embed']['from']);
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

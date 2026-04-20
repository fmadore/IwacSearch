<?php
declare(strict_types=1);

namespace IwacSearch\Browse;

use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Read + write access to `iwac_browse_config`.
 *
 * Uses Doctrine DBAL directly (via Omeka's `Omeka\Connection` service)
 * rather than an entity manager — the table has 9 columns and one access
 * pattern (find by slug, list ordered by position). An entity + metadata
 * mapping would triple the surface area for no win.
 *
 * The slug is the public identifier (`/browse/{slug}`) — uniqueness is
 * enforced at the schema level. Slugs are constrained to
 * `[a-z][a-z0-9-]*` by both the route constraint and SQL (CHECK clause
 * not supported on all MySQL versions; we validate in PHP on save).
 */
final class BrowseConfigRepository
{
    public const TABLE = 'iwac_browse_config';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Schema DDL — used by Module::install(). The default-sort literal
     * is hardcoded rather than parameterised because MySQL DDL doesn't
     * accept bound parameters in DEFAULT clauses anyway.
     */
    public static function createTableSql(): string
    {
        return sprintf(
            "CREATE TABLE IF NOT EXISTS %s (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(80) NOT NULL,
                title VARCHAR(200) NOT NULL,
                intro_html TEXT NULL,
                locked_filters TEXT NOT NULL,
                prominent_facets JSON NOT NULL,
                default_sort VARCHAR(80) NOT NULL DEFAULT '_text_match:desc',
                results_per_page INT UNSIGNED NOT NULL DEFAULT 10,
                position INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY iwac_browse_config_slug (slug),
                INDEX iwac_browse_config_position (position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            self::TABLE
        );
    }

    public static function dropTableSql(): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', self::TABLE);
    }

    /**
     * @return list<BrowseConfig>
     */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s ORDER BY position ASC, title ASC', self::TABLE)
        );
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findBySlug(string $slug): ?BrowseConfig
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE slug = ? LIMIT 1', self::TABLE),
            [$slug]
        );
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?BrowseConfig
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE id = ? LIMIT 1', self::TABLE),
            [$id]
        );
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Insert (when id is null) or update (when id is set).
     *
     * Returns the persisted config, including the new id on insert.
     */
    public function save(BrowseConfig $config): BrowseConfig
    {
        $this->validateSlug($config->slug);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $payload = [
            'slug'             => $config->slug,
            'title'            => $config->title,
            'intro_html'       => $config->introHtml === '' ? null : $config->introHtml,
            'locked_filters'   => $config->lockedFilters,
            'prominent_facets' => json_encode($config->prominentFacets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'default_sort'     => $config->defaultSort,
            'results_per_page' => $config->resultsPerPage,
            'position'         => $config->position,
            'updated_at'       => $now,
        ];

        if ($config->id === null) {
            $payload['created_at'] = $now;
            $this->connection->insert(self::TABLE, $payload);
            $newId = (int) $this->connection->lastInsertId();
            return new BrowseConfig(
                id:               $newId,
                slug:             $config->slug,
                title:            $config->title,
                introHtml:        $config->introHtml,
                lockedFilters:    $config->lockedFilters,
                prominentFacets:  $config->prominentFacets,
                defaultSort:      $config->defaultSort,
                resultsPerPage:   $config->resultsPerPage,
                position:         $config->position,
            );
        }

        $this->connection->update(self::TABLE, $payload, ['id' => $config->id]);
        return $config;
    }

    public function delete(int $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    public function existsBySlug(string $slug, ?int $exceptId = null): bool
    {
        $sql = sprintf('SELECT id FROM %s WHERE slug = ?', self::TABLE);
        $params = [$slug];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        return (bool) $this->connection->fetchOne($sql . ' LIMIT 1', $params);
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s', self::TABLE)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): BrowseConfig
    {
        $facets = json_decode((string) ($row['prominent_facets'] ?? '[]'), true);
        if (!is_array($facets)) {
            $facets = [];
        }

        return new BrowseConfig(
            id:               isset($row['id']) ? (int) $row['id'] : null,
            slug:             (string) ($row['slug'] ?? ''),
            title:            (string) ($row['title'] ?? ''),
            introHtml:        (string) ($row['intro_html'] ?? ''),
            lockedFilters:    (string) ($row['locked_filters'] ?? ''),
            prominentFacets:  array_values(array_map('strval', $facets)),
            defaultSort:      (string) ($row['default_sort'] ?? '_text_match:desc'),
            resultsPerPage:   (int) ($row['results_per_page'] ?? 10),
            position:         (int) ($row['position'] ?? 0),
        );
    }

    /**
     * Slug regex matches the route constraint in module.config.php
     * (`[a-zA-Z0-9_-]+`) plus we require it starts with a lowercase
     * letter to avoid collisions with future reserved paths like
     * `/browse/_admin`.
     */
    private function validateSlug(string $slug): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/', $slug)) {
            throw new RuntimeException("BrowseConfig: invalid slug `{$slug}` — must match /^[a-z][a-z0-9_-]{0,79}$/");
        }
    }
}

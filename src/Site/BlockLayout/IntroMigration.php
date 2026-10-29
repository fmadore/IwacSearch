<?php
declare(strict_types=1);

namespace IwacSearch\Site\BlockLayout;

use Closure;
use Doctrine\DBAL\Connection;
use Omeka\Stdlib\HtmlPurifier;

/**
 * Upgrade migration (3.20.0): purify and flag the intro of every stored search
 * block that predates {@see IntroHtml::PURIFIED_FLAG}, so render() stops
 * purifying on each page view.
 *
 * Called from Module::upgrade() only. It used to be a static method on
 * IwacSearchBlock, which put an upgrade step on the class Omeka builds for
 * every page view and loaded Omeka's AbstractBlockLayout just to run SQL.
 * Omeka runs upgrade() while the module is still inactive, with none of its
 * own services registered, so this takes core services only: the connection,
 * and a closure that resolves core's purifier — called only if such a block
 * exists. The per-block rule is {@see IntroHtml::migrated()}, unit-tested.
 */
final class IntroMigration
{
    /**
     * @param  Closure(): HtmlPurifier $purifier
     * @return int blocks updated
     */
    public static function run(Connection $connection, Closure $purifier): int
    {
        $updated = 0;
        $purify = static fn(string $html): string => (string) $purifier()->purify($html);
        foreach ($connection->executeQuery("SELECT id, data FROM site_page_block WHERE layout = 'iwacSearch'")->fetchAllAssociative() as $row) {
            $data = json_decode((string) $row['data'], true);
            $data = is_array($data) ? IntroHtml::migrated($data, $purify) : null;
            if ($data === null) {
                continue;
            }
            $connection->executeStatement(
                'UPDATE site_page_block SET data = ? WHERE id = ?',
                [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int) $row['id']]
            );
            $updated++;
        }
        return $updated;
    }
}

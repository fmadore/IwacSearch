<?php

declare(strict_types=1);

namespace IwacSearch\Indexer;

/**
 * Refuses a build that is far smaller than what it would replace.
 *
 * The import health check only catches a build where documents were
 * rejected or none were indexed. A source that silently shrank — a resource
 * class or item set renumbered so a mapper matches nothing, an item set
 * emptied by mistake, a filter that suddenly excludes a subset — produces a
 * perfectly healthy, much smaller build, and promoting it makes a whole
 * content type vanish from the public site. So, before promotion, every
 * content type (`type_s`) and the entity collection's total are compared
 * with the live generation, and a drop below the ratio refuses the swap
 * unless the operator says the drop is intended.
 *
 * Per type rather than in total: losing all 600 photographs from a 14 000
 * document corpus barely moves the total.
 */
final class ShrinkGuard
{
    /** A count may fall to this share of the live one (DRE Search uses the same). */
    public const MIN_RETAINED_RATIO = 0.5;

    /**
     * What shrank too far, one line per content type or collection; empty
     * when the build may be promoted. A type or collection with no live
     * documents has nothing to protect — a first install is never refused.
     *
     * @param array<string, int> $liveTypes     type_s → documents, live content generation
     * @param array<string, int> $newTypes      type_s → documents, new build
     * @param ?int               $liveEntities  live entity collection total; null when there is none
     * @return list<string>
     */
    public static function problems(array $liveTypes, array $newTypes, ?int $liveEntities, int $newEntities, float $ratio = self::MIN_RETAINED_RATIO): array
    {
        $problems = [];
        ksort($liveTypes);
        foreach ($liveTypes as $type => $live) {
            $new = $newTypes[$type] ?? 0;
            if ($live > 0 && $new < $live * $ratio) {
                $problems[] = sprintf('%s: %d → %d', $type, $live, $new);
            }
        }
        if ($liveEntities !== null && $liveEntities > 0 && $newEntities < $liveEntities * $ratio) {
            $problems[] = sprintf('entities: %d → %d', $liveEntities, $newEntities);
        }
        return $problems;
    }
}

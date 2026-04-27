<?php
declare(strict_types=1);

namespace IwacSearch\Util;

use Throwable;

/**
 * Helpers for turning a chained exception into a single human-readable
 * diagnostic string.
 *
 * Why we need this: Laminas's ServiceManager wraps factory failures in
 *
 *   ServiceNotCreatedException("Service with name 'Typesense\Client'
 *   could not be created", previous = <real cause>)
 *
 * If we surface only `$e->getMessage()` to ops (in a 503 JSON body or a
 * log line), the operator sees the wrapper and has to dig into the
 * application log to find the actual reason — assuming it's even
 * logged. By walking `getPrevious()` we keep the diagnostic
 * self-contained: a single line in the response body tells the
 * operator that the Docker secret is missing, or that Typesense is
 * unreachable, or whatever the root cause turned out to be.
 *
 * Bounded depth so a pathological cycle (or just deeply chained 3rd
 * party exceptions) can't run away with the response.
 */
final class ExceptionMessage
{
    private const MAX_DEPTH = 6;
    private const SEPARATOR = ' ← caused by: ';

    /**
     * Walk getPrevious() and return all messages joined left-to-right
     * (outermost first), capped at MAX_DEPTH frames.
     *
     * Output is intentionally a single line — callers may include it
     * as the `detail` field of a JSON error envelope or as the
     * payload of a single log call.
     */
    public static function chain(Throwable $e): string
    {
        $messages = [];
        $current = $e;
        $depth = 0;
        while ($current !== null && $depth < self::MAX_DEPTH) {
            $messages[] = $current->getMessage();
            $current = $current->getPrevious();
            $depth++;
        }
        return implode(self::SEPARATOR, $messages);
    }
}

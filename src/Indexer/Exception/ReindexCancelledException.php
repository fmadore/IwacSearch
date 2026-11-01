<?php

declare(strict_types=1);

namespace IwacSearch\Indexer\Exception;

use RuntimeException;

/**
 * An operator stopped the rebuild (Omeka's job "Stop"). Nothing was promoted
 * and the builds are dropped, so it is an outcome, not a failure: the job
 * logs it as a notice and ends as stopped rather than as an error.
 */
final class ReindexCancelledException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace IwacSearch\Indexer\Exception;

use RuntimeException;

/**
 * A finished build failed a check that guards promotion — rejected or
 * missing documents, counts that disagree with the catalog, or a build far
 * smaller than the live one. The previous generations stay live.
 */
final class VerificationException extends RuntimeException
{
}

<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Just the module's own autoloader — the tested seams are pure PHP over the
 * module's composer dependencies (Typesense's client, Symfony YAML). Nothing
 * here needs an Omeka bootstrap, which is exactly why these are the seams
 * worth testing first.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

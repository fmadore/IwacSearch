<?php

/**
 * PHPStan bootstrap: the released Omeka S 4.2.1 runtime, then the module.
 *
 * Omeka S is an application, not a Packagist library, and it pins its own
 * Laminas (and its own forks of laminas-mvc and laminas-log, the
 * omeka/sanimal-* packages). The released zip carries the exact vendor tree
 * production runs, so the analysis loads that tree rather than stubs or a
 * `require-dev` copy, either of which would check the module against
 * signatures it never runs on. CI downloads the zip and checks its sha256;
 * locally, point OMEKA_VENDOR at any 4.2.1 tree's vendor/autoload.php.
 *
 * Omeka's bootstrap.php, not its bare vendor/autoload.php: since 4.2.1 that
 * file prepends an autoloader swapping in PHP 8.5-patched copies of
 * Laminas\Escaper\Escaper, Laminas\Stdlib\SplPriorityQueue and Doctrine's
 * proxy factories, so the vendor autoloader alone would load classes
 * production does not. It also chdir()s to the Omeka root, which is why
 * every path here is built from __DIR__.
 *
 * Then the module's own autoloader, in front of Omeka's, as in production:
 * Omeka boots first and Module.php registers the module's Composer loader
 * later, which Composer prepends. That order decides which copy wins where
 * both trees ship a package — psr/log is 1.1.4 in core and 3.x here, and
 * OmekaPsrLogger's typed log() is only compatible with 3.x. PHPStan looks a
 * class up through the autoloaders a bootstrap file registers before it
 * reads the module's composer.json, and it leaves out loaders that were
 * registered before the bootstrap ran (the module's own, which PHPStan
 * loads at start-up). So the module's loader is registered again here,
 * wrapped in a new callable, prepended: without it every psr/log class
 * would be read from core's 1.1.4.
 *
 * PHPUnit does not use this file: it runs standalone, without Omeka, on
 * tests/php/bootstrap.php and tests/php/runtime-stubs.php.
 */

declare(strict_types=1);

$omekaVendor = getenv('OMEKA_VENDOR') ?: getenv('IWAC_OMEKA_VENDOR') ?: '/var/www/html/vendor/autoload.php';
$omekaBootstrap = dirname($omekaVendor, 2) . '/bootstrap.php';
if (!is_readable($omekaVendor) || !is_readable($omekaBootstrap)) {
    throw new RuntimeException(sprintf(
        'PHPStan analyses against the released Omeka S 4.2.1 runtime, which was not found at %s. '
        . 'Set OMEKA_VENDOR to the vendor/autoload.php of an extracted omeka-s-4.2.1.zip.',
        $omekaVendor,
    ));
}
require_once $omekaBootstrap;

// `require`, not `require_once`: Composer's autoload.php returns the loader,
// the instance already registered when PHPStan loaded it first.
$moduleLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($moduleLoader): void {
    $moduleLoader->loadClass($class);
}, true, true);

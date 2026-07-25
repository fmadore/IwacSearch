<?php
declare(strict_types=1);

/**
 * Runtime declarations for the handful of Omeka interfaces the unit tests
 * need to instantiate a test double for.
 *
 * Omeka S is not a composer dependency of this module (it's the application
 * the module is installed INTO), so its classes don't exist in `vendor/`.
 * Static analysis solves this with `tools/phpstan/omeka.stub.php`; PHPUnit
 * needs the same declarations to actually exist at runtime, and only for the
 * seams a test constructs a fake of.
 *
 * Keep this file minimal and keep the signatures identical to the PHPStan
 * stub — a divergence here would let a test pass against a shape production
 * never sees. It is excluded from the PHPStan run (see phpstan.neon) so the
 * two declarations of the same interface don't collide.
 */

namespace Omeka\Settings {
    if (!interface_exists(SettingsInterface::class)) {
        interface SettingsInterface
        {
            public function set($id, $value);

            public function get($id, $default = null);

            public function delete($id);
        }
    }
}

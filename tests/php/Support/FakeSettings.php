<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use Omeka\Settings\SettingsInterface;

/** In-memory Omeka settings. */
final class FakeSettings implements SettingsInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    /**
     * Signatures mirror Omeka's untyped SettingsInterface — a parameter type
     * cannot be narrowed in an implementation, so the types live in PHPDoc.
     *
     * @param  mixed $id
     * @param  mixed $value
     * @return void
     */
    public function set($id, $value)
    {
        $this->values[(string) $id] = $value;
    }

    /**
     * @param  mixed $id
     * @param  mixed $default
     * @return mixed
     */
    public function get($id, $default = null)
    {
        return $this->values[(string) $id] ?? $default;
    }

    /**
     * @param  mixed $id
     * @return void
     */
    public function delete($id)
    {
        unset($this->values[(string) $id]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}

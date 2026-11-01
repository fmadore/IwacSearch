<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Support;

use RuntimeException;

/** @internal */
final class FakeHealth extends \Typesense\Health
{
    public function __construct(private readonly FakeTypesense $server)
    {
    }

    /** @return array<string, mixed> */
    public function retrieve(): array
    {
        $answers = $this->server->health;
        $answer = $answers === [] ? ['ok' => true] : $answers[min($this->server->healthCalls, count($answers) - 1)];
        $this->server->healthCalls++;
        if ($answer instanceof RuntimeException) {
            throw $answer;
        }
        return $answer;
    }
}

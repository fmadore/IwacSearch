<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Controller;

use IwacSearch\Controller\ErrorEnvelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The JSON error body of the public controller. Its field names are a
 * contract with two readers: monitors, which branch on `code`, and the
 * Svelte client, whose error formatter reads `message` then `error` at the
 * top level (src/svelte/lib/transport.ts, formatHttpError).
 */
#[CoversClass(ErrorEnvelope::class)]
final class ErrorEnvelopeTest extends TestCase
{
    public function testTheBodyCarriesTheStableCodeAndTheRequestId(): void
    {
        self::assertSame(
            ['error' => 'token_unavailable', 'code' => 'token_unavailable', 'message' => 'Down.', 'request_id' => 'abc'],
            ErrorEnvelope::body('token_unavailable', 'Down.', 'abc')
        );
    }

    public function testExtraFieldsNeverOverrideTheEnvelope(): void
    {
        $body = ErrorEnvelope::body('search_unavailable', 'Typesense is unreachable.', 'id1', [
            'ok' => false,
            'code' => 'spoofed',
            'request_id' => 'other',
        ]);

        self::assertSame('search_unavailable', $body['code']);
        self::assertSame('id1', $body['request_id']);
        self::assertFalse($body['ok']);
    }

    public function testRequestIdsAreOpaqueAndFresh(): void
    {
        $ids = [ErrorEnvelope::requestId(), ErrorEnvelope::requestId()];

        self::assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $ids[0]);
        self::assertNotSame($ids[0], $ids[1]);
    }
}

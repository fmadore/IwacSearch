<?php
declare(strict_types=1);

namespace IwacSearch\Controller;

/**
 * The body of every JSON error SearchController returns.
 *
 *   {"error": "token_unavailable", "code": "token_unavailable",
 *    "message": "…", "request_id": "3f9c…"}
 *
 * `code` is the stable, machine-readable field: monitors and clients branch
 * on it, never on the message. `error` carries the same value because it is
 * what the token endpoint has always returned, and the Svelte client's
 * error formatter reads `message`, then `error`, at the top level — nesting
 * the envelope (as DRE Search does) would have lost both. `request_id` is
 * also sent as the X-Request-ID header and written to every log line about
 * the request, which is how an operator finds the cause: the response
 * itself never carries an exception, a host or a path.
 */
final class ErrorEnvelope
{
    /** A fresh, opaque id for one request: 24 hex characters. */
    public static function requestId(): string
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * @param  array<string, mixed> $extra Fields reported alongside the error
     *                                     (the health status); the envelope's
     *                                     own keys win over them.
     * @return array<string, mixed>
     */
    public static function body(string $code, string $message, string $requestId, array $extra = []): array
    {
        return [
            'error'      => $code,
            'code'       => $code,
            'message'    => $message,
            'request_id' => $requestId,
        ] + $extra;
    }
}

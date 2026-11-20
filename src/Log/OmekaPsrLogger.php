<?php

declare(strict_types=1);

namespace IwacSearch\Log;

use Laminas\Log\Logger as LaminasLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 adapter over Laminas\Log\Logger. Avoids Omeka's bundled
 * Laminas\Log\PsrLoggerAdapter, whose log() signature is pinned to
 * psr/log 1.x/2.x and fatals under psr/log 3.x (required by
 * typesense-php / Guzzle 7, which IwacSearch pulls in).
 *
 * Typed on the Logger class, not Laminas\Log\LoggerInterface: log() with a
 * priority is the class's own, and the interface declares only the
 * per-level methods (emerg … debug). Omeka's `Omeka\Logger` service is that
 * class.
 */
final class OmekaPsrLogger extends AbstractLogger
{
    private const LEVEL_MAP = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    public function __construct(private readonly LaminasLogger $logger)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $priority = self::LEVEL_MAP[$level] ?? self::LEVEL_MAP[LogLevel::INFO];
        $this->logger->log($priority, self::interpolate((string) $message, $context), $context);
    }

    /**
     * PSR-3 placeholders: `{key}` becomes the context value when it is a
     * scalar or Stringable; anything else, and an unknown key, stays as
     * written. Laminas does not do this, so a message logged as
     * "…: {message}" reached Omeka's log with the braces in it.
     *
     * @param array<array-key, mixed> $context
     */
    public static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }
}

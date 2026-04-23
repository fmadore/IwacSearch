<?php
declare(strict_types=1);

namespace IwacSearch\Log;

use Laminas\Log\LoggerInterface as LaminasLogger;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 adapter over Laminas\Log\Logger. Avoids Omeka's bundled
 * Laminas\Log\PsrLoggerAdapter, whose log() signature is pinned to
 * psr/log 1.x/2.x and fatals under psr/log 3.x (required by
 * typesense-php / Guzzle 7, which IwacSearch pulls in).
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
        $this->logger->log($priority, (string) $message, $context);
    }
}

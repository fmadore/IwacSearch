<?php

declare(strict_types=1);

namespace IwacSearch\Tests\Search;

use GuzzleHttp\Psr7\Response;
use IwacSearch\Search\HealthProbe;
use IwacSearch\Tests\Support\CallLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Typesense\Client as TypesenseClient;

/**
 * The anonymous health endpoint's probe. It is polled by a monitor, so the
 * assertions are about two things: it never throws, and nothing in what it
 * returns names a host, a key or an exception — the detail goes to the log.
 */
#[CoversClass(HealthProbe::class)]
final class HealthProbeTest extends TestCase
{
    /** Request paths the fake Typesense saw. */
    private CallLog $requests;

    private CallLog $logged;

    protected function setUp(): void
    {
        $this->requests = new CallLog();
        $this->logged = new CallLog();
    }

    /**
     * A real Typesense client whose HTTP transport is a stand-in, so the
     * probe goes through the SDK's own /health call and response handling.
     *
     * @return \Closure(): TypesenseClient
     */
    private function typesense(int $status, string $body, ?RuntimeException $failure = null): \Closure
    {
        $transport = new class ($this->requests, $status, $body, $failure) implements ClientInterface {
            public function __construct(private CallLog $requests, private int $status, private string $body, private ?RuntimeException $failure)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->requests->record(['path' => $request->getUri()->getPath()]);
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return new Response($this->status, ['Content-Type' => 'application/json'], $this->body);
            }
        };
        return static fn(): TypesenseClient => new TypesenseClient([
            'api_key' => 'secret-admin-key',
            'nodes' => [['host' => 'typesense.internal', 'port' => '8108', 'protocol' => 'http']],
            'num_retries' => 0,
            'client' => $transport,
        ]);
    }

    private function probe(?\Closure $client, ?\Closure $backlog): HealthProbe
    {
        $logger = new class ($this->logged) extends AbstractLogger {
            public function __construct(private CallLog $logged)
            {
            }

            /** @param array<array-key, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged->record(['line' => $level . ' ' . $message . ' ' . json_encode($context)]);
            }
        };
        return new HealthProbe($client, $backlog, $logger);
    }

    public function testAHealthyServerAndAnEmptyJournalAreOk(): void
    {
        $status = $this->probe(
            $this->typesense(200, '{"ok":true}'),
            static fn(): array => ['pending' => 0, 'oldest_seconds' => null],
        )->check();

        self::assertSame([
            'ok' => true,
            'typesense' => ['reachable' => true],
            'changes' => ['readable' => true, 'pending' => 0, 'oldest_pending_seconds' => null],
        ], $status);
        self::assertSame([['path' => '/health']], $this->requests->entries);
        self::assertSame(0, $this->logged->count());
    }

    public function testABacklogIsReportedWithoutBeingAnOutage(): void
    {
        $status = $this->probe(
            $this->typesense(200, '{"ok":true}'),
            static fn(): array => ['pending' => 42, 'oldest_seconds' => 900],
        )->check();

        self::assertTrue($status['ok']);
        self::assertSame(['readable' => true, 'pending' => 42, 'oldest_pending_seconds' => 900], $status['changes']);
    }

    public function testAnUnreachableServerIsReportedWithoutItsDetail(): void
    {
        $status = $this->probe(
            $this->typesense(200, '', new RuntimeException('cURL error 7: Failed to connect to typesense.internal port 8108')),
            static fn(): array => ['pending' => 0, 'oldest_seconds' => null],
        )->check();

        self::assertFalse($status['ok']);
        self::assertFalse($status['typesense']['reachable']);
        $public = (string) json_encode($status);
        self::assertStringNotContainsString('typesense.internal', $public);
        self::assertStringNotContainsString('8108', $public);
        self::assertStringNotContainsString('secret-admin-key', $public);
        self::assertSame(1, $this->logged->count());
        self::assertStringContainsString('typesense.internal', (string) $this->logged->entries[0]['line'], 'the cause is logged');
    }

    public function testAServerThatIsUpButNotReadyIsUnreachable(): void
    {
        // Typesense answers 503 {"ok":false} while it loads its data.
        $status = $this->probe(
            $this->typesense(503, '{"ok":false}'),
            static fn(): array => ['pending' => 0, 'oldest_seconds' => null],
        )->check();

        self::assertFalse($status['typesense']['reachable']);
        self::assertFalse($status['ok']);
    }

    public function testAMissingSecretIsUnreachableNotAnError(): void
    {
        $status = $this->probe(
            static fn(): TypesenseClient => throw new RuntimeException('Typesense API key not found. Expected secret file at /run/secrets/typesense_api_key'),
            static fn(): array => ['pending' => 0, 'oldest_seconds' => null],
        )->check();

        self::assertFalse($status['typesense']['reachable']);
        self::assertStringNotContainsString('/run/secrets', (string) json_encode($status));
    }

    public function testAnUnreadableJournalIsNotOk(): void
    {
        $status = $this->probe(
            $this->typesense(200, '{"ok":true}'),
            static fn(): array => throw new RuntimeException("Table 'omeka.iwac_search_change' doesn't exist"),
        )->check();

        self::assertFalse($status['ok']);
        self::assertTrue($status['typesense']['reachable']);
        self::assertSame(['readable' => false, 'pending' => null, 'oldest_pending_seconds' => null], $status['changes']);
        self::assertStringNotContainsString('omeka', (string) json_encode($status));
    }

    public function testNothingWiredReportsUnhealthyRatherThanPretending(): void
    {
        $status = $this->probe(null, null)->check();

        self::assertFalse($status['ok']);
        self::assertFalse($status['typesense']['reachable']);
        self::assertFalse($status['changes']['readable']);
    }

    public function testAClockSkewNeverReportsANegativeAge(): void
    {
        $status = $this->probe(
            $this->typesense(200, '{"ok":true}'),
            static fn(): array => ['pending' => 1, 'oldest_seconds' => -3],
        )->check();

        self::assertSame(0, $status['changes']['oldest_pending_seconds']);
    }
}

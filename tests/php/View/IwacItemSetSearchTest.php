<?php
declare(strict_types=1);

namespace IwacSearch\Tests\View;

use IwacSearch\Search\InitialResponseRenderer;
use IwacSearch\Tests\Support\CallLog;
use IwacSearch\View\Helper\IwacItemSetSearch;
use Laminas\View\Renderer\PhpRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Typesense\Client as TypesenseClient;
use Typesense\MultiSearch;

/**
 * The theme renders its own ledger whenever this helper returns '', so every
 * failure must come back as '' — never an exception, never an empty search
 * block — and a covered set must come back as the mount, with core's count
 * asked for in a way that leaves the ledger's shared paginator alone.
 */
#[CoversClass(IwacItemSetSearch::class)]
final class IwacItemSetSearchTest extends TestCase
{
    private CallLog $sent;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $apiCalls = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $partials = [];

    protected function setUp(): void
    {
        $this->sent = new CallLog();
        $this->apiCalls = [];
        $this->partials = [];
        unset($_SERVER['QUERY_STRING']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['QUERY_STRING']);
    }

    /**
     * @param array<string, mixed>|\Throwable $typesense one multi_search result, or a throwable
     */
    private function search(array|\Throwable $typesense, int|\Throwable $coreCount, ?object $site = null): string
    {
        $client = (new \ReflectionClass(TypesenseClient::class))->newInstanceWithoutConstructor();
        $client->multiSearch = new class ($typesense, $this->sent) extends MultiSearch {
            /** @param array<string, mixed>|\Throwable $response */
            public function __construct(private array|\Throwable $response, private CallLog $sent)
            {
            }

            /**
             * @param  array<string, mixed> $searches
             * @param  array<string, mixed> $queryParameters
             * @return array<string, mixed>
             */
            public function perform(array $searches, array $queryParameters = []): array
            {
                $this->sent->record($searches);
                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }
                return $this->response;
            }
        };
        $helper = new IwacItemSetSearch(new InitialResponseRenderer(clientFactory: static fn(): TypesenseClient => $client));
        $helper->setView($this->view($coreCount, $site));
        return $helper(new class {
            public function id(): int
            {
                return 2200;
            }
        });
    }

    private function view(int|\Throwable $coreCount, ?object $site): PhpRenderer
    {
        $test = $this;
        $recorder = new class {
            /** @param list<mixed> $args */
            public function __call(string $name, array $args): self
            {
                return $this;
            }
        };
        return new class ($test, $coreCount, $site, $recorder) extends PhpRenderer {
            public function __construct(
                private readonly IwacItemSetSearchTest $test,
                private readonly int|\Throwable $coreCount,
                private readonly ?object $site,
                private readonly object $recorder,
            ) {
            }

            public function currentSite(): ?object
            {
                return $this->site;
            }

            public function api(): object
            {
                return new class ($this->test, $this->coreCount) {
                    public function __construct(private readonly IwacItemSetSearchTest $test, private readonly int|\Throwable $count)
                    {
                    }

                    /** @param array<string, mixed> $query */
                    public function search(string $resource, array $query = []): object
                    {
                        $this->test->recordApi($resource, $query);
                        if ($this->count instanceof \Throwable) {
                            throw $this->count;
                        }
                        return new class ($this->count) {
                            public function __construct(private readonly int $count)
                            {
                            }

                            public function getTotalResults(): int
                            {
                                return $this->count;
                            }
                        };
                    }
                };
            }

            /** @param array<string, mixed> $values */
            public function partial(string $name, array $values = []): string
            {
                $this->test->recordPartial($name, $values);
                return '<section>mounted</section>';
            }

            public function assetUrl(string $file, ?string $module = null): string
            {
                return '/modules/' . $module . '/asset/' . $file;
            }

            public function headLink(): object
            {
                return $this->recorder;
            }

            public function headScript(): object
            {
                return $this->recorder;
            }
        };
    }

    /** @param array<string, mixed> $query */
    public function recordApi(string $resource, array $query): void
    {
        $this->apiCalls[] = [$resource, $query];
    }

    /** @param array<string, mixed> $values */
    public function recordPartial(string $name, array $values): void
    {
        $this->partials[] = [$name, $values];
    }

    /** @return array<string, mixed> */
    private static function found(int $found): array
    {
        return ['results' => [['hits' => [], 'found' => $found, 'page' => 1]]];
    }

    private static function site(): object
    {
        return new class {
            public function id(): int
            {
                return 1;
            }

            public function slug(): string
            {
                return 'westafrica';
            }
        };
    }

    public function testACoveredSetMountsTheSurfaceWithItsFirstPage(): void
    {
        self::assertSame('<section>mounted</section>', $this->search(self::found(25), 25, self::site()));

        self::assertSame('is_public:=true && item_set_ids:=[2200]', $this->sent->entries[0]['searches'][0]['filter_by']);
        [$name, $values] = $this->partials[0];
        self::assertSame('common/iwac-item-set-search', $name);
        self::assertSame('set', $values['block_id']);
        self::assertSame(25, $values['bootstrap']['initial_response']['found']);
    }

    public function testCoresCountIsAskedOfTheSiteWithoutTouchingTheSharedPaginator(): void
    {
        $this->search(self::found(25), 25, self::site());

        [$resource, $query] = $this->apiCalls[0];
        self::assertSame('items', $resource);
        self::assertSame(['item_set_id' => 2200, 'limit' => 1, 'site_id' => 1], $query);
        self::assertArrayNotHasKey('page', $query, "page/per_page would reconfigure the ledger's Omeka\\Paginator");
        self::assertArrayNotHasKey('per_page', $query);
    }

    public function testAUrlCarryingTheSurfacesStateSkipsTheSnapshot(): void
    {
        // The client adopts a snapshot only for the pristine state.
        $_SERVER['QUERY_STRING'] = 'bset.f.type_s=article&page=2';
        self::assertNotSame('', $this->search(self::found(25), 25));
        self::assertArrayNotHasKey('initial_response', $this->partials[0][1]['bootstrap']);

        // The theme's own parameters are not the surface's state.
        $_SERVER['QUERY_STRING'] = 'page=2&lr_q=ramadan';
        $this->search(self::found(25), 25);
        self::assertArrayHasKey('initial_response', $this->partials[1][1]['bootstrap']);
    }

    public function testEveryFailureKeepsTheThemesListing(): void
    {
        self::assertSame('', $this->search(self::found(0), 244), 'an authority set');
        self::assertSame('', $this->search(self::found(40), 47), "an editor's count with private items");
        self::assertSame('', $this->search(new RuntimeException('connection refused'), 25), 'Typesense down');
        self::assertSame('', $this->search(['results' => [['error' => 'Not found', 'code' => 404]]], 25), 'a search error');
        self::assertSame('', $this->search(self::found(25), new RuntimeException('database gone')), 'core failing');
        self::assertSame([], $this->partials, 'nothing was mounted');
    }
}

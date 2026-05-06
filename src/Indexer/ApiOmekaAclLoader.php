<?php
declare(strict_types=1);

namespace IwacSearch\Indexer;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-process ACL loader: paginates Omeka items via the Omeka\Api\Manager
 * service, never touching HTTP.
 *
 * Why this is necessary:
 *   - {@see OmekaAclLoader} hits Omeka's public REST API over HTTP, which
 *     works from a developer machine but fails inside a docker container
 *     trying to reach its own public DNS (`islam.zmo.de`). Symptom seen
 *     in practice: `HTTP 0 Failed to connect to islam.zmo.de port 443`.
 *   - From inside an Omeka job we have direct access to the in-process
 *     Api Manager. No network, no DNS, no firewall — just a database
 *     hop per page. Order-of-magnitude faster than HTTP and immune to
 *     the container's egress configuration.
 *
 * Important: the job runs in admin context, so `$api->search('items')`
 * returns ALL items including private ones. We filter explicitly via
 * `$item->isPublic()` rather than relying on the API to pre-filter.
 *
 * Same public contract as {@see OmekaAclLoader} (both implement
 * {@see OmekaAclLoaderInterface}) so {@see Reindexer} doesn't care
 * which backend it gets.
 */
final class ApiOmekaAclLoader implements OmekaAclLoaderInterface
{
    /** @var array<int, true>|null  Set semantics: keyed by public o:id */
    private ?array $publicIds = null;

    public function __construct(
        private readonly ApiManager $api,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $perPage = 100
    ) {
    }

    public function prime(): void
    {
        if ($this->publicIds !== null) {
            return;
        }

        $this->logger->info('Fetching public item IDs via Omeka ApiManager');

        $ids = [];
        $page = 1;
        while (true) {
            $response = $this->api->search('items', [
                'per_page' => $this->perPage,
                'page'     => $page,
            ]);
            /** @var list<ItemRepresentation> $items */
            $items = $response->getContent();
            if ($items === []) {
                break;
            }
            foreach ($items as $item) {
                if ($item->isPublic()) {
                    $ids[(int) $item->id()] = true;
                }
            }
            // Last-page detection: a partial page means we're done.
            if (count($items) < $this->perPage) {
                break;
            }
            $page++;
        }

        $this->publicIds = $ids;
        $this->logger->info('Public item IDs cached', [
            'count' => count($ids),
            'pages' => $page,
        ]);
    }

    public function isPublic(int $omekaId): bool
    {
        if ($this->publicIds === null) {
            $this->prime();
        }
        /** @var array<int, true> $ids — primed above */
        $ids = $this->publicIds;
        return isset($ids[$omekaId]);
    }

    public function size(): int
    {
        return $this->publicIds === null ? 0 : count($this->publicIds);
    }
}

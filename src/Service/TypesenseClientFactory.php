<?php
declare(strict_types=1);

namespace IwacSearch\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Builds a configured Typesense\Client.
 *
 * The admin API key is read from /run/secrets/typesense_api_key (mounted by
 * IWAC-docker as a Docker secret). It is NEVER read from app config or env
 * vars in production — keeping it as a file means it's not in the process
 * environment, not in `docker inspect`, and not in any error log dump.
 *
 * For local dev outside Docker, set IWAC_TYPESENSE_API_KEY in the env as a
 * fallback. This is intentionally less convenient — production should use
 * the secret file path.
 */
class TypesenseClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TypesenseClient
    {
        $config = $container->get('Config')['iwac_search']['typesense'] ?? [];

        $host     = $config['host']     ?? 'typesense';
        $port     = (int) ($config['port'] ?? 8108);
        $protocol = $config['protocol'] ?? 'http';

        $apiKey = $this->readApiKey($config['api_key_file'] ?? '/run/secrets/typesense_api_key');

        return new TypesenseClient([
            'api_key'         => $apiKey,
            'nodes'           => [[
                'host'     => $host,
                'port'     => (string) $port,
                'protocol' => $protocol,
            ]],
            'connection_timeout_seconds' => 10,
        ]);
    }

    /**
     * Resolve the API key with three tiers:
     *   1. Docker secret file (production)
     *   2. IWAC_TYPESENSE_API_KEY env var (local dev fallback)
     *   3. throw — never silently mint an empty key
     */
    private function readApiKey(string $secretPath): string
    {
        if (is_readable($secretPath)) {
            $key = trim((string) file_get_contents($secretPath));
            if ($key !== '') {
                return $key;
            }
        }

        $envKey = getenv('IWAC_TYPESENSE_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }

        throw new RuntimeException(sprintf(
            'IwacSearch: Typesense API key not found. Expected secret file at %s '
            . 'or IWAC_TYPESENSE_API_KEY env var.',
            $secretPath
        ));
    }
}

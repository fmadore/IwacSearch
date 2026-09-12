<?php
declare(strict_types=1);
require __DIR__ . '/contracts.php';

use IwacSearch\Indexer\ReindexOrchestrator;

// Mutate the source after the article stream has finished. The real journal must
// replay both changes before either new alias becomes visible.
$logger = new class($db, $listener) extends Psr\Log\AbstractLogger {
    private bool $mutated = false;
    public function __construct(private $db, private $listener) {}
    public function log($level, $message, array $context = []): void {
        echo "$level $message\n";
        if ($message !== 'Subset indexed' || ($context['subset'] ?? '') !== 'articles' || $this->mutated) return;
        $this->mutated = true;
        foreach ([1 => 'update', 2 => 'delete'] as $id => $operation) {
            $request = (new Omeka\Api\Request($operation, 'items'))->setId($id);
            $event = new Laminas\EventManager\Event('api.execute.pre', null, ['request' => $request]);
            $this->listener->onBeforeWrite($event, 'items');
            if ($operation === 'update') $this->db->executeStatement('UPDATE resource SET is_public=0 WHERE id=?', [$id]);
            else $this->db->executeStatement('DELETE FROM resource WHERE id=?', [$id]);
            $this->listener->onAfterWrite($event, 'items');
        }
    }
};
$previous = $ops->resolveAliasTarget('iwac_current');
$stats = (new ReindexOrchestrator($client, $db, dirname(__DIR__, 2), $logger))->run();
check($stats['catch_up']['ok'] && $stats['catch_up']['items'] === 2, 'rebuild replays changes before cutover');
check($ops->document('iwac_current', '1')['is_public'] === false && $ops->document('iwac_current', '2') === null, 'cutover includes privatization and deletion');
check($ops->documentCount($previous) > 0, 'previous collection retained after promotion');
check($stats['indexed'] === 1 && $stats['verified_counts']['article'] === 1, 'final source and server subset counts reconciled');
check($ops->documentCount('iwac_index_current') === 1, 'entity generation rebuilt from final source');

// An import outage specifically during replay must not move either alias.
$db->executeStatement('UPDATE resource SET is_public=1 WHERE id=1');
$transport = new class implements Psr\Http\Client\ClientInterface {
    public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface {
        if (str_contains($request->getUri()->getPath(), '/import')) {
            foreach (explode("\n", trim((string) $request->getBody())) as $line) {
                $doc = json_decode($line, true);
                if (($doc['id'] ?? '') === '1' && ($doc['is_public'] ?? true) === false) throw new RuntimeException('Injected replay outage');
            }
        }
        return (new GuzzleHttp\Client(['timeout' => 120]))->sendRequest($request);
    }
};
$failingClient = new Typesense\Client(['api_key' => 'iwac-disposable-test-key', 'nodes' => [['host' => '127.0.0.1', 'port' => '18108', 'protocol' => 'http']], 'num_retries' => 0, 'client' => $transport]);
$beforeContent = $ops->resolveAliasTarget('iwac_current');
$beforeIndex = $ops->resolveAliasTarget('iwac_index_current');
$loggerClass = $logger::class;
try {
    (new ReindexOrchestrator($failingClient, $db, dirname(__DIR__, 2), new $loggerClass($db, $listener)))->run();
    throw new LogicException('Replay failure went unnoticed');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Injected replay outage'), 'replay failure reaches orchestrator');
}
check($ops->resolveAliasTarget('iwac_current') === $beforeContent && $ops->resolveAliasTarget('iwac_index_current') === $beforeIndex, 'failed replay preserves both aliases');
check($journal->status()['pending'] > 0, 'failed replay remains durably retryable');
(new IwacSearch\Indexer\ChangeDrainer($db, $incremental))->run();
check($journal->status()['pending'] === 0, 'recovery drains failed changes');

$client->collections->create(['name' => 'iwac_v99_20180101_000000_aaaaaaaaaaaa', 'fields' => [['name' => 'is_public', 'type' => 'bool']]]);
$client->aliases->upsert('protected_test_alias', ['collection_name' => 'iwac_v99_20180101_000000_aaaaaaaaaaaa']);
$client->collections->create(['name' => 'iwac_v99_20170101_000000_aaaaaaaaaaaa', 'fields' => [['name' => 'is_public', 'type' => 'bool']]]);
$removed = (new IwacSearch\Indexer\CollectionRetention($db, $client))->prune();
check(in_array('iwac_v99_20170101_000000_aaaaaaaaaaaa', $removed, true), 'retention removes old unreferenced generations');
check(!in_array('iwac_v99_20180101_000000_aaaaaaaaaaaa', $removed, true), 'retention protects every alias target');
echo "Bulk integration passed.\n";

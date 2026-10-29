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
$previousIndex = $ops->resolveAliasTarget('iwac_index_current');
// A rebuild killed mid-import leaves its build recorded as `building`.
$ledger = new IwacSearch\Indexer\DatabaseGenerationLedger($db);
$orphan = 'iwac_v99_20210101_000000_bbbbbbbbbbbb';
$ledger->recordBuilding($orphan, 'iwac_current');
$client->collections->create(['name' => $orphan, 'fields' => [['name' => 'is_public', 'type' => 'bool']]]);
$stats = (new ReindexOrchestrator($client, $db, dirname(__DIR__, 2), $logger))->run();
// The two edits land during the content stream, so they are replayed in the
// unlocked phase; nothing changed after the second watermark.
check($stats['catch_up']['ok'] && $stats['catch_up']['unlocked_items'] === 2 && $stats['catch_up']['items'] === 0, 'rebuild replays changes before cutover');
check(isset($stats['gate_held_seconds']), 'cutover reports how long it held the write gate');
check($ops->document('iwac_current', '1')['is_public'] === false && $ops->document('iwac_current', '2') === null, 'cutover includes privatization and deletion');
check($ops->documentCount($previous) > 0, 'previous collection retained after promotion');
check($stats['indexed'] === 1 && $stats['verified_counts']['article'] === 1, 'final source and server subset counts reconciled');
check($ops->documentCount('iwac_index_current') === 1, 'entity generation rebuilt from final source');
check($stats['generations']['recovered'] === [$orphan] && !in_array($orphan, array_column($client->collections->retrieve(), 'name'), true), 'rebuild start drops the build an interrupted rebuild left');
$first = ['content' => $stats['collection'], 'index' => $stats['index']['collection']];
$expectedLedger = [
    $first['content'] => ['alias' => 'iwac_current', 'status' => 'live'],
    $first['index'] => ['alias' => 'iwac_index_current', 'status' => 'live'],
    $previous => ['alias' => 'iwac_current', 'status' => 'previous'],
    $previousIndex => ['alias' => 'iwac_index_current', 'status' => 'previous'],
];
ksort($expectedLedger);
$recorded = static function () use ($ledger): array {
    $rows = $ledger->generations();
    ksort($rows);
    return $rows;
};
check($recorded() === $expectedLedger, 'ledger records both live generations and their rollback targets');

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
$generations = array_column($client->collections->retrieve(), 'name');
check(count(preg_grep('/^iwac_v[0-9]+_/', $generations)) === 2, 'failed build is dropped; live and previous generations remain');
check($ledger->generations('building') === [] && $recorded() === $expectedLedger, 'failed build leaves nothing in the ledger');
check($journal->status()['pending'] > 0, 'failed replay remains durably retryable');
(new IwacSearch\Indexer\ChangeDrainer($db, $incremental))->run();
check($journal->status()['pending'] === 0, 'recovery drains failed changes');

// The next promotion keeps live + one previous per alias and deletes the rest.
$stats = (new ReindexOrchestrator($client, $db, dirname(__DIR__, 2), new $loggerClass($db, $listener)))->run();
$removedAtPromotion = $stats['generations']['removed'];
sort($removedAtPromotion);
$expectedRemoved = [$previous, $previousIndex];
sort($expectedRemoved);
check($removedAtPromotion === $expectedRemoved && !array_intersect($expectedRemoved, array_column($client->collections->retrieve(), 'name')), 'promotion deletes the generations it retires');
$rollback = $ledger->generations('previous');
check(count($rollback) === 2 && isset($rollback[$first['content']], $rollback[$first['index']]), 'the replaced generations become the rollback targets');

$create = static fn (string $name) => $client->collections->create(['name' => $name, 'fields' => [['name' => 'is_public', 'type' => 'bool']]]);
// Collections the ledger never recorded (built before 3.27) keep the old rule.
$create('iwac_v99_20180101_000000_aaaaaaaaaaaa');
$client->aliases->upsert('protected_test_alias', ['collection_name' => 'iwac_v99_20180101_000000_aaaaaaaaaaaa']);
$create('iwac_v99_20170101_000000_aaaaaaaaaaaa');
$create('iwac_v99_20190101_000000_aaaaaaaaaaaa');
// Without a recorded rollback target, the newest unrecorded one is kept as it.
$client->collections[$first['index']]->delete();
$ledger->forget($first['index']);
$create('iwac_index_v99_20170101_000000_aaaaaaaaaaaa');
$create('iwac_index_v99_20190101_000000_aaaaaaaaaaaa');
// A retired generation a promotion could not delete, and an orphaned build.
$create('iwac_v99_20160101_000000_cccccccccccc');
$ledger->recordBuilding('iwac_v99_20160101_000000_cccccccccccc', 'iwac_current');
$ledger->mark('iwac_v99_20160101_000000_cccccccccccc', 'retired');
$create('iwac_v99_20260101_000000_dddddddddddd');
$ledger->recordBuilding('iwac_v99_20260101_000000_dddddddddddd', 'iwac_current');
$removed = (new IwacSearch\Indexer\CollectionRetention($db, $client))->prune();
check(in_array('iwac_v99_20160101_000000_cccccccccccc', $removed, true) && in_array('iwac_v99_20260101_000000_dddddddddddd', $removed, true), 'retention removes retired and orphaned ledger generations whatever their age');
check(in_array('iwac_v99_20170101_000000_aaaaaaaaaaaa', $removed, true) && in_array('iwac_v99_20190101_000000_aaaaaaaaaaaa', $removed, true), 'retention removes old unrecorded generations once a rollback target is recorded');
check(in_array('iwac_index_v99_20170101_000000_aaaaaaaaaaaa', $removed, true) && !in_array('iwac_index_v99_20190101_000000_aaaaaaaaaaaa', $removed, true), 'retention keeps the newest unrecorded generation while no rollback target is recorded');
check(!in_array('iwac_v99_20180101_000000_aaaaaaaaaaaa', $removed, true), 'retention protects every alias target');
check(!array_intersect([$first['content'], $stats['collection'], $stats['index']['collection']], $removed), 'retention keeps the live and previous generations');
// Uninstall drops the tables and names the collections it leaves behind.
$uninstallLog = new Laminas\Log\Writer\Mock();
$services->setService('Omeka\Logger', (new Laminas\Log\Logger())->addWriter($uninstallLog));
$liveContent = (string) $ops->resolveAliasTarget('iwac_current');
$module->uninstall($services);
$tables = $db->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'iwac_search_%'");
$notices = implode("\n", array_column($uninstallLog->events, 'message'));
check($tables === [] && str_contains($notices, $liveContent) && $ops->documentCount('iwac_current') > 0, 'uninstall drops the module tables, leaves Typesense alone and logs the collections it created');
echo "Bulk integration passed.\n";

<?php
declare(strict_types=1);

// This harness intentionally accepts no production host, database, or credential.
// Run setup-local.sh first. Omeka's vendor must load before the module's vendor.
$omeka = '/tmp/iwac-integration/omeka-s';
require $omeka . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($omeka): void {
    if (str_starts_with($class, 'Omeka\\')) {
        $file = $omeka . '/application/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) require_once $file;
    }
});

use Doctrine\DBAL\DriverManager;
use IwacSearch\Indexer\{ChangeDrainer, ChangeJournal, CollectionOps, CollectionRetention, CountryResolver, DatabaseLock, EntityAuthority, IncrementalIndexer, ItemEventListener, OmekaSourceReader};
use IwacSearch\Indexer\Mapper\MapperRegistry;
use IwacSearch\Search\{PublicSearchPolicy, ScopeFilters, TypesenseSearchKeyProvider};
use Typesense\Client;

function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$params = ['driver' => 'pdo_mysql', 'user' => 'root', 'dbname' => 'iwac_search_test', 'unix_socket' => '/run/mysqld/mysqld.sock', 'charset' => 'utf8mb4'];
$db = DriverManager::getConnection($params);
check($db->executeQuery('SELECT DATABASE()')->fetchOne() === 'iwac_search_test', 'isolated database');
$db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
foreach ($db->fetchFirstColumn('SHOW TABLES') as $table) $db->executeStatement('DROP TABLE `' . str_replace('`', '``', $table) . '`');
foreach (explode(';', file_get_contents($omeka . '/application/data/install/schema.sql')) as $sql) {
    if (trim($sql) !== '') $db->executeStatement($sql);
}
$db->executeStatement('SET FOREIGN_KEY_CHECKS=0'); // Sparse fixtures retain the official column/index contracts.
ChangeJournal::install($db);
$db->insert('vocabulary', ['id' => 1, 'namespace_uri' => 'http://purl.org/dc/terms/', 'prefix' => 'dcterms', 'label' => 'DC']);
$db->insert('vocabulary', ['id' => 2, 'namespace_uri' => 'http://purl.org/ontology/bibo/', 'prefix' => 'bibo', 'label' => 'BIBO']);
foreach ([1 => [1, 'title'], 2 => [1, 'subject'], 3 => [1, 'abstract'], 4 => [2, 'content'], 5 => [1, 'alternative']] as $id => [$vocab, $name]) {
    $db->insert('property', ['id' => $id, 'vocabulary_id' => $vocab, 'local_name' => $name, 'label' => $name]);
}
function item(int $id, int $class, string $title, bool $public = true): void {
    global $db;
    $db->insert('resource', ['id' => $id, 'resource_type' => Omeka\Entity\Item::class, 'resource_class_id' => $class, 'title' => $title, 'is_public' => (int) $public, 'created' => '2026-01-01 00:00:00']);
    $db->insert('item', ['id' => $id]);
    value($id, 1, $title);
}
function value(int $id, int $property, ?string $text, bool $public = true, ?int $target = null): void {
    global $db;
    $db->insert('`value`', ['resource_id' => $id, 'property_id' => $property, '`value`' => $text, 'value_resource_id' => $target, 'is_public' => (int) $public, 'type' => $target ? 'resource:item' : 'literal']);
}
item(1, 36, 'Public article'); item(2, 36, 'Private article', false);
item(10, 94, 'Authority'); item(11, 94, 'Hidden authority', false);
value(1, 2, null, true, 10); value(1, 2, null, true, 11);
value(1, 3, 'CONFIDENTIAL ABSTRACT', false);
value(10, 5, 'CONFIDENTIAL ALIAS', false);
value(1, 4, str_repeat('context ', 80) . 'needle ' . str_repeat('context ', 80), false);
$reader = new OmekaSourceReader($db);
$authority = new EntityAuthority();
$registry = MapperRegistry::default($authority, new CountryResolver(dirname(__DIR__, 2) . '/data/newspaper-countries.json'));
$authority->build($reader);
$row = $reader->loadResources([1], $registry->allReadTerms())[1];
$doc = $registry->get('articles')->map($row['item'], $row['values'], null);
check(!str_contains(json_encode($doc), 'CONFIDENTIAL') && !str_contains(json_encode($doc), 'Hidden authority'), 'private values and linked private authorities excluded');
check(str_contains($doc['ocr_text'], 'needle') && !$doc['has_fulltext'], 'restricted OCR remains searchable with visibility flag');
$db->executeStatement('UPDATE `value` SET is_public=0 WHERE resource_id=10 AND property_id=1');
check($reader->loadResources([10], [])[10]['item']['title'] === '', 'cached private resource title never leaks');
$db->executeStatement('UPDATE `value` SET is_public=1 WHERE resource_id=10 AND property_id=1');

$client = new Client(['api_key' => 'iwac-disposable-test-key', 'nodes' => [['host' => '127.0.0.1', 'port' => '18108', 'protocol' => 'http']], 'num_retries' => 0]);
$ops = new CollectionOps(fn() => $client);
foreach ($client->collections->retrieve() as $c) $client->collections[$c['name']]->delete();
foreach (['iwac_v99_20200101_000000_aaaaaaaaaaaa', 'iwac_index_v99_20200101_000000_aaaaaaaaaaaa'] as $name) {
    $client->collections->create(['name' => $name, 'fields' => [['name' => '.*', 'type' => 'auto', 'optional' => true], ['name' => 'is_public', 'type' => 'bool'], ['name' => 'country_ss', 'type' => 'string[]', 'facet' => true, 'optional' => true]]]);
}
$client->aliases->upsert('iwac_current', ['collection_name' => 'iwac_v99_20200101_000000_aaaaaaaaaaaa']);
$client->aliases->upsert('iwac_index_current', ['collection_name' => 'iwac_index_v99_20200101_000000_aaaaaaaaaaaa']);
$incremental = new IncrementalIndexer($ops, $reader, $registry, $authority);
$incremental->reindexItems([1, 2, 10, 11]);
$doc['embedding'] = [0.1, 0.2];
$ops->flushBatch('iwac_current', [$doc]);
check($ops->document('iwac_index_current', '10')['title'] === 'Authority', 'entity metadata indexed incrementally');

$journal = new ChangeJournal($db);
$listener = new ItemEventListener($db, static function (): void {});
require_once dirname(__DIR__, 2) . '/Module.php';
$services = new Laminas\ServiceManager\ServiceManager(['services' => ['Omeka\Connection' => $db, ItemEventListener::class => $listener]]);
$module = new IwacSearch\Module();
$module->setServiceLocator($services);
$module->install($services);
$module->upgrade('3.18.0', '3.19.0', $services);
$shared = new Laminas\EventManager\SharedEventManager();
$module->attachListeners($shared);
$events = new Laminas\EventManager\EventManager($shared, [Omeka\Api\Adapter\ItemAdapter::class]);
$events->trigger('api.execute.pre', null, ['request' => new Omeka\Api\Request('read', 'items')]);
check(true, 'real Omeka module lifecycle and read-event wiring');
$request = (new Omeka\Api\Request('update', 'items'))->setId(10);
$event = new Laminas\EventManager\Event('api.execute.pre', null, ['request' => $request]);
$listener->onBeforeWrite($event, 'items');
$db->executeStatement("UPDATE `value` SET value='Renamed authority' WHERE resource_id=10 AND property_id=1");
$listener->onAfterWrite($event, 'items');
check(in_array(1, array_column($journal->pending(), 'item_id'), true), 'real Omeka update request journals reverse dependencies');
(new ChangeDrainer($db, $incremental))->run();
check(str_contains(json_encode($ops->document('iwac_current', '1')), 'Renamed authority'), 'authority rename refreshes linked content');

// Raw entities are the real api.execute.post payload, before representation conversion.
$entity = new Omeka\Entity\Item();
$idProperty = new ReflectionProperty(Omeka\Entity\Resource::class, 'id');
$idProperty->setValue($entity, 10);
$create = new Omeka\Api\Request('create', 'items');
$response = new Omeka\Api\Response(); $response->setContent($entity);
$createEvent = new Laminas\EventManager\Event('api.execute.post', null, ['request' => $create, 'response' => $response]);
$listener->onBeforeWrite($createEvent, 'items'); $listener->onAfterWrite($createEvent, 'items');
check(in_array(10, array_column($journal->pending(), 'item_id'), true), 'raw create response journals generated ID');

$delete = (new Omeka\Api\Request('delete', 'items'))->setId(10);
$deleteEvent = new Laminas\EventManager\Event('api.execute.pre', null, ['request' => $delete]);
$listener->onBeforeWrite($deleteEvent, 'items');
$db->executeStatement('DELETE FROM resource WHERE id=10');
$db->executeStatement('DELETE FROM `value` WHERE value_resource_id=10 OR resource_id=10');
$listener->onAfterWrite($deleteEvent, 'items');
(new ChangeDrainer($db, $incremental))->run();
check($ops->document('iwac_index_current', '10') === null, 'deleted authority removed from entity index');
check(!str_contains(json_encode($ops->document('iwac_current', '1')), 'Renamed authority'), 'delete tombstone refreshes former referrers');
check($journal->status()['pending'] === 0, 'successful journal rows acknowledged');
$journal->append([1]);
$rows = $journal->pending(); $journal->append([1]); $journal->acknowledge(array_column($rows, 'id'));
check($journal->status()['pending'] === 1, 'acknowledgment cannot swallow a concurrent update');

$lock = new DatabaseLock($db, 'rebuild'); $lock->acquire();
$other = new DatabaseLock(DriverManager::getConnection($params), 'rebuild');
try { $other->acquire(); throw new LogicException('Second rebuild acquired lock'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'already running'), 'concurrent rebuild excluded'); }
$lock->release();
$publicationConnection = DriverManager::getConnection($params);
$publication = new DatabaseLock($publicationConnection, 'publish');
$publication->acquire();
check((new ChangeDrainer($db, $incremental))->run() === 0, 'duplicate worker yields without holding catalog saves');
$freeMutation = new DatabaseLock($publicationConnection, 'mutation');
check($freeMutation->tryAcquire(), 'busy worker releases its mutation gate');
$freeMutation->release(); $publication->release();

$ops->flushBatch('iwac_current', [['id' => '30', 'title_txt' => 'needle', 'is_public' => true, 'country_ss' => ['Bénin']], ['id' => '31', 'title_txt' => 'needle', 'is_public' => true, 'country_ss' => ['Togo']], ['id' => '32', 'title_txt' => 'needle', 'is_public' => false, 'country_ss' => ['Togo']]]);
$result = $client->collections['iwac_current']->documents->search(['q' => '*', 'filter_by' => ScopeFilters::combine('is_public:=true', 'country_ss:=Bénin || country_ss:=Togo')]);
check($result['found'] === 2, 'OR scope cannot bypass public guard on real Typesense');
$parent = $client->keys->create(['description' => 'Disposable public policy test', 'actions' => ['documents:search'], 'collections' => TypesenseSearchKeyProvider::DEFAULT_COLLECTION_SCOPE]);
$key = $client->keys->generateScopedSearchKey($parent['value'], ['filter_by' => 'is_public:=true', ...PublicSearchPolicy::parameters()]);
$public = new Client(['api_key' => $key, 'nodes' => [['host' => '127.0.0.1', 'port' => '18108', 'protocol' => 'http']], 'num_retries' => 0]);
$result = $public->collections['iwac_current']->documents->search(['q' => 'needle', 'query_by' => 'ocr_text', 'highlight_fields' => 'ocr_text', 'highlight_full_fields' => 'ocr_text', 'snippet_threshold' => 10000, 'highlight_affix_num_tokens' => 10000, 'exclude_fields' => '', 'include_fields' => '*']);
check($result['found'] === 1, 'alias-only parent authorizes live alias');
check(!isset($result['hits'][0]['document']['ocr_text']), 'scoped exclusions cannot be widened');
check(!isset($result['hits'][0]['document']['embedding']), 'public response omits embedding vectors');
check(str_contains(json_encode($result['hits'][0]['highlights'] ?? []), 'needle'), 'restricted OCR snippets still render');
check(strlen(json_encode($result['hits'][0]['highlights'] ?? [])) < 1000, 'scoped highlight limits resist full-field and oversized-snippet overrides');
try { $public->collections['iwac_v99_20200101_000000_aaaaaaaaaaaa']->documents->search(['q' => '*']); throw new LogicException('Old generation accessible'); }
catch (Typesense\Exceptions\RequestUnauthorized) { check(true, 'public key cannot search retained generation directly'); }
$client->keys[(string) $parent['id']]->delete();
echo "Integration contracts passed.\n";

<?php
// CLI-only transport double: this test never loads the native mailer.
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
define('DIR_FS_CATALOG', getcwd() . '/');
define('DIR_FS_TMP', getcwd() . '/tmp/');
define('CFG_PATH_TO_DOMPDF', 'includes/libs/dompdf/3.1.0/vendor/autoload.php');
define('CFG_PATH_TO_DOMPDF_FONTS', 'includes/libs/dompdf/fonts/');
define('DIR_WS_ATTACHMENTS', 'uploads/attachments/');
require __DIR__ . '/quote-workflow-store.php';
require_once __DIR__ . '/production-kanban-time.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
define('CFG_EMAIL_ADDRESS_FROM', 'workflow-fixture@example.invalid');
define('CFG_EMAIL_NAME_FROM', 'Workflow fixture');
$current_access_schema = [];
class users
{
    public static $allowed = true;
    public static $deniedCreates = [];
    public static $deniedViews = [];
    public static $transport = true;
    public static $calls = 0;
    public static $subject = null;
    public static $sender = null;
    public static $recipient = null;
    public static $body = null;
    public static $attachmentPath = null;
    public static $attachmentName = null;
    static function has_access_to_entity($entity, $action) { return self::$allowed && !($action === 'create' && in_array($entity, self::$deniedCreates, true)) && !($action === 'view' && in_array($entity, self::$deniedViews, true)); }
    static function get_entities_access_schema($entity, $group) { return ['view','create','update']; }
    static function has_access($action, $schema = null) { return self::$allowed && !in_array($action, ['action_with_assigned','update_creator']); }
    static function has_access_to_assigned_item($entity, $id) { return false; }
    static function send_email($options) {
        self::$calls++;
        self::$subject = $options['subject'];
        self::$sender = ['email' => $options['from'], 'name' => $options['from_name']];
        self::$recipient = $options['to'];
        self::$body = $options['body'];
        if (count($options['attachments'] ?? []) !== 1 || $options['html_layout'] !== false) {
            throw new RuntimeException('Expected one PDF attachment and standalone email layout.');
        }
        self::$attachmentPath = array_keys($options['attachments'])[0];
        self::$attachmentName = $options['attachments'][self::$attachmentPath];
        if (!is_readable(self::$attachmentPath) || substr(file_get_contents(self::$attachmentPath), 0, 5) !== '%PDF-') {
            throw new RuntimeException('Missing or invalid PDF attachment.');
        }
        if (($options['force_send_from'] ?? false) !== true) throw new RuntimeException('User sender override missing.');
        if (!in_array($options['to'], ['workflow-fixture@example.invalid', 'other-recipient@example.invalid'], true)) throw new RuntimeException('Unsafe recipient in fixture.');
        return self::$transport;
    }
}
class items
{
    static function add_access_query($entity, $query) { return ''; }
    static function add_access_query_for_parent_entities($entity) { return ''; }
}
class modules
{
    static function include_module($module, $type) {
        if ($type !== 'sms' || $module['module'] !== 'clicksend') throw new RuntimeException('Unexpected fixture SMS provider.');
    }
}
class clicksend
{
    public static $calls = 0;
    public static $transport = true;
    public static $message = null;
    function send($module, $phones, $message) {
        if (!in_array($phones, [['+351912345678'], ['+351967123456']], true) || users::$transport !== true || users::$calls === 0) {
            throw new RuntimeException('Unsafe SMS fixture or SMS called before confirmed email.');
        }
        self::$calls++;
        self::$message = $message;
        return self::$transport;
    }
}
class access_rules
{
    function __construct($entity, $row) {}
    function get_access_schema() { return ['view','create','update']; }
    static function has_add_buttons_access($entity, $parent) { return users::$allowed; }
}
$checks = 0;
function vdCheck($condition) { global $checks; if (!$condition) throw new RuntimeException('Check failed #' . ($checks + 1)); $checks++; }
function vdReject($callback) {
    try { $callback(); } catch (DomainException $error) { vdCheck(true); return; }
    throw new RuntimeException('Expected rejection.');
}
function vdImmutable($sql) {
    global $db;
    try { $db->query($sql); } catch (mysqli_sql_exception $error) {
        if ($error->getCode() !== 1644) throw $error;
        vdCheck(true);
        return;
    }
    throw new RuntimeException('Immutable native row was mutable.');
}
function vdFixture($entity, array $values) {
    global $db;
    $row = [];
    foreach ($db->query("SHOW COLUMNS FROM app_entity_$entity") as $column) {
        if ($column['Field'] === 'id') continue;
        $row[$column['Field']] = $column['Default'] ?? ($column['Null'] === 'YES' ? null :
            (preg_match('/^(int|bigint|decimal|float|double|tinyint)/', $column['Type']) ? 0 : ''));
    }
    $row = array_replace($row, $values, ['date_added' => time(), 'created_by' => 1]);
    $stmt = $db->prepare("INSERT INTO app_entity_$entity (`" . implode('`,`', array_keys($row)) . '`) VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')');
    $values = array_values($row);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    return (int) $db->insert_id;
}
function vdRemoveFixtureOrders($quote,$expectedName='ISOLATED WORKFLOW FIXTURE') {
    global $db;
    foreach ($db->query("SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=$quote") as $order) {
        $id = (int) $order['order_id'];
        $native = $db->query("SELECT field_1994 FROM app_entity_121 WHERE id=$id")->fetch_assoc();
        if (!$native || $native['field_1994'] !== $expectedName) throw new RuntimeException('Unsafe production fixture cleanup.');
        $db->query("DELETE v FROM app_entity_171_values v JOIN app_entity_171 t ON t.id=v.items_id JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=t.parent_item_id WHERE r.order_id=$id");
        $db->query("DELETE t FROM app_entity_171 t JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=t.parent_item_id WHERE r.order_id=$id");
        $db->query("DELETE a FROM app_vd_production_time_audit a JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=a.operation_id WHERE r.order_id=$id");
        $materialEntity = vd_component_material_schema($db)['entity'];
        $db->query("DELETE n FROM app_entity_$materialEntity n JOIN app_vd_component_material_rows r ON r.native_id=n.id WHERE r.order_id=$id");
        $db->query("DELETE FROM app_vd_component_material_rows WHERE order_id=$id");
        foreach ([161,172,170,158,145,159] as $entity) {
            if ($entity === 170) $db->query("DELETE v FROM app_entity_170_values v JOIN app_vd_quote_production_rows r ON r.native_id=v.items_id AND r.entity_id=170 WHERE r.order_id=$id AND r.quote_id=$quote");
            $db->query("DELETE n FROM app_entity_$entity n JOIN app_vd_quote_production_rows r ON r.native_id=n.id AND r.entity_id=$entity WHERE r.order_id=$id AND r.quote_id=$quote");
        }
        $db->query("DELETE FROM app_vd_production_component_inputs WHERE order_id=$id");
        $db->query("DELETE FROM app_vd_quote_production_rows WHERE order_id=$id AND quote_id=$quote");
        $db->query("DELETE FROM app_vd_quote_production_orders WHERE order_id=$id AND quote_id=$quote");
        $db->query("DELETE FROM app_entity_121 WHERE id=$id");
    }
}
if (defined('VD_PRODUCT_TEST_BOOTSTRAP')) return;
$quote = $customer = $component = $quantityId = null;
try {
    $customer = vdFixture(26, ['field_221' => 'ISOLATED WORKFLOW FIXTURE', 'field_228' => 'workflow-fixture@example.invalid', 'field_5121' => 1370, 'field_5125' => 1374, 'field_5124' => '10']);
    $quote = vdFixture(146, ['field_3923' => $customer, 'field_3925' => 'workflow-fixture@example.invalid', 'field_3926' => '912 345 678', 'field_3927' => 'ISOLATED WORKFLOW FIXTURE', 'field_2030' => 36]);
    $component = vdFixture(262, ['parent_item_id' => $quote, 'field_4218' => 210, 'field_4219' => 297,
        'field_4220' => 1565, 'field_4229' => 1255, 'field_4233' => 1, 'field_6110' => 1544, 'field_6291' => '18']);
    $progress = function () use ($db, $component) {
        return (int) $db->query("SELECT field_4241 FROM app_entity_262 WHERE id=$component")->fetch_row()[0];
    };
    vdCheck($progress() === 5);
    $quantityId = vdFixture(261, ['parent_item_id' => $quote, 'field_3938' => 100]);
    $explicit = vdFixture(276, ['parent_item_id' => $component, 'field_4287' => 32, 'field_6179' => '0']);
    $legacy = vdFixture(388, ['parent_item_id' => $component, 'field_6101' => $quantityId, 'field_6102' => 100, 'field_6106' => '{"legacy":true}']);
    $service = new VDQuoteWorkflow($db, ['id' => 1, 'group_id' => 0,
        'email' => 'sending-user@example.invalid', 'name' => 'Sending user']);
    vdReject(function () use ($service, $quote) { $service->document($quote, 0); });
    vdReject(function () use ($service, $quote) { $service->createProductionOrder($quote); });
    $calc = ['source' => 'dynamic', 'quantity' => 100, 'quantityId' => $quantityId, 'machineId' => 6, 'modeId' => 3748,
        'component' => ['id' => $component, 'width' => 210, 'height' => 297, 'pages' => 1],
        'parts' => [['plan' => ['width' => 720, 'height' => 510, 'poses' => 4, 'columns' => 2, 'rows' => 2, 'per_sheet' => 2,
            'sheet_width' => 720, 'sheet_height' => 1020]]],
        'results' => ['plates' => 4],
        'allOperations' => ['complete' => true, 'errors' => [], 'cost' => 10.0, 'sale' => 12.0,
            'sourceOperations' => [['operation_id' => 7, 'equipment' => 6]], 'lines' => [
                ['key' => 'printing', 'name' => 'Internal machine', 'operationIds' => [7], 'cost' => 10.0, 'sale' => 12.0]]]];
    $calc['allOperations']['lines'][] = ['key' => 'paper', 'name' => 'Fixture paper', 'cost' => 0.0, 'sale' => 0.0];
    $calc['parts'][0]['paperCalculation'] = ['paper' => ['id' => 2652, 'internal_id' => 1406, 'name' => 'Frozen fixture paper'], 'sheets' => 50];
    $calc['parts'][0]['plate'] = ['materialId' => 984, 'unitPrice' => 2.93];
    $calc['parts'][0]['result'] = ['plates' => 4, 'goodPlans' => 25, 'wastePlans' => 75];
    $calc['parts'][0]['allocation'] = [4];
    $calc['parts'][0]['operation'] = ['id' => 7];
    $calc['parts'][0]['machine'] = ['id' => 6];
    $calc['parts'][0]['modeId'] = 3748;
    $calc['rubrics'] = [['id' => 0, 'quantity' => 100, 'name' => 'ISOLATED WORKFLOW FIXTURE', 'code' => '']];
    $calc['parts'][0]['produced'] = [100];
    $calc['parts'][0]['runs'] = 25;
    $calc['allOperations']['lines'][0]['hours'] = 0.5;
    $calc['allOperations']['lines'][0]['quantity'] = 0.5;
    $calc['allOperations']['lines'][] = ['key' => 'plates', 'name' => 'Fixture plates', 'quantity' => 4, 'cost' => 0.0, 'sale' => 0.0];
    foreach ([1,2,3,9,10,18,43,44] as $op) $calc['allOperations']['lines'][] =
        ['key' => 'operation:' . $op, 'operationId' => $op, 'name' => 'Fixture operation #' . $op, 'cost' => 0.0, 'sale' => 0.0];
    $calc['allOperations']['sourceOperations'][] = [
        'id' => 1001, 'operation_id' => 1, 'equipment' => '11,16'];
    $calc['allOperations']['inputs'] = ['operation:1001' => ['equipment' => '16']];
    $calc['allOperations']['lines'][0]['calculatedCost'] = 8.0;
    $calc['allOperations']['lines'][0]['costOverride'] = 10.0;
    $calc['allOperations']['inputs']['commercial'] = ['markup' => 20, 'lines' => [], 'costs' => ['printing' => 10]];
    $operationCount = 9;
    $input = ['request_id' => 'fixture-' . bin2hex(random_bytes(16)), 'quantity_id' => $quantityId, 'calculation' => $calc, 'expected_version' => 0];
    $v1 = $service->save($quote, $component, $input);
    vdCheck($progress() === 5);
    $stored = $db->query('SELECT payload,hash,request_hash FROM app_vd_quote_versions WHERE id=' . (int) $v1['workflow_version'])->fetch_assoc();
    $envelope = json_decode($stored['payload'], true, 512, JSON_THROW_ON_ERROR);
    vdCheck($envelope['version'] === 5 && isset($envelope['nativeCatalog'], $envelope['nativeDependencyRules'],
        $envelope['nativeDiscoveryOrder'], $envelope['nativeDiscovery']));
    vdCheck(in_array(18, $envelope['nativeDiscoveryOrder'], true) &&
        !in_array(32, $envelope['nativeDiscoveryOrder'], true));
    vdCheck(hash('sha256', $stored['payload']) === $stored['hash']);
    vdCheck($envelope['calculation']['allOperations']['inputs']['commercial']['costs']['printing'] === 10 &&
        (float) $envelope['calculation']['allOperations']['lines'][0]['calculatedCost'] === 8.0 &&
        (float) $envelope['calculation']['allOperations']['lines'][0]['cost'] === 10.0);
    $discoveredById = [];
    foreach ($envelope['nativeDiscovery'] as $row) $discoveredById[(int) $row['operation_id']] = $row;
    vdCheck($discoveredById[43]['requirement_parents'] === [3]);
    vdCheck(vd_workflow_version_hash($calc) === $stored['request_hash']);
    vdCheck($service->save($quote, $component, $input)['workflow_version'] === $v1['workflow_version']);
    $withoutImposition = $input;
    $withoutImposition['request_id'] = 'fixture-' . bin2hex(random_bytes(16));
    $withoutImposition['expected_version'] = $v1['workflow_version'];
    $withoutImposition['calculation']['allOperations']['lines'] = array_values(array_filter($calc['allOperations']['lines'],
        static function ($line) { return ($line['operationId'] ?? null) !== 43; }));
    try { $service->save($quote, $component, $withoutImposition); throw new RuntimeException('Offset version without imposition was accepted.'); }
    catch (DomainException $error) { vdCheck(strpos($error->getMessage(), 'Imposição') !== false); }
    $input['request_id'] = 'fixture-' . bin2hex(random_bytes(16));
    vdReject(function () use ($service, $quote, $component, $input) { $service->save($quote, $component, $input); });
    $input['expected_version'] = $v1['workflow_version'];
    $v2 = $service->save($quote, $component, $input);
    vdCheck(count($service->state($quote)['versions']) === 2);
    $stateVersion = $service->state($quote)['versions'][0];
    vdCheck($stateVersion['totals']['cost'] === 10.0 && $stateVersion['totals']['sale'] === 12.0 &&
        $stateVersion['totals']['unitSale'] === 0.12);
    $readSnapshot = $service->snapshot($quote, $v1['workflow_version']);
    require_once __DIR__ . '/quote-ink-store.php';
    $ink = new VDQuoteInk($db,$service,1);
    $inkCoverage = ['pages' => [
        ['page'=>1,'width_mm'=>210,'height_mm'=>297,'coverage_percent'=>['C'=>100,'M'=>50,'Y'=>0,'K'=>0]],
        ['page'=>2,'width_mm'=>210,'height_mm'=>297,'coverage_percent'=>['C'=>0,'M'=>0,'Y'=>0,'K'=>100]]],
        'spots'=>[],'engine'=>'isolated synthetic fixture'];
    $inkContent = '%PDF-synthetic-fixture';
    $inkHash = hash('sha256',$inkContent);
    $inkJson = json_encode($inkCoverage,JSON_THROW_ON_ERROR);
    $stmt = $db->prepare("INSERT INTO app_vd_quote_ink_pdfs(quote_id,component_id,name,content,hash,result,created_by,created_at) VALUES(?,?,'isolated-fixture.pdf',?,?,?,1,1)");
    $stmt->bind_param('iisss',$quote,$component,$inkContent,$inkHash,$inkJson);
    $stmt->execute();
    $inkPdf = (int)$db->insert_id;
    $beforeInk = $db->query('SELECT payload,hash FROM app_vd_quote_versions WHERE id='.(int)$v1['workflow_version'])->fetch_assoc();
    $inkEstimate = $ink->estimate($quote,$component,$inkPdf,(int)$v1['workflow_version'],
        ['rates'=>['C'=>1.5,'M'=>1.5,'Y'=>1.5,'K'=>1.5],'loss_percent'=>10,'startup_g'=>5]);
    $inkState = $ink->state($quote,$component);
    vdCheck($inkState['estimates'][0]['id'] == $inkEstimate['estimate_id'] &&
        $inkState['estimates'][0]['version_hash'] === $beforeInk['hash'] &&
        $inkState['estimates'][0]['pdf_hash'] === $inkHash);
    vdCheck(abs($inkState['estimates'][0]['result']['inks']['C']['total_g']-46.1642)<0.00001);
    vdCheck($db->query('SELECT payload,hash FROM app_vd_quote_versions WHERE id='.(int)$v1['workflow_version'])->fetch_assoc() === $beforeInk);
    vdCheck($ink->pdf($quote,$component,$inkPdf)['content'] === $inkContent);
    users::$allowed = false;
    vdReject(function() use($ink,$quote,$component) { $ink->state($quote,$component); });
    vdReject(function() use($ink,$quote,$component,$inkPdf) { $ink->pdf($quote,$component,$inkPdf); });
    users::$allowed = true;
    vdReject(function() use($ink,$quote,$component,$inkPdf) { $ink->pdf($quote,$component+100000,$inkPdf); });
    vdCheck($readSnapshot['calculation'] === $calc && $readSnapshot['component_id'] === $component);
    vdReject(function () use ($service, $quote) { $service->snapshot($quote, 0); });
    vdReject(function () use ($service, $quote, $v1) { $service->snapshot($quote + 1000000, $v1['workflow_version']); });
    vdCheck(count($service->state($quote)['legacy']) === 1);
    $confirmed = $service->confirm($quote, $v1['workflow_version']);
    vdCheck($progress() === 50);
    vdCheck($confirmed['plans'] === 1 && $confirmed['operations'] === $operationCount);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_276 WHERE vd_workflow_version=" .
        (int) $v1['workflow_version'])->fetch_row()[0] === 0);
    $axes = $db->query('SELECT field_4259,field_4260 FROM app_entity_279 WHERE vd_workflow_version=' . (int) $v1['workflow_version'])->fetch_assoc();
    vdCheck($axes['field_4259'] === '2' && $axes['field_4260'] === '2');
    vdImmutable("UPDATE app_entity_388 SET field_6106='mutated' WHERE id=" . (int) $v1['id']);
    vdImmutable("DELETE FROM app_entity_388 WHERE id=" . (int) $v1['id']);
    vdCheck($service->confirm($quote, $v1['workflow_version'])['already_confirmed'] === true);
    vdCheck($db->query("SELECT COUNT(*) FROM app_entity_276 WHERE parent_item_id=$component")->fetch_row()[0] == 1);
    vdCheck($db->query("SELECT field_6179 FROM app_entity_276 WHERE id=$explicit")->fetch_row()[0] === '0');
    $service->confirm($quote, $v2['workflow_version']);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_276 WHERE vd_workflow_version=" .
        (int) $v2['workflow_version'])->fetch_row()[0] === 0);
    $extraField = (int) $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=146 AND role='extra_discount'")->fetch_row()[0];
    if (!$extraField) throw new RuntimeException('Missing extra discount field');
    $db->query("UPDATE app_entity_146 SET field_$extraField=5 WHERE id=$quote");
    $doc1 = $service->approve($quote, [$v1['workflow_version']])['document'];
    $discountSnapshot = json_decode($service->document($quote, $doc1)['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    vdCheck((float) $discountSnapshot['customer']['discountPercent'] === 10.0 &&
        (float) $discountSnapshot['customer']['extraDiscountPercent'] === 5.0 &&
        (float) $discountSnapshot['commercialAlternatives'][0]['prices']['sale'] === 10.2);
    vdCheck(strpos($service->document($quote, $doc1)['html'], '15,00 %') === false &&
        strpos($service->document($quote, $doc1)['html'], '0,1020 €') !== false &&
        strpos($service->document($quote, $doc1)['html'], '10,20 €') !== false);
    vdCheck($progress() === 100);
    $service->confirm($quote, $v1['workflow_version']);
    vdCheck($progress() === 100);
    vdCheck($db->query('SELECT field_4288 FROM app_entity_276 WHERE field_4287=1 AND vd_workflow_version=' .
        (int) $v1['workflow_version'])->fetch_row()[0] === '16');
    vdReject(function () use ($service, $quote, $v1) {
        $service->reopen($quote, $v1['workflow_version']);
    });
    $productionOrder = array_column($db->query("SELECT field_4287 FROM app_entity_276 WHERE vd_workflow_version=" .
        (int) $v1['workflow_version'] . ' ORDER BY field_6180+0')->fetch_all(MYSQLI_ASSOC), 'field_4287');
    vdCheck(count($productionOrder) === $operationCount);
    vdCheck(array_search('43', $productionOrder, true) < array_search('3', $productionOrder, true));
    vdCheck(array_search('3', $productionOrder, true) < array_search('7', $productionOrder, true));
    $fixedFields = [];
    foreach ($db->query('SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=276')->fetch_all(MYSQLI_ASSOC) as $field) {
        $fixedFields[$field['role']] = 'field_' . (int) $field['field_id'];
    }
    $fixed = $db->query('SELECT * FROM app_entity_276 WHERE field_4287=7 AND vd_workflow_version=' . (int) $v1['workflow_version'])->fetch_assoc();
    vdCheck((float) $fixed[$fixedFields['fixed_cost']] === 10.0 &&
        (float) $fixed[$fixedFields['fixed_sale']] === 12.0 &&
        (float) $fixed[$fixedFields['run_quantity']] === 100.0);
    $projection = $db->query("SELECT native_id FROM app_vd_quote_projection WHERE version_id=" . (int) $v1['workflow_version'] . ' AND entity_id=276')->fetch_row()[0];
    vdImmutable("UPDATE app_entity_276 SET field_4287=18 WHERE id=$projection");
    vdImmutable("DELETE FROM app_entity_276 WHERE id=$projection");
    vdCheck($service->approve($quote, [$v1['workflow_version']])['document'] === $doc1);
    $html = $service->document($quote, $doc1)['html'];
    vdCheck((int) $service->document($quote, 0)['id'] === (int) $doc1);
    $expectedNumber = (new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y') . '/' . $quote;
    vdCheck(strpos($html, '<title>Orçamento ' . $expectedNumber . '</title>') !== false);
    vdCheck(strpos($html, 'Internal machine') === false && strpos($html, '23 %') !== false && strpos($html, '30 Dias') !== false);
    vdReject(function () use ($service, $quote, $doc1) { $service->accept($quote, $doc1, 100, 'fixture acceptance', null, vd_production_delivery_default()); });
    $db->query("UPDATE app_entity_146 SET field_$extraField=0 WHERE id=$quote");
    $emailBeforeExtra = users::$calls;
    vdReject(function () use ($service, $quote, $doc1) { $service->send($quote, $doc1); });
    vdCheck(users::$calls === $emailBeforeExtra);
    $staleDocument = array_values(array_filter($service->state($quote)['documents'], static function ($d) use ($doc1) { return (int) $d['id'] === (int) $doc1; }))[0];
    vdCheck($staleDocument['discount_change']['changed'] === true && $staleDocument['discount_change']['approved'] === [10.0, 5.0] &&
        $staleDocument['discount_change']['current'] === [10.0, 0.0] && $staleDocument['version_ids'] === [(int) $v1['workflow_version']]);
    $reapproved = $service->approve($quote, $staleDocument['version_ids'])['document'];
    vdCheck((int) $reapproved !== (int) $doc1);
    $reapprovedState = array_values(array_filter($service->state($quote)['documents'], static function ($d) use ($reapproved) { return (int) $d['id'] === (int) $reapproved; }))[0];
    vdCheck($reapprovedState['discount_change']['changed'] === false && (float) $reapprovedState['alternatives'][0]['sale'] === 10.8);
    $doc1 = $reapproved;
    $input['request_id'] = 'fixture-' . bin2hex(random_bytes(16));
    $input['expected_version'] = $v2['workflow_version'];
    $v3 = $service->save($quote, $component, $input);
    vdCheck($progress() === 50);
    vdCheck($service->document($quote, $doc1)['state'] === 'superseded');
    vdReject(function () use ($service, $quote) { $service->document($quote, 0); });
    vdReject(function () use ($service, $quote, $doc1) { $service->send($quote, $doc1); });
    $doc2 = $service->approve($quote, [$v2['workflow_version']])['document'];
    $invalidSender = new VDQuoteWorkflow($db, ['id' => 1, 'group_id' => 0, 'email' => 'invalid', 'name' => 'Fixture']);
    $beforeInvalidSender = users::$calls;
    vdReject(function () use ($invalidSender, $quote, $doc2) { $invalidSender->send($quote, $doc2); });
    vdCheck(users::$calls === $beforeInvalidSender && $service->document($quote, $doc2)['state'] === 'approved');
    $db->query("UPDATE app_entity_146 SET field_3926='invalid phone' WHERE id=$quote");
    $emailCalls = users::$calls;
    vdReject(function () use ($service, $quote, $doc2) { $service->send($quote, $doc2); });
    vdCheck(users::$calls === $emailCalls && clicksend::$calls === 0 &&
        $service->document($quote, $doc2)['state'] === 'approved');
    $db->query("UPDATE app_entity_146 SET field_3926='912 345 678' WHERE id=$quote");
    users::$transport = false;
    vdReject(function () use ($service, $quote, $doc2) { $service->send($quote, $doc2); });
    vdCheck(users::$subject === 'Proposta de orçamento ' . $expectedNumber);
    vdCheck($service->document($quote, $doc2)['state'] === 'send_unknown');
    vdCheck((int) $db->query("SELECT field_2030 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] === 36);
    vdCheck(clicksend::$calls === 0);
    $calls = users::$calls;
    vdReject(function () use ($service, $quote, $doc2) { $service->send($quote, $doc2); });
    vdCheck(users::$calls === $calls);
    vdReject(function () use ($service, $quote, $v1) { $service->approve($quote, [$v1['workflow_version']]); });
    $db->query("UPDATE app_vd_quote_documents SET state='superseded' WHERE id=" . (int) $doc2);
    $db->query("UPDATE app_vd_quote_deliveries SET email_state='sent',state='skipped_no_phone' WHERE document_id=$doc2");
    $service->confirm($quote, $v3['workflow_version']);
    $doc3 = $service->approve($quote, [$v3['workflow_version']])['document'];
    users::$transport = true;
    $sent = $service->send($quote, $doc3);
    $adjudication = $service->state($quote)['documents'][0];
    vdCheck($adjudication['can_accept'] === true &&
        count($adjudication['alternatives']) === 1 &&
        (float) $adjudication['alternatives'][0]['grossSale'] === 12.0 &&
        (float) $adjudication['alternatives'][0]['discountPercent'] === 10.0 &&
        (float) $adjudication['alternatives'][0]['sale'] === 10.8 &&
        abs((float) $adjudication['alternatives'][0]['unitSale'] - 0.108) < 0.000000001);
    $frozenDocument = $service->document($quote, $doc3);
    $db->query("UPDATE app_entity_26 SET field_5124=25 WHERE id=$customer");
    vdCheck($service->discount($quote) === 10.0 && $service->state($quote)['discount_percent'] === 10.0);
    vdCheck($service->extraDiscount($quote) === 0.0);
    vdImmutable("UPDATE app_entity_146 SET field_$extraField=5 WHERE id=$quote");
    vdCheck($service->document($quote, $doc3)['hash'] === $frozenDocument['hash'] &&
        $service->document($quote, $doc3)['html'] === $frozenDocument['html']);
    vdCheck($sent['state'] === 'sent' && $sent['sms_state'] === 'sent');
    vdCheck(users::$attachmentName === 'VeD_Orc_' . str_replace('/', '', $expectedNumber) . '_' . $doc3 . '.pdf');
    vdCheck(!file_exists(users::$attachmentPath));
    vdCheck(strpos(users::$body, 'Enviamos em anexo') !== false && strpos(users::$body, 'role="presentation"') !== false &&
        strpos(users::$body, '@page') === false && strpos(users::$body, 'position:fixed') === false);
    vdCheck(users::$sender === ['email' => 'sending-user@example.invalid', 'name' => 'Sending user']);
    $sendAudit = $db->query("SELECT detail FROM app_vd_quote_audit WHERE quote_id=$quote AND event='send_started' ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    vdCheck(json_decode($sendAudit, true, 512, JSON_THROW_ON_ERROR)['sender'] === users::$sender);
    vdCheck((int) $db->query("SELECT field_2030 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] === 39);
    vdCheck(clicksend::$calls === 1 && strpos(clicksend::$message, $expectedNumber) !== false &&
        strpos(clicksend::$message, 'foi enviado por email. Obrigado.') !== false);
    vdReject(function () use ($service, $quote, $doc3) { $service->send($quote, $doc3); });
    vdCheck(clicksend::$calls === 1);
    $resendInput = ['request_id' => 'fixture-' . bin2hex(random_bytes(16)),
        'email' => 'other-recipient@example.invalid', 'phone' => '967123456'];
    $originalResendHtml = $service->document($quote, $doc3)['html'];
    $resend = $service->send($quote, $doc3, $resendInput);
    vdCheck($resend['state'] === 'sent' && $resend['sms_state'] === 'sent' &&
        users::$recipient === 'other-recipient@example.invalid' && clicksend::$calls === 2);
    vdReject(function () use ($service, $quote, $doc3, $resendInput) { $service->send($quote, $doc3, $resendInput); });
    vdCheck(clicksend::$calls === 2);
    $deliveries = $service->state($quote)['documents'][0]['deliveries'];
    vdCheck(count($deliveries) === 2 && $deliveries[0]['email'] === 'other-recipient@example.invalid' &&
        $deliveries[1]['email'] === 'workflow-fixture@example.invalid');
    vdCheck($service->document($quote, $doc3)['html'] === $originalResendHtml);
    vdReject(function () use ($service, $quote, $doc3) { $service->accept($quote, $doc3, 200, 'fixture acceptance', null, vd_production_delivery_default()); });
    foreach ([null, '', '2026-02-30', '2025-01-01', '12/11/2026'] as $date) {
        vdReject(function () use ($service, $quote, $doc3, $date) { $service->accept($quote, $doc3, 100, '', null, $date); });
    }
    vdReject(function () use ($service, $quote, $doc3) {
        $service->accept($quote, $doc3, 100, 'Fixture acceptance reference', ['name' => 'bad.pdf', 'content' => '<html>not a PDF</html>'], vd_production_delivery_default());
    });
    vdCheck($service->document($quote, $doc3)['state'] === 'sent');
    $approvalBytes = "%PDF-1.4\n% Approval fixture\n";
    $db->query("UPDATE app_entity_146 SET field_3929='existing_attachment.pdf' WHERE id=$quote");
    users::$deniedCreates = [161];
    vdReject(function () use ($service, $quote, $doc3, $approvalBytes) {
        $service->accept($quote, $doc3, 100, '', ['name' => 'rollback.pdf', 'content' => $approvalBytes], vd_production_delivery_default());
    });
    users::$deniedCreates = [];
    vdCheck($service->document($quote, $doc3)['state'] === 'sent' &&
        !$db->query("SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=$quote")->num_rows);
    vdCheck(!$db->query("SELECT id FROM app_entity_121 WHERE field_5972=$quote")->num_rows);
    vdCheck(!$db->query("SELECT document_id FROM app_vd_quote_approval_files WHERE document_id=$doc3")->num_rows &&
        $db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] === 'existing_attachment.pdf');
    $componentMaterialSchema = vd_component_material_schema($db);
    $componentMaterialEntity = $componentMaterialSchema['entity'];
    users::$deniedCreates = [$componentMaterialEntity];
    $beforeMaterials = (int) $db->query("SELECT COUNT(*) FROM app_entity_$componentMaterialEntity")->fetch_row()[0];
    vdReject(function () use ($service, $quote, $doc3) {
        $service->accept($quote, $doc3, 100, '', null, vd_production_delivery_default());
    });
    users::$deniedCreates = [];
    vdCheck($service->document($quote, $doc3)['state'] === 'sent' &&
        !$db->query("SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=$quote")->num_rows &&
        (int) $db->query("SELECT COUNT(*) FROM app_entity_$componentMaterialEntity")->fetch_row()[0] === $beforeMaterials);
    $accepted = $service->accept($quote, $doc3, 100, '',
        ['name' => 'approval.pdf', 'content' => $approvalBytes], vd_production_delivery_default());
    $order = $accepted['production_order'];
    vdCheck($order['quantity'] === 100.0 && $order['document'] === (int) $doc3);
    $orderId = $order['id'];
    $nativeOrder = $db->query("SELECT * FROM app_entity_121 WHERE id=$orderId")->fetch_assoc();
    vdCheck($nativeOrder['field_2000'] === 'OP' . date('y') . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT) &&
        (int) $nativeOrder['field_2437'] === 897 &&
        (int) $nativeOrder['field_1992'] === vd_production_delivery_date(vd_production_delivery_default())->getTimestamp() &&
        (int) $nativeOrder['field_2471'] === 0);
    vdCheck($nativeOrder['field_1994'] === 'ISOLATED WORKFLOW FIXTURE');
    vdCheck($service->document($quote, $doc3)['delivery_date'] === vd_production_delivery_default() &&
        $service->productionOrder($quote)['delivery_date'] === vd_production_delivery_default());
    $orderComponent = $db->query("SELECT * FROM app_entity_159 WHERE parent_item_id=$orderId")->fetch_assoc();
    vdCheck((float) $orderComponent['field_2236'] === 210.0 && (float) $orderComponent['field_2242'] === 100.0 &&
        (int) $orderComponent['field_2579'] === 0);
    $productionComponentId = (int) $orderComponent['id'];
    $productionSequence = array_map('intval', array_column($db->query("SELECT field_2426 FROM app_entity_170 WHERE parent_item_id=$productionComponentId ORDER BY field_2430+0,id")->fetch_all(MYSQLI_ASSOC), 'field_2426'));
    vdCheck(array_search(43, $productionSequence, true) < array_search(2, $productionSequence, true));
    vdCheck(!in_array(44, $productionSequence, true));
    $plannedOperations = $db->query("SELECT * FROM app_entity_170 WHERE parent_item_id=$productionComponentId ORDER BY field_2430+0,id")->fetch_all(MYSQLI_ASSOC);
    $expectedPlanning = vd_production_schedule_backwards(
        new DateTimeImmutable(vd_production_delivery_default(), new DateTimeZone('Europe/Lisbon')),
        array_column($plannedOperations, 'field_2431', 'id'));
    foreach ($plannedOperations as $plannedOperation) {
        vdCheck((int) $plannedOperation['field_2536'] === $expectedPlanning[$plannedOperation['id']]['start'] &&
            (int) $plannedOperation['field_2531'] === $expectedPlanning[$plannedOperation['id']]['end']);
    }
    foreach ($db->query("SELECT * FROM app_entity_170 WHERE parent_item_id=$productionComponentId")->fetch_all(MYSQLI_ASSOC) as $op) {
        $staff = vd_production_operation_staff($db, $op['field_2426']);
        vdCheck($op['field_2428'] === $staff['field_2428'] && $op['field_2472'] === $staff['field_2472']);
        foreach ([2428,2472] as $field) {
            $indexed = array_column($db->query('SELECT value FROM app_entity_170_values WHERE items_id=' . (int) $op['id'] . " AND fields_id=$field ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'value');
            vdCheck($indexed === array_values(array_filter(explode(',', $staff['field_' . $field]), 'strlen')));
        }
        $source = json_decode($db->query('SELECT snapshot FROM app_vd_quote_production_rows WHERE entity_id=170 AND native_id=' . (int) $op['id'])->fetch_row()[0], true, 512, JSON_THROW_ON_ERROR);
        vdCheck($source['production_staff']['assigned_users'] === $staff['field_2428']);
    }
    vdCheck($db->query("SELECT id FROM app_entity_145 WHERE parent_item_id=$productionComponentId")->num_rows === 0);
    $orderPlan = $db->query("SELECT * FROM app_entity_158 WHERE parent_item_id=$productionComponentId")->fetch_assoc();
    vdCheck((int) $orderPlan['field_2203'] === 4 && (int) $orderPlan['field_2208'] === 2 &&
        (int) $orderPlan['field_2198'] === 0 && strpos($orderPlan['field_2209'], 'vd-production-plan-preview') !== false);
    vdCheck(substr_count($orderPlan['field_2209'], '<svg ') === 1 &&
        strpos($orderPlan['field_2209'], '<pre>') === false &&
        substr_count($orderPlan['field_2209'], 'data-kind="piece"') === 4);
    vdCheck((int) $db->query("SELECT field_2431 FROM app_entity_170 WHERE parent_item_id=$productionComponentId AND field_2426=7")->fetch_row()[0] === 30);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_161 m JOIN app_entity_170 o ON o.id=m.parent_item_id WHERE o.parent_item_id=$productionComponentId")->fetch_row()[0] === 2);
    $materialRoles = [];
    foreach ($db->query("SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=161 AND role LIKE 'op_%'") as $r) $materialRoles[$r['role']] = 'field_' . $r['field_id'];
    $paperMaterial = $db->query("SELECT m.* FROM app_entity_161 m JOIN app_entity_170 o ON o.id=m.parent_item_id WHERE o.parent_item_id=$productionComponentId AND o.field_2426=2")->fetch_assoc();
    vdCheck((int) $paperMaterial[$materialRoles['op_article']] === 2652 &&
        (int) $paperMaterial[$materialRoles['op_internal']] === 1406 &&
        (float) $paperMaterial[$materialRoles['op_quantity']] === 50.0 &&
        (int) $paperMaterial['field_2277'] === 0);
    vdCheck($order['unassigned_materials'] === 1);
    $directFields = $componentMaterialSchema['fields'];
    $directMaterials = $db->query("SELECT * FROM app_entity_$componentMaterialEntity WHERE parent_item_id=$productionComponentId")->fetch_all(MYSQLI_ASSOC);
    vdCheck(count($directMaterials) === 2);
    $directPaper = array_values(array_filter($directMaterials, static function ($row) use ($directFields, $paperMaterial) {
        return (int) $row[$directFields['source']] === (int) $paperMaterial['id'];
    }))[0];
    vdCheck((int) $directPaper[$directFields['article']] === 2652 &&
        (int) $directPaper[$directFields['internal']] === 1406 &&
        (float) $directPaper[$directFields['quantity']] === 50.0 &&
        $directPaper[$directFields['unit']] === $paperMaterial[$materialRoles['op_unit']]);
    vdCheck((int) $directPaper[$directFields['operation']] === (int) $paperMaterial['parent_item_id'] &&
        (int) $directPaper[$directFields['process']] === 2 &&
        $directPaper[$directFields['snapshot']] === $paperMaterial[$materialRoles['op_snapshot']]);
    $db->begin_transaction();
    vdCheck(vd_component_material_project($db, $paperMaterial['id'], 1) === (int) $directPaper['id']);
    $db->commit();
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_$componentMaterialEntity WHERE parent_item_id=$productionComponentId")->fetch_row()[0] === 2);
    $db->begin_transaction();
    $db->query('DELETE FROM app_vd_component_material_rows WHERE source_id=' . (int) $paperMaterial['id']);
    $db->query("DELETE FROM app_entity_$componentMaterialEntity WHERE id=" . (int) $directPaper['id']);
    $db->query("UPDATE app_entity_161 SET {$materialRoles['op_quantity']}='0.006768123456' WHERE id=" . (int) $paperMaterial['id']);
    $backfilledId = vd_component_material_project($db, $paperMaterial['id'], 1);
    $backfilled = $db->query("SELECT * FROM app_entity_$componentMaterialEntity WHERE id=$backfilledId")->fetch_assoc();
    vdCheck($backfilled[$directFields['quantity']] === '0.006768123456' &&
        (int) $backfilled['parent_item_id'] === $productionComponentId &&
        (int) $backfilled[$directFields['source']] === (int) $paperMaterial['id']);
    $db->rollback();
    vdCheck((int) $db->query("SELECT native_id FROM app_vd_component_material_rows WHERE source_id=" . (int) $paperMaterial['id'])->fetch_row()[0] === (int) $directPaper['id']);
    $presentation = $service->productionPresentation(121, $orderId);
    vdCheck(substr_count($presentation, '<svg ') === 1 && strpos($presentation, 'Materiais da OP') !== false &&
        strpos($presentation, 'Frozen fixture paper') !== false);
    vdCheck(strpos($presentation, '/' . $componentMaterialEntity . '-' . $directPaper['id']) !== false);
    vdCheck(substr_count($service->productionPresentation(159, $productionComponentId), '<svg ') === 1);
    vdCheck(substr_count($service->productionPresentation(158, (int) $orderPlan['id']), '<svg ') === 1);
    $printSummary = $service->productionPrintSummary($orderId);
    $printDom = new DOMDocument();
    $previousXmlErrors = libxml_use_internal_errors(true);
    try {
        vdCheck($printDom->loadHTML('<?xml encoding="UTF-8">' . $printSummary));
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousXmlErrors);
    }
    $printXpath = new DOMXPath($printDom);
    vdCheck($printXpath->query('//table[contains(@class,"vd-production-materials")]/thead/tr/th')->length === 3 &&
        $printXpath->query('//table[contains(@class,"vd-production-materials")]//th[text()="Componente"]')->length === 0);
    vdCheck($printXpath->query('//table[contains(@class,"vd-production-materials")]/tbody/tr[not(count(td)=3 or (count(td)=2 and td[2]/@colspan="2"))]')->length === 0);
    vdCheck($printXpath->query('//div[@class="vd-production-operations"]/h3[1][text()="Cores"]')->length === 1 &&
        $printXpath->query('//div[@class="vd-production-operations"]/h3[2][text()="Sequência de operações"]')->length === 1);
    vdCheck(strpos($printSummary, 'Cores: rubricas;') === false && strpos($printSummary, 'Dados da versão adjudicada.') === false);
    vdCheck($printXpath->query('//div[@class="vd-production-columns"]/div[@class="vd-production-operations"]//table')->length === 1 &&
        $printXpath->query('//div[@class="vd-production-columns"]/div[@class="vd-production-plan"]//svg')->length === 1);
    vdCheck($printXpath->query('//div[@class="vd-production-columns"]//h3[text()="Materiais da OP"]')->length === 0 &&
        substr_count($printSummary, 'Materiais da OP') === 1);
    $startExpression = vd_production_planned_start_sql('e.id', 'e.field_2471');
    $printStart = (int) $db->query("SELECT $startExpression FROM app_entity_121 e WHERE e.id=$orderId")->fetch_row()[0];
    $formattedStart = (new DateTimeImmutable('@' . $printStart))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i');
    vdCheck($printStart > 0 && strpos($printSummary, 'Início previsto da produção') !== false &&
        strpos($printSummary, $formattedStart) !== false);
    $db->begin_transaction();
    $db->query("UPDATE app_entity_170 o JOIN app_entity_159 c ON c.id=o.parent_item_id SET o.field_2531=0 WHERE c.parent_item_id=$orderId");
    vdCheck(strpos($service->productionPrintSummary($orderId), 'colspan="3"><strong>POR DEFINIR') !== false);
    $db->query("UPDATE app_entity_121 SET field_2471=1794229200 WHERE id=$orderId");
    vdCheck(strpos($service->productionPrintSummary($orderId), '09/11/2026 13:00') !== false);
    $db->rollback();
    vdCheck(strpos($printSummary, 'ORDEM DE PRODUÇÃO') !== false && strpos($printSummary, $order['number']) !== false);
    vdCheck(strpos($printSummary, 'Sequência de operações') !== false && strpos($printSummary, 'Controlo de produção e entrega') !== false);
    vdCheck(strpos($printSummary, 'Materiais da OP') !== false && substr_count($printSummary, '<svg ') === 1);
    vdCheck(strpos($printSummary, 'Abrir / ampliar plano') === false && strpos($printSummary, 'ISOLATED WORKFLOW FIXTURE') !== false);
    vdCheck(strpos($printSummary, 'Frozen fixture paper') !== false);
    preg_match('/<h3>Sequência de operações<\/h3>(<table>.*?<\/table>)/s', $printSummary, $operationTable);
    vdCheck(isset($operationTable[1]) && substr_count($operationTable[1], '<th>') === 2 &&
        strpos($operationTable[1], '<th>Pos.</th><th>Operação</th>') !== false &&
        strpos($operationTable[1], '<small>') === false);
    preg_match_all('/<tr>(.*?)<\/tr>/s', $operationTable[1], $operationRows);
    foreach (array_slice($operationRows[1], 1) as $operationRow) {
        vdCheck(substr_count($operationRow, '<td>') === 2);
    }
    vdCheck(strpos($operationTable[1], 'Trabalho previsto') === false &&
        strpos($operationTable[1], 'Tempo previsto') === false &&
        strpos($operationTable[1], 'Executante / data') === false &&
        strpos($operationTable[1], 'Controlo / visto') === false);
    users::$deniedViews = [$componentMaterialEntity];
    vdReject(function () use ($service, $orderId) { $service->productionPrintSummary($orderId); });
    users::$deniedViews = [];
    users::$allowed = false;
    vdReject(function () use ($service, $orderId) { $service->productionPrintSummary($orderId); });
    users::$allowed = true;
    $db->query("UPDATE app_entity_121 SET field_1992=0 WHERE id=$orderId");
    $deliverySaved = $service->setProductionDeliveryDate($quote, vd_production_delivery_default());
    vdCheck($deliverySaved['production_order']['delivery_date'] === vd_production_delivery_default());
    vdReject(function () use ($service, $quote) { $service->setProductionDeliveryDate($quote, vd_production_delivery_default()); });
    $repeatedOrder = $service->createProductionOrder($quote);
    vdCheck($repeatedOrder['already_created'] === true && $repeatedOrder['id'] === $orderId);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_121 WHERE field_5972=$quote")->fetch_row()[0] === 1);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_$componentMaterialEntity WHERE parent_item_id=$productionComponentId")->fetch_row()[0] === 2);
    vdReject(function () use ($service, $quote) { $service->restoreAdjudication($quote); });
    $timedOperation = (int) $db->query("SELECT id FROM app_entity_170 WHERE parent_item_id=$productionComponentId AND field_2426=2")->fetch_row()[0];
    $timingUser = ['id' => 1, 'group_id' => 0];
    $timeStart = time();
    $stockBefore = (int) $db->query('SELECT COUNT(*) FROM app_entity_100')->fetch_row()[0];
    $started = vd_production_kanban_time($db, $timingUser, $timedOperation, 3717, $timeStart);
    vdCheck($started['event'] === 'start' && $started['time_id'] > 0);
    $timeId = $started['time_id'];
    $again = vd_production_kanban_time($db, $timingUser, $timedOperation, 3717, $timeStart + 10);
    vdCheck($again['time_id'] === $timeId && $again['event'] === 'status');
    $restartedWhileRunning = vd_production_kanban_time($db, $timingUser, $timedOperation, 3725, $timeStart + 20);
    vdCheck($restartedWhileRunning['time_id'] === $timeId);
    $stopped = vd_production_kanban_time($db, $timingUser, $timedOperation, 3724, $timeStart + 125);
    $timeRow = $db->query("SELECT * FROM app_entity_171 WHERE id=$timeId")->fetch_assoc();
    vdCheck($stopped['event'] === 'stop' && (int) $timeRow['created_by'] === 1 &&
        (int) $timeRow['field_2447'] - (int) $timeRow['field_2446'] === 125 &&
        (int) $timeRow['field_2444'] === 3720 && (int) $timeRow['field_2507'] === 39);
    vd_production_kanban_time($db, $timingUser, $timedOperation, 3724, $timeStart + 140);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_171 WHERE parent_item_id=$timedOperation")->fetch_row()[0] === 1);
    $resumed = vd_production_kanban_time($db, $timingUser, $timedOperation, 3725, $timeStart + 200);
    vdCheck($resumed['time_id'] !== $timeId && $resumed['event'] === 'start');
    vd_production_kanban_time($db, $timingUser, $timedOperation, 39, $timeStart + 260);
    vdCheck((int) $db->query('SELECT field_2444 FROM app_entity_171 WHERE id=' . $resumed['time_id'])->fetch_row()[0] === 3722);
    vdCheck((int) $db->query("SELECT SUM(field_2447-field_2446) FROM app_entity_171 WHERE parent_item_id=$timedOperation")->fetch_row()[0] === 185);
    vdCheck((int) $db->query('SELECT COUNT(*) FROM app_entity_100')->fetch_row()[0] === $stockBefore);
    users::$deniedCreates = [171];
    vdReject(function () use ($db, $timingUser, $timedOperation, $timeStart) { vd_production_kanban_time($db, $timingUser, $timedOperation, 3717, $timeStart + 300); });
    users::$deniedCreates = [];
    vdCheck((int) $db->query("SELECT field_2425 FROM app_entity_170 WHERE id=$timedOperation")->fetch_row()[0] === 39);
    vdReject(function () use ($db, $timingUser, $timedOperation) { vd_production_kanban_time($db, $timingUser, $timedOperation, 999999); });
    $actorTwo = ['id' => 57, 'group_id' => 0];
    $another = vd_production_kanban_time($db, $actorTwo, $timedOperation, 3717, $timeStart + 400);
    vd_production_kanban_time($db, $timingUser, $timedOperation, 39, $timeStart + 410);
    vdCheck((int) $db->query('SELECT field_2447 FROM app_entity_171 WHERE id=' . $another['time_id'])->fetch_row()[0] === 0);
    vd_production_kanban_time($db, $actorTwo, $timedOperation, 36, $timeStart + 460);
    vdCheck((int) $db->query('SELECT field_2447 FROM app_entity_171 WHERE id=' . $another['time_id'])->fetch_row()[0] === $timeStart + 460);
    $index = $db->query("SELECT value FROM app_entity_170_values WHERE items_id=$timedOperation AND fields_id=2425")->fetch_row()[0];
    vdCheck((int) $index === 36);
    $duplicateA = vdFixture(171, ['parent_item_id' => $timedOperation, 'field_2444' => 3719,
        'field_2446' => $timeStart + 500, 'field_2447' => 0, 'field_2507' => 36]);
    $duplicateB = vdFixture(171, ['parent_item_id' => $timedOperation, 'field_2444' => 3719,
        'field_2446' => $timeStart + 500, 'field_2447' => 0, 'field_2507' => 36]);
    vdReject(function () use ($db, $timingUser, $timedOperation, $timeStart) {
        vd_production_kanban_time($db, $timingUser, $timedOperation, 39, $timeStart + 600);
    });
    vdCheck((int) $db->query("SELECT field_2425 FROM app_entity_170 WHERE id=$timedOperation")->fetch_row()[0] === 36 &&
        (int) $db->query("SELECT COUNT(*) FROM app_entity_171 WHERE id IN($duplicateA,$duplicateB) AND field_2447=0")->fetch_row()[0] === 2);
    $db->query("DELETE FROM app_entity_171 WHERE id=$duplicateB");
    vdReject(function () use ($db, $timingUser, $timedOperation, $timeStart) {
        vd_production_kanban_time($db, $timingUser, $timedOperation, 39, $timeStart + 400);
    });
    vd_production_kanban_time($db, $timingUser, $timedOperation, 39, $timeStart + 600);
    vdCheck((int) $db->query("SELECT field_2447 FROM app_entity_171 WHERE id=$duplicateA")->fetch_row()[0] === $timeStart + 600);
    $startedDates = $db->query("SELECT field_2536,field_2531 FROM app_entity_170 WHERE id=$timedOperation")->fetch_assoc();
    $db->begin_transaction();
    $db->query("UPDATE app_entity_170 SET field_2531=0 WHERE parent_item_id=$productionComponentId AND id<>$timedOperation");
    $scheduled = vd_production_schedule_order($db, $orderId, true, 1);
    vdCheck(in_array($timedOperation, $scheduled['preserved'], true) && $scheduled['planned'] > 0);
    vdCheck($db->query("SELECT field_2536,field_2531 FROM app_entity_170 WHERE id=$timedOperation")->fetch_assoc() === $startedDates);
    $repeatSchedule = vd_production_schedule_order($db, $orderId, true, 1);
    vdCheck($repeatSchedule['planned'] === 0);
    $db->rollback();
    vdCheck($service->approvalFile($quote, $doc3)['content'] === $approvalBytes);
    $nativeAttachment = $service->approvalFile($quote, $doc3);
    vdCheck($db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] ===
        'existing_attachment.pdf,' . $nativeAttachment['native_name']);
    vdCheck($db->query("SELECT field_2280 FROM app_entity_121 WHERE id=$orderId")->fetch_row()[0] ===
        'existing_attachment.pdf,' . $nativeAttachment['native_name']);
    vd_workflow_sync_approval_attachment($db, (int) $doc3);
    vdCheck($db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] ===
        'existing_attachment.pdf,' . $nativeAttachment['native_name']);
    vdCheck(hash_file('sha256', DIR_WS_ATTACHMENTS . date('Y/m/d', $nativeAttachment['uploaded_at']) . '/' .
        sha1($nativeAttachment['native_name'])) === $nativeAttachment['hash']);
    $acceptAudit = $db->query("SELECT detail FROM app_vd_quote_audit WHERE quote_id=$quote AND event='customer_accepted' ORDER BY id DESC LIMIT 1")->fetch_row()[0];
    vdCheck(json_decode($acceptAudit, true, 512, JSON_THROW_ON_ERROR)['evidence'] === '');
    vdCheck($service->state($quote)['documents'][0]['approval_file']['name'] === 'approval.pdf');
    vdReject(function () use ($service, $quote, $doc3) { $service->approvalFile($quote + 1000000, $doc3); });
    vdCheck($service->state($quote)['documents'][0]['can_accept'] === false &&
        (int) $service->state($quote)['control']['production_document'] === (int) $doc3);
    vdCheck($accepted['production_versions'] === [(int) $v3['workflow_version']]);
    vdCheck($db->query("SELECT COUNT(*) FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote AND p.production=1")->fetch_row()[0] == $operationCount + 1);
    foreach ([276 => $operationCount, 279 => 1] as $entity => $count) {
        $flag = (int) $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=$entity AND role='production'")->fetch_row()[0];
        vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_$entity WHERE vd_workflow_version=" .
            (int) $v3['workflow_version'] . " AND field_$flag='1'")->fetch_row()[0] === $count);
    }
    $lockedSend = ['request_id' => 'fixture-' . bin2hex(random_bytes(16)),
        'email' => 'workflow-fixture@example.invalid', 'phone' => ''];
    $lockedCalls = users::$calls;
    vdReject(function () use ($service, $quote, $doc3, $lockedSend) { $service->send($quote, $doc3, $lockedSend); });
    vdReject(function () use ($service, $quote, $doc3) { $service->accept($quote, $doc3, 100, '', null, vd_production_delivery_default()); });
    vdCheck(users::$calls === $lockedCalls);
    $restoreNonAdmin = new VDQuoteWorkflow($db, ['id' => 1, 'group_id' => 5]);
    vdReject(function () use ($restoreNonAdmin, $quote) { $restoreNonAdmin->restoreAdjudication($quote); });
    $originalHtml = $service->document($quote, $doc3)['html'];
    vdRemoveFixtureOrders($quote);
    $db->query("UPDATE app_entity_262 SET field_4218=311 WHERE id=$component");
    $recoveredOrder = $service->createProductionOrder($quote);
    vdCheck((float) $db->query("SELECT field_2236 FROM app_entity_159 WHERE parent_item_id=" . $recoveredOrder['id'])->fetch_row()[0] === 210.0);
    $db->query("UPDATE app_entity_262 SET field_4218=210 WHERE id=$component");
    vdRemoveFixtureOrders($quote);
    $service->restoreAdjudication($quote);
    vdCheck(empty($service->state($quote)['control']['production_document']) &&
        $service->document($quote, $doc3)['state'] === 'sent');
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote AND p.production=1")->fetch_row()[0] === 0);
    foreach ([276,279] as $entity) {
        $flag = (int) $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=$entity AND role='production'")->fetch_row()[0];
        vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_$entity WHERE vd_workflow_version=" .
            (int) $v3['workflow_version'] . " AND field_$flag='1'")->fetch_row()[0] === 0);
    }
    $service->accept($quote, $doc3, 100, '', ['name' => 'approval-new.pdf', 'content' => $approvalBytes . "% New approval\n"], vd_production_delivery_default());
    vdCheck($service->document($quote, $doc3)['html'] === $originalHtml &&
        $service->approvalFile($quote, $doc3)['name'] === 'approval-new.pdf');
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_vd_quote_approval_file_history WHERE document_id=$doc3")->fetch_row()[0] === 1);
    vdCheck(strpos($db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0],
        $nativeAttachment['native_name']) !== false);
    vdReject(function () use ($service, $quote) { $service->restoreAdjudication($quote); });
    vdRemoveFixtureOrders($quote);
    $service->restoreAdjudication($quote);
    $reopenInput = $input;
    $reopenInput['request_id'] = 'fixture-' . bin2hex(random_bytes(16));
    $reopenInput['quantity_id'] = 'rubrics';
    $reopenInput['expected_version'] = 0;
    $reopenVersion = $service->save($quote, $component, $reopenInput);
    $service->confirm($quote, $reopenVersion['workflow_version']);
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_276 WHERE vd_workflow_version=" .
        (int) $reopenVersion['workflow_version'])->fetch_row()[0] === 0);
    $nonAdmin = new VDQuoteWorkflow($db, ['id' => 1, 'group_id' => 5]);
    vdReject(function () use ($nonAdmin, $quote, $reopenVersion) {
        $nonAdmin->reopen($quote, $reopenVersion['workflow_version']);
    });
    $reopened = $service->reopen($quote, $reopenVersion['workflow_version']);
    vdCheck($reopened['state'] === 'superseded' && $reopened['history_preserved']);
    vdCheck($db->query('SELECT state FROM app_vd_quote_versions WHERE id=' .
        (int) $reopenVersion['workflow_version'])->fetch_row()[0] === 'superseded');
    users::$allowed = false;
    vdReject(function () use ($service, $quote) { $service->state($quote); });
    vdReject(function () use ($service, $quote, $v1) { $service->confirm($quote, $v1['workflow_version']); });
    users::$allowed = true;
    $nonApprover = new VDQuoteWorkflow($db, ['id' => 1, 'group_id' => 5]);
    vdReject(function () use ($nonApprover, $quote, $v1) { $nonApprover->approve($quote, [$v1['workflow_version']]); });
    $incompleteCalculation = $calc;
    $incompleteCalculation['allOperations']['complete'] = false;
    $incompleteCalculation['allOperations']['errors'] = ['Espiral: diâmetro e voltas por preencher.'];
    $incompleteCalculation['allOperations']['lines'][0]['cost'] = null;
    $incompleteCalculation['allOperations']['lines'][0]['sale'] = null;
    $incompleteCalculation['allOperations']['lines'][0]['errors'] = ['Custo interno em falta.'];
    $incompleteCalculation['allOperations']['partialCost'] = 0.0;
    $incompleteCalculation['allOperations']['partialSale'] = 0.0;
    $incompleteCalculation['allOperations']['cost'] = null;
    $incompleteCalculation['allOperations']['sale'] = null;
    $incompleteCalculation['allOperations']['manualAdjustmentScope'] = 'component';
    $incompleteCalculation['allOperations']['inputs']['lamination:fixture'] = [
        'entry' => '10', 'exit' => '10', 'narrow' => ''];
    $incompleteInput = ['request_id' => 'fixture-' . bin2hex(random_bytes(16)),
        'quantity_id' => $quantityId, 'calculation' => $incompleteCalculation,
        'expected_version' => (int) $v3['workflow_version']];
    $incompleteVersion = $service->save($quote, $component, $incompleteInput);
    vdCheck($incompleteVersion['base'] === null);
    $incompleteTotals = $service->state($quote)['versions'][0]['totals'];
    vdCheck($incompleteTotals['cost'] === null && $incompleteTotals['sale'] === null &&
        $incompleteTotals['complete'] === false && $incompleteTotals['partialCost'] === 0.0);
    $nativeDraft = $db->query('SELECT field_6105,field_6106 FROM app_entity_388 WHERE id=' .
        (int) $incompleteVersion['id'])->fetch_assoc();
    $nativeSnapshot = json_decode($nativeDraft['field_6106'], true, 512, JSON_THROW_ON_ERROR);
    vdCheck((float) $nativeDraft['field_6105'] === 0.0 &&
        $nativeSnapshot['calculation']['allOperations']['sale'] === null);
    vdCheck($nativeSnapshot['calculation']['allOperations']['manualAdjustmentScope'] === 'component' &&
        $nativeSnapshot['calculation']['allOperations']['inputs']['lamination:fixture'] === [
            'entry' => '10', 'exit' => '10', 'narrow' => '']);
    vdReject(function () use ($service, $quote, $incompleteVersion) {
        $service->confirm($quote, $incompleteVersion['workflow_version']);
    });
    vdReject(function () use ($service, $quote, $incompleteVersion) {
        $service->approve($quote, [$incompleteVersion['workflow_version']]);
    });
    vdCheck(count($service->state($quote)['audit']) >= 10);
    $languageFields = [];
    foreach ($db->query('SELECT entity_id,role,field_id FROM app_vd_quote_native_fields WHERE entity_id IN(146,262)') as $field) {
        $languageFields[$field['entity_id']][$field['role']] = 'field_' . (int) $field['field_id'];
        vdCheck((int) $db->query('SELECT is_required FROM app_fields WHERE id=' . (int) $field['field_id'])->fetch_row()[0] === 0);
    }
    $db->query("UPDATE app_entity_146 SET " . $languageFields[146]['language'] . "='true' WHERE id=$quote");
    vdReject(function () use ($service, $quote, $v3) { $service->approve($quote, [$v3['workflow_version']]); });
    $db->query("UPDATE app_entity_146 SET " . $languageFields[146]['english_reviewed'] . "='true'," .
        $languageFields[146]['english_description'] . "='Printed invitations' WHERE id=$quote");
    vdReject(function () use ($service, $quote, $v3) { $service->approve($quote, [$v3['workflow_version']]); });
    $db->query("UPDATE app_entity_262 SET " . $languageFields[262]['english_technical'] .
        "='210 x 297 mm, Offset printing (CMYK), paper and finishing reviewed' WHERE id=$component");
    $db->query("UPDATE app_entity_146 SET field_3928='Customer notes' WHERE id=$quote");
    vdReject(function () use ($service, $quote, $v3) { $service->approve($quote, [$v3['workflow_version']]); });
    $db->query("UPDATE app_entity_146 SET " . $languageFields[146]['english_observations'] .
        "='Reviewed customer notes' WHERE id=$quote");
    $englishId = $service->approve($quote, [$v3['workflow_version']])['document'];
    $englishDiscount = json_decode($service->document($quote, $englishId)['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    vdCheck((float) $englishDiscount['customer']['discountPercent'] === 10.0);
    $english = $service->document($quote, $englishId);
    $englishSnapshot = json_decode($english['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    vdCheck($englishSnapshot['quote']['language'] === 'en' && $englishSnapshot['quote']['englishReviewed'] === true &&
        strpos($english['html'], 'lang="en"') !== false && strpos($english['html'], '30 days') !== false);
    $db->query("UPDATE app_entity_146 SET " . $languageFields[146]['language'] . "='false' WHERE id=$quote");
    vdCheck($service->document($quote, $englishId)['html'] === $english['html']);
    users::$transport = true;
    clicksend::$transport = false;
    $db->query("UPDATE app_entity_146 SET field_2030=36 WHERE id=$quote");
    $englishSent = $service->send($quote, $englishId);
    vdCheck($englishSent['state'] === 'sent' && $englishSent['sms_state'] === 'send_unknown');
    vdCheck((int) $db->query("SELECT field_2030 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] === 39);
    vdCheck(users::$subject === 'Quotation ' . $expectedNumber);
    vdCheck(strpos(users::$body, 'Please find our quotation attached as a PDF.') !== false &&
        strpos(users::$body, 'lang="en"') !== false && !file_exists(users::$attachmentPath));
    vdCheck(strpos(clicksend::$message, 'has been sent by email. Thank you.') !== false &&
        strpos($englishSent['message'], 'não confirmou o SMS') !== false);
    $smsCalls = clicksend::$calls;
    vdReject(function () use ($service, $quote, $englishId) { $service->send($quote, $englishId); });
    vdCheck(clicksend::$calls === $smsCalls);
    $newAttempt = ['request_id' => 'fixture-' . bin2hex(random_bytes(16)),
        'email' => 'other-recipient@example.invalid', 'phone' => ''];
    vdReject(function () use ($service, $quote, $englishId, $newAttempt) { $service->send($quote, $englishId, $newAttempt); });
    vdCheck(clicksend::$calls === $smsCalls);
    // Resolve only the isolated test double's unknown SMS before the next fixture scenario.
    $db->query("UPDATE app_vd_quote_deliveries SET state='sent' WHERE document_id=$englishId AND state='send_unknown'");
    $emailOnlyId = $service->approve($quote, [$v3['workflow_version']])['document'];
    $db->query("UPDATE app_entity_146 SET field_3926='' WHERE id=$quote");
    $db->query("UPDATE app_entity_146 SET field_2030=36 WHERE id=$quote");
    $emailOnly = $service->send($quote, $emailOnlyId);
    vdCheck($emailOnly['state'] === 'sent' && $emailOnly['sms_state'] === 'skipped_no_phone' &&
        strpos($emailOnly['message'], 'apenas por email') !== false);
    vdCheck(clicksend::$calls === $smsCalls);
    vdCheck((int) $db->query("SELECT field_2030 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0] === 39);
    vdReject(function () use ($service, $quote, $emailOnlyId) { $service->send($quote, $emailOnlyId); });
    vdCheck(clicksend::$calls === $smsCalls);
    $quantity200 = vdFixture(261, ['parent_item_id' => $quote, 'field_3938' => 200]);
    $secondComponent = vdFixture(262, ['parent_item_id' => $quote, 'field_4218' => 210, 'field_4219' => 297,
        'field_4220' => 1565, 'field_4229' => 1255, 'field_4233' => 1, 'field_6110' => 1544]);
    $multiVersions = [(int) $v3['workflow_version']];
    $chosenVersions = [];
    foreach ([[$component, $quantity200, 200], [$secondComponent, $quantityId, 100], [$secondComponent, $quantity200, 200]] as $choice) {
        list($sourceComponent, $sourceQuantity, $amount) = $choice;
        $multiCalc = $calc;
        $multiCalc['component']['id'] = $sourceComponent;
        $multiCalc['quantity'] = $amount;
        $multiCalc['rubrics'][0]['quantity'] = $amount;
        $multiCalc['quantityId'] = $sourceQuantity;
        $multiCalc['parts'][0]['produced'] = [$amount];
        $multi = $service->save($quote, $sourceComponent, ['request_id' => 'fixture-' . bin2hex(random_bytes(16)),
            'quantity_id' => $sourceQuantity, 'expected_version' => 0, 'calculation' => $multiCalc]);
        $service->confirm($quote, $multi['workflow_version']);
        $multiVersions[] = (int) $multi['workflow_version'];
        if ($amount === 200) $chosenVersions[] = (int) $multi['workflow_version'];
    }
    $multiDoc = $service->approve($quote, array_reverse($multiVersions))['document'];
    $multiSnapshot = json_decode($service->document($quote, $multiDoc)['snapshot'], true);
    vdCheck(array_map('floatval', $multiSnapshot['quantities']) === [100.0, 200.0]);
    $multiState = array_values(array_filter($service->state($quote)['documents'], static function ($d) use ($multiDoc) { return (int) $d['id'] === (int) $multiDoc; }))[0];
    vdCheck(array_column($multiState['alternatives'], 'quantity') === [100.0, 200.0]);
    $service->send($quote, $multiDoc);
    $multiAccepted = $service->accept($quote, $multiDoc, 200, 'Multi-component alternative', null, vd_production_delivery_default());
    $acceptedVersions = $multiAccepted['production_versions'];
    sort($acceptedVersions);
    sort($chosenVersions);
    vdCheck($acceptedVersions === $chosenVersions);
    $multiOrder = (int) $multiAccepted['production_order']['id'];
    vdCheck((int) $db->query("SELECT COUNT(*) FROM app_entity_159 WHERE parent_item_id=$multiOrder AND field_2242=200")->fetch_row()[0] === 2);
    vdCheck((int) $db->query("SELECT COUNT(DISTINCT m.parent_item_id) FROM app_entity_$componentMaterialEntity m JOIN app_vd_component_material_rows r ON r.native_id=m.id WHERE r.order_id=$multiOrder")->fetch_row()[0] === 2);
    vdCheck((int) $db->query("SELECT COUNT(DISTINCT version_id) FROM app_vd_quote_production_rows WHERE order_id=$multiOrder")->fetch_row()[0] === 2);
    vdCheck(!$db->query("SELECT native_id FROM app_vd_quote_production_rows WHERE order_id=$multiOrder AND version_id=" . (int) $v3['workflow_version'])->num_rows);
    vdCheck($service->createProductionOrder($quote)['id'] === $multiOrder);
    echo "$checks integration checks passed; transport was a local double only.\n";
} finally {
    if ($quote) {
        vdRemoveFixtureOrders($quote);
        foreach ($db->query("SELECT native_name,uploaded_at FROM app_vd_quote_approval_files WHERE quote_id=$quote UNION SELECT native_name,uploaded_at FROM app_vd_quote_approval_file_history WHERE quote_id=$quote") as $file) {
            $path = DIR_WS_ATTACHMENTS . date('Y/m/d', $file['uploaded_at']) . '/' . sha1($file['native_name']);
            if ($file['native_name'] !== '' && is_file($path) && !unlink($path)) throw new RuntimeException('Fixture attachment cleanup failed.');
        }
        $db->query("DELETE p FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote");
        foreach (['app_vd_quote_approval_file_history','app_vd_quote_approval_files','app_vd_quote_deliveries','app_vd_quote_sms','app_vd_quote_audit','app_vd_quote_documents','app_vd_quote_versions','app_vd_quote_control'] as $table) $db->query("DELETE FROM $table WHERE quote_id=$quote");
        $db->query("DELETE FROM app_vd_quote_ink_estimates WHERE quote_id=$quote");
        $db->query("DELETE FROM app_vd_quote_ink_pdfs WHERE quote_id=$quote");
        $db->query("DELETE FROM app_vd_quote_legacy WHERE quote_id=$quote");
        foreach ([276,279,388,278,277,280] as $entity) $db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_261 WHERE parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_262 WHERE parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
    }
    if ($customer) $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    echo "Isolated fixtures removed.\n";
}

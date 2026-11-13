<?php
if (PHP_SAPI !== 'cli') exit(1);
$manifest = __DIR__ . '/browser-fixture.json';
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if (($argv[1] ?? '') === 'cleanup') {
    $fixture = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
    $quote = (int) $fixture['quote'];
    $component = (int) $fixture['component'];
    $customer = (int) $fixture['customer'];
    $marker = $db->query("SELECT field_3927 FROM app_entity_146 WHERE id=$quote")->fetch_assoc();
    if (!$marker || $marker['field_3927'] !== 'ISOLATED BROWSER FIXTURE — NEVER SEND') throw new RuntimeException('Unsafe browser fixture cleanup.');
    $db->query("DELETE p FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote");
    foreach (['app_vd_quote_products','app_vd_quote_product_components','app_vd_quote_ink_estimates','app_vd_quote_ink_pdfs','app_vd_quote_audit','app_vd_quote_documents','app_vd_quote_versions','app_vd_quote_control','app_vd_quote_legacy'] as $table) $db->query("DELETE FROM $table WHERE quote_id=$quote");
    foreach ([276,279,388,278,277,280] as $entity) $db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
    $db->query("DELETE FROM app_entity_261 WHERE parent_item_id=$quote");
    $db->query("DELETE FROM app_entity_262 WHERE parent_item_id=$quote");
    $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
    $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    foreach (["SELECT id FROM app_entity_146 WHERE id=$quote", "SELECT id FROM app_entity_26 WHERE id=$customer",
        "SELECT id FROM app_vd_quote_versions WHERE quote_id=$quote", "SELECT id FROM app_vd_quote_documents WHERE quote_id=$quote"] as $check) {
        if ($db->query($check)->num_rows) throw new RuntimeException('Fixture cleanup persistence mismatch.');
    }
    unlink($manifest);
    echo "Browser fixtures removed and verified.\n";
    exit;
}
if (file_exists($manifest)) throw new RuntimeException('Existing fixture must be cleaned first.');
function insertFixture($entity, array $values) {
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
$db->begin_transaction();
try {
    $customer = insertFixture(26, ['field_221' => 'ISOLATED BROWSER FIXTURE — NEVER SEND', 'field_228' => 'workflow-fixture@example.invalid', 'field_5121' => 1370, 'field_5125' => 1374]);
    $quote = insertFixture(146, ['field_3923' => $customer, 'field_3925' => 'workflow-fixture@example.invalid', 'field_3927' => 'ISOLATED BROWSER FIXTURE — NEVER SEND', 'field_2030' => 36]);
    $component = ($argv[1]??'')==='product' ? null : insertFixture(262, ['parent_item_id' => $quote, 'field_4218' => 210, 'field_4219' => 297, 'field_4233' => 1, 'field_6110' => 1544, 'field_4220' => 1565, 'field_4229' => 1255, 'field_6169' => '1', 'field_6170' => '1']);
    $quantity = insertFixture(261, ['parent_item_id' => $quote, 'field_3938' => 100]);
    if (($argv[1] ?? '') === 'ink') {
        $calculation = ['source'=>'dynamic','quantity'=>100,'quantityId'=>$quantity,'machineId'=>6,'modeId'=>3747,
            'component'=>['id'=>$component,'width'=>210,'height'=>297],
            'rubrics'=>[['id'=>0,'name'=>'Synthetic cyan','quantity'=>100]],
            'parts'=>[['operation'=>['id'=>7],'machine'=>['id'=>6],'modeId'=>3747,
                'plan'=>['width'=>720,'height'=>510,'poses'=>2],'allocation'=>[2],
                'result'=>['goodPlans'=>50,'wastePlans'=>10]]],
            'allOperations'=>['complete'=>true,'errors'=>[],'cost'=>10,'sale'=>12,
                'lines'=>[['key'=>'printing','name'=>'Fixture printing','cost'=>10,'sale'=>12]]]];
        $payload=json_encode(['version'=>5,'calculation'=>$calculation],JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$payload);
        $native=insertFixture(388,['parent_item_id'=>$component,'field_6101'=>$quantity,'field_6102'=>100,'field_6106'=>$payload]);
        $request='ink-fixture-'.bin2hex(random_bytes(16));
        $now=time();
        $stmt=$db->prepare("INSERT INTO app_vd_quote_versions(quote_id,component_id,quantity_id,quantity,version_no,state,hash,payload,native_id,request_id,created_by,created_at) VALUES(?,?,?,100,1,'draft',?,?,?,?,1,?)");
        $stmt->bind_param('iiissisi',$quote,$component,$quantity,$hash,$payload,$native,$request,$now);
        $stmt->execute();
    }
    $fixture = compact('quote','component','customer','quantity');
    if (file_put_contents($manifest, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot persist fixture cleanup manifest.');
    $db->commit();
    echo json_encode($fixture, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}

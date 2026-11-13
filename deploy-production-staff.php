<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/quote-production-order.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
foreach ($db->query('SELECT quote_id,order_id FROM app_vd_quote_production_orders')->fetch_all(MYSQLI_ASSOC) as $order) {
    $lock = 'vd_workflow_' . (int) $order['quote_id'];
    if ((int) $db->query("SELECT GET_LOCK('$lock',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
    try {
        $db->begin_transaction();
        $changes = [];
        $orderId = (int) $order['order_id'];
        $operations = $db->query("SELECT o.* FROM app_entity_170 o JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=o.id WHERE r.order_id=$orderId FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        foreach ($operations as $operation) {
            $id = (int) $operation['id'];
            $staff = vd_production_operation_staff($db, $operation['field_2426']);
            foreach ($staff as $field => $value) {
                if (trim((string) $operation[$field]) !== '' || $value === '') continue;
                $stmt = $db->prepare("UPDATE app_entity_170 SET $field=? WHERE id=?");
                $stmt->bind_param('si', $value, $id);
                $stmt->execute();
                $operation[$field] = $value;
                $changes[] = ['operation' => $id, 'field' => $field, 'users' => $value];
            }
            vd_production_staff_index($db, $id, ['field_2428' => $operation['field_2428'], 'field_2472' => $operation['field_2472']]);
        }
        if ($changes) {
            $json = json_encode(['order' => $orderId, 'source' => 'current_operation_catalog',
                'changes' => $changes, 'existing_assignments_preserved' => true], JSON_THROW_ON_ERROR);
            $now = time();
            $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,0,'production_staff_assigned',?,?)");
            $stmt->bind_param('isi', $order['quote_id'], $json, $now);
            $stmt->execute();
        }
        $db->commit();
        echo "OP $orderId: ", count($changes), " empty staffing fields assigned.\n";
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }
}

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/quote-production-order.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->query("UPDATE app_reports SET listing_order_fields='2430_asc' WHERE entities_id=170 AND reports_type='kanban3' AND listing_order_fields=''");
foreach ($db->query('SELECT quote_id,order_id FROM app_vd_quote_production_orders')->fetch_all(MYSQLI_ASSOC) as $order) {
    $lock = 'vd_workflow_' . (int) $order['quote_id'];
    if ((int) $db->query("SELECT GET_LOCK('$lock',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
    try {
        $db->begin_transaction();
        $orderId = (int) $order['order_id'];
        $changes = [];
        $operations = $db->query("SELECT o.* FROM app_entity_170 o JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=o.id WHERE r.order_id=$orderId ORDER BY o.parent_item_id,o.field_2430+0,o.id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        $components = [];
        foreach ($operations as $operation) {
            $id = (int) $operation['id'];
            if ((int) $operation['field_2426'] === 44) {
                if ((float) $operation['field_2549'] !== 0.0) throw new RuntimeException("Administrative operation $id has progress; explicit reconciliation required.");
                foreach ($db->query('SELECT id FROM app_entities WHERE parent_id=170')->fetch_all(MYSQLI_ASSOC) as $child) {
                    $entity = (int) $child['id'];
                    if ($db->query("SELECT id FROM app_entity_$entity WHERE parent_item_id=$id LIMIT 1")->num_rows) throw new RuntimeException("Administrative operation $id has dependent records; preserved.");
                }
                $json = json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $hash = hash('sha256', $json);
                $now = time();
                $reason = 'Custo exclusivo do orçamento, sem tarefa de produção; remoção autorizada pelo utilizador.';
                $stmt = $db->prepare('INSERT INTO app_vd_production_excluded_operations(native_id,order_id,operation_id,native_snapshot,hash,reason,archived_at) VALUES(?,?,44,?,?,?,?)');
                $stmt->bind_param('iisssi', $id, $orderId, $json, $hash, $reason, $now);
                $stmt->execute();
                $db->query("DELETE FROM app_entity_170 WHERE id=$id AND field_2426=44");
                $changes[] = ['archived_operation' => $id, 'operation_type' => 44];
            } else {
                $components[$operation['parent_item_id']][] = $operation;
            }
        }
        foreach ($components as $component => $rows) {
            $types = array_map('intval', array_column($rows, 'field_2426'));
            if (count($types) !== count(array_unique($types))) throw new RuntimeException('Repeated operation types; reconcile sequence manually.');
            $expected = vd_production_operation_sequence($types);
            if ($expected === $types) continue;
            $byType = array_column($rows, null, 'field_2426');
            $positions = array_column($rows, 'field_2430');
            if (count(array_unique($positions)) !== count($positions)) throw new RuntimeException('Ambiguous sequence positions.');
            foreach ($expected as $index => $type) {
                $op = $byType[$type];
                if ((string) $op['field_2430'] === (string) $positions[$index]) continue;
                $stmt = $db->prepare('UPDATE app_entity_170 SET field_2430=? WHERE id=?');
                $stmt->bind_param('si', $positions[$index], $op['id']);
                $stmt->execute();
                $changes[] = ['operation' => (int) $op['id'], 'previous_position' => $op['field_2430'], 'position' => $positions[$index]];
            }
        }
        if ($changes) {
            $json = json_encode(['order' => $orderId, 'changes' => $changes, 'quotation_costs_changed' => false,
                'approved_snapshots_changed' => false], JSON_THROW_ON_ERROR);
            $now = time();
            $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,0,'production_operation_policy_updated',?,?)");
            $stmt->bind_param('isi', $order['quote_id'], $json, $now);
            $stmt->execute();
        }
        $db->commit();
        echo "OP $orderId: ", count($changes), " operation policy changes.\n";
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }
}

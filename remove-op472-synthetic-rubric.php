<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/quote-production-order.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_workflow_24',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
try {
    $db->begin_transaction();
    $row = $db->query('SELECT * FROM app_vd_quote_production_rows WHERE order_id=472 AND entity_id=145 AND native_id=605 AND parent_id=524 FOR UPDATE')->fetch_assoc();
    if (!$row) throw new RuntimeException('Expected rubric provenance missing.');
    if (vd_production_rubric_archived($db, $row)) {
        echo "Rubric 605 already removed; archive verified.\n";
    } else {
        $source = $db->query('SELECT payload FROM app_vd_quote_versions WHERE id=89 AND quote_id=24 AND component_id=30')->fetch_assoc();
        $snapshot = json_decode($source['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!empty($snapshot['nativeRubrics']) || !empty($snapshot['calculation']['rubrics']) ||
            $db->query('SELECT id FROM app_entity_280 WHERE parent_item_id=30')->num_rows) {
            throw new RuntimeException('Real quotation rubrics found; removal refused.');
        }
        $native = $db->query('SELECT * FROM app_entity_145 WHERE id=605 AND parent_item_id=524 FOR UPDATE')->fetch_assoc();
        if (!$native || $native['field_2020'] !== 'Cartazes Rudolph Arié' ||
            (float) $native['field_2019'] !== 140.0 || $native['field_2021'] !== '') {
            throw new RuntimeException('Rubric edited; removal refused.');
        }
        if ($db->query('SELECT id FROM app_entities WHERE parent_id=145')->num_rows) throw new RuntimeException('Rubric has child entities; review required.');
        $json = json_encode($native, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $now = time();
        $stmt = $db->prepare('INSERT INTO app_vd_production_removed_rubrics VALUES(605,472,524,?,?,?)');
        $stmt->bind_param('ssi', $json, $hash, $now);
        $stmt->execute();
        $db->query('DELETE FROM app_entity_145_values WHERE items_id=605');
        $db->query('DELETE FROM app_entity_145 WHERE id=605 AND parent_item_id=524');
        if ($db->affected_rows !== 1) throw new RuntimeException('Rubric changed concurrently.');
        $details = json_encode(['order'=>472,'component'=>524,'rubric'=>605,'archive_hash'=>$hash,
            'reason'=>'Synthetic rubric created without quotation rubrics','original_manifest_preserved'=>true], JSON_THROW_ON_ERROR);
        $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(24,0,'synthetic_production_rubric_removed',?,?)");
        $stmt->bind_param('si', $details, $now);
        $stmt->execute();
        if (!vd_production_rubric_archived($db, $row)) throw new RuntimeException('Removal verification failed.');
        echo "Rubric 605 removed from OP472; native copy archived, original provenance preserved.\n";
    }
    $db->commit();
} catch (Throwable $error) { $db->rollback(); throw $error; }
finally { $db->query("SELECT RELEASE_LOCK('vd_workflow_24')"); }

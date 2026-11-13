<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/production-schedule.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
// Expose the planned minutes instead of concealing the time component.
foreach ([2536,2531] as $id) {
    $field = $db->query("SELECT configuration FROM app_fields WHERE id=$id AND entities_id=170")->fetch_assoc();
    if (!$field) throw new RuntimeException('Production scheduling date field missing.');
    $cfg = json_decode($field['configuration'], true, 512, JSON_THROW_ON_ERROR);
    $cfg['timepicker'] = '1';
    $cfg['step'] = '1';
    $cfg['format_time'] = 'H:i';
    $json = json_encode($cfg, JSON_THROW_ON_ERROR);
    $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=? AND configuration=?');
    $stmt->bind_param('sis', $json, $id, $field['configuration']);
    $stmt->execute();
}
if (($argv[1] ?? '') !== '--order' || !ctype_digit($argv[2] ?? '') || (int) $argv[2] < 1) {
    throw new RuntimeException('Specify an explicit OP with --order ID.');
}
$id = (int) $argv[2];
$order = $db->query("SELECT quote_id FROM app_vd_quote_production_orders WHERE order_id=$id")->fetch_assoc();
if (!$order) throw new RuntimeException('OP is not managed by the quotation workflow.');
$lock = 'vd_workflow_' . (int) $order['quote_id'];
if ((int) $db->query("SELECT GET_LOCK('$lock',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
try {
    $db->begin_transaction();
    $result = vd_production_schedule_order($db, $id, true, 0);
    $db->commit();
    echo "OP $id: ", json_encode($result, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) { $db->rollback(); throw $error; }
finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }

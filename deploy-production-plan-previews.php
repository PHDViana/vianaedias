<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/quote-production-order.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
foreach ($db->query('SELECT * FROM app_vd_quote_production_orders')->fetch_all(MYSQLI_ASSOC) as $order) {
    $lock = 'vd_workflow_' . (int) $order['quote_id'];
    if ((int) $db->query("SELECT GET_LOCK('$lock',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
    try {
        $db->begin_transaction();
        if (!hash_equals($order['hash'], hash('sha256', $order['snapshot']))) throw new RuntimeException('Invalid OP origin.');
        $origin = json_decode($order['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $versions = array_column($origin['versions'], null, 'id');
        $count = 0;
        foreach ($db->query('SELECT * FROM app_vd_quote_production_rows WHERE entity_id=158 AND order_id=' . (int) $order['order_id'])->fetch_all(MYSQLI_ASSOC) as $row) {
            if (!hash_equals($row['hash'], hash('sha256', $row['snapshot']))) throw new RuntimeException('Invalid plan origin.');
            $id = (int) $row['native_id'];
            $native = $db->query("SELECT parent_item_id,field_2209 FROM app_entity_158 WHERE id=$id FOR UPDATE")->fetch_assoc();
            if (!$native || (int) $native['parent_item_id'] !== (int) $row['parent_id']) throw new RuntimeException('Plan missing or moved.');
            $originalDetail = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $detail = $originalDetail;
            $source = $versions[$row['version_id']]['snapshot'];
            $index = (int) substr($row['line_key'], strlen('plan:'));
            $part = $source['calculation']['parts'][$index] ?? $source['calculation'];
            $detail['mode_id'] = $detail['mode_id'] ?? $part['modeId'] ?? $source['calculation']['modeId'] ?? '';
            $detail['plan'] += ['component_width' => $source['nativeComponent']['field_4218'],
                'component_height' => $source['nativeComponent']['field_4219']];
            $preview = vd_production_plan_display($detail);
            $current = $native['field_2209'];
            $marker = strpos($current, '<div class="vd-production-plan-preview"');
            if ($marker !== false) {
                $previous = str_replace('.</p>', '. Cores: rubricas; verde: área útil; laranja: pinça; tracejado: viragem. Dados da versão adjudicada.</p>', $preview);
                if (substr($current, $marker) === $preview) continue;
                if (substr($current, $marker) !== $previous) throw new RuntimeException("Plan $id preview edited; reconcile before publishing.");
                $updated = substr($current, 0, $marker) . $preview;
            } else {
                $legacy = '<p>Imposição, pinças, rotação, sangria, desperdício e tiragem fixados:</p><pre>' .
                    htmlspecialchars(json_encode($originalDetail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') . '</pre>';
                if (substr($current, -strlen($legacy)) !== $legacy) throw new RuntimeException("Plan $id edited; original display was preserved.");
                $updated = substr($current, 0, -strlen($legacy)) . $preview;
            }
            $stmt = $db->prepare('UPDATE app_entity_158 SET field_2209=? WHERE id=? AND field_2209=?');
            $stmt->bind_param('sis', $updated, $id, $current);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Plan changed concurrently.');
            $count++;
        }
        if ($count) {
            $detail = json_encode(['order' => (int) $order['order_id'], 'plans' => $count,
                'snapshots_changed' => false], JSON_THROW_ON_ERROR);
            $now = time();
            $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,0,'production_plan_previews_updated',?,?)");
            $stmt->bind_param('isi', $order['quote_id'], $detail, $now);
            $stmt->execute();
        }
        $db->commit();
        echo "OP {$order['order_id']}: $count plan previews updated.\n";
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }
}

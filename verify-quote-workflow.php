<?php
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
require_once __DIR__ . '/quote-component-materials.php';
require_once __DIR__ . '/quote-production-order.php';
require_once __DIR__ . '/production-component-guards.php';
vd_production_install_component_guards($db,false);
$manifest = json_decode(file_get_contents(__DIR__ . '/workflow-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $target => $entry) {
    if (hash_file('sha256', $target) !== $entry['hash']) throw new RuntimeException('Managed file integrity failure: ' . $target);
}
foreach ($manifest['custom_php'] ?? [] as $id => $entry) {
    $code = $db->query('SELECT code FROM app_custom_php WHERE id=' . (int) $id)->fetch_row()[0];
    if (hash('sha256', $code) !== $entry['hash']) throw new RuntimeException('Native helper integrity failure.');
}
$productCostingSettings=$db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=364')->fetch_row()[0];
$productCosting=json_decode($productCostingSettings,true,512,JSON_THROW_ON_ERROR);
if ($productCosting['php_code']!==preg_replace('/^<\?php\s*/','',file_get_contents(__DIR__.'/costing-configurator.php'))) throw new RuntimeException('Product-aware costing report integrity failure.');
echo "Verified product-aware costing report 364\n";
foreach ([365 => '3eae9d9ff5c0444d320f994ac2412b358a78fbf94d3ce89e6cf61dff40b5cf3c'] as $id => $hash) {
    $settings = $db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=' . $id)->fetch_row()[0];
    if (hash('sha256', $settings) !== $hash) throw new RuntimeException('Report integrity failure: ' . $id);
    echo 'Verified report ', $id, ' ', $hash, PHP_EOL;
}
$asset = json_decode(file_get_contents(__DIR__ . '/costing-asset.json'), true, 512, JSON_THROW_ON_ERROR);
if (hash_file('sha256', 'js/' . $asset['name']) !== $asset['hash']) throw new RuntimeException('Costing asset integrity failure.');
$markupConfiguration = json_decode($db->query('SELECT configuration FROM app_fields WHERE id=6271')->fetch_row()[0],
    true, 512, JSON_THROW_ON_ERROR);
if (($markupConfiguration['default_value'] ?? null) !== '50') throw new RuntimeException('Markup default must be 50%.');
$commercialFields = $db->query("SELECT f.entities_id,f.is_required,f.type,n.role FROM app_vd_quote_native_fields n JOIN app_fields f ON f.id=n.field_id WHERE n.entity_id IN(146,262) AND n.role IN('language','english_description','english_observations','english_payment','english_reviewed','english_technical')")->fetch_all(MYSQLI_ASSOC);
if (count($commercialFields) !== 6) throw new RuntimeException('Missing English commercial fields.');
foreach ($commercialFields as $field) {
    if ((int) $field['is_required'] !== 0) throw new RuntimeException('English fields must not block Portuguese native forms.');
}
$triggers = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'vd_quote_%_immutable_%'")->num_rows;
if ($triggers !== 6) throw new RuntimeException('Missing immutable native projection triggers.');
$initialProgress = $db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='vd_quote_component_initial_progress'")->fetch_assoc();
if (!$initialProgress || trim($initialProgress['ACTION_STATEMENT']) !== 'SET NEW.field_4241 = 5') throw new RuntimeException('Missing component initial progress trigger.');
$archives = $db->query('SELECT * FROM app_vd_quote_legacy')->fetch_all(MYSQLI_ASSOC);
foreach ($archives as $archive) {
    if (hash('sha256', $archive['snapshot']) !== $archive['hash']) throw new RuntimeException('Legacy archive integrity failure.');
    $current = $db->query('SELECT s.*,c.parent_item_id quote_id FROM app_entity_388 s JOIN app_entity_262 c ON c.id=s.parent_item_id WHERE s.id=' . (int) $archive['native_id'])->fetch_assoc();
    if (!$current || json_encode($current, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !== $archive['snapshot']) {
        throw new RuntimeException('Original legacy selection changed.');
    }
}
if (!$db->query('SELECT group_id FROM app_vd_quote_approval_groups WHERE group_id=4')->num_rows) {
    throw new RuntimeException('Initial approval profile missing.');
}
if (strtolower($db->query("SHOW COLUMNS FROM app_entity_388 LIKE 'field_6106'")->fetch_assoc()['Type']) !== 'mediumtext') {
    throw new RuntimeException('Native snapshot capacity mismatch.');
}
foreach (['app_vd_quote_versions', 'app_vd_quote_documents'] as $table) {
    echo $table, ': ', $db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0], PHP_EOL;
}
foreach (['op_article' => 99, 'op_internal' => 281] as $role => $catalog) {
    $field = $db->query("SELECT f.configuration FROM app_vd_quote_native_fields n JOIN app_fields f ON f.id=n.field_id WHERE n.entity_id=161 AND n.role='$role'")->fetch_assoc();
    if (!$field || (int) json_decode($field['configuration'], true, 512, JSON_THROW_ON_ERROR)['entity_id'] !== $catalog) {
        throw new RuntimeException('Production material catalog mismatch: ' . $role);
    }
}
foreach ($db->query('SELECT * FROM app_vd_quote_production_orders') as $order) {
    if (!hash_equals($order['hash'], hash('sha256', $order['snapshot']))) throw new RuntimeException('Production origin hash mismatch.');
    $snapshot = json_decode($order['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $deps=$db->query('SELECT component_id,input_component_id FROM app_vd_production_component_inputs WHERE order_id='.(int)$order['order_id'].' ORDER BY component_id,input_component_id')->fetch_all(MYSQLI_ASSOC);
    $expected=$snapshot['component_dependencies']??[];
    usort($expected,static function($a,$b){return [$a['component_id'],$a['input_component_id']]<=>[$b['component_id'],$b['input_component_id']];});
    if ($deps!=$expected) throw new RuntimeException('Production component dependency integrity failure.');
    $native = $db->query('SELECT field_5972 FROM app_entity_121 WHERE id=' . (int) $order['order_id'])->fetch_assoc();
    if (!$native || (int) $native['field_5972'] !== (int) $order['quote_id']) throw new RuntimeException('Production quote link mismatch.');
    $rows = $db->query('SELECT * FROM app_vd_quote_production_rows WHERE order_id=' . (int) $order['order_id'])->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== count($snapshot['native_rows'])) throw new RuntimeException('Missing production provenance rows.');
    foreach ($rows as $row) {
        $entity = (int) $row['entity_id'];
        if (!in_array($entity, [159,145,158,170,161,172], true)) throw new RuntimeException('Unexpected production entity.');
        if (vd_production_rubric_archived($db, $row)) continue;
        if ($entity === 170 && $row['line_key'] === 'operation:44') {
            $archived = $db->query('SELECT * FROM app_vd_production_excluded_operations WHERE operation_id=44 AND order_id=' . (int) $order['order_id'] . ' AND native_id=' . (int) $row['native_id'])->fetch_assoc();
            if ($archived) {
                if (!hash_equals($archived['hash'], hash('sha256', $archived['native_snapshot'])) ||
                    !hash_equals($row['hash'], hash('sha256', $row['snapshot'])) ||
                    $db->query('SELECT id FROM app_entity_170 WHERE id=' . (int) $row['native_id'])->num_rows) throw new RuntimeException('Administrative archive mismatch.');
                continue;
            }
        }
        $native = $db->query("SELECT parent_item_id FROM app_entity_$entity WHERE id=" . (int) $row['native_id'])->fetch_assoc();
        if (!$native || (int) $native['parent_item_id'] !== (int) $row['parent_id'] ||
            !hash_equals($row['hash'], hash('sha256', $row['snapshot']))) throw new RuntimeException('Production structure or source mismatch.');
        if ($entity === 170) {
            $staff = $db->query('SELECT field_2428,field_2472 FROM app_entity_170 WHERE id=' . (int) $row['native_id'])->fetch_assoc();
            foreach ([2428,2472] as $field) {
                $expected = array_values(array_filter(explode(',', $staff['field_' . $field]), 'strlen'));
                $indexed = array_column($db->query('SELECT value FROM app_entity_170_values WHERE items_id=' . (int) $row['native_id'] . " AND fields_id=$field")->fetch_all(MYSQLI_ASSOC), 'value');
                sort($expected);
                sort($indexed);
                if ($expected !== $indexed) throw new RuntimeException('Production staff index mismatch.');
            }
        }
    }
    echo 'Verified OP ', $order['order_id'], ' for quote ', $order['quote_id'], ', ', count($rows), ' native rows.', PHP_EOL;
    $materialSchema = vd_component_material_schema($db);
    $materialEntity = $materialSchema['entity'];
    $fields = $materialSchema['fields'];
    $materials = $db->query('SELECT r.*,p.native_id component_material_id,p.hash projection_hash,o.parent_item_id component_id FROM app_vd_quote_production_rows r JOIN app_entity_170 o ON o.id=r.parent_id LEFT JOIN app_vd_component_material_rows p ON p.source_id=r.native_id WHERE r.entity_id=161 AND r.order_id=' . (int) $order['order_id'])->fetch_all(MYSQLI_ASSOC);
    foreach ($materials as $material) {
        if (!$material['component_material_id'] || !hash_equals($material['hash'], $material['projection_hash'])) throw new RuntimeException('Component material projection missing or mismatched.');
        $native = $db->query("SELECT * FROM app_entity_$materialEntity WHERE id=" . (int) $material['component_material_id'])->fetch_assoc();
        if (!$native || (int) $native['parent_item_id'] !== (int) $material['component_id'] ||
            (int) $native[$fields['source']] !== (int) $material['native_id'] ||
            (int) $native[$fields['operation']] !== (int) $material['parent_id'] ||
            !hash_equals($material['hash'], hash('sha256', $native[$fields['snapshot']]))) throw new RuntimeException('Component material structure or source mismatch.');
    }
    echo 'Verified ', count($materials), ' materials directly under components, entity ', $materialEntity, '.', PHP_EOL;
}
echo 'Verified managed files, native helper, immutable asset, six triggers, historical originals, approval profile and snapshot capacity.', PHP_EOL;
if ($db->query("SHOW TABLES LIKE 'app_vd_production_time_files'")->num_rows) {
    foreach ($db->query('SELECT path,hash FROM app_vd_production_time_files')->fetch_all(MYSQLI_ASSOC) as $file) {
        if (!is_file($file['path']) || !hash_equals($file['hash'], hash_file('sha256', $file['path']))) throw new RuntimeException('Production Kanban time file changed: ' . $file['path']);
    }
    if ($db->query("SHOW TABLES LIKE 'app_vd_production_gantt_files'")->num_rows) {
        foreach ($db->query('SELECT path,hash FROM app_vd_production_gantt_files')->fetch_all(MYSQLI_ASSOC) as $file) {
            if (!is_file($file['path']) || !hash_equals($file['hash'], hash_file('sha256', $file['path']))) throw new RuntimeException('Production Gantt schedule file changed: ' . $file['path']);
        }
        echo "Verified production Gantt minute schedule files.\n";
    }
    if (is_file('_CustomDEVs/quote-workflow/vd_quote_workflow/quote-ink.php')) {
        foreach (['/usr/local/sbin/vd-ink-analyze'=>'vd-ink-analyze','/usr/local/libexec/vd-ink-worker.php'=>'quote-ink-worker.php'] as $path=>$source) {
            if (!is_file($path) || !hash_equals(hash_file('sha256',__DIR__.'/'.$source),hash_file('sha256',$path)) ||
                fileowner($path)!==0 || (fileperms($path)&0022)!==0) throw new RuntimeException('PDF ink worker changed or unsafe permissions: '.$path);
        }
        foreach (['app_vd_quote_ink_pdfs','app_vd_quote_ink_estimates'] as $table) {
            if (!$db->query("SHOW TABLES LIKE '$table'")->num_rows) throw new RuntimeException('Ink history schema missing.');
        }
        if ($db->query('SELECT e.id FROM app_vd_quote_ink_estimates e LEFT JOIN app_vd_quote_ink_pdfs p ON p.id=e.pdf_id AND p.component_id=e.component_id AND p.quote_id=e.quote_id LEFT JOIN app_vd_quote_versions v ON v.id=e.version_id AND v.component_id=e.component_id AND v.quote_id=e.quote_id WHERE p.id IS NULL OR v.id IS NULL OR e.pdf_hash<>p.hash OR e.version_hash<>v.hash LIMIT 1')->num_rows) {
            throw new RuntimeException('Ink estimate provenance mismatch.');
        }
        echo "Verified local isolated ink worker and estimate provenance.\n";
    }
    if ($db->query("SHOW TABLES LIKE 'app_vd_production_order_gantt'")->num_rows) {
        $planning = $db->query("SELECT p.field_id,p.configuration,f.configuration actual_configuration,f.type,g.start_date,g.end_date,g.entities_id FROM app_vd_production_order_gantt p JOIN app_fields f ON f.id=p.field_id JOIN app_ext_ganttchart g ON g.id=p.id WHERE p.id=2")->fetch_assoc();
        if (!$planning || $planning['configuration'] !== $planning['actual_configuration'] ||
            $planning['type'] !== 'fieldtype_dynamic_date' ||
            (int) $planning['start_date'] !== (int) $planning['field_id'] ||
            (int) $planning['end_date'] !== 1992 || (int) $planning['entities_id'] !== 121) {
            throw new RuntimeException('OP Gantt planned-start configuration changed.');
        }
        echo "Verified OP Gantt computed planned start, separate from actual start.\n";
    }
    echo "Verified production Kanban timing integration files.\n";
}
if ($db->query("SHOW TABLES LIKE 'app_vd_production_report'")->num_rows) {
    $report = $db->query("SELECT p.* FROM app_vd_production_report n JOIN app_ext_report_page p ON p.id=n.native_id WHERE n.role='report'")->fetch_assoc();
    if (!$report || (int) $report['entities_id'] !== 121 || $report['type'] !== 'print' ||
        strpos($report['save_as'], 'print') === false) throw new RuntimeException('Production Designer print report missing.');
    $block = $db->query("SELECT n.native_id,n.hash,b.settings FROM app_vd_production_report n JOIN app_ext_report_page_blocks b ON b.id=n.native_id AND b.report_id=" . (int) $report['id'] . " WHERE n.role='summary'")->fetch_assoc();
    if (!$block || strpos($report['description'], '${' . $block['native_id'] . '}') === false) throw new RuntimeException('Production summary block not in report layout.');
    if (!hash_equals($block['hash'], hash('sha256', $block['settings']))) throw new RuntimeException('Production report block changed; review Designer changes.');
    $navigation = $db->query("SELECT hash FROM app_vd_production_report WHERE role='navigation'")->fetch_assoc();
    if (!$navigation || !hash_equals($navigation['hash'], hash_file('sha256', '/home/vianaedias/public_html/modules/items/components/navigation.php'))) throw new RuntimeException('Production report navigation missing or changed.');
    echo 'Verified native production print Report Designer #', $report['id'], ' and summary block #', $block['native_id'], '.', PHP_EOL;
}

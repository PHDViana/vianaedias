<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/production-schedule.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$formula = vd_production_planned_start_sql('[id]', '[2471]');
$configuration = json_encode(['formula' => $formula, 'date_format' => 'd/m/Y H:i'], JSON_THROW_ON_ERROR);
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_order_gantt(id INT PRIMARY KEY,field_id INT NOT NULL,configuration TEXT NOT NULL,previous_start INT NOT NULL) ENGINE=InnoDB');
if ((int) $db->query("SELECT GET_LOCK('vd_production_order_gantt',10)")->fetch_row()[0] !== 1) throw new RuntimeException('OP Gantt publication busy.');
try {
    // Exercise the same expression against isolated native-shaped fixtures.
    $db->query('CREATE TEMPORARY TABLE vd_gantt_test_operations(id INT,parent_item_id INT,field_2536 INT,field_2531 INT)');
    $db->query('CREATE TEMPORARY TABLE vd_gantt_test_components(id INT,parent_item_id INT)');
    $db->query('CREATE TEMPORARY TABLE vd_gantt_test_orders(id INT,field_2471 INT)');
    $db->query('INSERT INTO vd_gantt_test_orders VALUES(1,0),(2,55),(3,0),(4,66)');
    $db->query('INSERT INTO vd_gantt_test_components VALUES(10,1),(11,1),(20,2),(40,4)');
    $db->query('INSERT INTO vd_gantt_test_operations VALUES(1,10,200,300),(2,11,100,150),(3,10,50,0),(4,20,20,0),(5,40,120,130)');
    $testFormula = str_replace(['app_entity_170','app_entity_159','[id]','[2471]'],
        ['vd_gantt_test_operations','vd_gantt_test_components','e.id','e.field_2471'], $formula);
    $actual = array_map('intval', array_column($db->query("SELECT $testFormula planned FROM vd_gantt_test_orders e ORDER BY e.id")->fetch_all(MYSQLI_ASSOC), 'planned'));
    if ($actual !== [100,55,0,120]) throw new RuntimeException('OP Gantt planned-start checks failed.');
    $db->begin_transaction();
    $report = $db->query('SELECT entities_id,start_date,end_date FROM app_ext_ganttchart WHERE id=2 FOR UPDATE')->fetch_assoc();
    $existing = $db->query('SELECT * FROM app_vd_production_order_gantt WHERE id=2')->fetch_assoc();
    if (!$report || (int) $report['entities_id'] !== 121 || (int) $report['end_date'] !== 1992 ||
        (int) $report['start_date'] !== ($existing ? (int) $existing['field_id'] : 2471)) {
        throw new RuntimeException('OP Gantt configuration changed; reconcile first.');
    }
    if ($existing) {
        $fieldId = (int) $existing['field_id'];
        $field = $db->query("SELECT entities_id,type,configuration FROM app_fields WHERE id=$fieldId")->fetch_assoc();
        if (!$field || (int) $field['entities_id'] !== 121 || $field['type'] !== 'fieldtype_dynamic_date' ||
            $field['configuration'] !== $configuration || $existing['configuration'] !== $configuration) {
            throw new RuntimeException('OP planned-start field changed; reconcile first.');
        }
    } else {
        $field = $db->query('SELECT * FROM app_fields WHERE id=2471 AND entities_id=121')->fetch_assoc();
        if (!$field) throw new RuntimeException('OP actual-start field missing.');
        unset($field['id']);
        $field['type'] = 'fieldtype_dynamic_date';
        $field['name'] = 'Data de Início Prevista';
        $field['short_name'] = '';
        $field['configuration'] = $configuration;
        $field['is_required'] = 0;
        $field['listing_status'] = 0;
        $field['sort_order'] = (int) $field['sort_order'] + 1;
        $field['tooltip'] = 'Início da primeira operação com intervalo planeado. Sem planeamento, usa a data de início existente.';
        $stmt = $db->prepare('INSERT INTO app_fields (`' . implode('`,`', array_keys($field)) . '`) VALUES(' . implode(',', array_fill(0, count($field), '?')) . ')');
        $values = array_values($field);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $fieldId = (int) $db->insert_id;
        $stmt = $db->prepare('INSERT INTO app_vd_production_order_gantt VALUES(2,?,?,2471)');
        $stmt->bind_param('is', $fieldId, $configuration);
        $stmt->execute();
        $db->query("UPDATE app_ext_ganttchart SET start_date=$fieldId WHERE id=2 AND start_date=2471");
    }
    $db->commit();
    // DDL commits implicitly; keep native field storage outside the metadata transaction.
    $column = $db->query("SHOW COLUMNS FROM app_entity_121 LIKE 'field_$fieldId'")->fetch_assoc();
    if (!$column) {
        $db->query("ALTER TABLE app_entity_121 ADD field_$fieldId BIGINT(11) NOT NULL");
        $column = $db->query("SHOW COLUMNS FROM app_entity_121 LIKE 'field_$fieldId'")->fetch_assoc();
    }
    if (!$column || !preg_match('/^bigint(?:\(\d+\))?$/i', $column['Type']) || $column['Null'] !== 'NO') {
        throw new RuntimeException('OP planned-start storage does not match the native dynamic-date type.');
    }
    echo "4 planned-start checks passed. OP Gantt 2 uses computed field $fieldId; actual starts unchanged.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_production_order_gantt')");
}

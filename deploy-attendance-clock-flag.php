<?php
// Cria o pisco "Relógio de Ponto" na ficha de RH (entidade 141, aba Horário de trabalho),
// por defeito ativo, e ativa-o nos colaboradores existentes. Idempotente.
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_attendance_clock_flag',10)")->fetch_row()[0] !== 1) {
    throw new RuntimeException('Lock unavailable.');
}
$db->begin_transaction();
try {
    $existing = $db->query("SELECT id FROM app_fields WHERE entities_id=141 AND type='fieldtype_boolean_checkbox' AND name='Relógio de Ponto'")->fetch_assoc();
    if ($existing) {
        $fieldId = (int) $existing['id'];
        echo "Field already registered: $fieldId\n";
    } else {
        $template = $db->query('SELECT * FROM app_fields WHERE id=1878 AND entities_id=141')->fetch_assoc();
        if (!$template) throw new RuntimeException('Template field 1878 missing.');
        $tab = $db->query("SELECT id FROM app_forms_tabs WHERE id=505 AND entities_id=141")->fetch_assoc();
        if (!$tab) throw new RuntimeException('Tab 505 (Horário de trabalho) missing.');
        unset($template['id']);
        $template['forms_tabs_id'] = 505;
        $template['forms_rows_position'] = '';
        $template['name'] = 'Relógio de Ponto';
        $template['tooltip'] = 'Com o pisco ativo, o colaborador é controlado pelo relógio de ponto e aparece no Resumo de ponto.';
        $template['configuration'] = '{"default_value":"true","text_boolean_true":"Sim","text_boolean_false":"Não"}';
        $template['sort_order'] = 0;
        $template['listing_status'] = 0;
        $template['listing_sort_order'] = 0;
        $stmt = $db->prepare('INSERT INTO app_fields (`' . implode('`,`', array_keys($template)) .
            '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
        $values = array_values($template);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $fieldId = (int) $db->insert_id;
        echo "Field created: $fieldId\n";
    }
    $column = $db->query("SHOW COLUMNS FROM app_entity_141 LIKE 'field_$fieldId'")->fetch_assoc();
    if (!$column) {
        $db->query("ALTER TABLE app_entity_141 ADD `field_$fieldId` VARCHAR(8) NOT NULL DEFAULT ''");
        echo "Column field_$fieldId added.\n";
    }
    $db->query("UPDATE app_entity_141 SET field_$fieldId='true' WHERE field_$fieldId NOT IN('true','false')");
    echo 'Existing employees activated: ' . $db->affected_rows . "\n";
    $db->commit();
    echo "Done. Clock flag field id: $fieldId\n";
} catch (\Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_attendance_clock_flag')");
}

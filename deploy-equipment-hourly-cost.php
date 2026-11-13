<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/equipment-hourly-cost.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_equipment_hourly_cost',10)")->fetch_row()[0] !== 1) {
    throw new RuntimeException('Equipment costing lock unavailable.');
}

function vd_cost_insert(mysqli $db, string $table, array $row): int
{
    unset($row['id']);
    $stmt = $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) .
        '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
    $values = array_values($row);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    return (int) $db->insert_id;
}

$definitions = [
    'replacement' => ['Renovação: custo de substituição (€)', 'Valor previsto de substituição, sem energia ou mão de obra.', '50000'],
    'reserve' => ['Renovação: montante já reservado (€)', 'Montante efetivamente reservado para a substituição.', '0'],
    'years' => ['Renovação: prazo (anos)', 'Anos até à substituição prevista.', '5'],
    'weekly_hours' => ['Custo equipamento: horas semanais', 'Média de horas de preparação e produção.', '7'],
    'weeks' => ['Custo equipamento: semanas anuais', 'Número de semanas de utilização previstas por ano.', '47'],
    'maintenance' => ['Custo equipamento: manutenção anual (€)', 'Manutenção e reparações anuais, sem energia ou mão de obra.', '500'],
    'other' => ['Custo equipamento: outros custos anuais (€)', 'Seguro e outros custos específicos. Deixar vazio se desconhecidos.', null],
    'depreciation' => ['Custo equipamento: amortização contabilística anual (€)', 'Informativa; não é adicionada à reserva de renovação para evitar dupla contagem.', '0'],
];

try {
    $machine = $db->query('SELECT field_1281 FROM app_entity_78 WHERE id=14')->fetch_assoc();
    if (!$machine || $machine['field_1281'] !== 'Cilíndrica - Preta') throw new RuntimeException('Unexpected equipment 14.');
    $tabs = $db->query("SELECT * FROM app_forms_tabs WHERE entities_id=78 AND name='Custo do equipamento'")->fetch_all(MYSQLI_ASSOC);
    if (count($tabs) > 1) throw new RuntimeException('Duplicate cost tabs.');
    $tab = $tabs ? (int) $tabs[0]['id'] : vd_cost_insert($db, 'app_forms_tabs', array_replace(
        $db->query('SELECT * FROM app_forms_tabs WHERE id=84 AND entities_id=78')->fetch_assoc(),
        ['name' => 'Custo do equipamento', 'sort_order' => 100]));
    $fields = [];
    foreach ($definitions as $key => [$name, $tooltip, $initial]) {
        $stmt = $db->prepare('SELECT * FROM app_fields WHERE entities_id=78 AND name=?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($matches) > 1) throw new RuntimeException('Duplicate field: ' . $name);
        if ($matches && ($matches[0]['type'] !== 'fieldtype_input_numeric' || (int) $matches[0]['forms_tabs_id'] !== $tab)) {
            throw new RuntimeException('Conflicting field: ' . $name);
        }
        if ($matches) {
            $id = (int) $matches[0]['id'];
        } else {
            $template = $db->query('SELECT * FROM app_fields WHERE id=6117 AND entities_id=78')->fetch_assoc();
            $config = ['width' => 'input-small', 'number_format' => '2/,/ ', 'min_value' => '0'];
            if ($key === 'weeks') $config['max_value'] = '52';
            $id = vd_cost_insert($db, 'app_fields', array_replace($template, [
                'forms_tabs_id' => $tab, 'forms_rows_position' => '', 'name' => $name, 'tooltip' => $tooltip,
                'tooltip_in_item_page' => 1, 'is_required' => 0, 'is_heading' => 0, 'listing_status' => 0,
                'sort_order' => 10 + count($fields), 'configuration' => json_encode($config, JSON_THROW_ON_ERROR),
            ]));
        }
        $fields[$key] = $id;
        if (!$db->query("SHOW COLUMNS FROM app_entity_78 LIKE 'field_$id'")->num_rows) {
            $db->query("ALTER TABLE app_entity_78 ADD `field_$id` MEDIUMTEXT NULL");
        }
    }

    $name = 'Custo horário de referência do equipamento';
    $stmt = $db->prepare('SELECT * FROM app_fields WHERE entities_id=78 AND name=?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($matches) > 1 || ($matches && ($matches[0]['type'] !== 'fieldtype_php_code' ||
        (int) $matches[0]['forms_tabs_id'] !== $tab))) throw new RuntimeException('Conflicting calculated field.');
    $mapping = [];
    foreach ($fields as $key => $id) $mapping[$key] = 'field_' . $id;
    $code = 'require_once DIR_FS_CATALOG . ' . var_export('includes/vd-equipment-hourly-cost.php', true) . '; ' .
        '$values=[]; foreach (' . var_export($mapping, true) . ' as $role=>$field) $values[$role]=$item_info[$field]??null; ' .
        '$output_value=vd_equipment_cost_html($values);';
    $config = json_encode(['dinamic_query' => '1', 'php_code' => $code], JSON_THROW_ON_ERROR);
    if ($matches) {
        if ($matches[0]['configuration'] !== $config) throw new RuntimeException('Calculated field configuration changed.');
        $output = (int) $matches[0]['id'];
    } else {
        $template = $db->query('SELECT * FROM app_fields WHERE id=6270')->fetch_assoc();
        $output = vd_cost_insert($db, 'app_fields', array_replace($template, [
            'entities_id' => 78, 'forms_tabs_id' => $tab, 'forms_rows_position' => '', 'name' => $name,
            'tooltip' => 'Referência apenas. Não altera operações ou orçamentos.', 'listing_status' => 0,
            'sort_order' => 30, 'configuration' => $config,
        ]));
    }
    if (!$db->query("SHOW COLUMNS FROM app_entity_78 LIKE 'field_$output'")->num_rows) {
        $db->query("ALTER TABLE app_entity_78 ADD `field_$output` MEDIUMTEXT NULL");
    }

    $columns = array_map(static fn($id) => "`field_$id`", $fields);
    $row = $db->query('SELECT ' . implode(',', $columns) . ' FROM app_entity_78 WHERE id=14')->fetch_assoc();
    $empty = true;
    foreach ($row as $value) if ($value !== null && $value !== '') $empty = false;
    if ($empty) {
        $set = [];
        foreach ($definitions as $key => $definition) {
            if ($definition[2] !== null) $set[] = '`field_' . $fields[$key] . "`='" . $db->real_escape_string($definition[2]) . "'";
        }
        $guard = implode(' AND ', array_map(static fn($column) => "($column IS NULL OR $column='')", $columns));
        $db->query('UPDATE app_entity_78 SET ' . implode(',', $set) . ' WHERE id=14 AND ' . $guard);
        if ($db->affected_rows !== 1) throw new RuntimeException('Concurrent equipment update.');
    } else {
        foreach ($definitions as $key => $definition) {
            $actual = $row['field_' . $fields[$key]];
            if ($definition[2] === null ? ($actual !== null && $actual !== '') : (string) $actual !== $definition[2]) {
                throw new RuntimeException('Existing equipment costing data differs; no values overwritten.');
            }
        }
    }
    $row = $db->query('SELECT ' . implode(',', $columns) . ' FROM app_entity_78 WHERE id=14')->fetch_assoc();
    $values = [];
    foreach ($fields as $key => $id) $values[$key] = $row['field_' . $id];
    echo 'Cost tab: ', $tab, '; fields: ', json_encode($fields), '; output: ', $output, PHP_EOL;
    echo vd_equipment_cost_html($values), PHP_EOL;
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_equipment_hourly_cost')");
}

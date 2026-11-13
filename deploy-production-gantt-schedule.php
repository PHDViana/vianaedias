<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_gantt_files(path VARCHAR(255) PRIMARY KEY,hash CHAR(64) NOT NULL) ENGINE=InnoDB');
if ((int) $db->query("SELECT GET_LOCK('vd_production_gantt',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Gantt publication busy.');
try {
    $condition = "(int) \$reports['id'] === 3 && (int) \$reports['entities_id'] === 170";
    $changes = [
        'plugins/ext/modules/ganttchart/actions/dhtmlx.php' => [
            [
                '            $end_date = $item[\'field_\' . $reports[\'end_date\']];',
                '            $end_date = $item[\'field_\' . $reports[\'end_date\']];' . "\n" .
                "            if ((int) \$reports['id'] === 2 && (int) \$reports['entities_id'] === 121) {\n" .
                "                \$start_date = strtotime(date('Y-m-d', \$start_date));\n" .
                "                \$end_date = strtotime(date('Y-m-d', \$end_date));\n" .
                "            }\n",
            ],
            [
                '(ganttchart::has_date_added($reports) ? "e.date_added":"e.field_" . $reports[\'start_date\'])',
                '(ganttchart::has_date_added($reports) ? "e.date_added":(((int) $reports[\'id\'] === 2 && (int) $reports[\'entities_id\'] === 121) ? "field_" : "e.field_") . $reports[\'start_date\'])',
            ],
            [
                "    case 'save':",
                "    case 'save':\n" .
                "        if ((int) \$reports['id'] === 2 && (int) \$reports['entities_id'] === 121) {\n" .
                "            error_log('VD OP Gantt: rejected write to computed planning view.');\n" .
                "            http_response_code(409);\n" .
                "            header('Content-Type: application/json');\n" .
                "            echo json_encode(['error' => 'Altere o planeamento nas operacoes da OP.']);\n" .
                "            exit();\n" .
                "        }\n",
            ],
            [
                '            $heading_field_value = $item[\'id\'];',
                "            /* VD production exact schedule */\n" .
                "            if ($condition) {\n" .
                "                \$duration = max(0, (\$end_date - \$start_date) / 60);\n" .
                "            }\n\n" . '            $heading_field_value = $item[\'id\'];',
            ],
            [
                '                        $sql_data[\'parent_id\'] = $_POST[\'parent\'];',
                "                        /* VD production exact deadline save */\n" .
                "                        if ($condition) {\n" .
                "                            \$sql_data['field_' . \$reports['end_date']] = get_date_timestamp(\$_POST['end_date']);\n" .
                "                        }\n" . '                        $sql_data[\'parent_id\'] = $_POST[\'parent\'];',
            ],
        ],
        'plugins/ext/modules/ganttchart/views/dhtmlx.php' => [
            [
                '//allows ordering',
                "<?php if ((int) \$reports['id'] === 2 && (int) \$reports['entities_id'] === 121): ?>\n" .
                "  gantt.config.readonly = true;\n" .
                "<?php endif; ?>\n//allows ordering",
            ],
            [
                "gantt.config.duration_unit='<?php echo ganttchart::get_duration_unit(\$reports) ?>';",
                "gantt.config.duration_unit='<?php echo ($condition) ? 'minute' : ganttchart::get_duration_unit(\$reports) ?>'; /* VD production minute schedule */",
            ],
        ],
    ];
    $files = [];
    foreach ($changes as $path => $patches) {
        $source = file_get_contents($path);
        $stmt = $db->prepare('SELECT hash FROM app_vd_production_gantt_files WHERE path=?');
        $stmt->bind_param('s', $path);
        $stmt->execute();
        $registered = $stmt->get_result()->fetch_assoc();
        if ($registered && !hash_equals($registered['hash'], hash('sha256', $source))) throw new RuntimeException('Gantt source changed; reconcile first.');
        foreach ($patches as [$anchor, $replacement]) {
            if (strpos($source, $replacement) !== false) continue;
            if (substr_count($source, $anchor) !== 1) throw new RuntimeException('Gantt schedule anchor changed.');
            $source = str_replace($anchor, $replacement, $source);
        }
        token_get_all($source, TOKEN_PARSE);
        $files[$path] = $source;
    }
    foreach ($files as $path => $source) {
        $backup = __DIR__ . '/before-schedule-' . str_replace('/', '-', $path) . '-' . hash_file('sha256', $path);
        if (file_get_contents($path) !== $source && !is_file($backup) && !copy($path, $backup)) throw new RuntimeException('Gantt backup failed.');
        if (file_put_contents($path, $source, LOCK_EX) !== strlen($source)) throw new RuntimeException('Gantt write failed.');
        $hash = hash('sha256', $source);
        $stmt = $db->prepare('INSERT INTO app_vd_production_gantt_files VALUES(?,?) ON DUPLICATE KEY UPDATE hash=VALUES(hash)');
        $stmt->bind_param('ss', $path, $hash);
        $stmt->execute();
    }
    echo "Production Gantt 3 uses exact minutes; OP Gantt 2 reads computed start dates. Other Gantts preserved.\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_production_gantt')");
}

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/production-duration.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_duration_files(path VARCHAR(255) PRIMARY KEY,hash CHAR(64) NOT NULL) ENGINE=InnoDB');
if ((int) $db->query("SELECT GET_LOCK('vd_production_duration',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Duration deployment busy.');
try {
    foreach ([[0,'00:00:00'],[18/60,'00:00:18'],[1/60,'00:00:01'],[19/60,'00:00:19'],[61.5,'01:01:30'],[1500,'25:00:00']] as $test) {
        if (vd_production_duration($test[0]) !== $test[1]) throw new RuntimeException('Duration test failed.');
    }
    $files = ['includes/vd_quote_workflow/production-duration.php' => file_get_contents(__DIR__ . '/production-duration.php')];
    foreach (['fieldtype_formula','fieldtype_input_numeric'] as $type) {
        $path = "includes/classes/fieldstypes/$type.php";
        $source = file_get_contents($path);
        $start = $type === 'fieldtype_formula' ? strpos($source, 'function output(') : strpos($source, 'static function number_format(');
        if ($start === false) throw new RuntimeException('Duration formatting method missing.');
        if (strpos($source, '/* VD production duration */') === false) {
            $anchor = $type === 'fieldtype_formula' ? "\$cfg = new fields_types_cfg(\$options['field']['configuration']);" : '$cfg = new fields_types_cfg($configuration);';
            $position = strpos($source, $anchor, $start);
            if ($position === false) throw new RuntimeException('Duration format anchor missing.');
            $code = "\n        /* VD production duration */\n" .
                "        if (\$cfg->get('vd_production_duration') === '1'" .
                ($type === 'fieldtype_formula' ? " && (!isset(\$options['is_export']) || isset(\$options['is_print']))" : '') . ") {\n" .
                "            require_once 'includes/vd_quote_workflow/production-duration.php';\n" .
                "            return vd_production_duration(\$value);\n        }\n";
            $source = substr_replace($source, $anchor . $code, $position, strlen($anchor));
        }
        $files[$path] = $source;
    }
    $totalsPath = 'modules/items/components/calculate_fields_totals.php';
    $totals = file_get_contents($totalsPath);
    if (strpos($totals, '/* VD duration totals */') === false) {
        $anchor = "    //add prefix and sufix";
        if (substr_count($totals, $anchor) !== 1) throw new RuntimeException('Duration totals anchor changed.');
        $code = "    /* VD duration totals */\n" .
            "    if (\$cfg->get('vd_production_duration') === '1') {\n" .
            "        require_once 'includes/vd_quote_workflow/production-duration.php';\n" .
            "        \$value = vd_production_duration(\$totals['total_' . \$field['id']] ?? 0);\n" .
            "        \$avg_value = \$listing_split->number_of_rows > 0 ? vd_production_duration((\$totals['total_' . \$field['id']] ?? 0) / \$listing_split->number_of_rows) : '';\n" .
            "    }\n";
        $totals = str_replace($anchor, $code . $anchor, $totals);
    }
    $files[$totalsPath] = $totals;
    foreach ($files as $path => $content) {
        $stmt = $db->prepare('SELECT hash FROM app_vd_production_duration_files WHERE path=?');
        $stmt->bind_param('s', $path);
        $stmt->execute();
        $registered = $stmt->get_result()->fetch_assoc();
        if ($registered && (!is_file($path) || !hash_equals($registered['hash'], hash_file('sha256', $path)))) throw new RuntimeException('Duration file changed: ' . $path);
        token_get_all($content, TOKEN_PARSE);
    }
    foreach ($files as $path => $content) {
        if (is_file($path) && file_get_contents($path) !== $content) {
            $backup = __DIR__ . '/before-duration-' . str_replace('/', '-', $path) . '-' . hash_file('sha256', $path);
            if (!is_file($backup) && !copy($path, $backup)) throw new RuntimeException('Duration backup failed.');
        }
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Duration persistence failed.');
        $hash = hash('sha256', $content);
        $stmt = $db->prepare('INSERT INTO app_vd_production_duration_files VALUES(?,?) ON DUPLICATE KEY UPDATE hash=VALUES(hash)');
        $stmt->bind_param('ss', $path, $hash);
        $stmt->execute();
    }
    $field = $db->query('SELECT configuration FROM app_fields WHERE id=2445 AND entities_id=171')->fetch_assoc();
    if (!$field) throw new RuntimeException('Native time formula missing.');
    $cfg = json_decode($field['configuration'], true, 512, JSON_THROW_ON_ERROR);
    if ($cfg['formula'] !== '([2447]-[2446])/60') throw new RuntimeException('Time formula changed; reconcile first.');
    $cfg['vd_production_duration'] = '1';
    $cfg['number_format'] = '6/./*';
    $cfg['suffix'] = '';
    $json = json_encode($cfg, JSON_THROW_ON_ERROR);
    $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=2445 AND configuration=?');
    $stmt->bind_param('ss', $json, $field['configuration']);
    $stmt->execute();
    echo "Production elapsed time and totals use HH:MM:SS; numeric minutes preserved. Six duration checks passed.\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_production_duration')");
}

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_time_audit(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_id INT UNSIGNED NOT NULL,actor_id INT UNSIGNED NOT NULL,previous_status INT NOT NULL,status INT NOT NULL,time_id INT UNSIGNED NULL,event VARCHAR(16) NOT NULL,created_at INT UNSIGNED NOT NULL,KEY(operation_id,actor_id)) ENGINE=InnoDB');
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_time_files(path VARCHAR(255) PRIMARY KEY,hash CHAR(64) NOT NULL) ENGINE=InnoDB');
if (in_array('--schema-only', $argv, true)) {
    echo "Production Kanban timing schema ready; live files unchanged.\n";
    exit();
}
if ((int) $db->query("SELECT GET_LOCK('vd_kanban_time_deployment',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Kanban deployment busy.');
try {
    $actionPath = 'plugins/ext/modules/kanban/actions/view.php';
    $componentPath = 'plugins/ext/modules/kanban/components/kanban.php';
    $action = file_get_contents($actionPath);
    $component = file_get_contents($componentPath);
    $currentFiles = [$actionPath => $action, $componentPath => $component];
    $helperPath = 'includes/vd_quote_workflow/production-kanban-time.php';
    if (is_file($helperPath)) $currentFiles[$helperPath] = file_get_contents($helperPath);
    foreach ($currentFiles as $path => $content) {
        $stmt = $db->prepare('SELECT hash FROM app_vd_production_time_files WHERE path=?');
        $stmt->bind_param('s', $path);
        $stmt->execute();
        $registered = $stmt->get_result()->fetch_assoc();
        if ($registered && !hash_equals($registered['hash'], hash('sha256', $content))) throw new RuntimeException('Kanban file changed; reconcile first: ' . $path);
    }
    $anchor = 'db_query("update app_entity_" . $reports[\'entities_id\'] . " set field_" . $reports[\'group_by_field\'] . " = " . $choices_id . " where id=\'" . $item_id . "\'");';
    $hook = preg_replace('/^<\?php\n/', '', file_get_contents(__DIR__ . '/production-kanban-hook.php'));
    $start = '/* VD production Kanban time */';
    $end = '/* End VD production Kanban time */';
    $replacement = $start . "\n" . $hook . "\n" . $end;
    if (strpos($action, $start) !== false) {
        $offset = strpos($action, $start);
        $endOffset = strpos($action, $end, $offset);
        if ($endOffset === false) throw new RuntimeException('Incomplete Kanban marker.');
        $updatedAction = substr_replace($action, $replacement, $offset, $endOffset + strlen($end) - $offset);
    } else {
        if (substr_count($action, $anchor) !== 1) throw new RuntimeException('Kanban status update anchor changed.');
        $updatedAction = str_replace($anchor, $replacement, $action);
    }
    $updatedComponent = $component;
    if (strpos($component, '/* VD Kanban update failure */') === false) {
        $pattern = '/\}\);(\s*\}\s*else\s*\{\s*\$\(report_id \+ " #kanban_heading_")/';
        if (preg_match_all($pattern, $component, $matches) !== 1) throw new RuntimeException('Kanban AJAX anchor changed.');
        $anchor = $matches[0][0];
        $updatedComponent = str_replace($anchor, '}).fail(function(xhr){ /* VD Kanban update failure */' . "\n" .
            '                                    alert((xhr.responseJSON && xhr.responseJSON.error) || "Não foi possível guardar a alteração. O quadro será atualizado.");' . "\n" .
            '                                    load_kanban_report<?= $reports[\'id\'] ?>();' . "\n" .
            '                                });' . $matches[1][0], $component);
    }
    $helper = file_get_contents(__DIR__ . '/production-kanban-time.php');
    $targets = ['includes/vd_quote_workflow/production-kanban-time.php' => $helper,
        $actionPath => $updatedAction, $componentPath => $updatedComponent];
    foreach ($targets as $path => $content) {
        token_get_all($content, TOKEN_PARSE);
    }
    foreach ($targets as $path => $content) {
        if (is_file($path) && file_get_contents($path) !== $content) {
            $backup = __DIR__ . '/before-time-' . str_replace('/', '-', $path) . '-' . hash_file('sha256', $path);
            if (!is_file($backup) && !copy($path, $backup)) throw new RuntimeException('Kanban backup failed.');
        }
        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Kanban publish failed.');
        $hash = hash('sha256', $content);
        $stmt = $db->prepare('INSERT INTO app_vd_production_time_files(path,hash) VALUES(?,?) ON DUPLICATE KEY UPDATE hash=VALUES(hash)');
        $stmt->bind_param('ss', $path, $hash);
        $stmt->execute();
    }
    echo "Production Kanban status/time integration installed; no production states changed.\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_kanban_time_deployment')");
}

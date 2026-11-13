<?php
// Instala o report "Operações — Tabela de configuração" (entidade 36 em tabela,
// com edição por popups nativos). Idempotente; não altera reports existentes.
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_operation_config_table',10)")->fetch_row()[0] !== 1) {
    throw new RuntimeException('Lock unavailable.');
}
$reportName = 'Operações — Tabela de configuração';
$db->begin_transaction();
try {
    $groups = array_column($db->query("SELECT access_groups_id FROM app_entities_access WHERE entities_id=36 AND FIND_IN_SET('view',access_schema)>0")->fetch_all(MYSQLI_ASSOC), 'access_groups_id');
    $usersGroups = implode(',', array_unique(array_merge(['0'], $groups)));
    $existing = $db->query("SELECT id FROM app_ext_report_page WHERE entities_id=0 AND name='" . $db->real_escape_string($reportName) . "'")->fetch_assoc();
    if ($existing) {
        $reportId = (int) $existing['id'];
        echo "Report already registered: $reportId\n";
    } else {
        $template = $db->query('SELECT * FROM app_ext_report_page WHERE id=29 AND entities_id=0')->fetch_assoc();
        if (!$template) throw new RuntimeException('Template report 29 missing.');
        unset($template['id']);
        $template['name'] = $reportName;
        $template['type'] = 'page';
        $template['save_as'] = '';
        $template['save_filename'] = '';
        $template['button_title'] = '';
        $template['description'] = '';
        $template['css'] = '';
        $template['users_groups'] = $usersGroups;
        $template['settings'] = '{"hide_page_title":"0","hide_print_button":"1"}';
        $stmt = $db->prepare('INSERT INTO app_ext_report_page (`' . implode('`,`', array_keys($template)) .
            '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
        $values = array_values($template);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $reportId = (int) $db->insert_id;
        echo "Report created: $reportId\n";
    }
    $code = "require_once 'includes/vd_quote_workflow/operation-config-table.php';\n" .
        '$output_value = \\VDOperationTable\\render(' . $reportId . ');';
    token_get_all('<?php ' . $code, TOKEN_PARSE);
    $settings = json_encode(['php_code' => $code], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $block = $db->query("SELECT id,settings FROM app_ext_report_page_blocks WHERE report_id=$reportId AND block_type='php'")->fetch_assoc();
    if ($block) {
        if ($block['settings'] !== $settings) {
            $stmt = $db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
            $stmt->bind_param('sis', $settings, $block['id'], $block['settings']);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Block changed concurrently.');
            echo 'Block updated: ', $block['id'], "\n";
        } else {
            echo 'Block unchanged: ', $block['id'], "\n";
        }
        $blockId = (int) $block['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO app_ext_report_page_blocks(parent_id,report_id,block_type,name,field_id,settings,sort_order) VALUES(0,?,'php','Tabela de configuração de operações',0,?,10)");
        $stmt->bind_param('is', $reportId, $settings);
        $stmt->execute();
        $blockId = (int) $db->insert_id;
        echo "Block created: $blockId\n";
    }
    $description = '${' . $blockId . '}';
    $stmt = $db->prepare('UPDATE app_ext_report_page SET description=?, users_groups=? WHERE id=?');
    $stmt->bind_param('ssi', $description, $usersGroups, $reportId);
    $stmt->execute();
    $url = 'index.php?module=report_page/view&id=' . $reportId;
    $menu = $db->query("SELECT id FROM app_entities_menu WHERE type='url' AND url='" . $db->real_escape_string($url) . "'")->fetch_assoc();
    if ($menu) {
        echo 'Menu entry already present: ', $menu['id'], "\n";
    } else {
        $parent = $db->query("SELECT id,parent_id FROM app_entities_menu WHERE url LIKE '%path=36' OR FIND_IN_SET('36',entities_list)>0 ORDER BY id LIMIT 1")->fetch_assoc();
        $parentId = $parent ? (int) $parent['parent_id'] : 0;
        $stmt = $db->prepare("INSERT INTO app_entities_menu(parent_id,name,icon,icon_color,bg_color,entities_list,reports_list,pages_list,type,url,users_groups,assigned_to,sort_order) VALUES(?,?,'fa-table','','','','','','url',?,?,'',99)");
        $stmt->bind_param('isss', $parentId, $reportName, $url, $usersGroups);
        $stmt->execute();
        echo 'Menu entry created: ', $db->insert_id, " (parent $parentId)\n";
    }
    $db->commit();
    echo "Done. Report URL: $url\n";
} catch (\Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_operation_config_table')");
}

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$html = file_get_contents(__DIR__ . '/manual-configuracao-operacoes.html');
if ($html === false || $html === '' || strlen($html) > 65535) throw new RuntimeException('Invalid help content.');

$groups = [0];
foreach ($db->query("SELECT access_groups_id FROM app_entities_access WHERE entities_id=36 AND FIND_IN_SET('view',access_schema)>0 ORDER BY access_groups_id") as $row) {
    $groups[] = (int) $row['access_groups_id'];
}
$groups = implode(',', array_unique($groups));
$name = 'Manual de configuração das operações';
if ((int) $db->query("SELECT GET_LOCK('vd_operation_help',10)")->fetch_row()[0] !== 1) {
    throw new RuntimeException('Operation help lock unavailable.');
}
try {
    $db->begin_transaction();
    try {
        foreach (['listing', 'info'] as $position) {
            $select = $db->prepare("SELECT * FROM app_help_pages WHERE entities_id=36 AND type='page' AND name=? AND position=? FOR UPDATE");
            $select->bind_param('ss', $name, $position);
            $select->execute();
            $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);
            if (count($rows) > 1) throw new RuntimeException('Duplicate operation help page: ' . $position);
            if ($rows) {
                $row = $rows[0];
                if ($row['description'] !== $html || $row['users_groups'] !== $groups || (int) $row['is_active'] !== 1) {
                    throw new RuntimeException('Existing help page differs; review before changing: ' . $position);
                }
                echo 'Verified help page ', $position, ': ', $row['id'], PHP_EOL;
                continue;
            }
            $insert = $db->prepare("INSERT INTO app_help_pages
                (entities_id,created_by,type,is_active,name,icon,start_date,end_date,description,color,position,users_groups,sort_order)
                VALUES (36,1,'page',1,?,'',0,0,?,'',?,?,0)");
            $insert->bind_param('ssss', $name, $html, $position, $groups);
            $insert->execute();
            echo 'Created help page ', $position, ': ', $db->insert_id, PHP_EOL;
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_operation_help')");
}

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->query('CREATE TABLE IF NOT EXISTS app_vd_production_report (role VARCHAR(32) PRIMARY KEY,native_id INT UNSIGNED NOT NULL,hash CHAR(64) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_production_report',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Report lock unavailable.');
$db->begin_transaction();
try {
    $registered = $db->query("SELECT native_id FROM app_vd_production_report WHERE role='report'")->fetch_assoc();
    if ($registered) {
        $reportId = (int) $registered['native_id'];
        $report = $db->query("SELECT * FROM app_ext_report_page WHERE id=$reportId AND entities_id=121")->fetch_assoc();
        if (!$report) throw new RuntimeException('Production report missing or moved.');
        if ($report['type'] === 'page' && $report['save_as'] === '' && $report['name'] === 'Ficha de produção — resumo da OP') {
            $db->query("UPDATE app_ext_report_page SET type='print',save_as='print',save_filename='VeD_[2000]' WHERE id=$reportId AND type='page' AND save_as=''");
        }
        echo "Preserved native Report Designer report $reportId and its editable layout.\n";
    } else {
        $template = $db->query('SELECT * FROM app_ext_report_page WHERE id=25 AND entities_id=262')->fetch_assoc();
        if (!$template) throw new RuntimeException('Report template missing.');
        unset($template['id']);
        $template['entities_id'] = 121;
        $template['type'] = 'print';
        $template['save_as'] = 'print';
        $template['save_filename'] = 'VeD_[2000]';
        $template['name'] = 'Ficha de produção — resumo da OP';
        $template['button_title'] = 'Imprimir ficha de produção';
        $template['button_icon'] = 'fa-print';
        $template['button_position'] = 'default';
        $template['description'] = '';
        $template['use_editor'] = 0;
        $template['users_groups'] = implode(',', array_unique(array_merge(['0'], array_column($db->query("SELECT access_groups_id FROM app_entities_access WHERE entities_id=121 AND FIND_IN_SET('view',access_schema)>0")->fetch_all(MYSQLI_ASSOC), 'access_groups_id'))));
        $template['settings'] = '{"hide_page_title":"1","hide_print_button":"0"}';
        $template['css'] = file_get_contents(__DIR__ . '/production-report.css');
        if ($template['css'] === false) throw new RuntimeException('Report stylesheet unavailable.');
        $stmt = $db->prepare('INSERT INTO app_ext_report_page (`' . implode('`,`', array_keys($template)) .
            '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
        $values = array_values($template);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $reportId = (int) $db->insert_id;
        $stmt = $db->prepare("INSERT INTO app_vd_production_report(role,native_id,hash) VALUES('report',?,?)");
        $hash = hash('sha256', json_encode($template, JSON_THROW_ON_ERROR));
        $stmt->bind_param('is', $reportId, $hash);
        $stmt->execute();
    }
    $stylesheet = file_get_contents(__DIR__ . '/production-report.css');
    if ($stylesheet === false || !preg_match('~/\* VD production columns \*/.*?/\* End VD production columns \*/~s', $stylesheet, $columns)) {
        throw new RuntimeException('Production report column stylesheet missing.');
    }
    $currentCss = $db->query("SELECT css FROM app_ext_report_page WHERE id=$reportId FOR UPDATE")->fetch_assoc()['css'];
    if (strpos($currentCss, '/* VD production columns */') === false) {
        $updatedCss = $currentCss . "\n" . $columns[0] . "\n";
        $stmt = $db->prepare('UPDATE app_ext_report_page SET css=? WHERE id=? AND css=?');
        $stmt->bind_param('sis', $updatedCss, $reportId, $currentCss);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Production report stylesheet changed concurrently.');
    } elseif (strpos($currentCss, $columns[0]) === false) {
        throw new RuntimeException('Production report columns edited in Designer; reconcile before publishing.');
    }
    if (!preg_match('~/\* VD production compact A4 \*/.*?/\* End VD production compact A4 \*/~s', $stylesheet, $compact)) {
        throw new RuntimeException('Production compact A4 stylesheet missing.');
    }
    $currentCss = $db->query("SELECT css FROM app_ext_report_page WHERE id=$reportId FOR UPDATE")->fetch_assoc()['css'];
    if (strpos($currentCss, '/* VD production compact A4 */') === false) {
        $updatedCss = $currentCss . "\n" . $compact[0] . "\n";
        $stmt = $db->prepare('UPDATE app_ext_report_page SET css=? WHERE id=? AND css=?');
        $stmt->bind_param('sis', $updatedCss, $reportId, $currentCss);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Production report stylesheet changed concurrently.');
    } elseif (strpos($currentCss, $compact[0]) === false) {
        throw new RuntimeException('Production compact A4 edited in Designer; reconcile before publishing.');
    }
    $code = preg_replace('/^<\?php\n/', '', file_get_contents(__DIR__ . '/production-report-block.php'));
    token_get_all('<?php ' . $code, TOKEN_PARSE);
    $settings = json_encode(['php_code' => $code], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $settings);
    $block = $db->query("SELECT n.native_id,n.hash,b.settings FROM app_vd_production_report n LEFT JOIN app_ext_report_page_blocks b ON b.id=n.native_id AND b.report_id=$reportId WHERE n.role='summary'")->fetch_assoc();
    if ($block) {
        if ($block['settings'] === null) throw new RuntimeException('Production report block missing.');
        if (hash('sha256', $block['settings']) !== $hash) {
            if (!hash_equals($block['hash'], hash('sha256', $block['settings']))) throw new RuntimeException('Report block edited in Designer; reconcile before publishing.');
            $stmt = $db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
            $stmt->bind_param('sis', $settings, $block['native_id'], $block['settings']);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Report block changed concurrently.');
            $stmt = $db->prepare("UPDATE app_vd_production_report SET hash=? WHERE role='summary'");
            $stmt->bind_param('s', $hash);
            $stmt->execute();
        }
    } else {
        $stmt = $db->prepare("INSERT INTO app_ext_report_page_blocks(parent_id,report_id,block_type,name,field_id,settings,sort_order) VALUES(0,?,'php','Resumo para acompanhar produção',0,?,10)");
        $stmt->bind_param('is', $reportId, $settings);
        $stmt->execute();
        $blockId = (int) $db->insert_id;
        $stmt = $db->prepare("INSERT INTO app_vd_production_report(role,native_id,hash) VALUES('summary',?,?)");
        $stmt->bind_param('is', $blockId, $hash);
        $stmt->execute();
        $description = '${' . $blockId . '}';
        $stmt = $db->prepare('UPDATE app_ext_report_page SET description=? WHERE id=?');
        $stmt->bind_param('si', $description, $reportId);
        $stmt->execute();
    }
    $componentRedirect = 'report_page' . $reportId;
    $redirect = $db->query("SELECT id FROM app_entities_configuration WHERE entities_id=159 AND configuration_name='redirect_after_click_heading'")->fetch_assoc();
    if ($redirect) {
        $stmt = $db->prepare('UPDATE app_entities_configuration SET configuration_value=? WHERE id=?');
        $stmt->bind_param('si', $componentRedirect, $redirect['id']);
    } else {
        $stmt = $db->prepare("INSERT INTO app_entities_configuration(entities_id,configuration_name,configuration_value) VALUES(159,'redirect_after_click_heading',?)");
        $stmt->bind_param('s', $componentRedirect);
    }
    $stmt->execute();
    $db->commit();
    $navigationPath = '/home/vianaedias/public_html/modules/items/components/navigation.php';
    $navigation = file_get_contents($navigationPath);
    $hook = file_get_contents(__DIR__ . '/production-report-navigation.php');
    if ($navigation === false || $hook === false) throw new RuntimeException('Production report navigation source missing.');
    token_get_all($hook, TOKEN_PARSE);
    $start = '/* VD production report navigation */';
    $end = '/* End VD production report navigation */';
    $insertion = $start . "\n" . preg_replace('/^<\?php\n/', '', $hook) . "\n" . $end;
    $registeredNavigation = $db->query("SELECT hash FROM app_vd_production_report WHERE role='navigation'")->fetch_assoc();
    if ($registeredNavigation && !hash_equals($registeredNavigation['hash'], hash('sha256', $navigation))) {
        throw new RuntimeException('Native navigation changed; reconcile before publishing.');
    }
    if (strpos($navigation, $start) !== false) {
        $offset = strpos($navigation, $start);
        $endOffset = strpos($navigation, $end, $offset);
        if ($endOffset === false) throw new RuntimeException('Production navigation marker incomplete.');
        $updatedNavigation = substr_replace($navigation, $insertion, $offset, $endOffset + strlen($end) - $offset);
    } else {
        $anchor = 'unset($navbar[0]);';
        if (substr_count($navigation, $anchor) !== 1) throw new RuntimeException('Native navigation anchor changed.');
        $updatedNavigation = str_replace($anchor, $anchor . "\n" . $insertion, $navigation);
    }
    token_get_all($updatedNavigation, TOKEN_PARSE);
    if ($updatedNavigation !== $navigation) {
        $backup = __DIR__ . '/navigation-before-production-report-' . hash('sha256', $navigation) . '.php';
        if (!is_file($backup) && file_put_contents($backup, $navigation, LOCK_EX) !== strlen($navigation)) throw new RuntimeException('Navigation backup failed.');
        if (file_get_contents($navigationPath) !== $navigation) throw new RuntimeException('Navigation changed concurrently.');
        if (file_put_contents($navigationPath, $updatedNavigation, LOCK_EX) !== strlen($updatedNavigation)) throw new RuntimeException('Navigation publish failed.');
    }
    $navigationHash = hash('sha256', $updatedNavigation);
    $stmt = $db->prepare("INSERT INTO app_vd_production_report(role,native_id,hash) VALUES('navigation',0,?) ON DUPLICATE KEY UPDATE hash=VALUES(hash)");
    $stmt->bind_param('s', $navigationHash);
    $stmt->execute();
    echo "Production Report Designer ready: report $reportId, entity 121.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_production_report')");
}

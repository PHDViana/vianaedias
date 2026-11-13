<?php
(static function () use (&$navbar, $app_path, $app_user) {
    $parts = explode('/', $app_path);
    if (!preg_match('/^121-([1-9][0-9]*)$/', $parts[0], $match)) return;
    $orderId = (int) $match[1];
    $result = db_query("SELECT p.* FROM app_vd_production_report n JOIN app_ext_report_page p ON p.id=n.native_id WHERE n.role='report' AND p.entities_id=121 AND p.is_active=1 AND (FIND_IN_SET(" . (int) $app_user['group_id'] . ",p.users_groups) OR FIND_IN_SET(" . (int) $app_user['id'] . ",p.assigned_to))");
    if (!$report = db_fetch_array($result)) return;
    $filters = new items_filters(121, $orderId);
    if (!$filters->check(['report_type' => 'report_page' . $report['id']])) return;
    $active = ($_GET['module'] ?? '') === 'report_page/view' && (int) ($_GET['id'] ?? 0) === (int) $report['id'];
    $tab = [
        'title' => 'Ficha de produção',
        'url' => url_for('report_page/view', 'id=' . (int) $report['id'] . '&path=121-' . $orderId),
        'menu_css' => 'navbar-production-report' . ($active ? ' selected' : ''),
    ];
    $position = count($navbar);
    foreach (array_values($navbar) as $index => $entry) {
        if (($entry['menu_css'] ?? '') === 'navbar-nav-entity-312') {
            $position = $index + 1;
            break;
        }
    }
    $navbar = array_values($navbar);
    array_splice($navbar, $position, 0, [$tab]);
})();

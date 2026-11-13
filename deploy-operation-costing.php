<?php
$base = __DIR__;
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$blocks = [
    364 => ['file' => 'costing-configurator.php', 'hash' => '9dff7a45fb9d30ffc648411d6c73cd3573649b64afa3a540ea4c4114a18e4bb1'],
    365 => ['file' => 'costing-simulation-engine.php', 'hash' => '3eae9d9ff5c0444d320f994ac2412b358a78fbf94d3ce89e6cf61dff40b5cf3c'],
];
$updates = [];
$markupOld = $db->query('SELECT configuration FROM app_fields WHERE id=6271')->fetch_row()[0];
$markupConfiguration = json_decode($markupOld, true, 512, JSON_THROW_ON_ERROR);
if (!in_array($markupConfiguration['default_value'] ?? null, ['30', '50'], true)) {
    throw new RuntimeException('Unexpected markup default; review field 6271 before deployment.');
}
$markupConfiguration['default_value'] = '50';
$markupNew = json_encode($markupConfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$asset = json_decode(file_get_contents($base . '/costing-asset.json'), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^vd-component-operation-costing-[a-f0-9]{12}\.js$/', $asset['name']) ||
    hash_file('sha256', $base . '/costing-asset.js') !== $asset['hash']) {
    throw new RuntimeException('Invalid costing asset');
}
foreach ($blocks as $id => $block) {
    $old = $db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=' . $id)->fetch_row()[0];
    if (hash('sha256', $old) !== $block['hash']) {
        throw new RuntimeException('Live block changed: ' . $id);
    }
    $settings = json_decode($old, true, 512, JSON_THROW_ON_ERROR);
    $source = file_get_contents($base . '/' . $block['file']);
    $source = preg_replace('/^<\?php\s*/', '', $source);
    token_get_all('<?php ' . $source, TOKEN_PARSE);
    if ($id === 364) {
        $start = strpos($source, '$simulationQueries = [');
        $end = strpos($source, '];', $start);
        if ($start === false || $end === false) {
            throw new RuntimeException('Missing report queries');
        }
        $componentId = 30;
        eval(substr($source, $start, $end + 2 - $start));
        foreach ($simulationQueries as $name => $sql) {
            if (!preg_match('/^SELECT\b/i', $sql)) {
                throw new RuntimeException('Non-read-only query: ' . $name);
            }
            $db->query('EXPLAIN ' . $sql);
            echo 'Validated query: ', $name, PHP_EOL;
        }
    }
    $settings['php_code'] = $source;
    $new = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($new) > 65535) {
        throw new RuntimeException('Report settings exceed TEXT capacity: ' . $id . ' (' . strlen($new) . ' bytes)');
    }
    if ($new === $old) {
        echo 'Verified unchanged block ', $id, ': ', hash('sha256', $old), PHP_EOL;
        continue;
    }
    $updates[$id] = ['old' => $old, 'new' => $new];
}
$written = [];
$markupWritten = false;
try {
    $assetPath = '/home/vianaedias/public_html/js/' . $asset['name'];
    if (file_exists($assetPath)) {
        if (hash_file('sha256', $assetPath) !== $asset['hash']) {
            throw new RuntimeException('Asset name conflict');
        }
    } else {
        if (!copy($base . '/costing-asset.js', $assetPath) || !chmod($assetPath, 0644) ||
            hash_file('sha256', $assetPath) !== $asset['hash']) {
            throw new RuntimeException('Asset deployment failed');
        }
    }
    foreach ($updates as $id => $update) {
        $stmt = $db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
        $stmt->bind_param('sis', $update['new'], $id, $update['old']);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Concurrent report update: ' . $id);
        }
        $written[] = $id;
        if ($db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=' . $id)->fetch_row()[0] !== $update['new']) {
            throw new RuntimeException('Report persistence mismatch: ' . $id);
        }
        echo 'Saved block ', $id, ': ', hash('sha256', $update['new']), PHP_EOL;
    }
    if ($markupNew !== $markupOld) {
        $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=6271 AND configuration=?');
        $stmt->bind_param('ss', $markupNew, $markupOld);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Concurrent markup field change.');
        $markupWritten = true;
    }
    if ($db->query('SELECT configuration FROM app_fields WHERE id=6271')->fetch_row()[0] !== $markupNew) {
        throw new RuntimeException('Markup default persistence mismatch.');
    }
    echo 'Verified markup default: 50%; existing quote values unchanged.', PHP_EOL;
} catch (Throwable $error) {
    if ($markupWritten) {
        $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=6271 AND configuration=?');
        $stmt->bind_param('ss', $markupOld, $markupNew);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Markup default rollback conflict.', 0, $error);
    }
    foreach (array_reverse($written) as $id) {
        $update = $updates[$id];
        $stmt = $db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
        $stmt->bind_param('sis', $update['old'], $id, $update['new']);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException('Rollback conflict for block ' . $id, 0, $error);
        }
    }
    throw $error;
}

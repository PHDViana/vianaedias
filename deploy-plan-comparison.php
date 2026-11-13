<?php
$base = __DIR__;
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$code = static function ($file) use ($base) {
    $source = file_get_contents($base . '/' . $file);
    if ($source === false) throw new RuntimeException('Missing source: ' . $file);
    return preg_replace('/^<\?php\s*/', '', $source);
};
$asset = json_decode(file_get_contents($base . '/costing-asset.json'), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^vd-component-operation-costing-[a-f0-9]{12}\.js$/', $asset['name']) ||
    hash_file('sha256', $base . '/costing-asset.js') !== $asset['hash']) {
    throw new RuntimeException('Invalid costing asset');
}
$assetPath = '/home/vianaedias/public_html/js/' . $asset['name'];
if (!file_exists($assetPath) && (!copy($base . '/costing-asset.js', $assetPath) || !chmod($assetPath, 0644))) {
    throw new RuntimeException('Cannot publish costing asset');
}
if (hash_file('sha256', $assetPath) !== $asset['hash']) throw new RuntimeException('Costing asset mismatch');
$simulation = json_decode(file_get_contents($base . '/simulation-asset.json'), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/^vd-component-simulation-[a-f0-9]{12}\.js$/', $simulation['name']) ||
    hash_file('sha256', $base . '/simulation-asset.js') !== $simulation['hash']) {
    throw new RuntimeException('Invalid simulation asset');
}
$simulationPath = '/home/vianaedias/public_html/js/' . $simulation['name'];
if (!file_exists($simulationPath) && (!copy($base . '/simulation-asset.js', $simulationPath) || !chmod($simulationPath, 0644))) {
    throw new RuntimeException('Cannot publish simulation asset');
}
if (hash_file('sha256', $simulationPath) !== $simulation['hash']) throw new RuntimeException('Simulation asset mismatch');
$db->begin_transaction();
try {
    foreach ([364 => ['costing-configurator.php', 'plan-configurator-prechange.php'],
        365 => ['costing-simulation-engine.php', 'plan-engine-prechange.php']] as $id => $files) {
        $old = $db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=' . $id . ' FOR UPDATE')->fetch_row()[0];
        $settings = json_decode($old, true, 512, JSON_THROW_ON_ERROR);
        $source = $code($files[0]);
        if ($settings['php_code'] !== $code($files[1]) && $settings['php_code'] !== $source) {
            throw new RuntimeException('Live report changed; review block ' . $id);
        }
        token_get_all('<?php ' . $source, TOKEN_PARSE);
        $settings['php_code'] = $source;
        $updated = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($updated) > 65535) throw new RuntimeException('Report exceeds storage limit: ' . $id);
        if ($updated !== $old) {
            $stmt = $db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
            $stmt->bind_param('sis', $updated, $id, $old);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('Concurrent report update');
        }
        if ($db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=' . $id)->fetch_row()[0] !== $updated) {
            throw new RuntimeException('Report persistence mismatch');
        }
        echo 'Verified report ', $id, ': ', hash('sha256', $updated), PHP_EOL;
    }
    foreach ([6125, 6126] as $id) {
        $field = $db->query('SELECT entities_id,configuration FROM app_fields WHERE id=' . $id . ' FOR UPDATE')->fetch_assoc();
        if (!$field || (int) $field['entities_id'] !== 276) throw new RuntimeException('Unexpected lamination field');
        $configuration = json_decode($field['configuration'], true, 512, JSON_THROW_ON_ERROR);
        $configuration['default_value'] = '10';
        $updated = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=?');
        $stmt->bind_param('si', $updated, $id);
        $stmt->execute();
        if ($db->query('SELECT configuration FROM app_fields WHERE id=' . $id)->fetch_row()[0] !== $updated) {
            throw new RuntimeException('Lamination default persistence mismatch');
        }
        echo 'Verified 10 mm default: ', $id, PHP_EOL;
    }
    $markupField = $db->query('SELECT entities_id,configuration FROM app_fields WHERE id=6271 FOR UPDATE')->fetch_assoc();
    if (!$markupField || (int) $markupField['entities_id'] !== 146) throw new RuntimeException('Unexpected markup field');
    $markupConfiguration = json_decode($markupField['configuration'], true, 512, JSON_THROW_ON_ERROR);
    if (!in_array($markupConfiguration['default_value'] ?? null, ['50', '75'], true))
        throw new RuntimeException('Markup default changed; review before deployment');
    $markupConfiguration['default_value'] = '75';
    $updatedMarkup = json_encode($markupConfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->query("UPDATE app_fields SET configuration='" . $db->real_escape_string($updatedMarkup) . "' WHERE id=6271");
    if ($db->query('SELECT configuration FROM app_fields WHERE id=6271')->fetch_row()[0] !== $updatedMarkup)
        throw new RuntimeException('Markup default persistence mismatch');
    echo 'Verified 75% markup default', PHP_EOL;
    $db->commit();
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}

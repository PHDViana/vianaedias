<?php
// Read-only comparison against the actual live report. No application/session bootstrap.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$base = __DIR__;
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(constant('DB_SERVER'), constant('DB_SERVER_USERNAME'), constant('DB_SERVER_PASSWORD'),
    constant('DB_DATABASE'), (int) constant('DB_SERVER_PORT'));
$db->set_charset('utf8mb4');
echo 'Database: ', $db->server_info, PHP_EOL;
$settings = $db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=364')->fetch_row()[0];
$live = json_decode($settings, true, 512, JSON_THROW_ON_ERROR)['php_code'];
$candidate = file_get_contents($base . '/costing-configurator.php');
$baselinePath = $base . '/quotation-load-query-baseline.json';
if (in_array('--record', $argv, true)) {
    if (file_exists($baselinePath)) throw new RuntimeException('Baseline already exists; preserve it');
    $start = strpos($live, '$simulationQueries = [');
    $end = strpos($live, '];', $start);
    if ($start === false || $end === false) throw new RuntimeException('Missing baseline queries');
    file_put_contents($baselinePath, json_encode([
        'report_hash' => hash('sha256', $settings),
        'queries' => substr($live, $start, $end + 2 - $start),
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
if (file_exists($baselinePath)) {
    $baseline = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
    $live = $baseline['queries'];
    echo 'Baseline report: ', $baseline['report_hash'], PHP_EOL;
}
$queries = static function ($source, $componentId) {
    $start = strpos($source, '$simulationQueries = [');
    $end = strpos($source, '];', $start);
    if ($start === false || $end === false) throw new RuntimeException('Missing report query block');
    $simulationQueries = [];
    eval(substr($source, $start, $end + 2 - $start));
    return $simulationQueries;
};
$run = static function ($sql) use ($db) {
    if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) throw new RuntimeException('Not read-only');
    $start = microtime(true);
    $rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    return [$rows, (microtime(true) - $start) * 1000];
};
$components = [30];
$result = $db->query('SELECT DISTINCT parent_item_id FROM app_entity_277 ORDER BY parent_item_id');
while ($row = $result->fetch_row()) if (!in_array((int) $row[0], $components, true)) $components[] = (int) $row[0];
$schemaChangedQueries = ['color_materials', 'costing_equipment'];
$intentionalQueryChanges = [
    'removed' => ['costing_operations'],
    'added' => ['material_records', 'explicit_operation_settings', 'ink_counter_machines',
        'ink_counter_periods', 'ink_purchase_totals', 'ink_latest_prices',
        'administrative_calibration', 'spiral_articles'],
];
$checks = 0;
foreach ($components as $componentId) {
    $before = $queries($live, $componentId);
    $after = $queries($candidate, $componentId);
    $removed = array_values(array_diff(array_keys($before), array_keys($after)));
    $added = array_values(array_diff(array_keys($after), array_keys($before)));
    sort($removed);
    sort($added);
    $expectedRemoved = $intentionalQueryChanges['removed'];
    $expectedAdded = $intentionalQueryChanges['added'];
    sort($expectedRemoved);
    sort($expectedAdded);
    if ($removed !== $expectedRemoved || $added !== $expectedAdded) {
        throw new RuntimeException("Unexpected query set change for component $componentId.");
    }
    $totalBefore = $totalAfter = 0;
    foreach ($before as $name => $sql) {
        if (!array_key_exists($name, $after)) continue;
        [$expected, $oldMs] = $run($sql);
        [$actual, $newMs] = $run($after[$name]);
        if (in_array($name, $schemaChangedQueries, true)) {
            $checks++;
            $totalBefore += $oldMs;
            $totalAfter += $newMs;
            if ($componentId === 30) printf("%s: %.1f -> %.1f ms (%d rows; intentional schema change)\n",
                $name, $oldMs, $newMs, count($actual));
            continue;
        }
        // SQL numeric aggregates may use a different decimal representation.
        foreach ($expected as $i => $row) {
            if (!isset($actual[$i]) || array_keys($row) !== array_keys($actual[$i])) {
                throw new RuntimeException("Different columns/row count: $componentId/$name");
            }
            foreach ($row as $key => $value) {
                $new = $actual[$i][$key];
                if ($value === $new) continue;
                if ($value !== null && $new !== null && is_numeric($value) && is_numeric($new) &&
                    (float) $value === (float) $new) continue;
                throw new RuntimeException("Different result: $componentId/$name/$i/$key");
            }
        }
        if (count($actual) !== count($expected)) throw new RuntimeException("Different rows: $componentId/$name");
        $checks++;
        $totalBefore += $oldMs;
        $totalAfter += $newMs;
        if ($componentId === 30) printf("%s: %.1f -> %.1f ms (%d rows)\n", $name, $oldMs, $newMs, count($actual));
    }
    foreach ($intentionalQueryChanges['added'] as $name) {
        [$rows, $elapsedMs] = $run($after[$name]);
        if ($componentId === 30) printf("%s: current-only %.1f ms (%d rows)\n",
            $name, $elapsedMs, count($rows));
    }
    printf("Component %d: %.1f -> %.1f ms; results identical\n", $componentId, $totalBefore, $totalAfter);
}
echo "PASS $checks query comparisons; no customer records changed\n";
foreach (['ink_counter_machines', 'ink_counter_periods', 'ink_purchase_totals', 'ink_latest_prices'] as $name) {
    [$rows, $elapsedMs] = $run($queries($candidate, 30)[$name]);
    printf("%s: %.1f ms (%d rows)\n", $name, $elapsedMs, count($rows));
}
// Isolated SQL constants exercise ties, numeric TEXT references, unit matching,
// unfinished/zero-quantity purchases and NULL latest price without writing data.
$fixture = "WITH purchases AS (
    SELECT 1 id,100 date_added,'07' article,3649 unit,39 state,1 qty,12 price UNION ALL
    SELECT 2,100,'7',3649,3790,1,NULL UNION ALL
    SELECT 3,101,'7',3649,1,1,99 UNION ALL
    SELECT 4,102,'7',3649,39,0,88 UNION ALL
    SELECT 5,103,'7',3648,39,1,77 UNION ALL
    SELECT 6,104,'7',3649,39,1,11 UNION ALL
    SELECT 7,104,'7',3649,39,1,NULL
), ranked AS (SELECT *,ROW_NUMBER() OVER(PARTITION BY article+0,unit ORDER BY date_added DESC,id DESC) n
    FROM purchases WHERE state IN(39,3790) AND qty>0)
SELECT id,price FROM ranked WHERE n=1 AND article+0=7 AND unit=3649";
[$rows] = $run($fixture);
if (count($rows) !== 1 || (int) $rows[0]['id'] !== 7 || $rows[0]['price'] !== null) {
    throw new RuntimeException('Latest-purchase semantics regression');
}
echo "PASS latest-purchase NULL/tie/state/quantity/unit/numeric-reference fixture\n";

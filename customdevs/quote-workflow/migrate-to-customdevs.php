<?php
/**
 * Moves the custom quote-workflow code into _CustomDEVs/quote-workflow and rewrites every path
 * that calls it (files, database rows, system cron). Run from anywhere as root:
 *   php migrate-to-customdevs.php            # dry run: lists what would change
 *   php migrate-to-customdevs.php --apply    # performs the migration
 * The pre-migration state (files, DB rows, cron) is saved in _CustomDEVs/_arquivo/migration-<stamp>/.
 */
if (PHP_SAPI !== 'cli') exit(1);
$apply = in_array('--apply', $argv, true);
$root = '/home/vianaedias/public_html';
chdir($root);
$dest = '_CustomDEVs/quote-workflow';
$stamp = gmdate('Ymd-His');
$archive = "_CustomDEVs/_arquivo/migration-$stamp";
$cron = '/etc/cron.d/vd-production-evaluation';

// Old location => new location (relative to public_html).
$moves = [
    'includes/vd_quote_workflow' => "$dest/vd_quote_workflow",
    'includes/vd_workflow_deployment' => "$dest/vd_workflow_deployment",
    'includes/vd-operation-reference-cost.php' => "$dest/vd-operation-reference-cost.php",
    'includes/vd-equipment-hourly-cost.php' => "$dest/vd-equipment-hourly-cost.php",
];
$bridges = [];
foreach (array_merge(glob('modules/items/actions/vd_*.php'), glob('modules/items/views/vd_*.php')) as $file) {
    $moves[$file] = "$dest/$file";
    $bridges[] = $file;
}
foreach (glob('js/vd-*') as $file) {
    $moves[$file] = "$dest/$file";
}

// Path rewrites, applied in one pass (strtr never re-replaces its own output). Escaped JSON variants included.
$prefixes = [
    'includes/vd_quote_workflow' => "$dest/vd_quote_workflow",
    'includes/vd_workflow_deployment' => "$dest/vd_workflow_deployment",
    'includes/vd-operation-reference-cost.php' => "$dest/vd-operation-reference-cost.php",
    'includes/vd-equipment-hourly-cost.php' => "$dest/vd-equipment-hourly-cost.php",
    'modules/items/actions/vd_' => "$dest/modules/items/actions/vd_",
    'modules/items/views/vd_' => "$dest/modules/items/views/vd_",
    'js/vd-' => "$dest/js/vd-",
];
$rewrite = [];
foreach ($prefixes as $old => $new) {
    $rewrite[$old] = $new;
    $rewrite[str_replace('/', '\\/', $old)] = str_replace('/', '\\/', $new);
}
// The deployment kit refers to sibling projects relative to its own folder; it moves one level deeper.
$rewrite['../../_CustomDEVs/'] = '../../';
$needles = array_keys($rewrite);

function say(string $text): void { echo $text, PHP_EOL; }
function containsAny(string $text, array $needles): bool {
    foreach ($needles as $needle) if (strpos($text, $needle) !== false) return true;
    return false;
}
function textFiles(string $dir, array $skip): Generator {
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        static function ($file) use ($skip) {
            $path = preg_replace('#^\./#', '', str_replace('\\', '/', $file->getPathname()));
            foreach ($skip as $prefix) if ($path === $prefix || strpos($path, $prefix . '/') === 0) return false;
            if ($file->isDir()) return basename($path) !== 'backups' || strpos($path, '_CustomDEVs/') !== 0;
            return true;
        }));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getSize() > 8 * 1024 * 1024) continue;
        if (!preg_match('/\.(php|phtml|js|css|json|md|txt|cjs|sh|html?)$|^\.htaccess$/i', $file->getFilename())) continue;
        yield preg_replace('#^\./#', '', str_replace('\\', '/', $file->getPathname()));
    }
}
$skip = ['uploads', 'backups', 'cache', 'tmp', 'log', 'owncloud', 'v2.vianaedias.net', 'db', 'prodfotos', '__CompanyLogos__',
    'plugins/ext', 'cgi-bin', '_CustomDEVs/_arquivo', '_CustomDEVs/_quarentena', '_CustomDEVs/bkUploads', '_CustomDEVs/phd-filetracker',
    "$dest/_migration"];

// ---------- Preflight ----------
foreach ($moves as $old => $new) {
    if (!file_exists($old)) throw new RuntimeException("Missing: $old");
    if (file_exists($new)) throw new RuntimeException("Already exists: $new");
}
$referencing = [];
foreach (textFiles('.', $skip) as $file) {
    $content = file_get_contents($file);
    if ($content !== false && containsAny($content, $needles)) $referencing[] = $file;
}
require 'config/database.php';
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
$db->set_charset('utf8mb4');
$dbTargets = [['app_fields', 'configuration'], ['app_entities_configuration', 'configuration_value'],
    ['app_ext_report_page_blocks', 'settings'], ['app_ext_processes_actions', 'settings']];
$dbRows = [];
foreach ($dbTargets as [$table, $column]) {
    $like = implode(' OR ', array_map(static function ($needle) use ($db, $column) {
        return "`$column` LIKE '%" . $db->real_escape_string(addcslashes($needle, '%_\\')) . "%'";
    }, $needles));
    foreach ($db->query("SELECT id, `$column` value FROM `$table` WHERE $like") as $row) {
        $dbRows[] = ['table' => $table, 'column' => $column, 'id' => (int) $row['id'], 'value' => $row['value']];
    }
}
$cronText = is_file($cron) ? file_get_contents($cron) : '';

say('Moves: ' . count($moves) . ' (' . count($bridges) . ' module bridges)');
foreach ($moves as $old => $new) say("  $old -> $new");
say('Files with path references (outside moved items): ' . count(array_filter($referencing, static function ($file) use ($moves) {
    foreach (array_keys($moves) as $old) if ($file === $old || strpos($file, $old . '/') === 0) return false;
    return true;
})));
foreach ($referencing as $file) {
    $inMoved = false;
    foreach (array_keys($moves) as $old) if ($file === $old || strpos($file, $old . '/') === 0) $inMoved = true;
    if (!$inMoved) say("  $file");
}
say('Database rows: ' . count($dbRows));
foreach ($dbRows as $row) say("  {$row['table']}#{$row['id']}");
say('Cron: ' . (containsAny($cronText, $needles) ? $cron : 'nothing to change'));
if (!$apply) { say('Dry run only. Re-run with --apply.'); exit(0); }

// ---------- Apply ----------
$owner = static function (string $path): void {
    @chown($path, 'vianaedias'); @chgrp($path, 'vianaedias');
};
mkdir("$archive/files", 0750, true);
// 1. Snapshot of the pre-migration state (files that move or get rewritten, DB rows, cron).
$snapshot = array_unique(array_merge(array_keys($moves), $referencing));
foreach ($snapshot as $path) {
    $target = "$archive/files/$path";
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0750, true);
    exec('cp -a ' . escapeshellarg($path) . ' ' . escapeshellarg($target), $out, $code);
    if ($code !== 0) throw new RuntimeException("Snapshot failed: $path");
}
file_put_contents("$archive/db-rows.json", json_encode($dbRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
if ($cronText !== '') file_put_contents("$archive/vd-production-evaluation.cron", $cronText);
file_put_contents("$archive/moves.json", json_encode($moves, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exec('chown -R vianaedias:vianaedias ' . escapeshellarg('_CustomDEVs/_arquivo'));
say("Snapshot: $archive");

// 2. Move.
foreach ($moves as $old => $new) {
    if (!is_dir(dirname($new))) { mkdir(dirname($new), 0755, true); $owner(dirname($new)); }
    if (!rename($old, $new)) throw new RuntimeException("Move failed: $old");
}
// 3. Rewrite paths in moved code, referencing files and the other _CustomDEVs projects.
$changed = 0;
$files = [];
foreach ($referencing as $file) {
    $mapped = $file;
    foreach ($moves as $old => $new) {
        if ($file === $old || strpos($file, $old . '/') === 0) { $mapped = $new . substr($file, strlen($old)); break; }
    }
    $files[] = $mapped;
}
foreach (textFiles($dest, ["$dest/_migration"]) as $file) $files[] = $file;
foreach (array_unique($files) as $file) {
    $content = file_get_contents($file);
    if ($content === false || !containsAny($content, $needles)) continue;
    $updated = strtr($content, $rewrite);
    $perms = fileperms($file) & 0777;
    $temporary = $file . '.migrate-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $updated) !== strlen($updated) || !chmod($temporary, $perms)) throw new RuntimeException("Write failed: $file");
    @chown($temporary, fileowner($file)); @chgrp($temporary, filegroup($file));
    if (!rename($temporary, $file)) throw new RuntimeException("Replace failed: $file");
    $changed++;
}
say("Rewritten files: $changed");
// Manifest source hashes follow the rewritten sources.
$manifestFile = "$dest/vd_workflow_deployment/workflow-manifest.json";
if (is_file($manifestFile)) {
    $manifest = json_decode(file_get_contents($manifestFile), true);
    foreach ($manifest['files'] ?? [] as $key => $entry) {
        $source = "$dest/vd_workflow_deployment/" . $entry['source'];
        if (is_file($source)) $manifest['files'][$key]['hash'] = hash_file('sha256', $source);
    }
    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    say('Manifest hashes refreshed.');
}
// 4. Bridges keep Rukovoditel's module URLs (module=items/vd_...) working.
foreach ($bridges as $file) {
    $code = "<?php\n// Código em _CustomDEVs/quote-workflow (migrado em " . gmdate('Y-m-d') . "). Esta ponte mantém o URL do Rukovoditel.\n" .
        "require DIR_FS_CATALOG . '$dest/$file';\n";
    file_put_contents($file, $code);
    chmod($file, 0644); $owner($file);
}
// 5. Web access: only static assets from _CustomDEVs/quote-workflow; deployment tooling fully denied.
file_put_contents("$dest/.htaccess", "Options -Indexes\nRequire all denied\n<FilesMatch \"(?i)\\.(css|js|png|jpe?g|gif|svg|woff2?|map)$\">\n    Require all granted\n</FilesMatch>\n");
file_put_contents("$dest/vd_workflow_deployment/.htaccess", "Options -Indexes\n<FilesMatch \".\">\n    Require all denied\n</FilesMatch>\n");
$owner("$dest/.htaccess"); $owner("$dest/vd_workflow_deployment/.htaccess"); $owner($dest);
// 6. Database rows and cron.
$statement = null;
foreach ($dbRows as $row) {
    $value = strtr($row['value'], $rewrite);
    $sql = "UPDATE `{$row['table']}` SET `{$row['column']}`=? WHERE id=?";
    $statement = $db->prepare($sql);
    $statement->bind_param('si', $value, $row['id']);
    if (!$statement->execute()) throw new RuntimeException("DB update failed: {$row['table']}#{$row['id']}");
}
say('Database rows updated: ' . count($dbRows));
if ($cronText !== '' && containsAny($cronText, $needles)) {
    file_put_contents($cron, strtr($cronText, ["$root/includes/vd_quote_workflow" => "$root/$dest/vd_quote_workflow"]));
    say("Cron updated: $cron");
}
say('Done.');

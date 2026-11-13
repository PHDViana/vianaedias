<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = '/home/vianaedias/public_html';
$custom = $root . '/_CustomDEVs/product-request-import';
$backups = $custom . '/backups/' . gmdate('Ymd-His');
if (!is_dir($custom) || !is_file($custom . '/product-request-import.php')) {
    throw new RuntimeException('Canonical import sources are missing from _CustomDEVs.');
}
$targets = [
    'product-configurator-action.php' => [
        'target' => $root . '/_CustomDEVs/quote-workflow/modules/items/actions/vd_product_configurator.php',
        'old' => 'c081d9eb0b16eae7d2551b2e32a874f20d50a6ea9928342eb2d2308f8313de03',
    ],
    'product-configurator-ui.js' => [
        'target' => $root . '/_CustomDEVs/quote-workflow/js/vd-product-configurator.js',
        'old' => '25c9fd7bd3f967cd17b5fcd62424370d3f2c45c58faa5e609c6e0126be801773',
    ],
    'product-request.css' => [
        'target' => $root . '/_CustomDEVs/quote-workflow/js/vd-product-request.css',
        'old' => 'a6ca8711df30eca095804acf19b9e116c58203f61728b2ea12c4aeb59e0dc267',
    ],
];
$written = [];
if (!is_dir($backups)) {
    if (!mkdir($backups, 0750, true) || !chown($backups, 'vianaedias') || !chgrp($backups, 'vianaedias')) {
        throw new RuntimeException('Cannot create the user-owned CustomDEVs backup folder.');
    }
}

function vdImportInstallFile(string $source, string $target, string $expectedOld, string $backupDir): bool
{
    $newHash = hash_file('sha256', $source);
    if ($newHash === false) throw new RuntimeException('Cannot hash source: ' . $source);
    $oldHash = is_file($target) ? hash_file('sha256', $target) : false;
    if ($oldHash === $newHash) return false;
    if ($oldHash !== $expectedOld) throw new RuntimeException('Concurrent file change; refusing to replace: ' . $target);
    $backup = $backupDir . '/' . basename($target) . '.before';
    if (!copy($target, $backup) || !chmod($backup, 0644) ||
        !chown($backup, 'vianaedias') || !chgrp($backup, 'vianaedias')) {
        throw new RuntimeException('Cannot create the CustomDEVs backup: ' . $target);
    }
    $temporary = $target . '.customdev-' . bin2hex(random_bytes(6));
    if (!copy($source, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) {
        if (is_file($temporary)) unlink($temporary);
        throw new RuntimeException('Cannot install: ' . $target);
    }
    if (hash_file('sha256', $target) !== $newHash) throw new RuntimeException('Installed hash mismatch: ' . $target);
    echo 'Installed ', $target, ' ', $newHash, PHP_EOL;
    return true;
}

try {
    foreach ($targets as $sourceName => $definition) {
        $source = $custom . '/' . $sourceName;
        if (!is_file($source)) throw new RuntimeException('Missing source: ' . $sourceName);
        if (substr($sourceName, -4) === '.php') token_get_all(file_get_contents($source), TOKEN_PARSE);
        if (vdImportInstallFile($source, $definition['target'], $definition['old'], $backups)) {
            $written[] = [$definition['target'], $definition['old']];
        }
    }

    $config = $root . '/_CustomDEVs/quote-workflow/vd_quote_workflow/product-configurator.php';
    $configSource = file_get_contents($config);
    $canonical = file_get_contents($custom . '/product-configurator.php');
    if ($configSource === false || $canonical === false) throw new RuntimeException('Cannot read native configurator validator.');
    token_get_all($canonical, TOKEN_PARSE);
    $oldHash = 'c5dc7187c1588bad6e22ec0d873b4a0a575acc50621544b0e004bb74227f650e';
    if (strpos($configSource, "'txt'=>['text/plain']") === false) {
        if (hash('sha256', $configSource) !== $oldHash) {
            throw new RuntimeException('Concurrent native configurator change; refusing the TXT attachment patch.');
        }
        $anchor = "'tiff'=>['image/tiff'],";
        if (substr_count($configSource, $anchor) !== 1) throw new RuntimeException('TXT validation anchor changed.');
        $updated = str_replace($anchor, $anchor . "'txt'=>['text/plain'],", $configSource);
        if ($updated !== $canonical) throw new RuntimeException('Custom source differs from the safe TXT allowlist patch.');
        $backup = $backups . '/product-configurator.php.before';
        if (!copy($config, $backup)) throw new RuntimeException('Cannot back up native configurator validator.');
        $temporary = $config . '.customdev-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated) ||
            !chmod($temporary, 0644) || !rename($temporary, $config)) {
            if (is_file($temporary)) unlink($temporary);
            throw new RuntimeException('Cannot install TXT attachment validation.');
        }
        echo 'Patched native document allowlist ', hash('sha256', $updated), PHP_EOL;
    } elseif (hash('sha256', $configSource) !== hash('sha256', $canonical)) {
        throw new RuntimeException('Installed TXT allowlist differs from the CustomDEVs canonical source.');
    }
    echo 'Deployment complete. Backups: ', $backups, PHP_EOL;
} catch (Throwable $error) {
    foreach (array_reverse($written) as [$target, $oldHash]) {
        $backup = $backups . '/' . basename($target) . '.before';
        if (is_file($backup) && hash_file('sha256', $backup) === $oldHash) {
            $temporary = $target . '.rollback-' . bin2hex(random_bytes(6));
            if (copy($backup, $temporary) && rename($temporary, $target)) continue;
        }
        error_log('VD request import rollback failed for ' . $target);
    }
    throw $error;
}

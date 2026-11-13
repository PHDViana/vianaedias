<?php
// Executed only by the isolated systemd service, with the PDF on stdin.
if (PHP_SAPI !== 'cli') exit(1);
ini_set('display_errors', 'stderr');
$directory = sys_get_temp_dir() . '/vd-ink-' . bin2hex(random_bytes(12));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create analysis workspace.');
try {
    $pdf = stream_get_contents(STDIN, 5 * 1024 * 1024 + 1);
    if (strlen($pdf) > 5 * 1024 * 1024 || substr($pdf, 0, 5) !== '%PDF-') throw new DomainException('PDF inválido ou superior a 5 MB.');
    file_put_contents($directory . '/input.pdf', $pdf);
    $run = static function (array $options) use ($directory) {
        $command = array_merge(['/usr/bin/gs', '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '-dNumRenderingThreads=1', '-dMaxBitmap=10000000', '-dFirstPage=1', '-dLastPage=21',
            '-dUseCropBox', '-dSimulateOverprint=true'], $options, ['-f', $directory . '/input.pdf']);
        $process = proc_open($command, [0 => ['file','/dev/null','r'],
            1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('PDF renderer unavailable.');
        $exit = proc_close($process);
        $stdout = file_get_contents($directory . '/stdout');
        $stderr = file_get_contents($directory . '/stderr');
        if ($exit !== 0 || preg_match('/error|repair|recover|warning|substitut|not found|invalid/i', $stderr . $stdout)) {
            error_log('VD ink renderer: ' . substr($stderr . $stdout, 0, 4000));
            throw new DomainException('PDF não processado com fidelidade. Verifique fontes, proteção e integridade do PDF.');
        }
        return $stdout;
    };
    $sizes = $run(['-sDEVICE=ink_cov', '-r72', '-sOutputFile=' . $directory . '/coverage',
        '-c', '<< /EndPage { exch pop 0 eq dup { (VD_PAGE ) print currentpagedevice /PageSize get == } if } >> setpagedevice']);
    $coverage = file_get_contents($directory . '/coverage');
    preg_match_all('/VD_PAGE\s+\[([\d.]+)\s+([\d.]+)\]/', $sizes, $dimensions, PREG_SET_ORDER);
    preg_match_all('/^\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+CMYK/m', $coverage, $channels, PREG_SET_ORDER);
    if (!$channels || count($channels) > 20 || count($dimensions) !== count($channels)) {
        error_log('VD ink coverage format: ' . substr($sizes, 0, 1000) . ' / ' . substr($coverage, 0, 1000));
        throw new DomainException('PDF sem páginas válidas, com mais de 20 páginas ou formato não suportado.');
    }
    $run(['-sDEVICE=tiffsep', '-r12', '-sOutputFile=' . $directory . '/separation-%d.tif']);
    $spots = [];
    foreach (glob($directory . '/separation-*.tif') as $path) {
        if (preg_match('/\((.*)\)\.tif$/', basename($path), $match) &&
            !in_array($match[1], ['Cyan','Magenta','Yellow','Black'], true)) $spots[] = $match[1];
    }
    $pages = [];
    foreach ($channels as $index => $channel) {
        $width = (float) $dimensions[$index][1] * 25.4 / 72;
        $height = (float) $dimensions[$index][2] * 25.4 / 72;
        if ($width <= 0 || $height <= 0 || $width > 2000 || $height > 2000) throw new DomainException('Formato de página fora dos limites.');
        $values = array_map('floatval', array_slice($channel, 1, 4));
        if (min($values) < 0 || max($values) > 100) throw new RuntimeException('Invalid renderer coverage.');
        $pages[] = ['page' => $index + 1, 'width_mm' => $width, 'height_mm' => $height,
            'coverage_percent' => array_combine(['C','M','Y','K'], $values)];
    }
    echo json_encode(['pages' => $pages, 'spots' => array_values(array_unique($spots)),
        'engine' => 'Ghostscript ink_cov, 72 dpi, CropBox, default CMYK conversion',
        'calibrated' => false], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('VD ink worker: ' . $error->getMessage());
    echo json_encode(['error' => $error instanceof DomainException ? $error->getMessage() : 'Falha no processador PDF.'], JSON_UNESCAPED_UNICODE);
    exit(1);
} finally {
    foreach (glob($directory . '/*') as $path) {
        if (is_file($path) && !unlink($path)) error_log('VD ink cleanup failed: ' . basename($path));
    }
    if (!rmdir($directory)) error_log('VD ink workspace cleanup failed.');
}

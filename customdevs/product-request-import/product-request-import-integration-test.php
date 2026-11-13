<?php
require_once __DIR__ . '/product-request-import.php';

function importIntegrationCheck($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function importTestPdf(array $lines): string
{
    $stream = "BT\n/F1 16 Tf\n50 740 Td\n";
    foreach ($lines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $stream .= '(' . $escaped . ") Tj\n0 -28 Td\n";
    }
    $stream .= "ET\n";
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        4 => '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($id = 1; $id <= 5; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
    }
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
}

$pdf = importTestPdf([
    'Produto: Cartoes de visita',
    'Quantidade: 250 exemplares',
    'Formato: 90 x 50 mm',
]);
$result = vd_request_import_extract('', [[
    'name' => 'pedido-teste.pdf',
    'mime' => 'application/pdf',
    'content' => $pdf,
]]);
importIntegrationCheck(strpos($result['text'], 'Quantidade: 250') !== false, 'Selectable PDF text extraction failed.');

if (extension_loaded('gd') && function_exists('imagettftext')) {
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    if (is_file($font)) {
        $image = imagecreatetruecolor(1800, 360);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        imagettftext($image, 42, 0, 45, 95, $black, $font, 'Produto: Cartoes de visita');
        imagettftext($image, 42, 0, 45, 180, $black, $font, 'Quantidade: 250 exemplares');
        $path = tempnam(sys_get_temp_dir(), 'vd-import-test-');
        imagepng($image, $path);
        imagedestroy($image);
        $png = file_get_contents($path);
        unlink($path);
        $ocr = vd_request_import_extract('', [[
            'name' => 'pedido-teste.png',
            'mime' => 'image/png',
            'content' => $png,
        ]]);
        importIntegrationCheck(stripos($ocr['text'], '250') !== false, 'Local image OCR failed.');
    }
}

echo "Product request PDF/OCR integration checks passed.\n";

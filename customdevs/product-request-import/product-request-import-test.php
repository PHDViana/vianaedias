<?php
$helper = __DIR__ . '/product-request-import.php';
if (!is_file($helper)) $helper = __DIR__ . '/customdevs/product-request-import/product-request-import.php';
require_once $helper;

function importCheck($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$types = [
    ['id' => 100, 'parent_id' => 0, 'name' => 'Cartões'],
    ['id' => 101, 'parent_id' => 100, 'name' => 'Cartões de visita'],
    ['id' => 102, 'parent_id' => 100, 'name' => 'Cartões de agradecimento'],
];
$text = "Produto: Cartões de visita\nReferência: REF-123\nQuantidade: 1.000 exemplares\n".
    "Formato: 90 x 50 mm\nPapel: Couché mate 350 g/m²\nImpressão: CMYK 4/4\n".
    "32 páginas\nEntrega prevista: 30/11/2026";
$parsed = vd_request_import_suggestions($text, $types);
importCheck(($parsed['suggestions']['product_type'] ?? 0) === 101, 'Subcategory detection failed.');
importCheck(($parsed['suggestions']['name'] ?? '') === 'Cartões de visita', 'Job name extraction failed.');
importCheck(($parsed['suggestions']['reference'] ?? '') === 'REF-123', 'Reference extraction failed.');
importCheck(($parsed['suggestions']['quantities'] ?? []) === [1000], 'Quantity extraction failed.');
importCheck(($parsed['suggestions']['width_mm'] ?? 0) === 90.0, 'Width extraction failed.');
importCheck(($parsed['suggestions']['height_mm'] ?? 0) === 50.0, 'Height extraction failed.');
importCheck(($parsed['suggestions']['total_pages'] ?? 0) === 32, 'Page count extraction failed.');
importCheck(($parsed['suggestions']['faces'] ?? 0) === 1256, 'Print faces extraction failed.');
importCheck(($parsed['suggestions']['delivery'] ?? '') === '2026-11-30', 'Delivery date extraction failed.');

$email = vd_request_import_extract("Produto: Cartões de visita\nQuantidade: 250", [[
    'name' => 'pedido.txt',
    'mime' => 'text/plain',
    'content' => "Produto: Cartões de visita\nQuantidade: 250",
]]);
importCheck(strpos($email['text'], 'Quantidade: 250') !== false, 'Text file extraction failed.');

$ambiguous = vd_request_import_suggestions("Formato: 90 x 50\nQuantidade: 250", $types);
importCheck(($ambiguous['suggestions']['width_mm'] ?? 0) === 90.0 && ($ambiguous['suggestions']['height_mm'] ?? 0) === 50.0, 'Dimensions without a unit must default to millimetres.');
importCheck((bool) array_filter($ambiguous['warnings'], static function ($warning) {
    return strpos($warning, 'sem unidade') !== false;
}), 'Unitless dimension warning missing.');
$card = vd_request_import_suggestions("500 cartões\n85x55\n4 cores cmyk", [['id' => 3708, 'parent_id' => 3707, 'name' => 'Cartões']]);
importCheck(($card['suggestions']['width_mm'] ?? 0) === 85.0 && ($card['suggestions']['height_mm'] ?? 0) === 55.0, 'Compact unitless dimensions failed.');

$loose = vd_request_import_suggestions("500 cartões\n4 cores cmyk\nFrente e Verso\ncouché 350 gr", [
    ['id' => 3707, 'parent_id' => 0, 'name' => 'Economato'],
    ['id' => 3708, 'parent_id' => 3707, 'name' => 'Cartões'],
    ['id' => 3829, 'parent_id' => 3707, 'name' => 'Cartões de agradecimento'],
    ['id' => 3802, 'parent_id' => 3800, 'name' => "Flyer's"],
]);
importCheck(($loose['suggestions']['product_type'] ?? 0) === 3708, 'Plural product name detection failed.');
importCheck(($loose['suggestions']['quantities'] ?? []) === [500], 'Leading quantity extraction failed.');
importCheck(($loose['suggestions']['print_mode'] ?? '') === 'cmyk' && ($loose['suggestions']['faces'] ?? 0) === 1256, 'Independent faces/colours extraction failed.');
importCheck(($loose['suggestions']['paper'] ?? '') === 'couché 350 gr', 'Unlabelled paper extraction failed.');
$flyer = vd_request_import_suggestions("Preciso de 1.000 flyers A5 4/0 em couché mate 150g", [['id' => 3802, 'parent_id' => 3800, 'name' => "Flyer's"]]);
importCheck(($flyer['suggestions']['product_type'] ?? 0) === 3802 && ($flyer['suggestions']['quantities'] ?? []) === [1000], 'Inline product quantity failed.');
importCheck(($flyer['suggestions']['paper'] ?? '') === 'couché mate 150g', 'Paper span extraction failed.');

$catalog = [
    ['id' => 3707, 'parent_id' => 0, 'name' => 'Economato'], ['id' => 3708, 'parent_id' => 3707, 'name' => 'Cartões'],
    ['id' => 3709, 'parent_id' => 3707, 'name' => 'Envelopes'], ['id' => 3711, 'parent_id' => 3707, 'name' => 'Capas'],
    ['id' => 3712, 'parent_id' => 0, 'name' => 'Revistas'], ['id' => 3714, 'parent_id' => 3712, 'name' => 'Agrafadas'],
    ['id' => 3800, 'parent_id' => 0, 'name' => 'Publicidade'], ['id' => 3802, 'parent_id' => 3800, 'name' => "Flyer's"],
    ['id' => 3856, 'parent_id' => 0, 'name' => 'Brochuras'], ['id' => 3862, 'parent_id' => 3856, 'name' => 'Agrafadas'],
];
$multi = vd_request_import_requests("Bom dia,\nEntrega: 30/11/2026\n\nCartões\n100, 200 e 500 unidades\n85x55\n\n" .
    "Brochuras com 4 páginas\nA4, 1000 exemplares\nCapa: couché 250g\nAgrafadas\n\n" .
    "2000 flyers A5 4/0, para colocar dentro dos envelopes", $catalog);
importCheck(count($multi) === 3, 'Multi-request split failed: ' . count($multi));
importCheck(($multi[0]['suggestions']['product_type'] ?? 0) === 3708 && ($multi[0]['suggestions']['quantities'] ?? []) === [100, 200, 500], 'First request mismatch.');
importCheck(($multi[1]['suggestions']['product_type'] ?? 0) === 3862 && ($multi[1]['suggestions']['quantities'] ?? []) === [1000], 'Brochure request mismatch.');
importCheck(($multi[2]['suggestions']['product_type'] ?? 0) === 3802 && ($multi[2]['suggestions']['quantities'] ?? []) === [2000], 'Flyer request mismatch.');
importCheck(($multi[2]['suggestions']['delivery'] ?? '') === '2026-11-30', 'Shared header not applied to every request.');
importCheck(vd_request_import_requests("500 cartões\n85x55\ncouché 350 gr", $catalog) === [], 'Single request must not be split.');
importCheck(count(vd_request_import_requests("500 cartões 85x55\n1000 cartões 90x50", $catalog)) === 2, 'Same product with two quantities must split.');
$list = vd_request_import_suggestions("Cartões de visita (100/200/500)\n4/4", $catalog);
importCheck(($list['suggestions']['quantities'] ?? []) === [100, 200, 500], 'Quantity list after product failed.');

$brochure = "500 brochuras A4 (fechado) agrafadas\n16 páginas\n4 cores cmyk\nCapa: couché 300 gr\nmiolo: 250gr\nplastificado na capa";
importCheck(vd_request_import_requests($brochure, $catalog) === [], 'A product mentioned in a detail line must not open a new request.');
importCheck((vd_request_import_suggestions($brochure, $catalog)['suggestions']['product_type'] ?? 0) === 3862, 'Brochure subcategory detection failed.');

echo "Product request import checks passed.\n";

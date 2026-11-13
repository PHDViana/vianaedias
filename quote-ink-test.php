<?php
require_once __DIR__ . '/quote-ink.php';
function vd_ink_fixture_pdf($content, $resources = '<< >>', $size = '595.276 841.89') {
    $streams = is_array($content) ? $content : [$content];
    $kids = [];
    foreach ($streams as $index => $stream) $kids[] = (3 + $index * 2) . ' 0 R';
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [' . implode(' ',$kids) . '] /Count ' . count($streams) . ' >>'];
    foreach ($streams as $index => $stream) {
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $size] /Resources $resources /Contents " . (4+$index*2) . ' 0 R >>';
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream";
    }
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i+1) . " 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf);
    $size = count($objects)+1;
    $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
    foreach (array_slice($offsets,1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) return;
$count = 0;
$check = static function ($condition) use (&$count) { if (!$condition) throw new RuntimeException('Ink check failed: ' . ($count+1)); $count++; };
$analysis = ['pages' => [['width_mm'=>210,'height_mm'=>297,'coverage_percent'=>['C'=>100,'M'=>50,'Y'=>0,'K'=>0]]], 'spots'=>[]];
$calc = ['component'=>['width'=>210,'height'=>297], 'rubrics'=>[['name'=>'Test']], 'parts'=>[[
    'operation'=>['id'=>7], 'machine'=>['id'=>6], 'modeId'=>3747, 'allocation'=>[2], 'result'=>['goodPlans'=>100,'wastePlans'=>10]]]];
$params = ['rates'=>['C'=>1.5,'M'=>1.5,'Y'=>1.5,'K'=>1.5], 'loss_percent'=>10,'startup_g'=>5];
$result = vd_ink_estimate($analysis,$calc,$params);
$check(abs($result['inks']['C']['printing_g']-20.5821)<0.00001);
$check(abs($result['inks']['C']['total_g']-27.64031)<0.00001);
$check($result['inks']['Y']['total_g'] === 0.0);
$check($result['pages'][0]['printed_copies_including_waste'] === 220.0);
$duplex = $analysis;
$duplex['pages'][] = $analysis['pages'][0];
$duplexCalc = $calc;
$duplexCalc['parts'][0]['modeId'] = 3748;
$duplexResult = vd_ink_estimate($duplex,$duplexCalc,$params);
$check(abs($duplexResult['inks']['C']['printing_g']-41.1642)<0.00001 &&
    $duplexResult['inks']['C']['fixed_loss_g'] === 5.0 && $duplexResult['pages'][1]['face']==='Verso');
$multipleCalc = $calc;
$multipleCalc['parts'][] = $calc['parts'][0];
$multipleResult = vd_ink_estimate($analysis,$multipleCalc,$params);
$check($multipleResult['pages'][0]['printed_copies_including_waste']===440.0 &&
    $multipleResult['inks']['C']['fixed_loss_g'] === 10.0);
foreach (['spot','pages','format','mode','digital','negative','missing'] as $case) {
    $a=$analysis; $c=$calc; $p=$params;
    if ($case==='spot') $a['spots']=['Pantone Test'];
    if ($case==='pages') $a['pages'][]=$a['pages'][0];
    if ($case==='format') $a['pages'][0]['width_mm']=720;
    if ($case==='mode') $c['parts'][0]['modeId']=3749;
    if ($case==='digital') $c['parts'][0]['machine']['id']=8;
    if ($case==='negative') $p['rates']['C']=-1;
    if ($case==='missing') unset($c['parts'][0]['allocation']);
    try { vd_ink_estimate($a,$c,$p); throw new RuntimeException('Invalid ink input accepted.'); }
    catch (DomainException $error) { $check(true); }
}
if (in_array('--worker', $argv, true)) {
    foreach ([
        ['1 0 0 0 k 0 0 595.276 841.89 re f',100],
        ['0.5 0 0 0 k 0 0 595.276 841.89 re f',50],
        ['',0]
    ] as [$stream,$expected]) {
        $render = vd_ink_render(vd_ink_fixture_pdf($stream));
        $check(count($render['pages'])===1 && abs($render['pages'][0]['coverage_percent']['C']-$expected)<0.25);
        $check(abs($render['pages'][0]['width_mm']-210)<0.1 && !$render['spots']);
    }
    $spot = vd_ink_render(vd_ink_fixture_pdf('/CS1 cs 1 scn 0 0 595.276 841.89 re f',
        '<< /ColorSpace << /CS1 [/Separation /PantoneTest /DeviceCMYK << /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] /C1 [1 0 0 0] /N 1 >>] >> >>'));
    $check(in_array('PantoneTest',$spot['spots'],true));
    $overprint=vd_ink_render(vd_ink_fixture_pdf('1 0 0 0 k 0 0 595.276 841.89 re f /GS1 gs 0 1 0 0 k 0 0 595.276 841.89 re f',
        '<< /ExtGState << /GS1 << /Type /ExtGState /OP true /op true /OPM 1 >> >> >>'));
    $check($overprint['pages'][0]['coverage_percent']['C'] > 99.9 &&
        $overprint['pages'][0]['coverage_percent']['M'] > 99.9);
    $twoPages = vd_ink_render(vd_ink_fixture_pdf(['1 0 0 0 k 0 0 595.276 841.89 re f','0 0 0 1 k 0 0 595.276 841.89 re f']));
    $check(count($twoPages['pages'])===2 && $twoPages['pages'][1]['coverage_percent']['K']>99.9 &&
        $twoPages['pages'][0]['coverage_percent']['C']>99.9);
    try { vd_ink_render(vd_ink_fixture_pdf(array_fill(0,21,''))); throw new RuntimeException('Overlong PDF accepted.'); }
    catch (DomainException $error) { $check(true); }
    try { vd_ink_render("%PDF-1.4\ninvalid"); throw new RuntimeException('Invalid PDF accepted.'); }
    catch (DomainException $error) { $check(true); }
}
echo "$count ink checks passed.\n";

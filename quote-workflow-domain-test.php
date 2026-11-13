<?php
require __DIR__ . '/quote-workflow-domain.php';
require __DIR__ . '/quote-production-order.php';
$checks = 0;
function check($condition)
{
    global $checks;
    if (!$condition) throw new RuntimeException('Assertion failed: ' . ($checks + 1));
    $checks++;
}
function rejects($callback)
{
    try {
        $callback();
    } catch (DomainException $error) {
        check(true);
        return;
    }
    throw new RuntimeException('Expected explicit validation error.');
}
$calculation = ['quantity' => 100, 'allOperations' => ['complete' => true, 'errors' => [],
    'cost' => 10.0, 'sale' => 12.0, 'lines' => [
        ['key' => 'printing', 'cost' => 10.0, 'sale' => 12.0, 'name' => 'RH confidential']
    ]]];
check(vd_workflow_can_approve(4, [4]));
check(!vd_workflow_can_approve(5, [4]));
check(vd_workflow_can_approve(5, [4, 5]));
check(vd_workflow_validate_calculation($calculation)['unitSale'] === 0.12);
check(strlen(vd_workflow_version_hash($calculation)) === 64);
$incomplete = $calculation;
$incomplete['allOperations']['complete'] = false;
$incomplete['allOperations']['errors'] = ['Espiral: diâmetro por preencher.'];
$incomplete['allOperations']['lines'][0]['cost'] = null;
$incomplete['allOperations']['lines'][0]['sale'] = null;
$incomplete['allOperations']['lines'][0]['errors'] = ['Custo interno em falta.'];
$incomplete['allOperations']['lines'][] = [
    'key' => 'paper',
    'cost' => 4.0,
    'sale' => 5.0,
    'name' => 'Papel'
];
$incomplete['allOperations']['partialCost'] = 4.0;
$incomplete['allOperations']['partialSale'] = 5.0;
$incomplete['allOperations']['cost'] = null;
$incomplete['allOperations']['sale'] = null;
check(vd_workflow_validate_calculation($incomplete, true)['complete'] === false);
check(vd_workflow_validate_calculation($incomplete, true)['partialCost'] === 4.0);
check(strlen(vd_workflow_version_hash($incomplete, true)) === 64);
rejects(static function () use ($incomplete) { vd_workflow_validate_calculation($incomplete); });
rejects(static function () use ($incomplete) { vd_workflow_version_hash($incomplete); });
$invalidDraft = $incomplete;
$invalidDraft['allOperations']['partialSale'] = 6.0;
rejects(static function () use ($invalidDraft) { vd_workflow_validate_calculation($invalidDraft, true); });
$invalidDraft = $incomplete;
$invalidDraft['allOperations']['lines'][0]['cost'] = -1;
rejects(static function () use ($invalidDraft) { vd_workflow_validate_calculation($invalidDraft, true); });
$invalidDraft = $incomplete;
$invalidDraft['allOperations']['lines'][0]['cost'] = 'not-a-number';
rejects(static function () use ($invalidDraft) { vd_workflow_validate_calculation($invalidDraft, true); });
$changed = $calculation;
$changed['quantity'] = 200;
check(vd_workflow_version_hash($changed) !== vd_workflow_version_hash($calculation));
foreach (['draft', 'confirmed', 'approved', 'sent', 'accepted'] as $state) {
    rejects(static function () use ($state) { vd_workflow_transition($state, 'invalid', true); });
}
check(vd_workflow_transition('draft', 'confirm', false) === 'confirmed');
check(vd_workflow_transition('confirmed', 'approve', true) === 'approved');
rejects(static function () { vd_workflow_transition('confirmed', 'approve', false); });
rejects(static function () { vd_workflow_transition('draft', 'begin_send', true); });
rejects(static function () { vd_workflow_transition('approved', 'begin_send', false); });
check(vd_workflow_transition('approved', 'begin_send', true) === 'sending');
check(vd_workflow_transition('sending', 'transport_unknown', true) === 'send_unknown');
rejects(static function () { vd_workflow_transition('send_unknown', 'retry', true); });
check(vd_workflow_transition('sending', 'transport_failed', true) === 'send_failed');
check(vd_workflow_transition('sending', 'transport_accepted', true) === 'sent');
check(vd_workflow_transition('sent', 'accept', true) === 'accepted');
rejects(static function () { vd_workflow_transition('accepted', 'revise', true); });
foreach ([null, -1, INF, 'invalid'] as $invalid) {
    $bad = $calculation;
    $bad['allOperations']['lines'][0]['cost'] = $invalid;
    rejects(static function () use ($bad) { vd_workflow_validate_calculation($bad); });
}
$bad = $calculation;
$bad['allOperations']['sale'] = 100;
rejects(static function () use ($bad) { vd_workflow_validate_calculation($bad); });
$bad = $calculation;
$bad['allOperations']['lines'][] = $bad['allOperations']['lines'][0];
rejects(static function () use ($bad) { vd_workflow_validate_calculation($bad); });
$bad = $calculation;
$bad['allOperations']['quantity'] = 200;
rejects(static function () use ($bad) { vd_workflow_validate_calculation($bad); });
$bad = $calculation;
$bad['results']['errors'] = ['Invalid geometry'];
rejects(static function () use ($bad) { vd_workflow_validate_calculation($bad); });
$quote = ['id' => 24, 'description' => '<script>unsafe</script>'];
$customer = ['name' => 'Cliente & teste', 'paymentTerms' => '30 dias'];
$components = [['description' => 'Componente A']];
$document = vd_workflow_commercial_document($quote, $customer, $components, [$calculation],
    strtotime('2026-11-06 12:00:00 UTC'));
check(strpos($document, '<script>') === false);
check(strpos($document, 'RH confidential') === false);
check(strpos($document, '21/11/2026') !== false);
check(strpos($document, '30 dias') !== false);
check(strpos($document, '12,00 €') !== false);
check(strpos($document, '0,1200 €') !== false);
$discountedDocument = vd_workflow_commercial_document($quote, $customer + ['discountPercent' => '10'],
    $components, [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
foreach (['P. unit.', 'Total s/ IVA', '0,1080 €', '10,80 €'] as $label)
    check(strpos($discountedDocument, $label) !== false);
foreach (['Desc.', '10,00 %', '0,1200 €'] as $hidden) check(strpos($discountedDocument, $hidden) === false);
check(strpos($document, 'Válido até 21/11/2026') !== false && strpos($document, '<h3>Componente A</h3>') !== false);
$sameTitle = vd_workflow_commercial_document(['id' => 24, 'description' => 'Flyers'], $customer, [['description' => 'Flyers']],
    [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
check(substr_count($sameTitle, 'Flyers') === 1);
check(vd_workflow_discounted_price(12, 100, '10')['sale'] === 10.8);
check(vd_workflow_discounted_price(341.75, 140, '12,5')['sale'] === 299.03);
check(vd_workflow_discounted_price(12, 100, 100)['sale'] === 0.0);
$extraPrice = vd_workflow_discounted_price(100, 10, 25, 5);
check($extraPrice['sale'] === 70.0 && $extraPrice['totalDiscountPercent'] === 30.0 &&
    $extraPrice['extraDiscountPercent'] === 5.0);
$extraDocument = vd_workflow_commercial_document($quote, $customer + ['discountPercent' => 25, 'extraDiscountPercent' => 5],
    $components, [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
check(strpos($extraDocument, '30,00 %') === false && strpos($extraDocument, '25,00 %') === false);
check(strpos($extraDocument, '0,0840 €') !== false && strpos($extraDocument, '8,40 €') !== false);
rejects(static function () { vd_workflow_discounted_price(100, 1, 80, 21); });
check(vd_workflow_discount_percent(null) === 0.0 && vd_workflow_discount_percent('') === 0.0);
foreach (['bad', -1, 101, [], true] as $invalidDiscount) {
    rejects(static function () use ($invalidDiscount) { vd_workflow_discount_percent($invalidDiscount); });
}
$nativeComponent = ['id' => 30, 'field_4225' => 'Convites', 'field_4242' => 'REF-1',
    'field_4218' => 400, 'field_4219' => 220, 'field_4233' => 1];
$technicalSnapshot = ['nativeComponent' => $nativeComponent, 'calculation' => ['allOperations' => ['lines' => [
    ['key' => 'paper', 'reference' => [['name' => 'Cartolina 300 g']], 'cost' => 123456],
    ['key' => 'printing', 'operationIds' => [4], 'name' => 'Internal machine'],
    ['key' => 'operation:1', 'operationId' => 44, 'operationRow' => 1, 'name' => 'RH confidential'],
    ['key' => 'operation:2', 'operationId' => 18, 'operationRow' => 2, 'name' => 'Vinco'],
    ['key' => 'color:1', 'color' => ['classification' => 'Pantone', 'pantone' => '123 C']]
]]]];
$technical = vd_workflow_commercial_component($technicalSnapshot);
check($technical['dimensions'] === '400 × 220 mm' && $technical['paper'] === 'Cartolina 300 g');
check($technical['printing'] === 'Impressão Digital' && $technical['finishing'] === 'Vinco');
check($technical['colors'] === 'Pantone 123 C');
foreach ([null, '', ' ', 0, '0'] as $pages) {
    $withoutPages = $technicalSnapshot;
    $withoutPages['nativeComponent']['field_4233'] = $pages;
    $descriptor = vd_workflow_commercial_component($withoutPages);
    check($descriptor['pages'] === '');
    $withoutPagesDocument = vd_workflow_commercial_document(['id' => 24, 'description' => 'Convites'],
        $customer, [$descriptor], [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
    check(strpos($withoutPagesDocument, 'Nº PÁG. / FLS. / Nº VIAS') === false);
}
check($technical['pages'] === 1);
$cmykSnapshot = $technicalSnapshot;
foreach (['Black', 'Yellow', 'Cyan', 'Magenta', 'Cyan'] as $index => $color) {
    $cmykSnapshot['calculation']['allOperations']['lines'][] = [
        'key' => 'color:' . ($index + 2), 'color' => ['classification' => $color, 'color_name' => $color]];
}
foreach ([9 => 'Corte Final', 10 => 'Embalamento', 13 => 'Plastificação'] as $id => $name) {
    $cmykSnapshot['calculation']['allOperations']['lines'][] = [
        'key' => 'operation:' . $id, 'operationId' => $id, 'operationRow' => $id, 'name' => $name];
}
$cmykTechnical = vd_workflow_commercial_component($cmykSnapshot);
check($cmykTechnical['colors'] === 'CMYK, Pantone 123 C');
check($cmykTechnical['finishing'] === 'Vinco; Plastificação');
$blackSnapshot = $technicalSnapshot;
$blackSnapshot['calculation']['allOperations']['lines'] = [
    ['key' => 'color:1', 'color' => ['classification' => 'Black']]];
check(vd_workflow_commercial_component($blackSnapshot)['colors'] === 'K');
$combined = vd_workflow_commercial_document(['id' => 24, 'description' => 'Convites'],
    $customer, [$cmykTechnical], [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
check(strpos($combined, 'Impressão Digital (CMYK, Pantone 123 C)') !== false);
check(strpos($combined, '<dt>CORES</dt>') === false);
$englishComponent = $technical;
$englishComponent['description'] = 'Invitations';
$englishComponent['englishTechnical'] = "400 × 220 mm\nDigital printing (CMYK)\n300 gsm textured board\nCreasing";
$englishDocument = vd_workflow_commercial_document(['id' => 24, 'description' => 'Invitations',
    'language' => 'en', 'observations' => 'Customer-approved notes'], ['name' => 'Customer',
        'paymentTerms' => '30 days', 'vat' => '23 %'], [$englishComponent], [$calculation],
    strtotime('2026-11-06 12:00:00 UTC'));
foreach (['lang="en"', 'QUOTATION', 'Unit Price', 'Total excl. VAT', 'Validity', '15 DAYS', 'Valid until',
    'TO BE AGREED', 'Print-ready digital artwork', 'Yours faithfully,', 'Digital printing (CMYK)',
    'Customer-approved notes'] as $required) check(strpos($englishDocument, $required) !== false);
check(strpos($englishDocument, 'De acordo com o vosso pedido') === false && strpos($englishDocument, 'Disc.') === false);
check(strpos($englishDocument, '0.1200 €') !== false && strpos($englishDocument, '12.00 €') !== false);
rejects(static function () use ($quote, $customer, $components, $calculation) {
    vd_workflow_commercial_document($quote + ['language' => 'fr'], $customer, $components, [$calculation], time());
});
rejects(static function () use ($quote, $customer, $components, $calculation) {
    vd_workflow_commercial_document($quote + ['language' => 'en'], $customer, $components, [$calculation], time());
});
check(strpos(json_encode($technical), 'confidential') === false && !isset($technical['cost']));
$otherQuantity = $calculation;
$otherQuantity['quantity'] = 200;
$otherQuantity['allOperations']['quantity'] = 200;
$otherQuantity['commercialComponents'] = [$technical];
$calculationWithTechnical = $calculation;
$calculationWithTechnical['commercialComponents'] = [$technical];
$branded = vd_workflow_commercial_document(['id' => 24, 'description' => 'Convites',
    'observations' => '<script>unsafe</script>'], $customer, [$technical],
    [$calculationWithTechnical, $otherQuantity], strtotime('2026-11-06 12:00:00 UTC'));
check(substr_count($branded, 'data:image/png;base64,') === 2);
check(strpos($branded, '.footer-space td{height:24mm}') !== false);
check(strpos($branded, '.footer{position:fixed;left:0;bottom:0;') !== false);
check(substr_count($branded, 'DIMENSÕES') === 1);
check(strpos($branded, '15 DIAS') !== false && strpos($branded, 'A COMBINAR') !== false);
check(strpos($branded, '<script>') === false && strpos($branded, 'Internal machine') === false);
check(strpos($branded, '0,0600 €') !== false);
check(strpos($branded, '<title>Orçamento 2026/24</title>') !== false);
check(strpos($branded, '<strong>ORÇAMENTO</strong> &nbsp; 2026/24') !== false);
$oldYear = vd_workflow_commercial_document(['id' => 24, 'number' => '2025/24', 'description' => 'Convites'],
    $customer, [$technical], [$calculation], strtotime('2026-11-06 12:00:00 UTC'));
check(strpos($oldYear, '<title>Orçamento 2025/24</title>') !== false);
$otherQuantity['commercialComponents'][0]['printing'] = 'Impressão Offset';
$different = vd_workflow_commercial_document(['id' => 24, 'description' => 'Convites'], $customer, [$technical],
    [$calculationWithTechnical, $otherQuantity], strtotime('2026-11-06 12:00:00 UTC'));
check(strpos($different, 'Alternativa: 100 unidades') !== false &&
    strpos($different, 'Alternativa: 200 unidades') !== false);
rejects(static function () use ($quote, $components, $calculation) {
    vd_workflow_commercial_document($quote, ['name' => 'Cliente'], $components, [$calculation], time());
});
$production = $calculation;
$production['machineId'] = 6;
$production['modeId'] = 3748;
$production['allOperations']['lines'][0]['operationIds'] = [7];
$production['parts'] = [['plan' => ['width' => 720, 'height' => 510,
    'poses' => 1, 'per_sheet' => 2, 'sheet_width' => 720, 'sheet_height' => 1020]]];
$rows = vd_workflow_production_rows($production, 1, 30, 14);
check($rows['origin']['version_id'] === 1);
check($rows['origin']['quantity_id'] === 14);
check($rows['plans'][0]['mode_id'] === 3748);
check($rows['plans'][0]['machine_id'] === 6);
check($rows['operations'][0]['operation_id'] === 7);
$otherRows = vd_workflow_production_rows($production, 2, 30, 15);
check($otherRows['origin'] !== $rows['origin']);
check($rows['origin']['quantity_id'] === 14);
$invalidPlan = $production;
$invalidPlan['parts'][0]['plan']['poses'] = 0;
rejects(static function () use ($invalidPlan) {
    vd_workflow_production_rows($invalidPlan, 1, 30, 14);
});
$production['rubrics'] = [['id' => 1, 'quantity' => 60, 'name' => '<A>', 'code' => 'A'],
    ['id' => 2, 'quantity' => 40, 'name' => 'B', 'code' => 'B']];
$production['parts'][0]['allocation'] = [3,2];
$production['parts'][0]['produced'] = [60,40];
$production['parts'][0]['runs'] = 20;
$production['parts'][0]['plan']['poses'] = 5;
$production['parts'][0]['plan']['suggestion'] = ['columns' => 5, 'rows' => 1, 'rotated' => true, 'gripper' => 10, 'bleed' => 3];
$production['parts'][0]['paperCalculation'] = ['paper' => ['id' => 2652, 'internal_id' => 1406, 'name' => 'Paper'], 'sheets' => 25];
$production['parts'][0]['plate'] = ['materialId' => 984, 'unitPrice' => 2.93];
$production['parts'][0]['result'] = ['plates' => 4, 'goodPlans' => 20, 'wastePlans' => 30];
$production['allOperations']['lines'][0]['hours'] = 0.3747619;
$production['allOperations']['lines'][0]['quantity'] = 0.3747619;
foreach (['paper', 'plates'] as $key) $production['allOperations']['lines'][] =
    ['key' => $key, 'name' => $key, 'quantity' => $key === 'paper' ? 25 : 4, 'cost' => 0, 'sale' => 0];
foreach ([2,3,13,43] as $op) $production['allOperations']['lines'][] =
    ['key' => 'operation:-' . $op, 'operationId' => $op, 'operationRow' => -$op, 'name' => 'Operation ' . $op,
        'quantity' => 1, 'hours' => 0.1, 'cost' => 0, 'sale' => 0];
$production['allOperations']['lines'][] = ['key' => 'imposition:-43:paper', 'operationId' => 43, 'parentOperationRow' => -43,
    'name' => 'Proof paper', 'quantity' => 1, 'unit' => 'folhas', 'cost' => 0, 'sale' => 0,
    'imposition' => ['paper' => ['id' => 228, 'name' => 'Proof paper']]];
$production['allOperations']['lines'][] = ['key' => 'lamination:-13:front:1', 'parentOperationRow' => -13,
    'name' => 'Film', 'quantity' => 0.260755632, 'unit' => 'm', 'cost' => 0, 'sale' => 0,
    'article' => ['id' => 533, 'internal_id' => 1017, 'name' => 'Film']];
$production['allOperations']['lines'][] = ['key' => 'color:1', 'name' => 'Cyan', 'quantity' => 0.0067684604, 'unit' => 'kg',
    'cost' => 0, 'sale' => 0, 'article' => ['id' => null],
    'color' => ['calibration' => ['priceSource' => ['articleId' => 2887, 'articleName' => 'Cyan tin']]]];
$production['allOperations']['sourceOperations'] = [['id' => -13, 'operation_id' => 13, 'equipment' => 20]];
$production['allOperations']['inputs'] = ['operation:-13' => ['equipment' => '21']];
$v = ['id' => 1, 'component_id' => 30, 'quantity_id' => 14, 'quantity' => 100,
    'snapshot' => ['calculation' => $production, 'nativeComponent' => ['field_4218' => 210, 'field_4219' => 297,
        'field_4220' => 1608, 'field_4229' => 1256, 'field_4225' => 'Component', 'field_4242' => 'C1'],
        'nativeCatalog' => [['id' => 13, 'name' => 'Lamination', 'speed' => 450]],
        'nativeColors' => [['field_4251' => 3728, 'field_4253' => 'Text Pantone']]]];
$nativeProduction = vd_production_component($v, ['1608' => 1600]);
$noRubrics = $v;
$noRubrics['snapshot']['nativeRubrics'] = [];
$noRubrics['snapshot']['calculation']['rubrics'] = [];
$noRubricProduction = vd_production_component($noRubrics, ['1608' => 1600]);
check($noRubricProduction['rubrics'] === []);
check(strpos($noRubricProduction['plans'][0]['fields']['field_2209'], '<th>Rubrica</th>') === false);
check(strpos($noRubricProduction['plans'][0]['fields']['field_2209'], '<svg ') !== false);
$noRubrics['snapshot']['calculation']['rubrics'] = [['id'=>0,'quantity'=>100,'name'=>'Component']];
check(vd_production_component($noRubrics, ['1608' => 1600])['rubrics'] === []);
unset($noRubrics['snapshot']['nativeRubrics']);
check(vd_production_component($noRubrics, ['1608' => 1600])['rubrics'] === []);
check(vd_production_operation_sequence([2,43,3,7,44,13]) === [43,2,3,7,13]);
check(vd_production_operation_sequence([43,2,7]) === [43,2,7]);
check(vd_production_operation_sequence([2,7,44]) === [2,7]);
check(vd_production_operation_sequence([44,43,7]) === [43,7]);
check(vd_production_operation_sequence([2,43,3,7,9,10,44,13]) === [43,2,3,7,13,9,10]);
check(vd_production_operation_sequence([7,13,9,10]) === [7,13,9,10]);
check(vd_production_operation_sequence([7,9,10]) === [7,9,10]);
check(vd_production_operation_sequence([7,13,10]) === [7,13,10]);
$withAdministrative = $v;
$withAdministrative['snapshot']['calculation']['allOperations']['lines'][] = [
    'key' => 'operation:-44', 'operationId' => 44, 'operationRow' => -44,
    'name' => 'Custos administrativos', 'quantity' => 1, 'cost' => 10, 'sale' => 13];
$withAdministrative['snapshot']['calculation']['allOperations']['cost'] += 10;
$withAdministrative['snapshot']['calculation']['allOperations']['sale'] += 13;
$withoutAdministrative = vd_production_component($withAdministrative, ['1608' => 1600]);
check(!isset($withoutAdministrative['operations'][44]));
check(array_search(43, array_keys($withoutAdministrative['operations']), true) <
    array_search(2, array_keys($withoutAdministrative['operations']), true));
check(end($withAdministrative['snapshot']['calculation']['allOperations']['lines'])['cost'] === 10);
$graphic = vd_production_plan_graphic($nativeProduction['plans'][0]['snapshot']);
check(substr_count($graphic, 'data-kind="piece"') === 5);
check(substr_count($graphic, 'data-kind="rubric-code"') === 5);
check(strpos($graphic, 'data-kind="gripper"') !== false && strpos($graphic, 'data-kind="print-area"') !== false);
check(strpos($graphic, 'rotação: sim') !== false && strpos($graphic, '<svg ') !== false);
$turnDetail = $nativeProduction['plans'][0]['snapshot'];
$turnDetail['mode_id'] = 3749;
$turnDetail['plan']['poses'] = 4;
$turnDetail['plan']['suggestion']['columns'] = 2;
$turnDetail['plan']['suggestion']['rows'] = 2;
$turnDetail['plan']['suggestion']['reservedGrippers'] = 2;
$turnDetail['allocation'] = [2,2];
$turnGraphic = vd_production_plan_graphic($turnDetail);
check(substr_count($turnGraphic, 'data-kind="gripper"') === 2 && strpos($turnGraphic, 'data-kind="turn"') !== false);
check(substr_count($turnGraphic, 'data-kind="piece"') === 4);
$turnDetail['allocation'] = [3,1];
rejects(static function () use ($turnDetail) { vd_production_plan_graphic($turnDetail); });
check(vd_production_easter(2026)->format('Y-m-d') === '2026-04-05');
foreach (['2026-11-09' => '2026-11-12', '2026-11-06' => '2026-11-11',
    '2026-11-27' => '2026-12-03', '2026-12-04' => '2026-12-10',
    '2026-12-23' => '2026-12-29', '2026-12-30' => '2027-01-05',
    '2026-04-01' => '2026-04-07', '2026-06-02' => '2026-06-08',
    '2026-06-08' => '2026-06-12'] as $start => $expected) {
    check(vd_production_delivery_default(new DateTimeImmutable($start, new DateTimeZone('Europe/Lisbon'))) === $expected);
}
check(vd_production_delivery_default(new DateTimeImmutable('2026-06-01T23:30:00-03:00')) === '2026-06-08');
rejects(static function () { vd_production_delivery_date(''); });
rejects(static function () { vd_production_delivery_date('2026-02-30'); });
rejects(static function () { vd_production_delivery_date('12/11/2026'); });
check(vd_production_delivery_date(vd_production_delivery_default())->format('H:i') === '00:00');
check($nativeProduction['fields']['field_2238'] === 1600 && $nativeProduction['fields']['field_2453'] === 889);
check($nativeProduction['fields']['field_2242'] === 100 && $nativeProduction['fields']['field_2579'] === 0);
check(array_column($nativeProduction['rubrics'], 'field_2019') === [60.0,40.0]);
check($nativeProduction['plans'][0]['fields']['field_2198'] === '');
check($nativeProduction['plans'][0]['fields']['field_2201'] === 5 && $nativeProduction['plans'][0]['fields']['field_2203'] === 5);
check(strpos($nativeProduction['plans'][0]['fields']['field_2209'], '&lt;A&gt;') !== false);
check($nativeProduction['plans'][0]['snapshot']['plan']['suggestion']['rotated'] === true);
check($nativeProduction['plans'][0]['snapshot']['produced'] === [60,40]);
check($nativeProduction['operations'][7]['fields']['field_2431'] === 23);
check($nativeProduction['operations'][13]['fields']['field_2427'] === '21');
check($nativeProduction['operations'][13]['fields']['field_2527'] === 450);
check($nativeProduction['operations'][2]['materials'][0]['quantity'] === 25.0);
check($nativeProduction['operations'][2]['materials'][0]['article_id'] === 2652);
check($nativeProduction['operations'][3]['materials'][0]['article_id'] === null);
check($nativeProduction['operations'][3]['materials'][0]['internal_id'] === 984);
check($nativeProduction['operations'][43]['materials'][0]['article_id'] === 228);
check($nativeProduction['operations'][13]['materials'][0]['quantity'] === 0.260755632);
check($nativeProduction['operations'][7]['materials'][0]['article_id'] === 2887);
check($nativeProduction['operations'][7]['materials'][0]['quantity'] === 0.0067684604);
$extras = $v;
$extras['snapshot']['calculation']['allOperations']['lines'][] = ['key' => 'operation:-32', 'operationId' => 32,
    'operationRow' => -32, 'name' => 'Spiral', 'quantity' => 100, 'hours' => 0.5, 'cost' => 0, 'sale' => 0];
$extras['snapshot']['calculation']['allOperations']['lines'][] = ['key' => 'spiral-material:-32', 'operationId' => 32,
    'parentOperationRow' => -32, 'name' => 'Spirals', 'quantity' => 110, 'unit' => 'Un.', 'cost' => 0, 'sale' => 0,
    'article' => ['id' => 200, 'name' => 'Spiral article'], 'spiral' => ['diameter' => 12, 'turns' => 30]];
$extras['snapshot']['calculation']['allOperations']['lines'][] = ['key' => 'material:22', 'name' => 'General material',
    'quantity' => 2.5, 'unit' => 'un.', 'cost' => 0, 'sale' => 0,
    'reference' => ['articleId' => 123, 'descriptionId' => 456]];
$extraProduction = vd_production_component($extras, ['1608' => 1600]);
check($extraProduction['operations'][32]['materials'][0]['article_id'] === 200);
check($extraProduction['operations'][32]['materials'][0]['quantity'] === 110.0);
check($extraProduction['operations'][32]['materials'][0]['snapshot']['spiral']['turns'] === 30);
check($extraProduction['operations'][7]['materials'][1]['article_id'] === 123 &&
    $extraProduction['operations'][7]['materials'][1]['internal_id'] === 456);
$mixed = $extras;
$mixed['snapshot']['calculation']['allOperations']['lines'][] = ['key' => 'printing-digital', 'operationId' => 4,
    'name' => 'Digital', 'quantity' => 100, 'hours' => 0.2, 'cost' => 0, 'sale' => 0];
$mixedProduction = vd_production_component($mixed, ['1608' => 1600]);
check(count($mixedProduction['operations'][4]['materials']) === 1 &&
    $mixedProduction['operations'][7]['materials'][0]['article_id'] === 2887);
rejects(static function () use ($v) { vd_production_component($v, []); });
$badRubrics = $v;
$badRubrics['snapshot']['calculation']['rubrics'][0]['quantity'] = 100;
rejects(static function () use ($badRubrics) { vd_production_component($badRubrics, ['1608' => 1600]); });
$badAllocation = $v;
unset($badAllocation['snapshot']['calculation']['parts'][0]['allocation']);
rejects(static function () use ($badAllocation) { vd_production_component($badAllocation, ['1608' => 1600]); });
$badParent = $v;
$badParent['snapshot']['calculation']['allOperations']['lines'][7]['parentOperationRow'] = -999;
rejects(static function () use ($badParent) { vd_production_component($badParent, ['1608' => 1600]); });
require_once __DIR__ . '/quote-product.php';
$assemblyCatalog = [20 => ['id' => 20, 'machines' => '32', 'unit' => 'jogos/h', 'setup' => '15'], 9 => ['id' => 9, 'machines' => '13', 'unit' => 'cortes/h', 'setup' => '10'],
    10 => ['id' => 10, 'machines' => '40', 'unit' => 'volumes/h', 'setup' => '5'], 26 => ['id' => 26, 'machines' => '', 'unit' => 'serviços', 'setup' => '0'],
    31 => ['id' => 31, 'machines' => '33', 'unit' => 'lotes/h', 'setup' => '10']];
$assemblyTariffs = [['operation_id' => 20, 'basis' => 'h', 'cost' => '28', 'version' => 'T'], ['operation_id' => 9, 'basis' => 'h', 'cost' => '28', 'version' => 'T'],
    ['operation_id' => 10, 'basis' => 'h', 'cost' => '18', 'version' => 'T'], ['operation_id' => 26, 'basis' => 'serviço', 'cost' => '100', 'version' => 'T']];
$assemblyEquipment = [['operation_id' => 20, 'machine_id' => '32', 'speed' => '1200', 'unit' => '', 'setup' => '15'],
    ['operation_id' => 9, 'machine_id' => '13', 'speed' => '250', 'unit' => '', 'setup' => '10'], ['operation_id' => 10, 'machine_id' => '40', 'speed' => '60', 'unit' => '', 'setup' => '5']];
$suggested = vd_product_assembly_suggestion(1000, ['thickness_mm' => 5, 'weight_g' => 250, 'thickness_source' => 'pedido'],
    [['operation_id' => 20], ['operation_id' => 9], ['operation_id' => 10], ['operation_id' => 26], ['operation_id' => 31]],
    $assemblyCatalog, $assemblyTariffs, $assemblyEquipment)['operations'];
check($suggested[0]['work_quantity'] === 1000.0 && $suggested[0]['minutes'] === 65.0 && $suggested[0]['hourly'] === 28.0 && $suggested[0]['equipment'] === 32);
check($suggested[1]['work_quantity'] === 150 && $suggested[1]['minutes'] === 46.0);
check($suggested[2]['work_quantity'] === 25 && $suggested[2]['unit'] === 'volumes');
check($suggested[3]['basis'] === 'serviço' && $suggested[3]['work_quantity'] === null && $suggested[3]['unit'] === 'cadernos' &&
    strpos($suggested[3]['notes'][0], 'Cadernos desconhecidos') === 0);
check($suggested[4]['hourly'] === null && strpos($suggested[4]['notes'][0], 'Sem tarifa') === 0);
$materialsSuggestion = vd_product_assembly_suggestion(1000, ['thickness_mm' => 8, 'binding_height_mm' => 297, 'signatures' => 10,
    'glue' => ['id' => 2828, 'price' => 1.7], 'spirals' => [['id' => 1, 'name' => 'Espiral Duplo Nº 6 A4 34v Preto', 'price' => 0.17],
    ['id' => 2, 'name' => 'Espiral Duplo Nº 5 A4 34v Preto', 'price' => 0.15], ['id' => 3, 'name' => 'Espiral Duplo Nº 6 24v Prata', 'price' => 0.12]]],
    [['operation_id' => 25], ['operation_id' => 32], ['operation_id' => 26]],
    $assemblyCatalog + [25 => ['id' => 25, 'machines' => '22', 'unit' => 'livros/h', 'setup' => '20'], 32 => ['id' => 32, 'machines' => '24', 'unit' => 'cadernos/h', 'setup' => '10']],
    array_merge($assemblyTariffs, [['operation_id' => 25, 'basis' => 'h', 'cost' => '35', 'version' => 'T'], ['operation_id' => 32, 'basis' => 'h', 'cost' => '25', 'version' => 'T']]),
    $assemblyEquipment);
check($materialsSuggestion['operations'][2]['work_quantity'] === 10000.0 && $materialsSuggestion['operations'][2]['unit'] === 'cadernos');
check($materialsSuggestion['materials'][0]['article_id'] === 2828 && abs($materialsSuggestion['materials'][0]['quantity'] - 1.4256) < 1e-9);
check($materialsSuggestion['materials'][1]['article_id'] === 1 && $materialsSuggestion['materials'][1]['quantity'] === 1000.0);
echo $checks . " workflow domain checks passed.\n";

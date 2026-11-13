<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/product-quote-preparation.php';
require_once __DIR__.'/product-request-conversion.php';
$checks=0;
function stickerCuttingCheck($ok) {
    global $checks;
    if(!$ok)throw new RuntimeException('Sticker cutting assertion '.($checks+1).' failed.');
    $checks++;
}
function stickerCuttingReject(callable $run) {
    try{$run();}catch(DomainException $error){stickerCuttingCheck(true);return;}
    throw new RuntimeException('Expected sticker cutting validation rejection.');
}
$payload=['type'=>'standard','product_type'=>3761,'quantities'=>[940],
    'request_details'=>['workflow_version'=>2,'cutting_operation'=>'plotter_a3'],
    'components'=>[['id'=>1,'name'=>'Autocolantes','kind'=>'printed','units_per_product'=>1,'inputs'=>[],
        'papers'=>[100],'colors'=>[3728,3729,3730,3731],
        'fields'=>[4218=>'81',4219=>'81',4229=>'1255',4220=>1565,6110=>1544,6119=>3,6120=>3,6169=>'true',6170=>'true',6267=>1600,6268=>1604],
        'request'=>['paper'=>'Vinil branco','printing'=>['mode'=>'cmyk','pantone'=>''],'finishing_operations'=>[]]]]];
$catalog=['defaults'=>[6119=>'3',6120=>'3'],'choices'=>[],
    'machines'=>[['id'=>14,'name'=>'Máquina de corte e vinco 700 × 500'],['id'=>19,'name'=>'Máquina de corte e vinco 700 × 500 B'],
        ['id'=>30,'name'=>'Máquina de corte e vinco 350 × 250'],['id'=>55,'name'=>'Mesa de corte'],['id'=>57,'name'=>'Plotter A3']],
    'articles'=>[['id'=>100,'internal_id'=>99,'width'=>700,'height'=>1000,'weight'=>80,'thickness'=>0.15,'is_paper'=>1],
        ['id'=>101,'name'=>'Vinyl 400 Branco Brilhante 70x100','internal_id'=>1031,'width'=>700,'height'=>1000,
            'weight'=>400,'thickness'=>0.44,'is_paper'=>0,'is_sticker'=>1]],
    'operations'=>[['id'=>8,'name'=>'Corte e Vinco - 700*500','machines'=>'14,19'],
        ['id'=>11,'name'=>'Corte e Vinco - 350*250','machines'=>'30'],['id'=>30,'name'=>'Corte Plotter A3','machines'=>'57'],
        ['id'=>41,'name'=>'Mesa de Corte','machines'=>'55']],
    'equipment'=>[],'finishing_rules'=>['crease'=>[18]]];
foreach([4220=>[1565,1248,1249,1608],4229=>[1255,1256,1257],6267=>[1600,1601,1602,1603],6268=>[1604,1605,1606,1607]] as $field=>$ids)
    foreach($ids as $id)$catalog['choices'][]=['fields_id'=>$field,'id'=>$id,'name'=>'Choice '.$id,'value'=>''];
foreach([
    'plotter_a3'=>[30,'Corte Plotter A3'],
    'cutting_table'=>[41,'Mesa de Corte']
] as $choice=>[$operationId,$name]) {
    $test=$payload;$test['request_details']['cutting_operation']=$choice;
    vd_config_request_validate($test);
    $prepared=vd_config_quote_preparation($test,$catalog);
    stickerCuttingCheck((string)$prepared['payload']['components'][0]['fields'][6291]===(string)$operationId);
    stickerCuttingCheck((int)$prepared['payload']['components'][0]['request']['finishing_operations'][0]['id']===$operationId);
}
$vinyl=$payload;$vinyl['components'][0]['papers']=[];
$vinyl['components'][0]['request']['paper']='Autocolante Vinil';
$vinylReview=vd_config_quote_preparation($vinyl,$catalog);
$paperField=array_values(array_filter($vinylReview['fields'],static function($field){return $field['key']==='1.paper';}))[0];
stickerCuttingCheck(in_array(101,array_map('intval',array_column($paperField['options'],'id')),true));
$vinyl['components'][0]['papers']=[101];
$vinylReview=vd_config_quote_preparation($vinyl,$catalog);
stickerCuttingCheck($vinylReview['payload']['components'][0]['papers']===[101]);
stickerCuttingCheck(!in_array('1.paper',array_column($vinylReview['missing'],'key'),true));
$vinylOutsideStickers=$vinyl;$vinylOutsideStickers['product_type']=3745;
unset($vinylOutsideStickers['request_details']['cutting_operation']);
stickerCuttingReject(static function()use($vinylOutsideStickers,$catalog){vd_config_quote_preparation($vinylOutsideStickers,$catalog);});
$crease=$payload;$crease['request_details']['cutting_operation']='cut_and_crease';
vd_config_request_validate($crease);
$preparation=vd_config_quote_preparation($crease,$catalog);
stickerCuttingCheck(array_column($preparation['missing'],'key')===['1.variant.0']);
$preparation=vd_config_quote_preparation($crease,$catalog,['1.variant.0'=>'11']);
stickerCuttingCheck((string)$preparation['payload']['components'][0]['fields'][6291]==='11');
stickerCuttingCheck((int)$preparation['payload']['components'][0]['request']['finishing_operations'][0]['id']===11);
$state=['id'=>476,'revision'=>1,'reference'=>'','notes'=>'','payload'=>$payload];
$state['payload']['request_details']['cutting_operation']='plotter_a3';
$summary=vd_config_request_summary($state);
stickerCuttingCheck(strpos($summary,'Operação de corte pretendida: Corte de plotter A3')!==false);
$bad=$payload;unset($bad['request_details']['cutting_operation']);
stickerCuttingReject(static function()use($bad){vd_config_request_validate($bad);});
$bad=$payload;$bad['request_details']['cutting_operation']='unknown';
stickerCuttingReject(static function()use($bad){vd_config_request_validate($bad);});
$bad=$payload;$bad['product_type']=3745;
$bad['request_details']['cutting_operation']='plotter_a3';
stickerCuttingReject(static function()use($bad){vd_config_request_validate($bad);});
echo "$checks sticker cutting and quote-preparation checks passed.\n";

<?php
require_once __DIR__.'/quote-product.php';
require_once __DIR__.'/product-finishing.php';
$checks=0;
function stageCheck($ok) {global $checks;if(!$ok)throw new RuntimeException('Failed stage check '.($checks+1));$checks++;}
function stageReject(callable $run) {try{$run();}catch(DomainException $error){stageCheck(true);return;}throw new RuntimeException('Expected stage rejection');}
$part=['id'=>1,'name'=>'Capa','role'=>'cover','kind'=>'printed','inputs'=>[],'units_per_product'=>1,
    'fields'=>['4218'=>'462','4219'=>'210','4233'=>'4'],
    'request'=>['cover'=>['left_mm'=>'80','right_mm'=>'80','spine_mm'=>'6']]];
$payload=['type'=>'magazine','product_type'=>3713,'quantities'=>[500],'components'=>[$part,
    ['id'=>2,'name'=>'Miolo','kind'=>'printed','inputs'=>[],'units_per_product'=>1,'fields'=>['4233'=>'32']]],
    'request_details'=>['workflow_version'=>1,'final_format'=>['width_mm'=>'148','height_mm'=>'210'],
        'finishing_operations'=>[['id'=>19,'name'=>'Alcear','variants'=>[19,20]]],
        'packaging_operations'=>[['id'=>29,'name'=>'Ensacar','variants'=>[29]]]]];
$prepared=vd_config_prepare_stages($payload);
stageCheck(count($prepared['components'])===5);
stageCheck($prepared['components'][0]['fields']['4233']==='4');
stageCheck($prepared['components'][2]['inputs']===[1,2]);
stageCheck($prepared['components'][3]['inputs']===[3]);
stageCheck($prepared['components'][4]['inputs']===[4]);
stageCheck($prepared===vd_config_prepare_stages($prepared));
stageCheck(vd_config_prepare_stages(array_replace_recursive($payload,['components'=>[0=>['fields'=>['4233'=>'8']]]]))['components'][0]['fields']['4233']==='4');
$bad=$payload;$bad['components'][0]['fields']['4218']='300';
stageReject(function()use($bad){vd_config_prepare_stages($bad);});
$formatOnly=$payload;$formatOnly['type']='standard';
$formatOnly['components']=[['id'=>1,'name'=>'Produto','kind'=>'printed','inputs'=>[],'units_per_product'=>1]];
foreach([['254','300'],['148','324'],['324','148'],['300','254']] as $format) {
    $bad=$formatOnly;$bad['request_details']['final_format']=['width_mm'=>$format[0],'height_mm'=>$format[1]];
    stageReject(function()use($bad){vd_config_prepare_stages($bad);});
}
foreach([['253.999','323.999'],['323.999','253.999'],['254','210']] as $format) {
    $valid=$formatOnly;$valid['request_details']['final_format']=['width_mm'=>$format[0],'height_mm'=>$format[1]];
    stageCheck(count(vd_config_prepare_stages($valid)['components'])===4);
}
foreach(['0','-1','INF','NaN','',null] as $value) {
    $bad=$payload;$bad['request_details']['final_format']['width_mm']=$value;
    stageReject(function()use($bad){vd_config_prepare_stages($bad);});
}
$bad=$prepared;$bad['components'][3]['inputs']=[1,2];
stageReject(function()use($bad){vd_product_stage_graph($bad['components']);});
$jacket=$part;$jacket['id']=6;$jacket['name']='Sobrecapa';$jacket['role']='jacket';
$payload['components'][]=$jacket;
stageReject(function()use($payload){vd_config_prepare_stages($payload);});
$prepared=vd_config_prepare_stages($payload,['stages.jacket_operation'=>47]);
stageCheck($prepared['components'][3]['inputs']===[1,2,6]);
stageCheck(in_array(47,array_column($prepared['components'][3]['request']['finishing_operations'],'id'),true));
$catalog=[
    19=>['name'=>'Alcear manual','base'=>'Alcear','stage'=>'Assemblagem','machines'=>''],
    20=>['name'=>'Alcear máquina','base'=>'Alcear','stage'=>'Assemblagem','machines'=>''],
    29=>['name'=>'Ensacar','base'=>'Ensacar','stage'=>'Embalamento individual','machines'=>'']
];
$metadata=['kind'=>'assembly','stage'=>'assembly','units_per_product'=>1,'requirements'=>['operations'=>[['id'=>19,'name'=>'Alcear','variants'=>[19,20]]]]];
$definition=['quantity'=>500,'markup'=>20,'operations'=>[['operation_id'=>20,'minutes'=>60,'hourly'=>30,'work_quantity'=>500,'unit'=>'un.','equipment'=>0]],'materials'=>[]];
$calculation=vd_product_nonprint_calculation($definition,$metadata,$catalog,[],[]);
stageCheck($calculation['allOperations']['cost']===30.0);
stageCheck($calculation['units']===500.0);
stageCheck(count($calculation['allOperations']['lines'])===1);
$bad=$definition;$bad['operations'][]=array_replace($bad['operations'][0],['operation_id'=>19]);
stageReject(function()use($bad,$metadata,$catalog){vd_product_nonprint_calculation($bad,$metadata,$catalog,[],[]);});
$bad=$definition;$bad['operations'][0]['operation_id']=29;
stageReject(function()use($bad,$metadata,$catalog){vd_product_nonprint_calculation($bad,$metadata,$catalog,[],[]);});
$bad=$definition;$bad['operations']=[];
stageReject(function()use($bad,$metadata,$catalog){vd_product_nonprint_calculation($bad,$metadata,$catalog,[],[]);});
stageCheck(vd_config_prepare_stages(['type'=>'magazine','components'=>[]])===['type'=>'magazine','components'=>[]]);
$catalog[20]['machines']='32';
stageReject(function()use($definition,$metadata,$catalog){vd_product_nonprint_calculation($definition,$metadata,$catalog,[],[32=>['id'=>32]]);});
$definition['operations'][0]['equipment']=32;
stageCheck(vd_product_nonprint_calculation($definition,$metadata,$catalog,[],[32=>['id'=>32]])['allOperations']['cost']===30.0);
echo "$checks stage and cover checks passed.\n";

<?php
require_once __DIR__.'/product-configurator.php';
$checks=0;
function requirementCheck($ok){global $checks;if(!$ok)throw new RuntimeException('Requirement check failed '.($checks+1));$checks++;}
function requirementReject(callable $run){try{$run();}catch(DomainException $error){requirementCheck(true);return;}throw new RuntimeException('Expected requirement rejection');}
$schema=['product.brochure_category'=>9000,'product.brochure_simple'=>9001,'product.brochure_stapled'=>9002,
    'product.brochure_spiral'=>9003,'product.brochure_sewn'=>9004,'product.menu_simple'=>9010,'product.menu_stapled'=>9011,
    'config.corrugated_type'=>3855,'stages.jacket_operation'=>47];
$types=[['id'=>9000,'parent_id'=>0],['id'=>9001,'parent_id'=>9000],['id'=>9002,'parent_id'=>9000],
    ['id'=>9003,'parent_id'=>9000],['id'=>9004,'parent_id'=>9000],['id'=>3800,'parent_id'=>0],
    ['id'=>3851,'parent_id'=>3800],['id'=>9010,'parent_id'=>3851],['id'=>9011,'parent_id'=>3851],
    ['id'=>3743,'parent_id'=>0],['id'=>3751,'parent_id'=>3743],['id'=>3855,'parent_id'=>3743]];
$payload=['type'=>'standard','product_type'=>9001,'quantities'=>[500],'components'=>[
    ['id'=>1,'name'=>'Produto','kind'=>'printed','inputs'=>[],'units_per_product'=>1,'fields'=>[]]],
    'request_details'=>['workflow_version'=>2,'total_pages'=>4,'finishing_operations'=>[['id'=>20,'name'=>'Alcear']]]];
$prepared=vd_config_prepare_requirements($payload,$types,$schema);
requirementCheck($prepared['components'][0]['fields']['4233']==='4');
requirementCheck($prepared['request_details']['finishing_operations']===[]);
foreach([null,0,-1,'4',1.5,100001] as $pages) {
    $bad=$payload;$bad['request_details']['total_pages']=$pages;
    requirementReject(function()use($bad,$types,$schema){vd_config_prepare_requirements($bad,$types,$schema);});
}
$bad=$payload;$bad['request_details']['total_pages']=8;
requirementReject(function()use($bad,$types,$schema){vd_config_prepare_requirements($bad,$types,$schema);});
$bad['product_type']=9010;
requirementReject(function()use($bad,$types,$schema){vd_config_prepare_requirements($bad,$types,$schema);});
foreach([9002=>23,9003=>32,9004=>26,9011=>23] as $type=>$operation) {
    $valid=$payload;$valid['product_type']=$type;$valid['request_details']['total_pages']=8;
    $valid=vd_config_prepare_stages(vd_config_prepare_requirements($valid,$types,$schema),$schema);
    $catalog=[];
    foreach([19,20,$operation] as $id)$catalog[]=['id'=>$id,'name'=>'Operation '.$id,'base'=>in_array($id,[19,20],true)?'Alcear':'Binding',
        'stage'=>'assembly','product_types'=>[$type]];
    $derived=vd_config_derive_assembly($valid,$types,$schema,$catalog);
    $assembly=array_values(array_filter($derived['components'],static function($part){return $part['stage']==='assembly';}))[0];
    requirementCheck(array_column($assembly['request']['finishing_operations'],'id')===[19,$operation]);
    requirementCheck($assembly['request']['finishing_operations'][0]['variants']===[19,20]);
    requirementReject(function()use($valid,$types,$schema){vd_config_derive_assembly($valid,$types,$schema,[]);});
}
$payload['type']='magazine';$payload['product_type']=9002;$payload['request_details']['total_pages']=36;
$payload['components']=[
    ['id'=>1,'name'=>'Capa','role'=>'cover','kind'=>'printed','fields'=>[]],
    ['id'=>2,'name'=>'Miolo','kind'=>'printed','fields'=>[]],
    ['id'=>3,'name'=>'Sobrecapa','role'=>'jacket','kind'=>'printed','fields'=>['4233'=>'4']]];
$prepared=vd_config_prepare_requirements($payload,$types,$schema);
requirementCheck($prepared['components'][1]['fields']['4233']==='32');
requirementCheck($prepared['components'][2]['fields']['4233']==='4');
$magazineTypes=array_merge($types,[['id'=>3712,'parent_id'=>0],['id'=>3715,'parent_id'=>3712]]);
$perfectBound=$payload;$perfectBound['product_type']=3715;$perfectBound['request_details']['total_pages']=48;
$perfectBound['components']=array_slice($payload['components'],0,2);
requirementCheck(vd_config_prepare_requirements($perfectBound,$magazineTypes,$schema)['components'][1]['fields']['4233']==='44');
foreach(vd_config_box_models() as $model=>$label) {
    $type=['folding_flaps'=>'folding_box','automatic_bottom'=>'folding_box','lid_base'=>'lid_base_box','mailer'=>'folding_box','rigid'=>'rigid_box','insert'=>'accessory_box','custom'=>'standard'][$model];
    $box=['type'=>$type,'product_type'=>3751,'request_details'=>['workflow_version'=>2,'box'=>[
        'length_mm'=>'300','width_mm'=>'200','height_mm'=>'100','measurement'=>'internal','model'=>$model,'model_description'=>$model==='custom'?'Caixa própria':'']]];
    requirementCheck(vd_config_prepare_packaging($box,$schema)===$box);
}
$bad=$box;$bad['request_details']['box']['model_description']='';
requirementReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
$bad['request_details']['box']['model']='unknown';
requirementReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
foreach([
    ['test.svg','<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10"/></svg>'],
    ['test.dxf',"0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"],
    ['test.ai',"%PDF-1.4\nVD drawing fixture\n%%EOF"],
    ['test.ai',"%!PS-Adobe-3.0\n%%Creator: Adobe Illustrator\n%%EOF"]
] as [$name,$content])requirementCheck(vd_config_document($name,$content)['content']===$content);
foreach([
    ['test.svg','<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///test">]><svg>&x;</svg>'],
    ['test.svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    ['test.svg','<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
    ['test.svg','<svg xmlns="http://www.w3.org/2000/svg"><image href="https://invalid.test/test"/></svg>'],
    ['test.dxf','random text'],['test.ai','random text'],['test.svg','<html/>']
] as [$name,$content])requirementReject(function()use($name,$content){vd_config_document($name,$content);});
echo "$checks product requirements, native models and drawing checks passed.\n";

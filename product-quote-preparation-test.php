<?php
require_once __DIR__.'/product-quote-preparation.php';
$checks=0;
function preparationCheck($value) {
    global $checks;$checks++;
    if(!$value)throw new RuntimeException('Preparation assertion '.$checks.' failed.');
}
function preparationReject(callable $callback) {
    try{$callback();}catch(DomainException $error){preparationCheck(true);return;}
    throw new RuntimeException('Expected preparation rejection.');
}
$catalog=['defaults'=>[6119=>'3',6120=>'3',6121=>'true',6169=>'false',6170=>'false'],
    'choices'=>[],'machines'=>[['id'=>8,'name'=>'Guilhotina'],['id'=>11,'name'=>'Posto A'],['id'=>16,'name'=>'Posto B']],
    'articles'=>[['id'=>100,'name'=>'Cartolina 450 g','internal_id'=>1,'width'=>700,'height'=>1000,'weight'=>450,'thickness'=>'','is_paper'=>1],
        ['id'=>101,'name'=>'Papel 100 g','internal_id'=>2,'width'=>700,'height'=>1000,'weight'=>100,'thickness'=>0.1,'is_paper'=>1]],
    'operations'=>[['id'=>1,'name'=>'Artes finais','machines'=>'11,16'],['id'=>2,'name'=>'Corte inicial','machines'=>'8'],
        ['id'=>8,'name'=>'Corte e vinco','machines'=>'8'],['id'=>9,'name'=>'Corte final','machines'=>'8'],['id'=>10,'name'=>'Embalamento','machines'=>''],
        ['id'=>42,'name'=>'Aplicação de BiAdesivo','machines'=>''],['id'=>19,'name'=>'Alcear manual','machines'=>''],
        ['id'=>20,'name'=>'Alcear máquina','machines'=>'8']]];
foreach([4220=>[1565,1248,1249],4229=>[1255,1256],6267=>[1600],6268=>[1604]] as $field=>$ids)
    foreach($ids as $id)$catalog['choices'][]=['fields_id'=>$field,'id'=>$id,'name'=>'Opção '.$id];
$base=['type'=>'standard','quantities'=>[140,280],'components'=>[['id'=>1,'name'=>'Cartaz','kind'=>'printed','stage'=>'component',
    'fields'=>[4218=>'488',4219=>'499',4229=>''],'papers'=>[],'colors'=>[3728,3729,3730,3731],
    'request'=>['paper'=>'Cartolina 450 g','die'=>['status'=>'none','number'=>''],'finishing_operations'=>[
        ['id'=>42,'name'=>'Aplicação de BiAdesivo','tape'=>['quantity'=>4,'x_mm'=>'20','y_mm'=>'20']],
        ['id'=>9,'name'=>'Corte final','variants'=>[9]],['id'=>10,'name'=>'Embalamento','variants'=>[10]]]]]]];
$original=$base;
$review=vd_config_quote_preparation($base,$catalog);
preparationCheck(array_column($review['missing'],'key')===['1.field.4229','1.paper','1.equipment.1']);
preparationCheck($base===$original);
preparationCheck($review['payload']['components'][0]['fields'][6119]==='3');
preparationCheck($review['payload']['components'][0]['fields'][6120]==='3');
preparationCheck($review['payload']['components'][0]['fields'][4220]===1565);
$answers=['1.field.4229'=>'1256','1.paper'=>'100','1.equipment.1'=>'11'];
$review=vd_config_quote_preparation($base,$catalog,$answers);
preparationCheck(array_column($review['missing'],'key')===['1.thickness.100']);
$answers['1.thickness.100']='0.6';
$review=vd_config_quote_preparation($base,$catalog,$answers);
preparationCheck($review['missing']===[]);
$part=$review['payload']['components'][0];
preparationCheck($part['papers']===[100]);
preparationCheck($part['request']['quote_preparation']['paper_thickness'][100]===0.6);
$tape=array_values(array_filter($part['technical_operations'],static function($op){return $op['operation_id']===42;}))[0];
preparationCheck($tape['adhesive_per_copy']===4&&$tape['adhesive_width']==='20'&&$tape['adhesive_height']==='20');
preparationCheck($base===$original);
foreach([['1.field.4229'=>999],['1.paper'=>999],['1.equipment.1'=>8],['1.thickness.100'=>-1],['1.thickness.100'=>81],
    ['1.thickness.100'=>'NaN'],['1.field.4218'=>'100'],['1.field.4229'=>[]]] as $invalid)
    preparationReject(static function()use($base,$catalog,$answers,$invalid){vd_config_quote_preparation($base,$catalog,array_replace($answers,$invalid));});
$known=$base;$known['components'][0]['papers']=[101];$known['components'][0]['fields'][4229]=1255;
$known['components'][0]['fields'][6169]='true';
$review=vd_config_quote_preparation($known,$catalog);
preparationCheck($review['missing']===[]);
preparationCheck($review['fields']===[]);
$custom=$catalog;$custom['defaults'][6119]='5';
preparationCheck(vd_config_quote_preparation($known,$custom)['payload']['components'][0]['fields'][6119]==='5');
$variant=$known;$variant['components'][0]['request']['finishing_operations']=[['id'=>19,'name'=>'Alcear','variants'=>[19,20]]];
$review=vd_config_quote_preparation($variant,$catalog);
preparationCheck(array_column($review['missing'],'key')===['1.variant.0']);
$review=vd_config_quote_preparation($variant,$catalog,['1.variant.0'=>'20']);
preparationCheck($review['missing']===[]);
preparationCheck($review['payload']['components'][0]['request']['finishing_operations'][0]['id']===20);
preparationCheck(array_column($review['payload']['components'][0]['technical_operations'],'operation_id')===[20,2]);
$die=$known;$die['components'][0]['request']['die']=['status'=>'used','number'=>'123'];
$review=vd_config_quote_preparation($die,$catalog);
preparationCheck($review['payload']['components'][0]['fields'][4220]===1248);
preparationCheck($review['payload']['components'][0]['fields'][4221]==='123');
echo "$checks preparation validation, defaults, conditional inputs and source preservation checks passed.\n";

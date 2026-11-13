<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/product-request-conversion.php';
require_once __DIR__.'/product-quote-preparation.php';
$checks=0;
function foldingCheck($ok) {
    global $checks;
    if(!$ok)throw new RuntimeException('Folding assertion '.($checks+1).' failed.');
    $checks++;
}
function foldingReject(callable $run) {
    try{$run();}catch(DomainException $error){foldingCheck(true);return;}
    throw new RuntimeException('Expected folding validation rejection.');
}
$types=[['id'=>3800,'parent_id'=>0],['id'=>3801,'parent_id'=>3800],['id'=>3824,'parent_id'=>3800]];
$base=['type'=>'standard','product_type'=>3801,'quantities'=>[1000],
    'rubrics'=>[['quantity'=>400,'description'=>'Versão A','code'=>'A'],['quantity'=>600,'description'=>'Versão B','code'=>'B']],
    'request_details'=>['workflow_version'=>2,'folding'=>['count'=>2,'delivery'=>'closed'],'dispatch'=>['pickup']],
    'components'=>[['id'=>1,'name'=>'Desdobrável','kind'=>'printed','inputs'=>[],'units_per_product'=>1,
        'papers'=>[100],'colors'=>[3804],'fields'=>[4218=>'297',4219=>'210',6169=>'true',6170=>'true'],
        'request'=>['die'=>['status'=>'none','number'=>''],'finishing_operations'=>[]]]]];
$catalog=['defaults'=>[6119=>'3',6120=>'3'],'choices'=>[],'machines'=>[['id'=>23,'name'=>'Vincadora']],
    'articles'=>[['id'=>100,'internal_id'=>1,'width'=>700,'height'=>1000,'weight'=>150,'thickness'=>0.15,'is_paper'=>1]],
    'operations'=>[['id'=>18,'name'=>'Vincar','machines'=>'23'],['id'=>21,'name'=>'Dobrar','machines'=>''],
        ['id'=>9,'name'=>'Corte final','machines'=>''],['id'=>10,'name'=>'Embalamento','machines'=>''],
        ['id'=>8,'name'=>'Corte e vinco','machines'=>'']]];
foreach([4220=>[1565,1608,1248,1249],6267=>[1600],6268=>[1604]] as $field=>$ids)
    foreach($ids as $id)$catalog['choices'][]=['fields_id'=>$field,'id'=>$id,'name'=>'Choice '.$id];
foreach(['open','closed'] as $delivery) {
    $payload=$base;$payload['request_details']['folding']['delivery']=$delivery;
    $prepared=vd_config_prepare_requirements($payload,$types,[]);
    foldingCheck($prepared['components'][0]['request']['folding']===$payload['request_details']['folding']);
    foldingCheck($prepared['components'][0]['fields']['4220']===1565);
    foldingCheck($prepared['components'][0]['fields']['6110']===($delivery==='closed'?1545:1544));
    foldingCheck($prepared['components'][0]['fields']['6291']===($delivery==='closed'?'21':''));
    foldingCheck(vd_config_prepare_requirements($prepared,$types,[])===$prepared);
    vd_config_rubrics_validate($prepared);foldingCheck(true);
    $stages=vd_config_prepare_stages($prepared);
    $stages=vd_config_derive_assembly($stages,$types,[],[]);
    $simple=vd_config_quote_payload($stages,'Desdobrável de teste');
    foldingCheck(count($simple['components'])===1);
    $review=vd_config_quote_preparation($simple,$catalog);
    foldingCheck($review['missing']===[]);
    $part=$review['payload']['components'][0];
    foldingCheck(array_column($part['technical_operations'],'operation_id')===($delivery==='closed'?[21]:[]));
    foldingCheck((int)$part['fields']['4220']===1565);
    $assigned=vd_config_assign_rubrics($review['payload']);
    foldingCheck(array_column($assigned['components'][0]['rubrics'],'quantity')===[400,600]);
    $spec=vd_config_native_specs()['config'];$packed=vd_config_native_pack($prepared,$spec);
    $schema=[];$row=[];$id=1;
    foreach($packed as $name=>$value) {$schema['config.'.$name]=$id;$row['field_'.$id++]=$value;}
    foldingCheck(vd_config_native_unpack($row,'config',$schema,$spec)===$prepared);
    $summary=vd_config_request_summary(['id'=>1,'revision'=>1,'reference'=>'','notes'=>'','payload'=>$prepared]);
    foldingCheck(strpos($summary,'Número de dobras: 2')!==false);
    foldingCheck(strpos($summary,'Entrega do desdobrável: '.($delivery==='open'?'aberto':'fechado'))!==false);
}
foreach([null,[],['count'=>0,'delivery'=>'closed'],['count'=>-1,'delivery'=>'open'],['count'=>1.5,'delivery'=>'open'],
    ['count'=>'2','delivery'=>'closed'],['count'=>2,'delivery'=>''],['count'=>2,'delivery'=>'unknown']] as $folding) {
    $bad=$base;$bad['request_details']['folding']=$folding;
    foldingReject(static function()use($bad,$types){vd_config_prepare_requirements($bad,$types,[]);});
}
$opened=vd_config_prepare_requirements($base,$types,[]);
$opened['request_details']['folding']['delivery']='open';
$opened=vd_config_prepare_requirements($opened,$types,[]);
foldingCheck($opened['components'][0]['fields']['6291']==='');
$other=$opened;$other['product_type']=3824;unset($other['request_details']['folding']);
$other=vd_config_prepare_requirements($other,$types,[]);
foldingCheck(!isset($other['components'][0]['request']['folding']));
foldingCheck(!isset($other['components'][0]['fields']['6110']));
foldingCheck($other['components'][0]['fields']['4220']===1565);
foldingCheck($other['components'][0]['fields']['6291']==='');
foreach(['new'=>1249,'used'=>1248] as $status=>$choice) {
    $die=$base;$die['components'][0]['request']['die']=['status'=>$status,'number'=>$status==='used'?'123':''];
    $die=vd_config_prepare_requirements($die,$types,[]);
    foldingCheck($die['components'][0]['fields']['4220']===$choice);
    foldingCheck($die['components'][0]['fields']['6291']==='21');
}
$bad=$base;$bad['quantities']=[999];
foldingReject(static function()use($bad){vd_config_rubrics_validate($bad);});
$bad=$base;$bad['product_type']=3824;
foldingReject(static function()use($bad){vd_config_rubrics_validate($bad);});
$legacy=$base;$legacy['request_details']['workflow_version']=1;unset($legacy['request_details']['folding']);
foldingCheck(vd_config_prepare_requirements($legacy,$types,[])===$legacy);
foreach([21,33,35] as $operation) {
    $selected=$base;
    $selected['components'][0]['request']['finishing_operations']=[['id'=>$operation,'name'=>'Dobra escolhida']];
    $selected=vd_config_prepare_requirements($selected,$types,[]);
    foldingCheck($selected['components'][0]['fields']['6291']===(string)$operation);
    foldingCheck($selected['components'][0]['fields']['6110']===($operation===21?1545:1544));
    $selected['request_details']['folding']['delivery']='open';
    foldingReject(static function()use($selected,$types){vd_config_prepare_requirements($selected,$types,[]);});
}
$multiple=$base;$multiple['components'][0]['request']['finishing_operations']=[['id'=>21],['id'=>33]];
foldingReject(static function()use($multiple,$types){vd_config_prepare_requirements($multiple,$types,[]);});
foreach(['open','closed'] as $delivery) {
    $machine=$base;$machine['request_details']['folding']['delivery']=$delivery;
    $machine['components'][0]['request']['finishing_operations']=[['id'=>900,'name'=>'Vincar - Máquina']];
    $machine=vd_config_prepare_requirements($machine,$types,['operation.machine_crease'=>900]);
    foldingCheck($machine['components'][0]['fields']['4220']===1565);
    foldingCheck($machine['components'][0]['fields']['6291']===($delivery==='closed'?'900,21':'900'));
    foldingCheck(vd_config_prepare_requirements($machine,$types,['operation.machine_crease'=>900])===$machine);
    $machine['components'][0]['request']['finishing_operations'][]=['id'=>18,'name'=>'Vincar - Manual'];
    foldingReject(static function()use($machine,$types){vd_config_prepare_requirements($machine,$types,['operation.machine_crease'=>900]);});
}
foreach([149,150,150.01,170] as $weight)foreach(['open','closed'] as $delivery)foreach([false,true] as $choosePaper) {
    $weighted=$base;$weighted['request_details']['folding']['delivery']=$delivery;
    if($choosePaper)$weighted['components'][0]['papers']=[];
    $weighted=vd_config_prepare_requirements($weighted,$types,[]);
    $weightedCatalog=$catalog;$weightedCatalog['articles'][0]['weight']=$weight;
    $review=vd_config_quote_preparation($weighted,$weightedCatalog,$choosePaper?['1.paper'=>100]:[]);
    foldingCheck($review['missing']===[]);
    $part=$review['payload']['components'][0];
    foldingCheck((int)$part['fields']['4220']===($weight>150?1608:1565));
    $expected=$weight>150?[18]:[];
    if($delivery==='closed')$expected[]=21;
    foldingCheck(array_column($part['technical_operations'],'operation_id')===$expected);
    foldingCheck(array_values(array_filter(array_map('intval',explode(',',$part['fields']['6291']))))===$expected);
}
$manual=$base;$manual['components'][0]['request']['finishing_operations']=[['id'=>18,'name'=>'Vincar - Manual']];
$manual=vd_config_prepare_requirements($manual,$types,[]);
$manual=vd_config_quote_preparation($manual,$catalog);
foldingCheck(array_column($manual['payload']['components'][0]['technical_operations'],'operation_id')===[18,21]);
echo "$checks folding, delivery, rubric, persistence and preparation checks passed.\n";

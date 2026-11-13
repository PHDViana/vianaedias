<?php
if(PHP_SAPI!=='cli')exit(1);
define('VD_PRODUCT_TEST_BOOTSTRAP',true);
require __DIR__.'/quote-workflow-integration-test.php';
require __DIR__.'/product-request-conversion.php';
require_once __DIR__.'/product-quote-preparation.php';
$user=['id'=>1,'group_id'=>0,'email'=>'sending-user@example.invalid','name'=>'Fixture'];
$service=new VDProductConfigurator($db,$user);$workflow=new VDQuoteWorkflow($db,$user);
$schema=vd_config_schema($db);$store=new VDConfigNativeStore($db,$schema);$config=0;$quote=0;
$keep=false;
function vdStagesPreparedConversion($id) {
    global $db,$service,$user;
    $paper=$db->query("SELECT id FROM app_entity_99 WHERE FIND_IN_SET('190',field_1019)>0 AND field_3632+0>0 AND field_1021+0>0 AND field_1022+0>0 AND field_1023+0>0 AND field_1024+0>0 AND field_1024+0<=80 ORDER BY id LIMIT 1")->fetch_row();
    if(!$paper)throw new RuntimeException('No complete paper available for staged fixtures.');
    $revision=$service->state($id)['revision'];$answers=[];
    for($pass=0;$pass<5;$pass++) {
        $model=vd_config_quote_preparation_state($service,$id,$answers,$revision);
        if(!$model['missing'])return vd_config_request_to_draft($db,$user,$id,false,$answers,$revision);
        foreach($model['fields'] as $field)if($field['missing']) {
            if($field['kind']!=='select'||!$field['options'])throw new RuntimeException('Unexpected staged fixture input: '.$field['key']);
            $answers[$field['key']]=substr($field['key'],-6)==='.paper'?$paper[0]:$field['options'][0]['id'];
            if(substr($field['key'],-11)==='.field.4220')$answers[$field['key']]=1565;
        }
    }
    throw new RuntimeException('Staged fixture preparation did not converge.');
}
if(($argv[1]??'')==='--cleanup-request') {
    $id=(int)($argv[2]??0);$state=$service->state($id);
    if($state['name']!=='ISOLATED STAGES FIXTURE'||$store->quotes($id))throw new RuntimeException('Unsafe standalone stage fixture cleanup.');
    foreach($store->attachments($id) as $document)$store->removeDocument($id,$document['id']);
    $store->finishFiles(true);
    foreach(['part','quantity','finish','submission','piece'] as $prefix)$store->clear($prefix,$id);
    $db->query('DELETE FROM app_entity_'.$schema['config.entity'].' WHERE id='.$id);
    echo "Standalone stage fixture removed; customer and other requests untouched.\n";exit;
}
if(($argv[1]??'')==='--verify-browser') {
    $quote=(int)($argv[2]??0);$source=$store->source($quote);
    if(!$source)throw new RuntimeException('Stage fixture source missing.');
    $state=$service->state($source['config_id']);$frozen=json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR);
    vdCheck($state['name']==='ISOLATED STAGES FIXTURE');
    vdCheck($state['revision']>$frozen['revision']);
    vdCheck(hash_equals($source['hash'],hash('sha256',$source['payload'])));
    vdCheck(count($state['payload']['components'])===6);
    vdCheck($state['payload']['components'][0]['fields']['4233']==='4');
    vdCheck($state['payload']['components'][0]['fields']['4218']==='462');
    vdCheck($state['payload']['request_details']['packaging_operations'][0]['id']===29);
    $operations=array_filter($state['payload']['request_details']['finishing_operations'],static function($operation){return in_array(19,$operation['variants']??[],true);});
    $variants=$operations?array_values($operations)[0]['variants']:[];sort($variants);
    vdCheck($variants===[19,20]);
    vdCheck($frozen['payload']['components'][0]['fields']['4233']==='4');
    vdCheck((int)$db->query("SELECT COUNT(*) FROM app_vd_quote_versions WHERE quote_id=$quote")->fetch_row()[0]===0);
    echo "$checks persisted staged browser checks passed; original quote source preserved.\n";exit;
}
$cleanup=($argv[1]??'')==='--cleanup';
$customer=$cleanup?0:vdFixture(26,['field_221'=>'ISOLATED STAGES FIXTURE','field_224'=>'176','field_228'=>'workflow-fixture@example.invalid','field_5121'=>1370,'field_5125'=>1374]);
try {
    if($cleanup) {
        $id=(int)($argv[2]??0);
        $row=$db->query('SELECT field_3927 name,field_3923 customer,field_'.$schema['quote.config']." config FROM app_entity_146 WHERE id=$id")->fetch_assoc();
        if(!$row||$row['name']!=='ISOLATED STAGES FIXTURE')throw new RuntimeException('Unsafe stage fixture cleanup.');
        $quote=$id;$config=(int)$row['config'];$customer=(int)$row['customer'];
        if($service->state($config)['name']!=='ISOLATED STAGES FIXTURE')throw new RuntimeException('Unsafe stage configuration cleanup.');
    }elseif(($argv[1]??'')==='--boxes') {
        $revision=0;
        foreach(vd_config_box_models() as $model=>$label) {
            $type=['folding_flaps'=>'folding_box','automatic_bottom'=>'folding_box','lid_base'=>'lid_base_box',
                'mailer'=>'folding_box','rigid'=>'rigid_box','insert'=>'accessory_box','custom'=>'folding_box'][$model];
            $components=[];
            foreach(vd_product_template($type) as $index=>[$name,$kind])if($kind!=='assembly')
                $components[]=['id'=>$index+1,'name'=>$name,'kind'=>$kind,'inputs'=>[],'units_per_product'=>1,
                    'fields'=>['4218'=>'300','4219'=>'200','4233'=>'1','4229'=>'1255'],'papers'=>[],
                    'colors'=>$kind==='printed'?[3728,3729,3730,3731]:[],'rubrics'=>[],
                    'request'=>$kind==='printed'?['printing'=>['mode'=>'cmyk','pantone'=>'']]:[]];
            $box=['model'=>$model,'model_description'=>$model==='custom'?'Caixa personalizada de teste':'',
                'length_mm'=>'300','width_mm'=>'200','height_mm'=>'100','measurement'=>'internal'];
            $payload=['type'=>$type,'product_type'=>3751,'quantities'=>[500],'components'=>$components,
                'request_details'=>['workflow_version'=>2,'box'=>$box,'dispatch'=>['pickup'],'finishing_operations'=>[],'packaging_operations'=>[]]];
            $state=$service->submit($config,$revision,['name'=>'ISOLATED STAGES FIXTURE','customer'=>$customer,'reference'=>'BOX','notes'=>''],
                $payload,'boxes-'.bin2hex(random_bytes(16)));
            $config=$state['id'];$revision=$state['revision'];
            $reopened=$service->state($config)['payload'];
            vdCheck($reopened['request_details']['box']===$box);
            vdCheck($reopened['type']===$type);
        }
        $quote=vdStagesPreparedConversion($config)['quote'];
        $product=$workflow->productState($quote);
        foreach($product['components'] as $part)vdCheck($part['product']['requirements']['product_details']['box']===$box);
        vdCheck(json_decode($store->source($quote)['payload'],true)['payload']===$state['payload']);
        echo "$checks native box model persistence and conversion checks passed.\n";
    }else {
    $cover=['id'=>1,'name'=>'Capa','role'=>'cover','kind'=>'printed','inputs'=>[],'units_per_product'=>1,
        'papers'=>[],'colors'=>[3728,3729,3730,3731],'rubrics'=>[],
        'fields'=>['4218'=>'462','4219'=>'210','4233'=>'4','4229'=>'1255','4220'=>1565,'6169'=>'true','6170'=>'true'],
        'request'=>['printing'=>['mode'=>'cmyk','pantone'=>''],'cover'=>['left_mm'=>'80','right_mm'=>'80','spine_mm'=>'6']]];
    $miolo=$cover;$miolo['id']=2;$miolo['name']='Miolo';unset($miolo['role'],$miolo['request']['cover']);
    $miolo['fields']['4218']='148';$miolo['fields']['4233']='32';
    $jacket=$cover;$jacket['id']=3;$jacket['name']='Sobrecapa';$jacket['role']='jacket';
    $jacketId=$schema['stages.jacket_operation'];
    $payload=['type'=>'magazine','product_type'=>3713,'quantities'=>[500],'components'=>[$cover,$miolo,$jacket],
        'request_details'=>['workflow_version'=>1,'final_format'=>['width_mm'=>'148','height_mm'=>'210','thickness_mm'=>'6'],
            'dispatch'=>['pickup'],'finishing_operations'=>[['id'=>19,'name'=>'Alcear','variants'=>[19,20]],['id'=>23,'name'=>'Agrafar Revista','variants'=>[23]]],
            'packaging_operations'=>[['id'=>29,'name'=>'Ensacar','variants'=>[29]]]]];
    if(in_array($argv[1]??'',['--requirements','--browser-requirements'],true)) {
        $payload['product_type']=$schema['product.brochure_stapled'];
        $payload['request_details']['workflow_version']=2;
        $payload['request_details']['total_pages']=36;
        $payload['request_details']['finishing_operations']=[];
    }
    $files=($payload['request_details']['workflow_version']===2)?[
        ['name'=>'fixture.svg','content'=>'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0L10 10"/></svg>'],
        ['name'=>'fixture.dxf','content'=>"0\nSECTION\n2\nENTITIES\n0\nENDSEC\n0\nEOF\n"],
        ['name'=>'fixture.ai','content'=>"%PDF-1.4\nVD drawing fixture\n%%EOF"]]:[];
    $state=$service->submit(0,0,['name'=>'ISOLATED STAGES FIXTURE','customer'=>$customer,'reference'=>'STAGED','notes'=>''], $payload,'stages-'.bin2hex(random_bytes(16)),$files);
    $config=$state['id'];
    vdCheck(count($state['payload']['components'])===6);
    vdCheck($state['payload']['components'][0]['fields']['4233']==='4');
    vdCheck($service->state($config)['payload']===$state['payload']);
    $state=$service->submit($config,$state['revision'],['name'=>'ISOLATED STAGES FIXTURE','customer'=>$customer,'reference'=>'STAGED','notes'=>''],
        $state['payload'],'stages-'.bin2hex(random_bytes(16)));
    vdCheck(count($state['payload']['components'])===6);
    $quote=vdStagesPreparedConversion($config)['quote'];
    vdCheck(vd_config_request_to_draft($db,$user,$config)['quote']===$quote);
    $source=$store->source($quote);vdCheck(json_decode($source['payload'],true)['payload']===$state['payload']);
    if($files) {
        vdCheck(count($store->attachments($config))===3);
        $copied=explode(',',$db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0]);
        vdCheck(count($copied)===3);
        foreach($copied as $index=>$name)vdCheck(file_get_contents($store->filePath($name))===$files[$index]['content']);
    }
    $product=$workflow->productState($quote);$definitions=[];$versions=[];
    if(in_array($argv[1]??'',['--browser','--browser-requirements'],true)) {
        $keep=true;echo json_encode(['quote'=>$quote,'config'=>$config],JSON_THROW_ON_ERROR),"\n";return;
    }
    foreach($product['components'] as $component)$definitions[]=['id'=>(int)$component['id'],'kind'=>$component['product']['kind'],
        'inputs'=>$component['product']['inputs'],'units_per_product'=>1];
    $workflow->configureProduct($quote,['type'=>'magazine','revision'=>1,'components'=>$definitions]);
    $product=$workflow->productState($quote);
    $catalog=array_column($product['catalog'],null,'id');
    $quantity=(int)$product['quantities'][0]['id'];
    foreach($product['components'] as $component) {
        $id=(int)$component['id'];$metadata=$component['product'];
        if($metadata['kind']==='printed') {
            $calc=['source'=>'dynamic','quantity'=>500,'quantityId'=>$quantity,'machineId'=>6,'modeId'=>3748,
                'component'=>['id'=>$id],
                'parts'=>[['plan'=>['width'=>720,'height'=>510,'poses'=>1,'columns'=>1,'rows'=>1,'per_sheet'=>2,'sheet_width'=>720,'sheet_height'=>1020],
                    'paperCalculation'=>['paper'=>['id'=>2652,'internal_id'=>1406,'name'=>'Fixture paper'],'sheets'=>250],
                    'result'=>['plates'=>4,'goodPlans'=>500,'wastePlans'=>0],'plate'=>['materialId'=>984],
                    'operation'=>['id'=>7],'machine'=>['id'=>6],'modeId'=>3748,'allocation'=>[1],'produced'=>[500],'runs'=>500]],
                'rubrics'=>[['id'=>0,'quantity'=>500,'name'=>'Fixture','code'=>'']],'results'=>['plates'=>4],
                'allOperations'=>['complete'=>true,'errors'=>[],'cost'=>1,'sale'=>1.2,'sourceOperations'=>[['operation_id'=>7,'equipment'=>6],['operation_id'=>44,'equipment'=>'']],
                    'lines'=>[['key'=>'paper','name'=>'Fixture paper','cost'=>1,'sale'=>1.2],
                        ['key'=>'printing','name'=>'Fixture print','operationId'=>7,'hours'=>0.5,'quantity'=>500,'cost'=>0,'sale'=>0],
                        ['key'=>'operation:44','operationId'=>44,'name'=>'Administração','hours'=>0.1,'quantity'=>500,'cost'=>0,'sale'=>0]]]];
            foreach([3,43] as $op)$calc['allOperations']['lines'][]=['key'=>'operation:'.$op,'operationId'=>$op,'name'=>'Fixture operation','hours'=>0.1,'quantity'=>500,'cost'=>0,'sale'=>0];
            foreach($db->query("SELECT id FROM app_entity_278 WHERE parent_item_id=$id")->fetch_all(MYSQLI_ASSOC) as $color)
                $calc['allOperations']['lines'][]=['key'=>'color:'.$color['id'],'name'=>'Fixture ink','quantity'=>0,'cost'=>0,'sale'=>0];
        }else {
            $definition=['quantity'=>500,'markup'=>20,'operations'=>[],'materials'=>[]];
            foreach($metadata['requirements']['operations'] as $requirement)$definition['operations'][]=[
                'operation_id'=>$requirement['id']===19?20:$requirement['id'],'minutes'=>10,'hourly'=>30,'work_quantity'=>500,
                'equipment'=>(int)explode(',',(string)$catalog[$requirement['id']===19?20:$requirement['id']]['machines'])[0],'unit'=>'un.'];
            if($metadata['stage']==='assembly') {
                $bad=$definition;$bad['operations'][]=array_replace($bad['operations'][0],['operation_id'=>19]);
                vdReject(function()use($workflow,$quote,$id,$bad){$workflow->nonprintCalculation($quote,$id,$bad);});
            }
            if($metadata['stage']==='packaging') {
                $definition['materials'][]=['article_id'=>1328,'operation_id'=>29,'quantity'=>500,'unit'=>'un.','unit_cost'=>0.01];
                $bad=$definition;$bad['operations'][0]['operation_id']=23;
                vdReject(function()use($workflow,$quote,$id,$bad){$workflow->nonprintCalculation($quote,$id,$bad);});
            }
            $calc=$workflow->nonprintCalculation($quote,$id,$definition);
            vdCheck($calc['units']===500.0);
            if($metadata['stage']==='assembly')vdCheck($calc['allOperations']['cost']===20.0);
            if($metadata['stage']==='packaging')vdCheck($calc['allOperations']['cost']===10.0);
        }
        $saved=$workflow->save($quote,$id,['request_id'=>'stages-'.bin2hex(random_bytes(16)),'quantity_id'=>$quantity,'expected_version'=>0,'calculation'=>$calc]);
        $versions[]=$version=(int)$saved['workflow_version'];$workflow->confirm($quote,$version);
    }
    $frozen=$db->query("SELECT id,hash,payload FROM app_vd_quote_versions WHERE quote_id=$quote ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $doc=$workflow->approve($quote,$versions)['document'];
    $workflow->send($quote,$doc,['request_id'=>'stages-'.bin2hex(random_bytes(16)),'email'=>'workflow-fixture@example.invalid','phone'=>'']);
    $workflow->accept($quote,$doc,500,'Fixture stages',null,'2027-11-12');
    $order=(int)$workflow->createProductionOrder($quote)['id'];
    $links=$db->query("SELECT * FROM app_vd_production_component_inputs WHERE order_id=$order")->fetch_all(MYSQLI_ASSOC);
    vdCheck(count($links)===5);
    $parts=$db->query('SELECT id,field_'.$schema['production_part.stage'].' stage,field_'.$schema['production_part.requirements']." requirements FROM app_entity_159 WHERE parent_item_id=$order")->fetch_all(MYSQLI_ASSOC);
    $stageIds=[];
    foreach($parts as $part) {
        $id=(int)$part['id'];$stageIds[$part['stage']]=$id;
        vdCheck(is_array(json_decode($part['requirements'],true)));
        if($files)vdCheck(json_decode($part['requirements'],true)['product_details']['total_pages']===36);
    }
    foreach(['assembly','packaging','dispatch'] as $stage)vdReject(function()use($db,$stageIds,$stage){vd_production_check_inputs($db,$stageIds[$stage]);});
    vdCheck((int)$db->query('SELECT field_2427 FROM app_entity_170 WHERE parent_item_id='.$stageIds['assembly'].' AND field_2426=20')->fetch_row()[0]===32);
    foreach(['assembly','packaging','dispatch'] as $stage) {
        $id=$stageIds[$stage];
        $op=(int)$db->query("SELECT id FROM app_entity_170 WHERE parent_item_id=$id ORDER BY id LIMIT 1")->fetch_row()[0];
        vdImmutable("UPDATE app_entity_170 SET field_2425=3717 WHERE id=$op");
    }
    foreach($links as $link) {
        $end=(int)$db->query('SELECT MAX(field_2531) FROM app_entity_170 WHERE parent_item_id='.(int)$link['input_component_id'])->fetch_row()[0];
        $start=(int)$db->query('SELECT MIN(field_2536) FROM app_entity_170 WHERE parent_item_id='.(int)$link['component_id'])->fetch_row()[0];
        vdCheck($end<=$start);
    }
    foreach(['assembly','packaging','dispatch'] as $stage) {
        $id=$stageIds[$stage];
        foreach($db->query("SELECT input_component_id FROM app_vd_production_component_inputs WHERE component_id=$id")->fetch_all(MYSQLI_ASSOC) as $input)
            $db->query('UPDATE app_entity_170 SET field_2425=39 WHERE parent_item_id='.(int)$input['input_component_id']);
        vd_production_check_inputs($db,$id);
        vdCheck(true);
    }
    vdCheck(strpos($workflow->productionPrintSummary($order),'Embalamento individual')!==false);
    if($files)vdCheck(strpos($workflow->productionPrintSummary($order),'Páginas totais do produto (incluindo capa):</strong> 36')!==false);
    vdCheck($workflow->createProductionOrder($quote)['already_created']===true);
    vdCheck($frozen===$db->query("SELECT id,hash,payload FROM app_vd_quote_versions WHERE quote_id=$quote ORDER BY id")->fetch_all(MYSQLI_ASSOC));
    echo "$checks staged request → quote → production checks passed.\n";
    }
}finally {
    if(!$keep) {
    $db->query('SET @vd_workflow_projection_mutation=1,@vd_workflow_production_mutation=1');
    if($quote) {
        foreach(array_filter(explode(',',$db->query("SELECT field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0])) as $name) {
            $path=$store->filePath($name);
            if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot remove staged fixture attachment.');
        }
        vdRemoveFixtureOrders($quote,'ISOLATED STAGES FIXTURE');
        $db->query("DELETE p FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote");
        foreach(['app_vd_quote_products','app_vd_quote_product_components','app_vd_quote_deliveries','app_vd_quote_sms','app_vd_quote_documents','app_vd_quote_versions','app_vd_quote_audit','app_vd_quote_control','app_vd_quote_legacy'] as $table)$db->query("DELETE FROM $table WHERE quote_id=$quote");
        foreach([276,279,388,280,278,277] as $entity)$db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
        foreach([261,262] as $entity)$db->query("DELETE FROM app_entity_$entity WHERE parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
    }
    if($config) {
        foreach($store->attachments($config) as $document)$store->removeDocument($config,$document['id']);
        $store->finishFiles(true);
        foreach(['part','quantity','finish','submission','piece'] as $prefix)$store->clear($prefix,$config);
        $db->query('DELETE FROM app_entity_'.$schema['config.entity'].' WHERE id='.(int)$config);
    }
    $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    $db->query('SET @vd_workflow_projection_mutation=NULL,@vd_workflow_production_mutation=NULL');
    }
}

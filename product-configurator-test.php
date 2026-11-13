<?php
if(PHP_SAPI!=='cli')exit(1);
define('VD_PRODUCT_TEST_BOOTSTRAP',true);
require __DIR__.'/quote-workflow-integration-test.php';
require __DIR__.'/product-configurator.php';
require __DIR__.'/production-evaluation.php';
$schema=vd_config_schema($db);
$nativeStore=new VDConfigNativeStore($db,$schema);
$id=$customer=null;$quotes=[];
$browserFile=__DIR__.'/configurator-browser-fixture.json';
if(($argv[1]??'')==='--browser') {
    if(is_file($browserFile))throw new RuntimeException('Clean the previous browser fixture first.');
    $customer=vdFixture(26,['field_221'=>'ISOLATED WORKFLOW FIXTURE','field_228'=>'workflow-fixture@example.invalid']);
    $id=vdFixture($schema['config.entity'],['field_'.$schema['config.name']=>'ISOLATED WORKFLOW FIXTURE','field_'.$schema['config.customer']=>$customer]);
    $fixture=json_encode(compact('id','customer'),JSON_THROW_ON_ERROR);
    if(file_put_contents($browserFile,$fixture)===false)throw new RuntimeException('Cannot persist fixture identifiers.');
    echo $fixture,"\n";exit;
}
$cleanup=($argv[1]??'')==='--cleanup-browser';
if($cleanup) {
    $fixture=json_decode(file_get_contents($browserFile),true,512,JSON_THROW_ON_ERROR);
    $id=(int)$fixture['id'];$customer=(int)$fixture['customer'];
    $name=$db->query('SELECT field_'.$schema['config.name'].' FROM app_entity_'.$schema['config.entity'].' WHERE id='.$id)->fetch_row()[0]??null;
    if($name!=='ISOLATED WORKFLOW FIXTURE')throw new RuntimeException('Unsafe configurator fixture cleanup.');
    $quotes=array_map('intval',array_column($nativeStore->quotes($id),'quote_id'));
    foreach($quotes as $quote)if(($db->query("SELECT field_3927 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0]??null)!==$name)throw new RuntimeException('Unsafe generated fixture cleanup.');
}
try {
    if(!$cleanup) {
    vdCheck(vd_evaluation_delta(5,null)['difference']===null);
    vdCheck(vd_evaluation_delta(5,0)['difference']===-5);
    vdCheck(vd_evaluation_delta(0,5)['percent']===null);
    $times=vd_evaluation_time([['field_2446'=>100,'field_2447'=>160],['field_2446'=>130,'field_2447'=>220]]);
    vdCheck($times['elapsed_minutes']===2&&$times['labor_minutes']===2.5);
    vdCheck(vd_evaluation_time([])['elapsed_minutes']===null);
    vdCheck(vd_evaluation_time([['field_2446'=>1,'field_2447'=>0]])['missing']===true);
    $customer=vdFixture(26,['field_221'=>'ISOLATED WORKFLOW FIXTURE','field_228'=>'workflow-fixture@example.invalid','field_5121'=>1370,'field_5125'=>1374]);
    $id=vdFixture($schema['config.entity'],['field_'.$schema['config.name']=>'ISOLATED WORKFLOW FIXTURE','field_'.$schema['config.customer']=>$customer]);
    $user=['id'=>1,'group_id'=>0,'email'=>'sending-user@example.invalid','name'=>'Fixture'];
    $service=new VDProductConfigurator($db,$user);$workflow=new VDQuoteWorkflow($db,$user);
    $payload=['product_type'=>3714,'type'=>'magazine','markup'=>20,'quantities'=>[100],'components'=>[
        ['id'=>1,'name'=>'Capa','kind'=>'printed','units_per_product'=>1,'inputs'=>[],'papers'=>[2652],'colors'=>[],'rubrics'=>[],
            'fields'=>['4218'=>210,'4219'=>297,'4233'=>1,'4220'=>1565,'4229'=>1255,'6110'=>1544,'6169'=>'true','6170'=>'false']],
        ['id'=>2,'name'=>'Miolo','kind'=>'printed','units_per_product'=>1,'inputs'=>[],'papers'=>[2652],'colors'=>[],'rubrics'=>[],
            'fields'=>['4218'=>210,'4219'=>297,'4233'=>1,'4220'=>1565,'4229'=>1255,'6110'=>1544,'6169'=>'true','6170'=>'false']],
        ['id'=>3,'name'=>'Assemblagem','kind'=>'assembly','units_per_product'=>1,'inputs'=>[1,2],'papers'=>[],'colors'=>[],'rubrics'=>[],'fields'=>[]]
    ],'selections'=>[]];
    users::$allowed=false;
    vdReject(function()use($service,$id){$service->state($id);});users::$allowed=true;
    $state=$service->save($id,0,$payload);vdCheck($state['revision']===1);
    vdReject(function()use($service,$id,$payload){$service->save($id,0,$payload);});
    $counts=$db->query('SELECT (SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_262) components')->fetch_assoc();
    $simulation=$service->simulation($id,1);
    vdCheck(count($simulation['data']['papers'])>0);
    vdCheck(!array_intersect([9,10,19,20,23,24,25,26,32,39],array_map('intval',array_column($simulation['data']['costing_operations'],'operation_id'))));
    vdCheck($counts===$db->query('SELECT (SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_262) components')->fetch_assoc());
    vdCheck(count($service->catalog($id)['product_types'])>=30);
    vdReject(function()use($service,$id,$state){$service->generate($id,$state['revision']);});
    $calc=['source'=>'dynamic','quantity'=>100,'quantityId'=>100,'machineId'=>6,'modeId'=>3748,
        'component'=>['id'=>1,'width'=>210,'height'=>297,'pages'=>1],
        'parts'=>[['plan'=>['width'=>720,'height'=>510,'poses'=>4,'columns'=>2,'rows'=>2,'per_sheet'=>2,'sheet_width'=>720,'sheet_height'=>1020],
            'paperCalculation'=>['paper'=>['id'=>2652,'internal_id'=>1406,'name'=>'Fixture paper'],'sheets'=>50],
            'result'=>['plates'=>4,'goodPlans'=>25,'wastePlans'=>0],'plate'=>['materialId'=>984],
            'operation'=>['id'=>7],'machine'=>['id'=>6],'modeId'=>3748,'allocation'=>[4],'produced'=>[100],'runs'=>25]],
        'rubrics'=>[['id'=>0,'quantity'=>100,'name'=>'Fixture','code'=>'']],
        'results'=>['plates'=>4],'allOperations'=>['complete'=>true,'errors'=>[],'cost'=>10,'sale'=>12,
            'sourceOperations'=>[['operation_id'=>7,'equipment'=>6]],'lines'=>[
                ['key'=>'printing','name'=>'Fixture print','operationId'=>7,'hours'=>0.5,'quantity'=>100,'cost'=>10,'sale'=>12],
                ['key'=>'paper','name'=>'Paper','cost'=>0,'sale'=>0]]]];
    foreach([2,3,43,44] as $op)$calc['allOperations']['lines'][]=['key'=>'operation:'.$op,'operationId'=>$op,'name'=>'Fixture operation','hours'=>0.1,'quantity'=>100,'cost'=>0,'sale'=>0];
    $state=$service->select($id,$state['revision'],1,100,$calc);
    $calc['component']['id']=2;
    $state=$service->select($id,$state['revision'],2,100,$calc);
    $definition=['operations'=>[['operation_id'=>23,'minutes'=>10,'hourly'=>30,'work_quantity'=>100,'equipment'=>0,'unit'=>'un.']],
        'materials'=>[['article_id'=>1328,'operation_id'=>23,'quantity'=>1.25,'unit'=>'kg','unit_cost'=>2]]];
    $state=$service->nonprint($id,$state['revision'],3,100,$definition);
    // A denied create must roll back the header and every generated row.
    $countBefore=(int)$db->query('SELECT COUNT(*) FROM app_entity_146')->fetch_row()[0];
    users::$deniedCreates=[388];
    vdReject(function()use($service,$id,$state){$service->generate($id,$state['revision']);});
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_146')->fetch_row()[0]===$countBefore);
    users::$deniedCreates=[];
    $generated=$service->generate($id,$state['revision']);$quote=$quotes[]=$generated['quote'];
    vdCheck($quote>0);
    vdCheck($service->generate($id,$state['revision'])['already_generated']===true);
    vdCheck((int)$db->query("SELECT field_".$schema['quote.config']." FROM app_entity_146 WHERE id=$quote")->fetch_row()[0]===$id);
    vdCheck($db->query("SELECT field_3930 FROM app_entity_146 WHERE id=$quote")->fetch_row()[0]==='3712,3714');
    $frozen=$nativeStore->source($quote);
    vdImmutable('UPDATE app_entity_146 SET field_'.$schema['quote.hash']."=REPEAT('0',64) WHERE id=$quote");
    $versions=array_map('intval',array_column($workflow->state($quote)['versions'],'id'));
    foreach($versions as $version)$workflow->confirm($quote,$version);
    $document=$workflow->approve($quote,$versions)['document'];
    $workflow->send($quote,$document,['request_id'=>'config-test-'.bin2hex(random_bytes(16)),'email'=>'workflow-fixture@example.invalid','phone'=>'']);
    $workflow->accept($quote,$document,100,'Isolated fixture',null,'2027-11-12');
    $order=(int)$workflow->createProductionOrder($quote)['id'];
    vdCheck((int)$db->query("SELECT field_".$schema['order.config']." FROM app_entity_121 WHERE id=$order")->fetch_row()[0]===$id);
    $db->query("UPDATE app_entity_121 SET field_2437=883 WHERE id=$order");
    vdCheck($db->query("SELECT order_id FROM app_vd_variance_queue WHERE order_id=$order")->num_rows===1);
    $evaluation=vd_evaluation_process($db,$order);
    vdCheck($evaluation>0);
    $result=json_decode($db->query("SELECT payload FROM app_vd_production_evaluations WHERE order_id=$order")->fetch_row()[0],true);
    vdCheck($result['config']===$id&&$result['quote']===$quote);
    vdCheck($result['operations'][0]['time']['actual']===null);
    vdCheck($result['materials'][0]['quantity']['actual']===null);
    vdCheck(count($result['missing'])>0);
    vdCheck(strpos(vd_evaluation_html($db,$user,$evaluation),'SEM DADOS')!==false);
    $material=(int)$result['materials'][0]['id'];$field=$schema['actual_material'];
    $db->query("UPDATE app_entity_394 SET field_$field='0' WHERE id=$material");
    vdCheck($db->query("SELECT order_id FROM app_vd_variance_queue WHERE order_id=$order")->num_rows===1);
    vdCheck(vd_evaluation_process($db,$order)===$evaluation);
    $updated=json_decode($db->query("SELECT payload FROM app_vd_production_evaluations WHERE order_id=$order")->fetch_row()[0],true);
    vdCheck($updated['materials'][0]['quantity']['actual']===0.0);
    vdCheck($updated['materials'][0]['quantity']['difference']<0);
    $changed=$state['payload'];$changed['markup']=30;$changed['selections']=[];
    $service->save($id,$state['revision'],$changed);
    vdCheck($frozen===$nativeStore->source($quote));
    echo "$checks independent configurator and evaluation checks passed.\n";
    }
}finally{
    foreach($quotes as $quote) {
        $orders=$db->query("SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=$quote")->fetch_all(MYSQLI_ASSOC);
        foreach($orders as $order) {
            $orderId=(int)$order['order_id'];
            $db->query("DELETE n FROM app_entity_".$schema['evaluation.entity']." n JOIN app_vd_production_evaluations e ON e.native_id=n.id WHERE e.order_id=$orderId");
            $db->query("DELETE FROM app_vd_production_evaluations WHERE order_id=$orderId");
        }
        vdRemoveFixtureOrders($quote);
        foreach($orders as $order)$db->query('DELETE FROM app_vd_variance_queue WHERE order_id='.(int)$order['order_id']);
        $db->query("DELETE p FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote");
        foreach(['app_vd_quote_products','app_vd_quote_product_components','app_vd_quote_deliveries','app_vd_quote_sms','app_vd_quote_documents','app_vd_quote_versions','app_vd_quote_audit','app_vd_quote_control','app_vd_quote_legacy'] as $table)$db->query("DELETE FROM $table WHERE quote_id=$quote");
        foreach([276,279,388,280,278,277] as $entity)$db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
        foreach([261,262] as $entity)$db->query("DELETE FROM app_entity_$entity WHERE parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
    }
    if($id){
        foreach(['part','quantity','finish','submission','document','piece'] as $prefix)$nativeStore->clear($prefix,$id);
        $db->query("DELETE FROM app_entity_".$schema['config.entity']." WHERE id=$id");
    }
    if($customer)$db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    if($cleanup){unlink($browserFile);echo "Configurator browser fixture and generated quotations removed.\n";}
}

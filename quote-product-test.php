<?php
if (PHP_SAPI!=='cli') exit(1);
define('VD_PRODUCT_TEST_BOOTSTRAP',true);
require __DIR__.'/quote-workflow-integration-test.php';
$fixtures=[];
try {
    foreach(['magazine','folding_box','rigid_box','lid_base_box','accessory_box'] as $type) {
        $customer=vdFixture(26,['field_221'=>'ISOLATED WORKFLOW FIXTURE','field_228'=>'workflow-fixture@example.invalid','field_5121'=>1370,'field_5125'=>1374]);
        $quote=vdFixture(146,['field_3923'=>$customer,'field_3925'=>'workflow-fixture@example.invalid','field_3927'=>'ISOLATED WORKFLOW FIXTURE','field_2030'=>36]);
        $fixtures[]=['quote'=>$quote,'customer'=>$customer];
        $quantity=vdFixture(261,['parent_item_id'=>$quote,'field_3938'=>100]);
        $service=new VDQuoteWorkflow($db,['id'=>1,'group_id'=>0,'email'=>'sending-user@example.invalid','name'=>'Fixture']);
        users::$allowed=false;
        vdReject(function()use($service,$quote){$service->configureProduct($quote,['type'=>'magazine']);});
        users::$allowed=true;
        users::$deniedCreates=[262];
        vdReject(function()use($service,$quote,$type){$service->configureProduct($quote,['type'=>$type,'revision'=>0,'create_template'=>true]);});
        users::$deniedCreates=[];
        if ($type==='magazine') {
            $existing=[];
            foreach(['Capa','Miolo'] as $name) {
                $nativeId=vdFixture(262,['parent_item_id'=>$quote,'field_4225'=>$name,'field_4220'=>1565,
                    'field_4229'=>1255,'field_6110'=>1544,'field_6169'=>'true']);
                $existing[]=['id'=>$nativeId,'kind'=>'printed','inputs'=>[],'units_per_product'=>1];
            }
            $service->configureProduct($quote,['type'=>$type,'revision'=>0,'components'=>$existing,'add_assembly'=>true]);
            vdCheck((int)$service->productState($quote)['components'][0]['id']===$existing[0]['id']);
        } else $service->configureProduct($quote,['type'=>$type,'revision'=>0,'create_template'=>true]);
        $state=$service->productState($quote);
        vdCheck(count($state['components'])===count(vd_product_template($type)));
        vdCheck($state['revision']===1);
        $definitions=[];
        foreach($state['components'] as $component) $definitions[]=['id'=>(int)$component['id'],'kind'=>$component['product']['kind'],'inputs'=>$component['product']['inputs'],'units_per_product'=>1];
        vdCheck(count(vd_product_graph($definitions))===count($definitions));
        $invalid=$definitions;
        $last=count($invalid)-1;$invalid[$last]['inputs'][]=$invalid[$last]['id'];
        vdReject(function()use($service,$quote,$type,$invalid){$service->configureProduct($quote,['type'=>$type,'revision'=>1,'components'=>$invalid]);});
        vdReject(function()use($service,$quote,$type){$service->configureProduct($quote,['type'=>$type,'revision'=>1,'create_template'=>true]);});
        $versions=[];
        foreach($state['components'] as $component) {
            $id=(int)$component['id'];$kind=$component['product']['kind'];
            if($kind==='printed') {
                $db->query("UPDATE app_entity_262 SET field_4218=210,field_4219=297,field_4233=1 WHERE id=$id");
                $calc=['source'=>'dynamic','quantity'=>100,'quantityId'=>$quantity,'machineId'=>6,'modeId'=>3748,
                    'component'=>['id'=>$id,'width'=>210,'height'=>297,'pages'=>1],
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
            } else {
                $definition=['quantity'=>100,'markup'=>20,'operations'=>[],'materials'=>[]];
                foreach($kind==='assembly'?($type==='magazine'?[19,23,9,10]:[24,10]):[2] as $op) $definition['operations'][]=[
                    'operation_id'=>$op,'minutes'=>10,'hourly'=>30,'work_quantity'=>100,'equipment'=>0,'unit'=>'un.'];
                $definition['materials'][]=['article_id'=>1328,'operation_id'=>$kind==='assembly'?10:2,'quantity'=>1.25,'unit'=>'kg','unit_cost'=>2];
                $calc=$service->nonprintCalculation($quote,$id,$definition);
                vdCheck(count(vd_workflow_production_rows($calc,1,$id,$quantity)['plans'])===0);
                vdCheck(!in_array('paper',array_column($calc['allOperations']['lines'],'key'),true));
                vdCheck(abs($calc['allOperations']['cost']-(count($definition['operations'])*5+2.5))<0.00001);
            }
            $saved=$service->save($quote,$id,['request_id'=>'product-test-'.bin2hex(random_bytes(16)),'quantity_id'=>$quantity,'expected_version'=>0,'calculation'=>$calc]);
            $version=(int)$saved['workflow_version'];
            $versions[]=$version;
            $service->confirm($quote,$version);
            $versionData=$service->snapshot($quote,$version);
            vdCheck($versionData['calculation']['product']['kind']===$kind);
        }
        $frozen=$db->query("SELECT id,hash,payload FROM app_vd_quote_versions WHERE quote_id=$quote ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $service->configureProduct($quote,['type'=>$type==='magazine'?'folding_box':'magazine','revision'=>1,'components'=>$definitions]);
        vdReject(function()use($service,$quote,$versions){$service->confirm($quote,$versions[0]);});
        vdReject(function()use($service,$quote,$versions){$service->approve($quote,$versions);});
        $service->configureProduct($quote,['type'=>$type,'revision'=>2,'components'=>$definitions]);
        vdCheck($frozen===$db->query("SELECT id,hash,payload FROM app_vd_quote_versions WHERE quote_id=$quote ORDER BY id")->fetch_all(MYSQLI_ASSOC));
        $stockBefore=(int)$db->query('SELECT COUNT(*) FROM app_entity_100')->fetch_row()[0];
        $docId=$service->approve($quote,$versions)['document'];
        $doc=$service->document($quote,$docId);
        vdCheck(strpos($doc['html'],'Assemblagem')!==false);
        $service->send($quote,$docId,['request_id'=>'product-send-'.bin2hex(random_bytes(16)),'email'=>'workflow-fixture@example.invalid','phone'=>'']);
        $service->accept($quote,$docId,100,'Isolated fixture',null,'2027-11-12');
        $order=$service->createProductionOrder($quote);
        $orderId=(int)$order['id'];
        vdCheck($orderId>0);
        vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_100')->fetch_row()[0]===$stockBefore);
        $links=$db->query("SELECT * FROM app_vd_production_component_inputs WHERE order_id=$orderId")->fetch_all(MYSQLI_ASSOC);
        vdCheck(count($links)===count($state['components'])-1);
        $assembly=(int)$links[0]['component_id'];
        $assemblyOp=(int)$db->query("SELECT id FROM app_entity_170 WHERE parent_item_id=$assembly ORDER BY field_2430+0,id LIMIT 1")->fetch_row()[0];
        $start=(int)$db->query("SELECT MIN(field_2536) FROM app_entity_170 WHERE parent_item_id=$assembly")->fetch_row()[0];
        foreach($links as $link) {
            $input=(int)$link['input_component_id'];
            $end=(int)$db->query("SELECT MAX(field_2531) FROM app_entity_170 WHERE parent_item_id=$input")->fetch_row()[0];
            vdCheck($end<=$start);
        }
        vdImmutable("UPDATE app_entity_170 SET field_2425=3717 WHERE id=$assemblyOp");
        vdImmutable("UPDATE app_entity_170 SET field_2549=50 WHERE id=$assemblyOp");
        vdReject(function()use($db,$assembly){vd_production_check_inputs($db,$assembly);});
        vdImmutable("INSERT INTO app_entity_171(parent_item_id,date_added,created_by,field_2444,field_2445,field_2446,field_2447,field_2507,field_2552) VALUES($assemblyOp,1,1,3719,'',1,0,36,'')");
        $time=vdFixture(171,['parent_item_id'=>$assemblyOp,'field_2446'=>0,'field_2447'=>0]);
        vdImmutable("UPDATE app_entity_171 SET field_2446=1 WHERE id=$time");
        vdCheck((int)$db->query("SELECT COUNT(*) FROM app_entity_158 WHERE parent_item_id=$assembly")->fetch_row()[0]===0);
        vdCheck((int)$db->query("SELECT COUNT(*) FROM app_entity_172 WHERE parent_item_id=$assembly")->fetch_row()[0]===0);
        $db->query("UPDATE app_entity_170 o JOIN app_vd_production_component_inputs i ON i.input_component_id=o.parent_item_id SET o.field_2425=39 WHERE i.component_id=$assembly");
        vd_production_check_inputs($db,$assembly);
        $db->query("UPDATE app_entity_170 SET field_2425=3717 WHERE id=$assemblyOp");
        vdCheck((int)$db->query("SELECT field_2425 FROM app_entity_170 WHERE id=$assemblyOp")->fetch_row()[0]===3717);
        vdCheck($service->createProductionOrder($quote)['already_created']===true);
        vdReject(function()use($service,$quote,$definitions,$type){$service->configureProduct($quote,['type'=>$type,'revision'=>1,'components'=>$definitions]);});
    }
    echo "Product checks: $checks passed.\n";
} finally {
    $db->query('SET @vd_workflow_projection_mutation=1,@vd_workflow_production_mutation=1');
    foreach($fixtures as $fixture) {
        $quote=(int)$fixture['quote'];$customer=(int)$fixture['customer'];
        vdRemoveFixtureOrders($quote);
        $db->query("DELETE p FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=$quote");
        foreach(['app_vd_quote_products','app_vd_quote_product_components','app_vd_quote_deliveries','app_vd_quote_sms','app_vd_quote_documents','app_vd_quote_versions','app_vd_quote_audit','app_vd_quote_control','app_vd_quote_legacy'] as $table)$db->query("DELETE FROM $table WHERE quote_id=$quote");
        foreach([276,279,388,280,278,277] as $entity)$db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
        foreach([261,262] as $entity)$db->query("DELETE FROM app_entity_$entity WHERE parent_item_id=$quote");
        $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
        $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    }
    $db->query('SET @vd_workflow_projection_mutation=NULL,@vd_workflow_production_mutation=NULL');
}

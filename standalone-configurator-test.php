<?php
if(PHP_SAPI!=='cli')exit(1);
define('VD_PRODUCT_TEST_BOOTSTRAP',true);
require __DIR__.'/quote-workflow-integration-test.php';
require __DIR__.'/product-configurator.php';
$user=['id'=>1,'group_id'=>0,'email'=>'fixture@example.invalid','name'=>'Fixture'];
class VDStandaloneConfiguratorFixture extends VDProductConfigurator {
    function finishingOperations() {
        global $db;
        $rows=parent::finishingOperations();
        $types=array_map('intval',array_column($db->query('SELECT id FROM app_global_lists_choices WHERE lists_id=24 AND is_active=1')->fetch_all(MYSQLI_ASSOC),'id'));
        foreach($rows as &$row)$row['product_types']=$types;
        unset($row);
        return $rows;
    }
    function finishingRules() {
        global $db;
        $rules=parent::finishingRules();
        $rules['lamination_products']=array_map('intval',array_column($db->query('SELECT id FROM app_global_lists_choices WHERE lists_id=24 AND is_active=1')->fetch_all(MYSQLI_ASSOC),'id'));
        return $rules;
    }
}
$service=new VDStandaloneConfiguratorFixture($db,$user);$schema=$service->schema;$id=0;$customer=0;$supplier=0;$contact=0;$foreignContact=0;
$nativeStore=new VDConfigNativeStore($db,$schema);
function vdStandaloneNativeCleanup(VDConfigNativeStore $store,$id) {
    foreach($store->attachments($id) as $document)$store->removeDocument($id,$document['id']);
    $store->finishFiles(true);
    foreach(['part','quantity','finish','submission','piece','rubric'] as $prefix)$store->clear($prefix,$id);
}
if(($argv[1]??'')==='--browser-customer') {
    $customer=vdFixture(26,['field_221'=>'ISOLATED CONFIGURATOR CLIENT','field_224'=>'176']);
    $supplier=vdFixture(26,['field_221'=>'ISOLATED CONFIGURATOR SUPPLIER','field_224'=>'177']);
    echo json_encode(compact('customer','supplier'),JSON_THROW_ON_ERROR),"\n";exit;
}
if(($argv[1]??'')==='--cleanup-browser-customer') {
    foreach(array_slice($argv,2) as $value) {
        $customer=(int)$value;
        $row=$db->query("SELECT field_221 FROM app_entity_26 WHERE id=$customer")->fetch_assoc();
        if(!$row||!in_array($row['field_221'],['ISOLATED CONFIGURATOR CLIENT','ISOLATED CONFIGURATOR SUPPLIER'],true))throw new RuntimeException('Unsafe customer fixture cleanup.');
        if($db->query('SELECT id FROM app_entity_'.$schema['config.entity'].' WHERE field_'.$schema['config.customer'].'='.$customer)->num_rows)throw new RuntimeException('Remove the browser request before its customer.');
        $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    }
    echo "Browser customer fixtures removed.\n";exit;
}
if(in_array($argv[1]??'',['--cleanup-browser','--verify-native-browser'],true)) {
    $id=(int)($argv[2]??0);
    $state=$service->state($id);
    if($state['name']!=='ISOLATED STANDALONE BROWSER FIXTURE'||$state['quotes'])throw new RuntimeException('Unsafe browser fixture cleanup.');
    if($argv[1]==='--verify-native-browser') {
        $parts=$nativeStore->children('part',$id);$quantities=$nativeStore->children('quantity',$id);$documents=$nativeStore->attachments($id);
        if(count($parts)!==1||count($quantities)!==2||count($documents)!==1)throw new RuntimeException('Native browser fixture rows missing.');
        foreach(['app_vd_product_drafts','app_vd_product_submissions','app_vd_product_attachments'] as $table)
            if($db->query("SELECT config_id FROM $table WHERE config_id=$id")->num_rows)throw new RuntimeException('Fixture was stored in a retired table.');
        echo "Native browser fixture verified: 1 component, 2 quantities, 1 attachment; no retired-table writes.\n";exit;
    }
    vdStandaloneNativeCleanup($nativeStore,$id);
    $db->query('DELETE FROM app_entity_'.$schema['config.entity']." WHERE id=$id");
    echo "Standalone browser fixture removed.\n";exit;
}
try {
    $internal=$service->internalCustomer();
    vdCheck($internal['id']===$schema['config.internal_customer']&&$internal['name']==='Interno');
    vdCheck((int)$db->query('SELECT entities_id FROM app_ext_report_page WHERE id='.$schema['config.report'])->fetch_row()[0]===0);
    $menu=$db->query('SELECT * FROM app_entities_menu WHERE id='.$schema['config.menu'])->fetch_assoc();
    vdCheck(in_array($menu['type'],['url','url_self'],true)&&strpos($menu['url'],'id='.$schema['config.report'])!==false);
    $counts=$db->query('SELECT (SELECT COUNT(*) FROM app_entity_'.$schema['config.entity'].') configurations,(SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_121) orders,(SELECT COUNT(*) FROM app_entity_170) operations')->fetch_assoc();
    vdCheck(count($service->catalog()['product_types'])>=30);
    $service->recent();$service->customers('ISOLATED');
    vdCheck($counts===$db->query('SELECT (SELECT COUNT(*) FROM app_entity_'.$schema['config.entity'].') configurations,(SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_121) orders,(SELECT COUNT(*) FROM app_entity_170) operations')->fetch_assoc());
    $customer=vdFixture(26,['field_221'=>'ISOLATED STANDALONE CUSTOMER','field_224'=>'176,177']);
    $supplier=vdFixture(26,['field_221'=>'ISOLATED STANDALONE SUPPLIER','field_224'=>'177']);
    $contact=vdFixture(90,['parent_item_id'=>$customer,'field_918'=>'ISOLATED CONTACT','field_921'=>'contact@example.invalid','field_920'=>'000000000']);
    $foreignContact=vdFixture(90,['parent_item_id'=>$supplier,'field_918'=>'ISOLATED FOREIGN CONTACT']);
    $contacts=$service->contacts($customer);
    vdCheck(array_map('intval',array_column($contacts,'id'))===[$contact]);
    vdCheck(strpos($contacts[0]['text'],'contact@example.invalid')!==false);
    vdCheck(count($service->contacts($customer,'contact@example.invalid'))===1);
    vdCheck($service->contacts($customer,'NO MATCH')===[]);
    vdReject(function()use($service,$supplier){$service->contacts($supplier);});
    vdReject(function()use($service){$service->contacts(0);});
    $identity=['name'=>'ISOLATED STANDALONE FIXTURE','customer'=>$customer,'reference'=>'test','notes'=>'Information only'];
    $payload=['type'=>'standard','product_type'=>$schema['config.other_type'],'quantities'=>[],'components'=>[],'request_details'=>['other_product'=>'Produto especial']];
    $invalid=$payload;$invalid['request_details']['contact_id']=$foreignContact;
    vdReject(function()use($service,$identity,$invalid){$service->submit(0,0,$identity,$invalid,'contact-'.bin2hex(random_bytes(16)));});
    $invalid['request_details']['contact_id']=-1;
    vdReject(function()use($service,$identity,$invalid){$service->submit(0,0,$identity,$invalid,'contact-'.bin2hex(random_bytes(16)));});
    $payload['request_details']['contact_id']=$contact;
    $payload['request_details']['contact']=$contacts[0]['text'];
    $finishings=$service->finishingOperations();
    $finishingIds=array_map('intval',array_column($finishings,'id'));
    $stageField='field_'.$schema['operation.stage'];
    $expectedExtra=array_map('intval',array_column($db->query("SELECT o.id FROM app_entity_36 o JOIN app_fields_choices c ON c.id=o.field_6292 AND c.fields_id=6292 WHERE (c.name='Extra' OR o.$stageField IN('Assemblagem','Embalamento individual')) AND o.field_6129='true' ORDER BY o.id")->fetch_all(MYSQLI_ASSOC),'id'));
    $sortedIds=$finishingIds;sort($sortedIds);
    vdCheck($sortedIds===$expectedExtra);
    vdCheck(!in_array(13,$finishingIds,true)&&!in_array(8,$finishingIds,true)&&in_array(6,$finishingIds,true)&&in_array(32,$finishingIds,true));
    vdCheck(!in_array(7,$finishingIds,true)&&!in_array(44,$finishingIds,true)&&!in_array(3,$finishingIds,true));
    $chosen=array_values(array_filter($finishings,static function($operation){return (int)$operation['id']===6;}))[0];
    $chosen['id']=(int)$chosen['id'];
    $payload['request_details']['finishing_operations']=[$chosen];
    $rules=$service->catalog()['finishing_rules'];
    vdCheck(in_array(36,$finishingIds,true));
    $typeChoices=$service->catalog()['product_types'];
    vdCheck(vd_config_finishing_applies(3824,[3800],$typeChoices));
    vdCheck(vd_config_finishing_applies(3824,[3824],$typeChoices));
    vdCheck(!vd_config_finishing_applies(3824,[],$typeChoices));
    vdCheck(!vd_config_finishing_applies(3824,[3707],$typeChoices));
    vdCheck(!vd_config_finishing_applies(3800,[3824],$typeChoices));
    $associationFixture=[['id'=>36,'name'=>'Colocar Fio','product_types'=>[3824]]];
    $associationPayload=['product_type'=>3824,'request_details'=>['finishing_operations'=>[['id'=>36,'name'=>'Colocar Fio','thread'=>['type'=>'Algodão','color'=>'Branco','length_mm'=>300]]]]];
    vdCheck(vd_config_finishing_review($associationPayload,$typeChoices,$associationFixture,[],$rules)['errors']===[]);
    $associationFixture[0]['product_types']=[];
    vdCheck(count(vd_config_finishing_review($associationPayload,$typeChoices,$associationFixture,[],$rules)['errors'])===1);
    $associationFixture[0]['legacy_selected']=true;
    $legacyAssociation=vd_config_finishing_review($associationPayload,$typeChoices,$associationFixture,[],$rules);
    vdCheck($legacyAssociation['errors']===[]&&count($legacyAssociation['warnings'])===1);
    $realService=new VDProductConfigurator($db,$user);
    foreach($realService->finishingOperations() as $operation) {
        $storedProducts=$db->query('SELECT field_6442 FROM app_entity_36 WHERE id='.(int)$operation['id'])->fetch_row()[0];
        vdCheck($operation['product_types']===($storedProducts===''?[]:array_map('intval',explode(',',$storedProducts))));
    }
    $threadOperation=['id'=>36,'name'=>'Colocar Fio','thread'=>['type'=>'Algodão','color'=>'Branco','length_mm'=>'300']];
    $threadPayload=['product_type'=>3824,'components'=>[['id'=>1,'name'=>'Cartaz','kind'=>'printed','request'=>['finishing_operations'=>[$threadOperation]]]]];
    $threadReview=$service->finishingReview($threadPayload);
    vdCheck($threadReview['errors']===[]&&$threadReview['warnings']===[]);
    foreach([null,'',0,-1,'abc',[],true,'1e999',300,'300.5','0.1'] as $length) {
        $invalid=$threadPayload;$invalid['components'][0]['request']['finishing_operations'][0]['thread']['length_mm']=$length;
        vdCheck(($service->finishingReview($invalid)['errors']===[])===in_array($length,[300,'300.5','0.1'],true));
    }
    foreach([null,[],['type'=>'Algodão'],['type'=>'','color'=>'Branco'],['type'=>'Algodão','color'=>'  '],['type'=>[],'color'=>'Branco'],['type'=>str_repeat('x',501),'color'=>'Branco']] as $thread) {
        if(is_array($thread))$thread['length_mm']='300';
        $invalid=$threadPayload;$invalid['components'][0]['request']['finishing_operations'][0]['thread']=$thread;
        vdCheck(count($service->finishingReview($invalid)['errors'])>0);
    }
    $threadPayload['components'][0]['request']['finishing_operations']=[];
    vdCheck($service->finishingReview($threadPayload)['errors']===[]);
    $payload['request_details']['finishing_operations'][]=$threadOperation;
    $enveloping=$schema['config.enveloping_operation'];
    vdCheck(in_array($enveloping,$finishingIds,true));
    $envelopePayload=['product_type'=>3708,'components'=>[['id'=>1,'name'=>'Produto','kind'=>'printed','fields'=>['4218'=>323,'4219'=>253],'request'=>['finishing_operations'=>[['id'=>$enveloping,'name'=>'Envelopar']]]]]];
    $envelopeReview=$service->finishingReview($envelopePayload);
    vdCheck($envelopeReview['errors']===[]&&$envelopeReview['warnings']===[]);
    foreach([[253,323,true],[323.99,253.99,true],[324,253,false],[323,254,false],[254,324,false],[300,300,false],[0,200,false],['',200,false],[100,200,true]] as $case) {
        $envelopePayload['components'][0]['fields']=['4218'=>$case[0],'4219'=>$case[1]];
        vdCheck(($service->finishingReview($envelopePayload)['errors']===[])===$case[2]);
    }
    vdCheck(count($service->finishingReview(['product_type'=>3708,'request_details'=>['finishing_operations'=>[['id'=>$enveloping,'name'=>'Envelopar']]]])['errors'])===1);
    $envelopePayload['components'][0]['request']['finishing_operations']=[['id'=>29,'name'=>'Ensacar']];
    foreach([[323,253,true],[253,323,true],[323.99,253.99,true],[324,253,false],[323,254,false],[254,324,false],[300,300,false],[0,200,false],['',200,false]] as $case) {
        $envelopePayload['components'][0]['fields']=['4218'=>$case[0],'4219'=>$case[1]];
        vdCheck(($service->finishingReview($envelopePayload)['errors']===[])===$case[2]);
    }
    vdCheck($rules['die_operation']>0&&in_array($rules['die_operation'],$finishingIds,true));
    $dieReview=$service->finishingReview(['product_type'=>3751,'components'=>[],'request_details'=>['finishing_operations'=>[['id'=>$rules['die_operation'],'name'=>'Cortante']]]]);
    vdCheck($dieReview['errors']===[]&&count($dieReview['warnings'])===1);
    $baseReview=$service->finishingReview(['product_type'=>3751,'request_details'=>['finishing_operations'=>[['id'=>8,'name'=>'Corte e Vinco']]]]);
    vdCheck(count($baseReview['errors'])===1);
    $usedDie=['product_type'=>3751,'components'=>[['id'=>1,'name'=>'Caixa','kind'=>'printed','fields'=>[],'request'=>['die'=>['status'=>'used','number'=>'CT-123']]]]];
    vdCheck($service->finishingReview($usedDie)['errors']===[]);
    vdCheck(count($service->finishingReview($usedDie)['warnings'])===1);
    $invalid=$usedDie;$invalid['components'][0]['request']['die']['number']=' ';
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $invalid=$usedDie;$invalid['components'][0]['request']['die']['status']='new';
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $newDie=$invalid;$newDie['components'][0]['request']['finishing_operations']=[['id'=>$rules['die_operation'],'name'=>'Produção de cortante']];
    vdCheck($service->finishingReview($newDie)['errors']===[]);
    $newDie['components'][0]['request']['die']['status']='used';
    vdCheck(count($service->finishingReview($newDie)['errors'])===1);
    $printPayload=['components'=>[['kind'=>'printed','name'=>'Produto','colors'=>[],'fields'=>[4229=>'1255']]],'request_details'=>['dispatch'=>['pickup']]];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    foreach(['cmyk'=>[3728,3729,3730,3731],'black'=>[3731],'pantone'=>[3732],'cmyk_pantone'=>[3728,3729,3730,3731,3732],'white'=>[3821],'combined'=>[3731,3821,3732],'none'=>[3804]] as $mode=>$colors) {
        $printPayload['components'][0]['colors']=$colors;
        $printPayload['components'][0]['request']['printing']=['mode'=>$mode,'pantone'=>'Pantone 186 C, Pantone 871 C'];
        vd_config_request_validate($printPayload);vdCheck(true);
    }
    $printPayload['components'][0]['colors']=[3732];
    $printPayload['components'][0]['request']['printing']=['mode'=>'combined','pantone'=>''];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    $printPayload['components'][0]['request']['printing']=['mode'=>'white','pantone'=>'Pantone 186 C'];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    $printPayload['components'][0]['colors']=[3804,3821];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    $printPayload['components'][0]['colors']=[3821];
    $printPayload['request_details']['dispatch']=['unknown'];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    $printPayload['request_details']['dispatch']=['pickup','pickup'];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    $printPayload['request_details']['dispatch']=['pickup','carrier'];
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    foreach(['pickup','carrier','delivery'] as $method) {
        $printPayload['request_details']['dispatch']=[$method];
        vd_config_request_validate($printPayload);vdCheck(true);
    }
    $printPayload['components'][0]['colors']=[3821,3742];
    $printPayload['components'][0]['request']['printing']['mode']='combined';
    vdReject(function()use($printPayload){vd_config_request_validate($printPayload);});
    vdCheck(!isset($rules['profiles'])&&!isset($rules['products']));
    vdCheck(in_array(23,$rules['binding'],true)&&in_array(32,$rules['binding'],true));
    vdCheck($service->finishingReview(['product_type'=>3708])['profile']==='operations');
    vdCheck($service->finishingReview(['product_type'=>3714])['profile']==='operations');
    vdCheck($service->finishingReview(['product_type'=>3854])['profile']==='operations');
    vdCheck($service->finishingReview(['product_type'=>3836])['profile']==='operations');
    $reviewPayload=['type'=>'standard','product_type'=>3708,'quantities'=>[],'request_details'=>[],'components'=>[
        ['id'=>1,'name'=>'Produto','kind'=>'printed','inputs'=>[],'fields'=>['6267'=>1602,'6268'=>1605],'request'=>['lamination'=>true,'finishing_operations'=>[['id'=>22,'name'=>'Termoestampagem']]]]
    ]];
    $review=$service->finishingReview($reviewPayload);
    vdCheck($review['errors']===[]&&count($review['warnings'])===1);
    $reviewPayload['components'][0]['request']['finishing_operations']=[];
    vdCheck($service->finishingReview($reviewPayload)['errors']===[]);
    vdCheck($service->finishingReview($reviewPayload)['warnings']===[]);
    $invalid=$reviewPayload;$invalid['components'][0]['fields']=['6267'=>1600,'6268'=>1604];
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $invalid=$reviewPayload;$invalid['components'][0]['fields']['6267']=1605;
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $invalid=$reviewPayload;$invalid['components'][0]['request']['lamination']=false;
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $invalid=$reviewPayload;$invalid['components'][0]['kind']='material';
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $invalid=$reviewPayload;$invalid['components'][0]['request']['finishing_operations']=[['id'=>7,'name'=>'Impressão']];
    vdCheck(count($service->finishingReview($invalid)['errors'])===1);
    $bindingReview=static function($payload)use($service,$rules){
        return vd_config_finishing_review($payload,$service->catalog()['product_types'],[
            ['id'=>23,'name'=>'Agrafar','product_types'=>[3712,3756]],['id'=>25,'name'=>'Brochar','product_types'=>[3712,3756]],['id'=>26,'name'=>'Coser','product_types'=>[3712,3756]],['id'=>32,'name'=>'Espiral','product_types'=>[3712,3756]]
        ],[],$rules);
    };
    $bindingPayload=['product_type'=>3714,'request_details'=>['finishing_operations'=>[['id'=>23,'name'=>'Agrafar'],['id'=>25,'name'=>'Brochar']]]];
    vdCheck(count($bindingReview($bindingPayload)['errors'])>=1);
    $bindingPayload['request_details']['finishing_operations']=[['id'=>25,'name'=>'Brochar'],['id'=>26,'name'=>'Coser']];
    vdCheck($bindingReview($bindingPayload)['errors']===[]);
    vdCheck(count($bindingReview($bindingPayload)['warnings'])>=1);
    $bindingPayload['product_type']=3757;
    vdCheck($bindingReview($bindingPayload)['warnings']===[]);
    $unusual=['product_type'=>3708,'request_details'=>['finishing_operations'=>[['id'=>32,'name'=>'Espiral']]]];
    vdCheck($service->finishingReview($unusual)['errors']===[]&&$service->finishingReview($unusual)['warnings']===[]);
    $reviewPayload['product_type']=3714;$reviewPayload['components'][0]['name']='Miolo';
    vdCheck(count($service->finishingReview($reviewPayload)['warnings'])===1);
    $invalid=$payload;$invalid['components']=$reviewPayload['components'];
    $invalid['components'][0]['fields']['6267']=2147483647;
    vdReject(function()use($service,$identity,$invalid){$service->submit(0,0,$identity,$invalid,'lamination-'.bin2hex(random_bytes(16)));});
    foreach([[['id'=>7,'name'=>'PrintMaster']],[$chosen,$chosen],[['id'=>2147483647,'name'=>'Missing']],'invalid'] as $invalidFinishing) {
        $invalid=$payload;$invalid['request_details']['finishing_operations']=$invalidFinishing;
        vdReject(function()use($service,$identity,$invalid){$service->submit(0,0,$identity,$invalid,'finishing-'.bin2hex(random_bytes(16)));});
    }
    $request='standalone-'.bin2hex(random_bytes(16));
    users::$allowed=false;
    vdReject(function()use($service,$customer){$service->contacts($customer);});
    vdReject(function()use($service,$identity,$payload,$request){$service->submit(0,0,$identity,$payload,$request);});
    users::$allowed=true;
    $invalid=$payload;$invalid['product_type']=999999;
    vdReject(function()use($service,$identity,$invalid,$request){$service->submit(0,0,$identity,$invalid,$request);});
    foreach([0,3822,3823] as $missingType) {
        $invalid=$payload;$invalid['product_type']=$missingType;
        vdReject(function()use($service,$identity,$invalid,$request){$service->submit(0,0,$identity,$invalid,$request);});
    }
    $invalid=$payload;$invalid['request_details']['other_product']='  ';
    vdReject(function()use($service,$identity,$invalid,$request){$service->submit(0,0,$identity,$invalid,$request);});
    $invalid=$identity;$invalid['customer']=0;
    vdReject(function()use($service,$invalid,$payload,$request){$service->submit(0,0,$invalid,$payload,$request);});
    $invalid['customer']=$supplier;
    vdReject(function()use($service,$invalid,$payload,$request){$service->submit(0,0,$invalid,$payload,$request);});
    $results=$service->customers('ISOLATED STANDALONE');
    vdCheck(in_array($customer,array_map('intval',array_column($results,'id')),true));
    vdCheck(!in_array($supplier,array_map('intval',array_column($results,'id')),true));
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_'.$schema['config.entity'])->fetch_row()[0]===(int)$counts['configurations']);
    $state=$service->submit(0,0,$identity,$payload,$request);$id=$state['id'];
    vdCheck($id>0&&$state['revision']===1);
    vdCheck($state['payload']===$payload);
    vdCheck($service->state($id)['payload']['request_details']['contact_id']===$contact);
    vdCheck($service->state($id)['payload']['request_details']['finishing_operations']===$payload['request_details']['finishing_operations']);
    vdCheck($state['customer_name']==='ISOLATED STANDALONE CUSTOMER');
    vdCheck($state['payload']['request_details']['other_product']==='Produto especial');
    vdCheck($service->submit(0,0,$identity,$payload,$request)['id']===$id);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_'.$schema['config.entity'])->fetch_row()[0]===(int)$counts['configurations']+1);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_146')->fetch_row()[0]===(int)$counts['quotes']);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_121')->fetch_row()[0]===(int)$counts['orders']);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_170')->fetch_row()[0]===(int)$counts['operations']);
    $legacy=$payload;$legacy['request_details']['finishing_operations']=[['id'=>13,'name'=>'Plastificação']];
    vdCheck(count($service->finishingReview($legacy)['errors'])===1);
    try {
        $nativeStore->save($id,$legacy,1,1,time());
        $legacyReview=$service->finishingReview($legacy,$id);
        vdCheck($legacyReview['errors']===[]&&count($legacyReview['warnings'])>=1);
        vdCheck($service->finishingReview($payload,$id)['errors']===[]);
    }finally{
        $nativeStore->save($id,$payload,1,1,time());
    }
    $other=$identity;$other['notes']='Different';
    vdReject(function()use($service,$other,$payload,$request){$service->submit(0,0,$other,$payload,$request);});
    $identity['customer']=$customer;$identity['notes']='Collected later';
    $payload=['type'=>'magazine','product_type'=>3714,'quantities'=>[250,500],'components'=>[
        ['id'=>1,'name'=>'Capa','kind'=>'printed','inputs'=>[],'units_per_product'=>1,'fields'=>['4218'=>'210','4219'=>'297','4229'=>'1255'],'papers'=>[],'colors'=>[3728],'rubrics'=>[]],
        ['id'=>2,'name'=>'Miolo','kind'=>'printed','inputs'=>[],'units_per_product'=>1,'fields'=>['4233'=>'32','4229'=>'1256'],'papers'=>[],'colors'=>[3731],'rubrics'=>[]],
        ['id'=>3,'name'=>'Assemblagem','kind'=>'assembly','inputs'=>[1,2],'units_per_product'=>1,'fields'=>[],'papers'=>[],'colors'=>[],'rubrics'=>[]]
    ]];
    $payload['components'][0]['fields']['6267']=1602;
    $payload['components'][0]['fields']['6268']=1605;
    $payload['components'][0]['request']=['lamination'=>true,'finishing_operations'=>[['id'=>22,'name'=>'Termoestampagem']]];
    $payload['components'][0]['request']['printing']=['mode'=>'cmyk_pantone','pantone'=>'Pantone 186 C'];
    $payload['components'][0]['colors']=[3728,3729,3730,3731,3732];
    $payload['components'][0]['request']['die']=['status'=>'used','number'=>'CT-TEST-123'];
    $payload['request_details']['dispatch']=['carrier'];
    foreach(['',null,0,999,[],true,'1255x'] as $faces) {
        $invalid=$payload;$invalid['components'][1]['fields']['4229']=$faces;
        vdReject(function()use($service,$id,$identity,$invalid){$service->submit($id,1,$identity,$invalid,'faces-'.bin2hex(random_bytes(16)));});
        vdCheck($service->state($id)['revision']===1);
    }
    $state=$service->submit($id,1,$identity,$payload,'standalone-'.bin2hex(random_bytes(16)));
    vdCheck($state['revision']===2&&$state['payload']===$payload);
    vdCheck($service->state($id)['payload']['components'][0]['fields']['6268']===1605);
    vdCheck(count($service->finishingReview($service->state($id)['payload'])['warnings'])===2);
    vdCheck($service->state($id)['payload']['components'][0]['request']['printing']['pantone']==='Pantone 186 C');
    vdCheck($service->state($id)['payload']['components'][0]['request']['die']['number']==='CT-TEST-123');
    vdCheck($service->state($id)['payload']['request_details']['dispatch']===['carrier']);
    vdCheck($state['customer_name']==='ISOLATED STANDALONE CUSTOMER');
    vdCheck(!isset($state['payload']['markup'])&&!isset($state['payload']['selections']));
    vdCheck($db->query('SELECT field_'.$schema['config.product_type'].' FROM app_entity_'.$schema['config.entity']." WHERE id=$id")->fetch_row()[0]==='3712,3714');
    vdReject(function()use($service,$id,$identity,$payload){$service->submit($id,1,$identity,$payload,'standalone-'.bin2hex(random_bytes(16)));});
    $priced=$payload;$priced['markup']=50;
    vdReject(function()use($service,$id,$identity,$priced){$service->submit($id,2,$identity,$priced,'standalone-'.bin2hex(random_bytes(16)));});
    vdCheck($service->state($id)['revision']===2);
    $rubricPayload=$payload;$rubricPayload['type']='standard';$rubricPayload['product_type']=3708;$rubricPayload['quantities']=[350];
    $rubricPayload['rubrics']=[['quantity'=>100,'description'=>'Ana Silva','code'=>'A1'],['quantity'=>250,'description'=>'Tamanho M','code'=>'']];
    vd_config_rubrics_validate($rubricPayload);vdCheck(true);
    foreach([
        static function($p){$p['quantities']=[300];return $p;},
        static function($p){$p['product_type']=3714;return $p;},
        static function($p){$p['rubrics'][0]['quantity']=0;return $p;},
        static function($p){$p['rubrics'][0]['quantity']='100';return $p;},
        static function($p){$p['rubrics'][1]['description']='  ';return $p;},
        static function($p){$p['rubrics'][0]['price']=1;return $p;},
        static function($p){$p['rubrics'][0]['code']=str_repeat('x',101);return $p;},
        static function($p){$p['rubrics']=array_fill(0,501,['quantity'=>1,'description'=>'x','code'=>'']);$p['quantities']=[501];return $p;}
    ] as $mutate)vdReject(function()use($mutate,$rubricPayload){vd_config_rubrics_validate($mutate($rubricPayload));});
    $assigned=vd_config_assign_rubrics($rubricPayload);
    vdCheck($assigned['components'][0]['rubrics'][1]===['quantity'=>250,'name'=>'Tamanho M','code'=>'']&&$assigned['components'][2]['rubrics']===[]);
    try {
        $nativeStore->save($id,$rubricPayload,2,1,time());
        $loaded=$nativeStore->payload($db->query('SELECT * FROM app_entity_'.$schema['config.entity']." WHERE id=$id")->fetch_assoc());
        vdCheck($loaded['rubrics']===$rubricPayload['rubrics']&&$loaded['quantities']===[350]&&count($nativeStore->children('rubric',$id))===2);
    }finally{
        $nativeStore->save($id,$payload,2,1,time());
        $typeField=$schema['config.product_type'];$db->query('UPDATE app_entity_'.$schema['config.entity']." SET field_$typeField='3712,3714' WHERE id=$id");
    }
    vdCheck(count($nativeStore->children('rubric',$id))===0&&!array_key_exists('rubrics',$service->state($id)['payload']));
    $before=(int)$db->query('SELECT COUNT(*) FROM app_entity_146')->fetch_row()[0];
    $service->template(0,1);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_146')->fetch_row()[0]===$before);
    $payload['request_details']=['contact'=>'Cliente / telefone','delivery'=>'2026-12-01','finishing'=>'Plastificação mate'];
    $payload['components'][0]['request']=array_replace($payload['components'][0]['request'],['paper'=>'Couché 250 g','description'=>'Capa com abas']);
    $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $upload=vd_config_document('referência.pdf',$pdf);
    vdCheck($upload['mime']==='application/pdf'&&$upload['hash']===hash('sha256',$pdf));
    vdReject(function(){vd_config_document('malicious.php','<?php echo 1;');});
    vdReject(function(){vd_config_document('not-a-pdf.pdf','<?php echo 1;');});
    vdReject(function(){vd_config_document('empty.pdf','');});
    vdReject(function(){vd_config_document('large.pdf',str_repeat('x',10*1024*1024+1));});
    $documentRequest='document-'.bin2hex(random_bytes(16));
    $state=$service->submit($id,2,$identity,$payload,$documentRequest,[$upload]);
    vdCheck($state['revision']===3&&count($state['attachments'])===1);
    vdCheck($state['payload']['request_details']===$payload['request_details']);
    vdCheck($state['payload']['components'][0]['request']===$payload['components'][0]['request']);
    $attachment=(int)$state['attachments'][0]['id'];
    vdCheck($service->document($id,$attachment)['content']===$pdf);
    vdCheck(count($service->submit($id,2,$identity,$payload,$documentRequest,[$upload])['attachments'])===1);
    vdReject(function()use($service,$attachment){$service->document(0,$attachment);});
    $denied=$payload;$denied['request_details']['delivery']='2026-02-31';
    vdReject(function()use($service,$id,$identity,$denied){$service->submit($id,3,$identity,$denied,'invalid-'.bin2hex(random_bytes(16)));});
    vdReject(function()use($service,$id,$identity,$payload){$service->submit($id,3,$identity,$payload,'remove-'.bin2hex(random_bytes(16)),[],[2147483647]);});
    vdCheck($service->state($id)['revision']===3);
    vdCheck(count($service->state($id)['attachments'])===1);
    vdReject(function()use($service,$id,$identity,$payload,$upload){$service->submit($id,3,$identity,$payload,'many-'.bin2hex(random_bytes(16)),array_fill(0,11,$upload));});
    $large=vd_config_document('nine-mb.pdf',$pdf.str_repeat(' ',9*1024*1024-strlen($pdf)));
    vdReject(function()use($service,$id,$identity,$payload,$large){$service->submit($id,3,$identity,$payload,'total-'.bin2hex(random_bytes(16)),[$large,$large,$large]);});
    vdCheck($service->state($id)['revision']===3&&count($service->state($id)['attachments'])===1);
    $state=$service->submit($id,3,$identity,$payload,'remove-'.bin2hex(random_bytes(16)),[],[$attachment]);
    vdCheck($state['revision']===4&&$state['attachments']===[]);
    $internalIdentity=$identity;$internalIdentity['customer']=$internal['id'];
    $state=$service->submit($id,4,$internalIdentity,$payload,'internal-'.bin2hex(random_bytes(16)));
    vdCheck((int)$state['customer']===$internal['id']&&$state['customer_name']==='Interno');
    vdCheck((int)$service->state($id)['customer']===$internal['id']);
    vdReject(function()use($service,$id,$attachment){$service->document($id,$attachment);});
    $stableParts=array_column($nativeStore->children('part',$id),'id');
    $db->begin_transaction();
    try {
        $longPayload=$payload;$longPayload['legacy_context']=str_repeat('ç',40000);
        $nativeStore->save($id,$longPayload,6,1,time());
        vdCheck($service->state($id)['payload']===$longPayload);
        vdCheck(array_column($nativeStore->children('part',$id),'id')===$stableParts);
        vdCheck(count($nativeStore->children('piece',$id))>=2);
    }finally{$db->rollback();}
    vdCheck($service->state($id)['payload']===$payload);
    echo "$checks standalone collection-only checks passed.\n";
}finally{
    if($id) {
        vdStandaloneNativeCleanup($nativeStore,$id);
        $db->query('DELETE FROM app_entity_'.$schema['config.entity']." WHERE id=$id");
    }
    if($contact)$db->query("DELETE FROM app_entity_90 WHERE id=$contact");
    if($foreignContact)$db->query("DELETE FROM app_entity_90 WHERE id=$foreignContact");
    if($customer)$db->query("DELETE FROM app_entity_26 WHERE id=$customer");
    if($supplier)$db->query("DELETE FROM app_entity_26 WHERE id=$supplier");
}

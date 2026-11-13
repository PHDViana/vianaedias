<?php
if(PHP_SAPI!=='cli')exit(1);
define('VD_PRODUCT_TEST_BOOTSTRAP',true);
require __DIR__.'/quote-workflow-integration-test.php';
require __DIR__.'/product-request-conversion.php';
$user=['id'=>1,'group_id'=>0,'email'=>'fixture@example.invalid','name'=>'Fixture'];
$schema=vd_config_schema($db);$service=new VDProductConfigurator($db,$user);$store=new VDConfigNativeStore($db,$schema);
$ids=[];$customers=[];$contacts=[];
function vdConversionFixture($incomplete=false,$staged=false) {
    global $db,$service,$schema,$store,$ids,$customers,$contacts;
    $paper=$db->query("SELECT id FROM app_entity_99 WHERE FIND_IN_SET('190',field_1019)>0 AND field_3632+0>0 AND field_1021+0>0 AND field_1022+0>0 AND field_1023+0>0 AND field_1024+0>0 AND field_1024+0<=80 ORDER BY id LIMIT 1")->fetch_row();
    if(!$paper)throw new RuntimeException('No complete paper available for conversion fixtures.');
    $customer=$customers[]=vdFixture(26,['field_221'=>'ISOLATED CONVERSION CUSTOMER','field_224'=>'176']);
    $contact=$contacts[]=vdFixture(90,['parent_item_id'=>$customer,'field_918'=>'ISOLATED CONVERSION CONTACT']);
    $payload=['type'=>'standard','product_type'=>3824,'quantities'=>[250,500],
        'request_details'=>['contact_id'=>$contact,'contact'=>'ISOLATED CONVERSION CONTACT','delivery'=>'2027-12-10','dispatch'=>['carrier']],
        'components'=>[['id'=>1,'name'=>'Cartaz','kind'=>'printed','units_per_product'=>1,'inputs'=>[],
            'papers'=>[(int)$paper[0]],'colors'=>[3732],'rubrics'=>[],'fields'=>['4218'=>'210','4219'=>'297','4229'=>'1255','4220'=>1565,'6169'=>'true','6170'=>'true'],
            'request'=>['paper'=>'Papel solicitado, sem escolha de artigo','printing'=>['mode'=>'pantone','pantone'=>'186 C, 871 C']]]]];
    if($staged) {
        $payload['request_details']['workflow_version']=2;
        $payload['request_details']['finishing']='Colocar biadesivo nos quatro cantos.';
        $payload['components'][0]['request']['finishing_operations']=[
            ['id'=>42,'name'=>'Aplicação de BiAdesivo','tape'=>['quantity'=>4,'x_mm'=>'20','y_mm'=>'20']]];
    }
    if($incomplete)$payload['quantities']=[];
    $state=$service->submit(0,0,['name'=>'ISOLATED CONVERSION FIXTURE','customer'=>$customer,'reference'=>'REFERENCE-FIXTURE','notes'=>'REQUISITO-SEM-PREÇO'],
        $payload,'conversion-'.bin2hex(random_bytes(16)),[['name'=>'requisitos.pdf','content'=>"%PDF-1.4\nVD conversion fixture\n%%EOF"]]);
    $ids[]=$state['id'];
    return $state;
}
function vdConversionCleanup($id) {
    global $db,$store,$schema;
    $state=(new VDProductConfigurator($db,['id'=>1,'group_id'=>0]))->state($id);
    if($state['name']!=='ISOLATED CONVERSION FIXTURE')throw new RuntimeException('Unsafe conversion fixture cleanup.');
    foreach($store->quotes($id) as $source) {
        $quote=(int)$source['quote_id'];
        $row=$db->query("SELECT field_3927,field_3929 FROM app_entity_146 WHERE id=$quote")->fetch_assoc();
        if($row['field_3927']!==$state['name'])throw new RuntimeException('Unsafe quote fixture cleanup.');
        foreach(array_filter(explode(',',$row['field_3929'])) as $name) {
            $path=$store->filePath($name);
            if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot remove copied fixture attachment.');
        }
        foreach([276,279,388,280,278,277] as $entity)$db->query("DELETE n FROM app_entity_$entity n JOIN app_entity_262 c ON c.id=n.parent_item_id WHERE c.parent_item_id=$quote");
        foreach([261,262] as $entity)$db->query("DELETE FROM app_entity_$entity WHERE parent_item_id=$quote");
        foreach(['app_vd_quote_products','app_vd_quote_product_components','app_vd_quote_audit'] as $table)$db->query("DELETE FROM $table WHERE quote_id=$quote");
        $db->query("DELETE FROM app_entity_146 WHERE id=$quote");
    }
    foreach($store->attachments($id) as $document)$store->removeDocument($id,$document['id']);
    $store->finishFiles(true);
    foreach(['part','quantity','finish','submission','piece','rubric'] as $prefix)$store->clear($prefix,$id);
    $db->query('DELETE FROM app_entity_'.$schema['config.entity'].' WHERE id='.(int)$id);
    $customer=(int)$state['customer'];
    $row=$db->query("SELECT field_221 FROM app_entity_26 WHERE id=$customer")->fetch_assoc();
    if($row['field_221']!=='ISOLATED CONVERSION CUSTOMER')throw new RuntimeException('Unsafe customer fixture cleanup.');
    $db->query("DELETE FROM app_entity_90 WHERE parent_item_id=$customer AND field_918='ISOLATED CONVERSION CONTACT'");
    $db->query("DELETE FROM app_entity_26 WHERE id=$customer");
}
function vdConversionPreparationFixture($matchingPaper=false) {
    global $service;
    $state=vdConversionFixture(false,true);$payload=$state['payload'];
    $payload['components'][0]['papers']=[];
    $payload['components'][0]['request']['die']=['status'=>'none','number'=>''];
    if($matchingPaper)$payload['components'][0]['request']['paper']='Invercote Duo 450 gr';
    $state=$service->submit($state['id'],$state['revision'],['name'=>$state['name'],'customer'=>$state['customer'],'reference'=>'PREPARATION','notes'=>'Apenas observações do cliente'],
        $payload,'preparation-'.bin2hex(random_bytes(16)));
    // Historical requests could be saved before print faces became mandatory.
    $payload['components'][0]['fields']['4229']='';
    return $service->save($state['id'],$state['revision'],$payload);
}
if(in_array($argv[1]??'',['--browser','--browser-incomplete','--browser-preparation','--browser-extraction'],true)) {
    $state=in_array($argv[1],['--browser-preparation','--browser-extraction'],true)?
        vdConversionPreparationFixture($argv[1]==='--browser-extraction'):vdConversionFixture($argv[1]==='--browser-incomplete');
    echo json_encode(['id'=>$state['id'],'schema'=>array_filter($schema,static function($key){return strpos($key,'conversion.')===0||in_array($key,['config.status','config.quote','config.convert_button'],true);},ARRAY_FILTER_USE_KEY)],JSON_THROW_ON_ERROR),"\n";exit;
}
if(($argv[1]??'')==='--cleanup-browser') {
    foreach(array_slice($argv,2) as $id)vdConversionCleanup((int)$id);
    echo "Conversion browser fixtures removed.\n";exit;
}
if(($argv[1]??'')==='--verify-browser') {
    foreach(array_slice($argv,2) as $value) {
        $id=(int)$value;$state=$service->state($id);
        vdCheck($state['name']==='ISOLATED CONVERSION FIXTURE');
        vdCheck(count($state['quotes'])===1);
        $quote=(int)$state['quotes'][0]['quote_id'];
        vdCheck($state['conversion']===['status'=>$schema['conversion.converted'],'quote'=>$quote,'error'=>'']);
        $source=$store->source($quote);
        vdCheck(hash_equals($source['hash'],hash('sha256',$source['payload'])));
        $snapshot=json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR);
        vdCheck($snapshot['payload']===$state['payload']);
        $header=$db->query("SELECT * FROM app_entity_146 WHERE id=$quote")->fetch_assoc();
        vdCheck((int)$header['field_2030']===36&&$header['field_6271']==='');
        vdCheck((int)$db->query("SELECT COUNT(*) FROM app_vd_quote_versions WHERE quote_id=$quote")->fetch_row()[0]===0);
        vdCheck((int)$db->query("SELECT COUNT(*) FROM app_vd_quote_production_orders WHERE quote_id=$quote")->fetch_row()[0]===0);
        vdCheck(file_get_contents($store->filePath($header['field_3929']))===$service->document($id,$state['attachments'][0]['id'])['content']);
        vdCheck((int)$db->query("SELECT COUNT(*) FROM app_entity_261 WHERE parent_item_id=$quote")->fetch_row()[0]===2);
    }
    echo "$checks persisted browser conversion checks passed.\n";exit;
}
if(($argv[1]??'')==='--convert') {
    $id=(int)($argv[2]??0);
    if($service->state($id)['name']!=='ISOLATED CONVERSION FIXTURE')throw new RuntimeException('Not a conversion fixture.');
    echo json_encode(vd_config_request_to_draft($db,$user,$id),JSON_THROW_ON_ERROR),"\n";exit;
}
try {
    $counts=$db->query('SELECT (SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_121) orders,(SELECT COUNT(*) FROM app_entity_170) operations')->fetch_assoc();
    $state=vdConversionFixture();$id=$state['id'];
    vdCheck(isset(vd_config_request_to_draft($db,$user,$id,true)['skipped']));
    vdCheck($counts===$db->query('SELECT (SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_121) orders,(SELECT COUNT(*) FROM app_entity_170) operations')->fetch_assoc());
    users::$allowed=false;
    vdReject(function()use($db,$user,$id){vd_config_request_to_draft($db,$user,$id);});users::$allowed=true;
    users::$deniedCreates=[261];
    vdReject(function()use($db,$user,$id){vd_config_request_to_draft($db,$user,$id);});users::$deniedCreates=[];
    vdCheck($counts===$db->query('SELECT (SELECT COUNT(*) FROM app_entity_146) quotes,(SELECT COUNT(*) FROM app_entity_121) orders,(SELECT COUNT(*) FROM app_entity_170) operations')->fetch_assoc());
    vdCheck($store->quotes($id)===[]);
    $result=vd_config_request_to_draft($db,$user,$id);$quote=$result['quote'];
    vdCheck(!$result['already_created']);
    vdCheck(vd_config_request_to_draft($db,$user,$id)['already_created']);
    vdCheck(vd_config_request_to_draft($db,$user,$id,true)['quote']===$quote);
    vdCheck(count($store->quotes($id))===1);
    $header=$db->query("SELECT * FROM app_entity_146 WHERE id=$quote")->fetch_assoc();
    vdCheck((int)$header['field_2030']===36);
    vdCheck($header['field_6271']==='');
    vdCheck((int)$header['field_3924']===$state['payload']['request_details']['contact_id']);
    vdCheck($header['field_3930']==='3800,3824');
    vdCheck($header['field_3928']==='REQUISITO-SEM-PREÇO');
    $summary=vd_config_request_summary($state,$service->catalog()['choices']);
    foreach(['REFERENCE-FIXTURE','ISOLATED CONVERSION CONTACT','2027-12-10','Transportador','186 C, 871 C','Papel solicitado','REQUISITO-SEM-PREÇO'] as $text)
        vdCheck(strpos($summary,$text)!==false);
    vdCheck(array_map('intval',array_column($db->query("SELECT field_3938 FROM app_entity_261 WHERE parent_item_id=$quote ORDER BY id")->fetch_all(MYSQLI_ASSOC),'field_3938'))===[250,500]);
    $component=$db->query("SELECT * FROM app_entity_262 WHERE parent_item_id=$quote")->fetch_assoc();$componentId=(int)$component['id'];
    vdCheck((float)$component['field_4218']===210.0&&(float)$component['field_4219']===297.0&&(int)$component['field_4220']===1565);
    vdCheck((int)$db->query("SELECT field_4251 FROM app_entity_278 WHERE parent_item_id=$componentId")->fetch_row()[0]===3732);
    vdCheck(array_column($db->query("SELECT field_4253 FROM app_entity_278 WHERE parent_item_id=$componentId ORDER BY id")->fetch_all(MYSQLI_ASSOC),'field_4253')===['186 C','871 C']);
    vdCheck($component['field_4242']==='REFERENCE-FIXTURE');
    vdCheck(strpos($header['field_4417'],'2027-12-10')!==false&&strpos($header['field_4417'],'Transportadora')!==false);
    vdCheck((int)$db->query("SELECT COUNT(*) FROM app_vd_quote_versions WHERE quote_id=$quote")->fetch_row()[0]===0);
    vdCheck((int)$db->query("SELECT COUNT(*) FROM app_entity_276 WHERE parent_item_id=$componentId")->fetch_row()[0]===0);
    $source=$store->source($quote);vdCheck((int)$source['revision']===$state['revision']);
    vdCheck(json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR)['payload']===$state['payload']);
    vdImmutable('UPDATE app_entity_146 SET field_'.$schema['quote.revision']."=999 WHERE id=$quote");
    $copy=$store->filePath($header['field_3929']);$original=$service->document($id,$state['attachments'][0]['id']);
    vdCheck(file_get_contents($copy)===$original['content']);
    $store->removeDocument($id,$state['attachments'][0]['id']);$store->finishFiles(true);
    vdCheck(file_get_contents($copy)===$original['content']);
    $record=$store->rows('SELECT * FROM app_entity_'.$schema['config.entity'].' WHERE id=?',[$id])[0];
    vdCheck((int)$record[$store->field('config','status')]===$schema['conversion.converted']);
    vdCheck((int)$record[$store->field('config','quote')]===$quote);
    $store->update('config',$id,['quote'=>0,'status'=>$schema['conversion.ready']]);
    vdCheck(vd_config_request_to_draft($db,$user,$id,true)['quote']===$quote);
    $payload=$state['payload'];$payload['components'][0]['request']['paper']='PEDIDO-ALTERADO';
    $service->submit($id,$state['revision'],['name'=>$state['name'],'customer'=>$state['customer'],'reference'=>'changed','notes'=>'changed'],
        $payload,'conversion-'.bin2hex(random_bytes(16)));
    vdCheck($source===$store->source($quote));
    vdCheck(vd_config_request_to_draft($db,$user,$id)['quote']===$quote);
    $second=vdConversionFixture();$secondId=$second['id'];
    $store->update('config',$secondId,['quote'=>$quote]);
    vdReject(function()use($db,$user,$secondId){vd_config_request_to_draft($db,$user,$secondId);});
    $store->update('config',$secondId,['quote'=>0,'status'=>$schema['conversion.ready']]);
    $automatic=vd_config_request_to_draft($db,$user,$secondId,true);
    vdCheck(!$automatic['already_created']&&$automatic['quote']!==$quote);
    vdCheck(vd_config_request_to_draft($db,$user,$secondId,true)['quote']===$automatic['quote']);
    $incomplete=vdConversionFixture(true);$incompleteId=$incomplete['id'];
    vdReject(function()use($db,$user,$incompleteId){vd_config_request_to_draft($db,$user,$incompleteId);});
    vdCheck($store->quotes($incompleteId)===[]);
    $summaryState=$second;$summaryState['payload']['components'][0]['request']['finishing_operations']=[
        ['id'=>36,'name'=>'Colocar Fio','thread'=>['type'=>'Algodão','color'=>'Branco','length_mm'=>'300.5']]];
    vdCheck(strpos(vd_config_request_summary($summaryState),'tipo: Algodão; cor: Branco; comprimento: 300.5 mm')!==false);
    $tapeState=vdConversionFixture();$tapeId=$tapeState['id'];$tapePayload=$tapeState['payload'];
    $tapePayload['components'][0]['request']['finishing_operations']=[
        ['id'=>42,'name'=>'Aplicação de BiAdesivo','tape'=>['quantity'=>2,'x_mm'=>'120.5','y_mm'=>'10']]];
    vdCheck($service->finishingReview($tapePayload,$tapeId)['errors']===[]);
    foreach([['quantity'=>0],['quantity'=>1.5],['x_mm'=>0],['y_mm'=>-1],['x_mm'=>'NaN']] as $invalid) {
        $bad=$tapePayload;$bad['components'][0]['request']['finishing_operations'][0]['tape']=array_replace($bad['components'][0]['request']['finishing_operations'][0]['tape'],$invalid);
        vdCheck(count($service->finishingReview($bad,$tapeId)['errors'])>0);
    }
    $tapeState=$service->submit($tapeId,$tapeState['revision'],['name'=>$tapeState['name'],'customer'=>$tapeState['customer'],'reference'=>'tape','notes'=>''],
        $tapePayload,'tape-'.bin2hex(random_bytes(16)));
    vdCheck($tapeState['payload']===$tapePayload);
    $finish=$store->children('finish',$tapeId)[0];
    vdCheck($finish[$store->field('finish','tape_quantity')]==='2'&&$finish[$store->field('finish','tape_x')]==='120.5'&&$finish[$store->field('finish','tape_y')]==='10');
    $tapeQuote=vd_config_request_to_draft($db,$user,$tapeId)['quote'];
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$tapeQuote")->fetch_row()[0]==='');
    $notes=vd_config_request_summary($tapeState,$service->catalog()['choices']);
    vdCheck(strpos($notes,'2 tira(s) por exemplar; X: 120.5 mm; Y: 10 mm')!==false);
    $rubricState=vdConversionFixture();$rubricId=$rubricState['id'];$rubricPayload=$rubricState['payload'];
    $rubricPayload['product_type']=3708;$rubricPayload['quantities']=[350];
    $rubricPayload['rubrics']=[['quantity'=>100,'description'=>'Ana Silva','code'=>'A1'],['quantity'=>250,'description'=>'Tamanho M','code'=>'']];
    $wrongTotal=$rubricPayload;$wrongTotal['quantities']=[250,500];
    vdReject(function()use($service,$rubricId,$rubricState,$wrongTotal){$service->submit($rubricId,$rubricState['revision'],['name'=>$rubricState['name'],'customer'=>$rubricState['customer'],'reference'=>'','notes'=>''],
        $wrongTotal,'rubric-'.bin2hex(random_bytes(16)));});
    $rubricState=$service->submit($rubricId,$rubricState['revision'],['name'=>$rubricState['name'],'customer'=>$rubricState['customer'],'reference'=>'rubricas','notes'=>''],
        $rubricPayload,'rubric-'.bin2hex(random_bytes(16)));
    vdCheck($rubricState['payload']['rubrics']===$rubricPayload['rubrics']&&count($store->children('rubric',$rubricId))===2);
    vdCheck(strpos(vd_config_request_summary($rubricState),"100 · Ana Silva · A1\n250 · Tamanho M\nTotal: 350")!==false);
    $rubricQuote=vd_config_request_to_draft($db,$user,$rubricId)['quote'];
    vdCheck(array_map('intval',array_column($db->query("SELECT field_3938 FROM app_entity_261 WHERE parent_item_id=$rubricQuote")->fetch_all(MYSQLI_ASSOC),'field_3938'))===[350]);
    vdCheck($db->query("SELECT r.field_4320 quantity,r.field_4321 name,r.field_4322 code FROM app_entity_280 r JOIN app_entity_262 c ON c.id=r.parent_item_id WHERE c.parent_item_id=$rubricQuote ORDER BY r.id")->fetch_all(MYSQLI_ASSOC)===
        [['quantity'=>'100','name'=>'Ana Silva','code'=>'A1'],['quantity'=>'250','name'=>'Tamanho M','code'=>'']]);
    $documentState=vdConversionFixture();$documentId=$documentState['id'];$document=$documentState['payload'];
    $document['product_type']=3753;$document['quantities']=[1,10,20];
    $book=['start'=>'000001','sets_per_book'=>50,'copies'=>3,'paper_colors'=>['Branco','Amarelo','Rosa'],'binding'=>'glued'];
    $document['request_details']['document_book']=$book;
    vdCheck(vd_config_book_numbering($book,[1,10,20])===[1=>'000050',10=>'000500',20=>'001000']);
    $variants=$book;$variants['sets_per_book']=100;$variants['copies']=2;$variants['paper_colors']=['Branco','Amarelo'];
    vdCheck(vd_config_book_numbering($variants,[10])[10]==='001000');
    $variants=$book;$variants['copies']=4;$variants['paper_colors'][]='Azul';
    vdCheck(vd_config_book_numbering($variants,[10])[10]==='000500');
    foreach([['start'=>'-1'],['start'=>'1.5'],['sets_per_book'=>0],['copies'=>2],['binding'=>'both'],['paper_colors'=>['Branco','','Rosa']],['start'=>'999999999999']] as $invalid)
        vdReject(function()use($book,$invalid){vd_config_book_numbering(array_replace($book,$invalid),[10]);});
    $types=$service->catalog()['product_types'];
    foreach($types as $type)if((int)$type['parent_id']===3752)vdCheck(vd_config_document_product($type['id'],$types));
    vdCheck(!vd_config_document_product(3824,$types));
    $bad=$document;unset($bad['request_details']['document_book']);
    vdReject(function()use($bad,$types){vd_config_prepare_book($bad,$types);});
    $documentState=$service->submit($documentId,$documentState['revision'],['name'=>$documentState['name'],'customer'=>$documentState['customer'],'reference'=>'documents','notes'=>''],
        $document,'documents-'.bin2hex(random_bytes(16)));
    vdCheck($documentState['payload']['components'][0]['request']['paper']==='Autocopiativo');
    vdCheck($documentState['payload']['request_details']['document_book']===$book+['unit'=>'livro']);
    $rows=$store->children('quantity',$documentId);
    vdCheck(array_column($rows,$store->field('quantity','number_end'))===['000050','000500','001000']);
    $documentQuote=vd_config_request_to_draft($db,$user,$documentId)['quote'];
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$documentQuote")->fetch_row()[0]==='');
    $notes=vd_config_request_summary($documentState,$service->catalog()['choices']);
    foreach(['1 livro autocopiativo','50 conjuntos × 3 vias (150 folhas)','10 livro(s): número de fim 000500','via 1: Branco','via 2: Amarelo','via 3: Rosa','Acabamento do livro: Colado'] as $text)vdCheck(strpos($notes,$text)!==false);
    vdCheck(json_decode($store->source($documentQuote)['payload'],true,512,JSON_THROW_ON_ERROR)['payload']===$documentState['payload']);
    $boxState=vdConversionFixture();$boxId=$boxState['id'];$box=$boxState['payload'];
    $box['product_type']=$schema['config.corrugated_type'];
    $box['request_details']['box']=['length_mm'=>'300','width_mm'=>'200','height_mm'=>'150','measurement'=>'internal'];
    $box['components'][0]['colors']=[3804];
    $box['components'][0]['fields']=['4218'=>'300','4219'=>'200','4229'=>'','4220'=>1565,'6169'=>'true','6170'=>'true'];
    $box['components'][0]['request']=['paper'=>'Cartão canelado','printing'=>['mode'=>'none','pantone'=>''],
        'corrugated'=>['thickness_mm'=>'3.5']];
    foreach(['thickness_mm'] as $key)foreach(['',0,-1,'NaN'] as $invalid) {
        $bad=$box;$bad['components'][0]['request']['corrugated'][$key]=$invalid;
        vdReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
    }
    foreach(['length_mm','width_mm','height_mm'] as $key)foreach(['',0,-1,'NaN'] as $invalid) {
        $bad=$box;$bad['request_details']['box'][$key]=$invalid;
        vdReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
    }
    $bad=$box;$bad['components'][0]['colors']=[3731];$bad['components'][0]['request']['printing']['mode']='black';
    vdReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
    $bad=$box;$bad['request_details']['box']['measurement']='unknown';
    vdReject(function()use($bad,$schema){vd_config_prepare_packaging($bad,$schema);});
    $ordinary=$box;$ordinary['product_type']=3751;unset($ordinary['components'][0]['request']['corrugated']);
    $ordinary['components'][0]['fields']['4229']='1255';
    $ordinary['components'][0]['colors']=[3732];$ordinary['components'][0]['request']['printing']=['mode'=>'pantone','pantone'=>'186 C'];
    $ordinary['request_details']['box']['measurement']='external';
    vdCheck(vd_config_prepare_packaging($ordinary,$schema)===$ordinary);
    $boxState=$service->submit($boxId,$boxState['revision'],['name'=>$boxState['name'],'customer'=>$boxState['customer'],'reference'=>'corrugated','notes'=>''],
        $box,'corrugated-'.bin2hex(random_bytes(16)));
    vdCheck($boxState['payload']===$box);
    $part=$store->children('part',$boxId)[0];
    vdCheck($part[$store->field('part','corrugated_thickness')]==='3.5');
    $record=$store->rows('SELECT * FROM app_entity_'.$schema['config.entity'].' WHERE id=?',[$boxId])[0];
    vdCheck($record[$store->field('config','box_height')]==='150'&&$record[$store->field('config','box_measurement')]==='Interiores');
    $boxQuote=vd_config_request_to_draft($db,$user,$boxId)['quote'];
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$boxQuote")->fetch_row()[0]==='');
    $notes=vd_config_request_summary($boxState,$service->catalog()['choices']);
    foreach(['300 × 200 × 150 mm','Espessura do cartão canelado: 3.5 mm','Sem impressão'] as $text)vdCheck(strpos($notes,$text)!==false);
    vdCheck(json_decode($store->source($boxQuote)['payload'],true,512,JSON_THROW_ON_ERROR)['payload']===$box);
    $ordinaryState=vdConversionFixture();$ordinaryId=$ordinaryState['id'];
    $ordinary['request_details']['contact_id']=$ordinaryState['payload']['request_details']['contact_id'];
    $ordinaryState=$service->submit($ordinaryId,$ordinaryState['revision'],['name'=>$ordinaryState['name'],'customer'=>$ordinaryState['customer'],'reference'=>'box','notes'=>''],
        $ordinary,'box-'.bin2hex(random_bytes(16)));
    vdCheck($ordinaryState['payload']===$ordinary);
    $ordinaryQuote=vd_config_request_to_draft($db,$user,$ordinaryId)['quote'];
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$ordinaryQuote")->fetch_row()[0]==='');
    $notes=vd_config_request_summary($ordinaryState,$service->catalog()['choices']);
    vdCheck(strpos($notes,'300 × 200 × 150 mm')!==false&&strpos($notes,'exteriores')!==false);
    $simple=vdConversionFixture(false,true);
    $simpleQuote=vd_config_request_to_draft($db,$user,$simple['id'])['quote'];
    $parts=$db->query("SELECT * FROM app_entity_262 WHERE parent_item_id=$simpleQuote")->fetch_all(MYSQLI_ASSOC);
    vdCheck(count($parts)===1);
    vdCheck($parts[0]['field_4225']===$simple['name']);
    vdCheck($parts[0]['field_6291']==='42,9,10');
    $metadata=vd_product_stage_read($db,(int)$parts[0]['id']);
    vdCheck(array_column($metadata['requirements']['operations'],'id')===[42,9,10]);
    vdCheck($metadata['requirements']['details']['finishing_operations'][0]['tape']===['quantity'=>4,'x_mm'=>'20','y_mm'=>'20']);
    vdCheck($metadata['requirements']['product_details']['finishing']==='Colocar biadesivo nos quatro cantos.');
    vdCheck(json_decode($store->source($simpleQuote)['payload'],true)['payload']===$simple['payload']);
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$simpleQuote")->fetch_row()[0]==='REQUISITO-SEM-PREÇO');
    vdCheck(users::$calls===0&&clicksend::$calls===0);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_121')->fetch_row()[0]===(int)$counts['orders']);
    vdCheck((int)$db->query('SELECT COUNT(*) FROM app_entity_170')->fetch_row()[0]===(int)$counts['operations']);
    require_once __DIR__.'/product-quote-preparation.php';
    $pending=vdConversionPreparationFixture();$pendingId=$pending['id'];
    $before=vd_config_native_json($pending);
    $manual=vd_config_conversion_process($pendingId,$user,false);
    vdCheck($manual['preparation_required']&&$manual['quote']===0);
    vdCheck($app_redirect_to==='item_info_page'.$schema['config.entity'].'-'.$pendingId.'&prepare_quote=1');
    $model=vd_config_quote_preparation_state($service,$pendingId);
    vdCheck(array_column($model['fields'],'key')===['1.field.4229','1.paper']);
    vdReject(function()use($db,$user,$pendingId){vd_config_request_to_draft($db,$user,$pendingId);});
    vdCheck($store->quotes($pendingId)===[]);
    $store->update('config',$pendingId,['status'=>$schema['conversion.ready']]);
    vdReject(function()use($db,$user,$pendingId){vd_config_request_to_draft($db,$user,$pendingId,true);});
    $store->update('config',$pendingId,['status'=>$schema['conversion.preparing']]);
    vdCheck(vd_config_native_json($service->state($pendingId))===$before);
    $paperRow=$db->query("SELECT p.id,p.field_3632 internal_id FROM app_entity_99 p WHERE FIND_IN_SET('190',p.field_1019)>0 AND p.field_3632+0>0 AND p.field_1021+0>0 AND p.field_1022+0>0 AND p.field_1023+0>0 AND COALESCE(p.field_1024+0,0)=0 AND NOT EXISTS(SELECT 1 FROM app_entity_99 s WHERE s.field_3632=p.field_3632 AND s.field_1023+0=p.field_1023+0 AND FIND_IN_SET('190',s.field_1019)>0 AND s.field_1024+0>0 AND s.field_1024+0<=80) ORDER BY p.id LIMIT 1")->fetch_assoc();
    if(!$paperRow)throw new RuntimeException('No paper with missing thickness for preparation fixture.');
    $answers=['1.field.4229'=>'1256','1.paper'=>$paperRow['id']];
    $model=vd_config_quote_preparation_state($service,$pendingId,$answers,$pending['revision']);
    vdCheck($model['missing']===1);
    $answers['1.thickness.'.$paperRow['id']]='0.45';
    vdCheck(vd_config_quote_preparation_state($service,$pendingId,$answers,$pending['revision'])['missing']===0);
    vdReject(function()use($db,$user,$pendingId,$answers,$pending){vd_config_request_to_draft($db,$user,$pendingId,false,$answers,$pending['revision']-1);});
    $bad=$answers;$bad['1.field.4229']=999;
    vdReject(function()use($db,$user,$pendingId,$bad,$pending){vd_config_request_to_draft($db,$user,$pendingId,false,$bad,$pending['revision']);});
    vdCheck($store->quotes($pendingId)===[]);
    $prepared=vd_config_request_to_draft($db,$user,$pendingId,false,$answers,$pending['revision']);$preparedQuote=$prepared['quote'];
    vdCheck(vd_config_request_to_draft($db,$user,$pendingId,false,$answers,$pending['revision'])['quote']===$preparedQuote);
    $part=$db->query("SELECT * FROM app_entity_262 WHERE parent_item_id=$preparedQuote")->fetch_assoc();$partId=(int)$part['id'];
    vdCheck((int)$part['field_4229']===1256&&(float)$part['field_6119']>0);
    $material=$db->query("SELECT field_4278,field_4280 FROM app_entity_277 WHERE parent_item_id=$partId")->fetch_assoc();
    vdCheck((int)$material['field_4278']===(int)$paperRow['id']&&(int)$material['field_4280']===(int)$paperRow['internal_id']);
    $tape=$db->query("SELECT field_6254,field_6255,field_6256 FROM app_entity_276 WHERE parent_item_id=$partId AND field_4287=42")->fetch_assoc();
    vdCheck((int)$tape['field_6254']===4&&(float)$tape['field_6255']===20.0&&(float)$tape['field_6256']===20.0);
    vdCheck((float)$db->query("SELECT field_6257 FROM app_entity_276 WHERE parent_item_id=$partId AND field_4287=42")->fetch_row()[0]===30.0);
    vdCheck((int)$db->query("SELECT COUNT(*) FROM app_entity_276 WHERE parent_item_id=$partId AND field_4287 IN(9,10)")->fetch_row()[0]===0);
    $stage=vd_product_stage_read($db,$partId);
    vdCheck($stage['requirements']['details']['quote_preparation']['paper_thickness'][$paperRow['id']]===0.45);
    $simulation=['workflow_component'=>[['parent_item_id'=>$preparedQuote]],'costing_catalog'=>[],
        'papers'=>[['id'=>$paperRow['id'],'thickness'=>'']]];
    vd_product_stage_simulation($db,$simulation,$partId);
    vdCheck($simulation['papers'][0]['thickness']===0.45);
    vdCheck(json_decode($store->source($preparedQuote)['payload'],true,512,JSON_THROW_ON_ERROR)['payload']===$pending['payload']);
    vdCheck($service->state($pendingId)['payload']===$pending['payload']);
    vdCheck($db->query("SELECT field_3928 FROM app_entity_146 WHERE id=$preparedQuote")->fetch_row()[0]==='Apenas observações do cliente');
    vdCheck((int)$db->query("SELECT COUNT(*) FROM app_vd_quote_versions WHERE quote_id=$preparedQuote")->fetch_row()[0]===0);
    $matched=vdConversionPreparationFixture(true);$matchedId=$matched['id'];
    $model=vd_config_quote_preparation_state($service,$matchedId);
    vdCheck(array_column($model['fields'],'key')===['1.field.4229']);
    vdCheck(count($model['resolved'])===1);
    $matchedResult=vd_config_request_to_draft($db,$user,$matchedId,false,['1.field.4229'=>'1255'],$matched['revision']);
    $matchedQuote=$matchedResult['quote'];
    $matchedPart=$db->query("SELECT id FROM app_entity_262 WHERE parent_item_id=$matchedQuote")->fetch_row()[0];
    vdCheck((int)$db->query("SELECT field_4278 FROM app_entity_277 WHERE parent_item_id=$matchedPart")->fetch_row()[0]===2652);
    vdCheck(json_decode($store->source($matchedQuote)['payload'],true,512,JSON_THROW_ON_ERROR)['payload']===$matched['payload']);
    vdCheck($service->state($matchedId)['payload']['components'][0]['papers']===[]);
    echo "$checks native draft conversion and preparation checks passed.\n";
}finally {
    users::$allowed=true;users::$deniedCreates=[];
    foreach($ids as $id)vdConversionCleanup($id);
}

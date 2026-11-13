<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');$db->begin_transaction();
try {
    $schema=vd_config_schema($db);$store=new VDConfigNativeStore($db,$schema);$source=$store->source(277);
    $quote=$db->query('SELECT * FROM app_entity_146 WHERE id=277 FOR UPDATE')->fetch_assoc();
    if(!$source||!$quote||(int)$source['config_id']!==151||(int)$quote['field_'.$schema['quote.config']]!==151||
        !hash_equals($source['hash'],hash('sha256',$source['payload'])))
        throw new RuntimeException('Origem do rascunho 277 inválida.');
    foreach(['app_vd_quote_versions','app_vd_quote_documents','app_vd_quote_production_orders'] as $table)
        if($db->query("SELECT quote_id FROM $table WHERE quote_id=277 LIMIT 1")->num_rows)
            throw new RuntimeException('O rascunho já tem versões/documentos; não alterar componentes automaticamente.');
    if((int)$quote['field_2030']!==36)throw new RuntimeException('O orçamento já não está em rascunho.');
    $original=json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR);
    $payload=vd_config_quote_payload($original['payload'],$original['name']);
    if(count($payload['components'])!==1)throw new RuntimeException('A origem não corresponde a um produto simples.');
    $part=$payload['components'][0];
    $rows=$db->query('SELECT c.id,c.field_4225 name,c.field_'.$schema['quote_part.stage'].' stage,m.kind,m.inputs FROM app_entity_262 c JOIN app_vd_quote_product_components m ON m.component_id=c.id AND m.quote_id=277 WHERE c.parent_item_id=277 ORDER BY c.id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    if(count($rows)===1&&$rows[0]['name']===$original['name']) {
        $db->commit();echo "O rascunho simples já tem um componente com o título do pedido.\n";exit;
    }
    if(count($rows)!==3||array_column($rows,'stage')!==['component','assembly','dispatch']||
        array_column($rows,'kind')!==['printed','assembly','assembly'])
        throw new RuntimeException('A estrutura do rascunho foi alterada; rever antes de consolidar.');
    $keep=(int)$rows[0]['id'];$remove=[(int)$rows[1]['id'],(int)$rows[2]['id']];
    foreach($db->query('SELECT id FROM app_entities WHERE parent_id=262')->fetch_all(MYSQLI_ASSOC) as $entity) {
        $table='app_entity_'.(int)$entity['id'];
        if($db->query("SELECT id FROM $table WHERE parent_item_id IN(".implode(',',$remove).') LIMIT 1')->num_rows)
            throw new RuntimeException('Uma etapa técnica já tem dados; não remover.');
    }
    $name=$original['name'];$extras=$part['fields']['6291'];
    $stmt=$db->prepare('UPDATE app_entity_262 SET field_4225=?,field_6291=? WHERE id=? AND parent_item_id=277');
    $stmt->bind_param('ssi',$name,$extras,$keep);$stmt->execute();
    vd_product_stage_write($db,262,'quote_part',$keep,['stage'=>'component','requirements'=>[
        'operations'=>$part['request']['finishing_operations'],'details'=>$part['request'],'role'=>$part['role']??'',
        'final_format'=>$payload['request_details']['final_format']??[],
        'product_details'=>array_intersect_key($payload['request_details'],array_flip(['total_pages','box','finishing']))]]);
    $db->query('DELETE FROM app_vd_quote_product_components WHERE quote_id=277 AND component_id IN('.implode(',',$remove).')');
    $db->query('DELETE FROM app_entity_262 WHERE parent_item_id=277 AND id IN('.implode(',',$remove).')');
    $db->commit();
    echo "Rascunho 277 consolidado num componente: $keep. Título herdado; operações e instruções preservadas. Origem imutável intacta.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

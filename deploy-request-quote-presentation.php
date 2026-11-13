<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->begin_transaction();
try {
    $schema=vd_config_schema($db);$field=(int)$schema['quote.snapshot'];
    $rows=$db->query("SELECT id,configuration_value FROM app_entities_configuration WHERE entities_id=146 AND configuration_name='item_page_hidden_fields' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    if(count($rows)>1)throw new RuntimeException('Configuração de visibilidade duplicada.');
    $hidden=$rows?array_filter(array_map('intval',explode(',',$rows[0]['configuration_value']))):[];
    $hidden[]=$field;$value=implode(',',array_unique($hidden));
    if($rows) {
        $stmt=$db->prepare('UPDATE app_entities_configuration SET configuration_value=? WHERE id=?');
        $id=(int)$rows[0]['id'];$stmt->bind_param('si',$value,$id);
    }else{
        $stmt=$db->prepare("INSERT INTO app_entities_configuration(entities_id,configuration_name,configuration_value) VALUES(146,'item_page_hidden_fields',?)");
        $stmt->bind_param('s',$value);
    }
    $stmt->execute();
    if(($argv[1]??'')==='--repair-277') {
        $store=new VDConfigNativeStore($db,$schema);$source=$store->source(277);
        $quote=$db->query('SELECT field_3928 notes,field_2030 status,field_'.(int)$schema['quote.config'].' config FROM app_entity_146 WHERE id=277 FOR UPDATE')->fetch_assoc();
        if(!$source||!$quote||(int)$source['config_id']!==151||(int)$quote['config']!==151||
            !hash_equals($source['hash'],hash('sha256',$source['payload'])))
            throw new RuntimeException('Origem do rascunho 277 inválida.');
        if((int)$quote['status']!==36||
            $db->query('SELECT id FROM app_vd_quote_documents WHERE quote_id=277 LIMIT 1')->num_rows||
            $db->query('SELECT quote_id FROM app_vd_quote_production_orders WHERE quote_id=277 LIMIT 1')->num_rows)
            throw new RuntimeException('O orçamento já avançou; não alterar as observações automaticamente.');
        $original=json_decode($source['payload'],true,512,JSON_THROW_ON_ERROR);
        $notes=(string)$original['notes'];
        if($quote['notes']!==$notes) {
            $prefix='Pedido do configurador #151 — revisão '.(int)$original['revision'];
            if(strncmp($quote['notes'],$prefix,strlen($prefix))!==0)
                throw new RuntimeException('Observações editadas; não substituir.');
            $stmt=$db->prepare('UPDATE app_entity_146 SET field_3928=? WHERE id=277');
            $stmt->bind_param('s',$notes);$stmt->execute();
        }
        echo "Rascunho 277: observações repostas a partir do campo Observações original. Snapshot preservado.\n";
    }
    $db->commit();
    echo "Configuração fixada na origem ocultada pela configuração nativa da ficha, sem apagar dados.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

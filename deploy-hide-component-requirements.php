<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');$db->begin_transaction();
try {
    $schema=vd_config_schema($db);
    if(empty($schema['quote_part.requirements']))throw new RuntimeException('Campo nativo de requisitos indisponível.');
    $field=(int)$schema['quote_part.requirements'];
    $row=$db->query("SELECT id FROM app_fields WHERE id=$field AND entities_id=262 FOR UPDATE")->fetch_assoc();
    if(!$row)throw new RuntimeException('Campo de requisitos não pertence aos componentes.');
    $db->query("UPDATE app_fields SET listing_status=0 WHERE id=$field AND entities_id=262");
    $rows=$db->query("SELECT id,configuration_value FROM app_entities_configuration WHERE entities_id=262 AND configuration_name='item_page_hidden_fields' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    if(count($rows)>1)throw new RuntimeException('Configuração de visibilidade duplicada.');
    $hidden=$rows?array_filter(array_map('intval',explode(',',$rows[0]['configuration_value']))):[];
    $hidden[]=$field;$value=implode(',',array_unique($hidden));
    if($rows) {
        $stmt=$db->prepare('UPDATE app_entities_configuration SET configuration_value=? WHERE id=?');
        $id=(int)$rows[0]['id'];$stmt->bind_param('si',$value,$id);
    }else{
        $stmt=$db->prepare("INSERT INTO app_entities_configuration(entities_id,configuration_name,configuration_value) VALUES(262,'item_page_hidden_fields',?)");
        $stmt->bind_param('s',$value);
    }
    $stmt->execute();$db->commit();
    echo "Campo $field ocultado na listagem e ficha de componentes; conteúdo preservado.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

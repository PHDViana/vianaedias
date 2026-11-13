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
    $schema=vd_config_schema($db);
    if(empty($schema['conversion.converted'])||empty($schema['config.status']))throw new RuntimeException('Estado nativo de conversão não configurado.');
    $id=(int)$schema['conversion.converted'];$field=(int)$schema['config.status'];
    $row=$db->query("SELECT name FROM app_fields_choices WHERE id=$id AND fields_id=$field FOR UPDATE")->fetch_assoc();
    if(!$row||!in_array($row['name'],['Convertido em orçamento','Orçamento em andamento'],true))
        throw new RuntimeException('O estado foi alterado externamente; rever antes de atualizar.');
    $db->query("UPDATE app_fields_choices SET name='Orçamento em andamento' WHERE id=$id AND fields_id=$field");
    $db->commit();
    echo "Estado de conversão: Orçamento em andamento. ID, automatismos e registos preservados.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

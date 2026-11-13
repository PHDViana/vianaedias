<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);$db->set_charset('utf8mb4');
$schema=vd_product_stage_schema($db);
if(empty($schema['operation.stage']))throw new RuntimeException('Execute primeiro migrate-configurator-native.php --schema.');
$fields=$db->query("SELECT id FROM app_fields WHERE entities_id=36 AND type='fieldtype_dropdown_multiple' AND JSON_UNQUOTE(JSON_EXTRACT(configuration,'$.use_global_list'))='24'")->fetch_all(MYSQLI_ASSOC);
if(count($fields)!==1)throw new RuntimeException('Associação de produtos ambígua.');
$field='field_'.(int)$fields[0]['id'];
$db->begin_transaction();
try {
    $db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.entity' FOR UPDATE");
    $schema=vd_product_stage_schema($db);
    if(empty($schema['stages.jacket_operation'])) {
        $extra=$db->query("SELECT id FROM app_fields_choices WHERE fields_id=6292 AND name='Extra'")->fetch_all(MYSQLI_ASSOC);
        if(count($extra)!==1)throw new RuntimeException('Classificação Extra indisponível.');
        $id=vd_config_insert($db,36,['field_1982'=>'Colocar sobrecapa','field_6128'=>'Colocar sobrecapa',
            'field_6129'=>'true','field_6292'=>$extra[0]['id'],$field=>'3712,3756',
            'field_'.$schema['operation.stage']=>'Assemblagem']);
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('stages.jacket_operation',$id)");
    }
    if(empty($schema['stages.configured'])) {
        foreach([19=>[3712,3756],20=>[3712,3756],23=>[3712],24=>[3712,3756,3751],25=>[3712,3756],26=>[3712,3756],39=>[3756]] as $id=>$types) {
            $row=$db->query("SELECT $field products FROM app_entity_36 WHERE id=$id FOR UPDATE")->fetch_assoc();
            if(!$row)throw new RuntimeException('Operação de assemblagem inexistente: '.$id);
            $before=array_filter(array_map('intval',explode(',',$row['products'])));
            $value=implode(',',array_values(array_unique(array_merge($before,$types))));
            $stmt=$db->prepare("UPDATE app_entity_36 SET $field=? WHERE id=?");$stmt->bind_param('si',$value,$id);$stmt->execute();
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('stages.configured',1)");
    }
    $db->commit();
    echo "Native operation stages installed; assembly product associations initialized once. Existing prices and histories preserved.\n";
}catch(Throwable $error) {$db->rollback();throw $error;}

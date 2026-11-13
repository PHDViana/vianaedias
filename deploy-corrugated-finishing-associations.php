<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-finishing.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$apply=($argv[1]??'')==='--apply';
if(!in_array($argv[1]??'--inspect',['--inspect','--apply'],true))throw new RuntimeException('Use --inspect ou --apply.');
$db->begin_transaction();
try {
    $schema=array_map('intval',array_column($db->query('SELECT role,native_id FROM app_vd_config_schema')->fetch_all(MYSQLI_ASSOC),'native_id','role'));
    $target=$schema['config.corrugated_type']??0;
    $types=$db->query('SELECT id,parent_id,name FROM app_global_lists_choices WHERE lists_id=24 ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $byId=array_column($types,null,'id');
    if(!$target||!isset($byId[$target])||(int)$byId[$target]['parent_id']!==3743)throw new RuntimeException('Subtipo de cartão canelado indisponível.');
    $ancestors=[];$parent=(int)$byId[$target]['parent_id'];
    while($parent) {
        if(!isset($byId[$parent])||in_array($parent,$ancestors,true))throw new RuntimeException('Hierarquia de produtos inválida.');
        $ancestors[]=$parent;$parent=(int)$byId[$parent]['parent_id'];
    }
    $fields=$db->query("SELECT id FROM app_fields WHERE entities_id=36 AND type='fieldtype_dropdown_multiple' AND JSON_UNQUOTE(JSON_EXTRACT(configuration,'$.use_global_list'))='24'")->fetch_all(MYSQLI_ASSOC);
    if(count($fields)!==1)throw new RuntimeException('Campo de associação de produtos ambíguo.');
    $field='field_'.(int)$fields[0]['id'];$changes=[];
    foreach($db->query("SELECT id,field_1982 name,$field products FROM app_entity_36 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC) as $operation) {
        $before=array_values(array_filter(array_map('intval',explode(',',(string)$operation['products']))));
        if(!vd_config_finishing_applies($target,$before,$types))continue;
        $after=[];
        foreach($before as $id) {
            if($id===$target)continue;
            if(in_array($id,$ancestors,true)) {
                foreach($types as $type)if((int)$type['parent_id']===$id&&(int)$type['id']!==$target)$after[]=(int)$type['id'];
            }else $after[]=$id;
        }
        $after=array_values(array_unique($after));
        if(vd_config_finishing_applies($target,$after,$types))throw new RuntimeException('Cartão canelado ainda associado à operação '.$operation['id']);
        foreach($types as $type) {
            $id=(int)$type['id'];
            if($id===$target||in_array($id,$ancestors,true))continue;
            if(vd_config_finishing_applies($id,$before,$types)!==vd_config_finishing_applies($id,$after,$types))
                throw new RuntimeException('Alteração inesperada noutro produto: '.$id);
        }
        $changes[]=['operation'=>(int)$operation['id'],'name'=>$operation['name'],'before'=>$before,'after'=>$after];
        if($apply) {
            $value=implode(',',$after);$id=(int)$operation['id'];
            $stmt=$db->prepare("UPDATE app_entity_36 SET $field=? WHERE id=?");
            $stmt->bind_param('si',$value,$id);$stmt->execute();
        }
    }
    if($apply)$db->commit();else $db->rollback();
    echo json_encode(['applied'=>$apply,'product'=>$target,'changes'=>$changes],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $error) {$db->rollback();throw $error;}

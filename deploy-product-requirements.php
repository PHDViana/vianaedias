<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);$db->set_charset('utf8mb4');
function vdRequirementChoice(mysqli $db,$role,$name,$parent,array $template) {
    $schema=vd_config_schema($db);
    if(isset($schema[$role])) {
        $id=$schema[$role];
        if(!$db->query("SELECT id FROM app_global_lists_choices WHERE id=$id AND lists_id=24 AND parent_id=$parent AND is_active=1")->num_rows)
            throw new RuntimeException('Tipo registado inválido: '.$role);
        return $id;
    }
    $stmt=$db->prepare('SELECT id,is_active FROM app_global_lists_choices WHERE lists_id=24 AND parent_id=? AND name=?');
    $stmt->bind_param('is',$parent,$name);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if(count($rows)>1||($rows&&!(int)$rows[0]['is_active']))throw new RuntimeException('Tipo duplicado/inativo: '.$name);
    if($rows)$id=(int)$rows[0]['id'];
    else {
        $row=array_replace($template,['parent_id'=>$parent,'name'=>$name,'notes'=>'','sort_order'=>10,'is_default'=>0]);
        unset($row['id']);
        $stmt=$db->prepare('INSERT INTO app_global_lists_choices (`'.implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')');
        $values=array_values($row);$stmt->bind_param(str_repeat('s',count($values)),...$values);$stmt->execute();$id=(int)$db->insert_id;
    }
    $stmt=$db->prepare('INSERT INTO app_vd_config_schema(role,native_id) VALUES(?,?)');$stmt->bind_param('si',$role,$id);$stmt->execute();
    return $id;
}
$db->begin_transaction();
try {
    $db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.entity' FOR UPDATE");
    $schema=vd_config_schema($db);
    $parent=$db->query('SELECT * FROM app_global_lists_choices WHERE lists_id=24 AND id=3851 AND is_active=1 FOR UPDATE')->fetch_assoc();
    if(!$parent)throw new RuntimeException('Tipo Menus / ementas indisponível.');
    $brochure=vdRequirementChoice($db,'product.brochure_category','Brochuras',0,$parent);
    $schema['product.brochure_category']=$brochure;
    foreach(['menu'=>3851,'brochure'=>$brochure] as $family=>$parentId)
        foreach(['simple'=>'Até 4 páginas — sem encadernação','stapled'=>'Agrafadas','spiral'=>'Espiral','sewn'=>'Cosidas'] as $key=>$name) {
            $role='product.'.$family.'_'.$key;
            $schema[$role]=vdRequirementChoice($db,$role,$name,$parentId,$parent);
        }
    if(empty($schema['product.requirements_initialized'])) {
        $associations=[19=>[3851],20=>[3851],23=>[$schema['product.menu_stapled']],32=>[$schema['product.menu_spiral']],26=>[$schema['product.menu_sewn']]];
        foreach($associations as $id=>$added) {
            $row=$db->query("SELECT field_6442 products FROM app_entity_36 WHERE id=$id AND field_6129='true' FOR UPDATE")->fetch_assoc();
            if(!$row)throw new RuntimeException('Operação de encadernação indisponível: '.$id);
            $ids=array_values(array_unique(array_merge(array_filter(array_map('intval',explode(',',$row['products']))),$added)));
            $value=implode(',',$ids);$stmt=$db->prepare('UPDATE app_entity_36 SET field_6442=? WHERE id=?');
            $stmt->bind_param('si',$value,$id);$stmt->execute();
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('product.requirements_initialized',1)");
    }
    if(empty($schema['product.brochures_initialized'])) {
        $associations=[19=>[$brochure],20=>[$brochure],23=>[$schema['product.brochure_stapled']],
            32=>[$schema['product.brochure_spiral']],26=>[$schema['product.brochure_sewn']]];
        // Copy component finishing eligibility from magazines once, excluding binding variants.
        foreach($db->query("SELECT id,field_6442 products FROM app_entity_36 WHERE field_6129='true'")->fetch_all(MYSQLI_ASSOC) as $operation) {
            $id=(int)$operation['id'];
            if(!in_array($id,[19,20,23,25,26,32,39],true)&&in_array(3712,array_map('intval',explode(',',$operation['products'])),true))
                $associations[$id]=array_merge($associations[$id]??[],[$brochure]);
        }
        foreach($associations as $id=>$added) {
            $row=$db->query("SELECT field_6442 products FROM app_entity_36 WHERE id=$id AND field_6129='true' FOR UPDATE")->fetch_assoc();
            if(!$row)throw new RuntimeException('Operação da brochura indisponível: '.$id);
            $ids=array_values(array_unique(array_merge(array_filter(array_map('intval',explode(',',$row['products']))),$added)));
            $value=implode(',',$ids);$stmt=$db->prepare('UPDATE app_entity_36 SET field_6442=? WHERE id=?');
            $stmt->bind_param('si',$value,$id);$stmt->execute();
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('product.brochures_initialized',1)");
    }
    $db->commit();
    echo "Native Brochuras category and menu subtypes initialized; existing product names, operation prices and histories preserved.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

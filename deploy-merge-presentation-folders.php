<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$db->begin_transaction();
try {
    $rows=$db->query('SELECT id,name,parent_id,is_active FROM app_global_lists_choices WHERE lists_id=24 AND id IN(3711,3827) FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
    $types=array_column($rows,null,'id');
    if(count($types)!==2||$types[3711]['name']!=='Capas'||$types[3827]['name']!=='Pastas de apresentação'||
        (int)$types[3711]['parent_id']!==3707||(int)$types[3827]['parent_id']!==3707||!(int)$types[3711]['is_active'])
        throw new RuntimeException('Taxonomia alterada; rever antes de remover o duplicado.');
    if($db->query('SELECT id FROM app_global_lists_choices WHERE lists_id=24 AND parent_id=3827')->num_rows)
        throw new RuntimeException('O tipo duplicado tem subtipos; requer revisão.');
    $updated=0;
    foreach($db->query('SELECT id,field_6442 products FROM app_entity_36 FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $operation) {
        $products=array_filter(array_map('intval',explode(',',$operation['products'])));
        if(!in_array(3827,$products,true)||in_array(3711,$products,true))continue;
        $products[]=3711;$value=implode(',',$products);$id=(int)$operation['id'];
        $stmt=$db->prepare('UPDATE app_entity_36 SET field_6442=? WHERE id=?');
        $stmt->bind_param('si',$value,$id);$stmt->execute();$updated++;
    }
    $db->query('UPDATE app_global_lists_choices SET is_active=0 WHERE lists_id=24 AND id=3827');
    $db->commit();
    echo "Pastas de apresentação desativado; Capas mantido. Associações acrescentadas: $updated. Históricos e preços inalterados.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

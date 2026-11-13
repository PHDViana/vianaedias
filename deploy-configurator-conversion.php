<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require 'config/server.php';
require_once 'includes/functions/database.php';
require_once 'includes/classes/model/app_logs.php';
require_once 'includes/classes/model/entities.php';
require_once 'includes/classes/model/fields.php';
require_once 'includes/classes/fields_types.php';
require_once __DIR__.'/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');$db_link=$db;
if(($argv[1]??'')==='--inspect') {
    $result=[];
    foreach(['app_ext_processes','app_ext_processes_actions','app_reports_filters','app_fields_choices'] as $table)
        $result[$table.'_columns']=$db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC);
    $result['processes']=$db->query("SELECT id,name,entities_id,button_position,users_groups FROM app_ext_processes ORDER BY id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
    $result['actions']=$db->query("SELECT * FROM app_ext_processes_actions WHERE type LIKE 'runphp%' LIMIT 2")->fetch_all(MYSQLI_ASSOC);
    $result['quote_fields']=$db->query('SELECT id,name,type,configuration FROM app_fields WHERE entities_id=146 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $result['operations']=$db->query('SELECT * FROM app_entity_36 WHERE id IN(19,20)')->fetch_all(MYSQLI_ASSOC);
    $result['operation_fields']=$db->query('SELECT id,name,type FROM app_fields WHERE entities_id=36 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $result['quote_statuses']=$db->query('SELECT id,name,is_default FROM app_global_lists_choices WHERE lists_id=10 ORDER BY sort_order,id')->fetch_all(MYSQLI_ASSOC);
    $result['filters']=$db->query('SELECT reports_id,fields_id,filters_values,filters_condition FROM app_reports_filters ORDER BY id DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['quote_statuses'=>$result['quote_statuses'],'filters'=>$result['filters'],
        'process_columns'=>array_column($result['app_ext_processes_columns'],'Field'),
        'filter_columns'=>array_column($result['app_reports_filters_columns'],'Field')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";exit;
}
if(($argv[1]??'')==='--enable') {
    $runtime='_CustomDEVs/quote-workflow/vd_quote_workflow/product-request-conversion.php';
    if(!is_file($runtime)||!hash_equals(hash_file('sha256',__DIR__.'/product-request-conversion.php'),hash_file('sha256',$runtime)))throw new RuntimeException('Publique primeiro a implementação verificada.');
    $schema=vd_config_schema($db);
    foreach(['conversion.manual_process','conversion.automatic_process'] as $role) {
        if(empty($schema[$role]))throw new RuntimeException('Instale primeiro os processos.');
        $db->query('UPDATE app_ext_processes SET is_active=1 WHERE id='.(int)$schema[$role]);
    }
    echo "Native request conversion processes enabled.\n";exit;
}
if(($argv[1]??'')!=='--install')throw new RuntimeException('Use --inspect, --install ou --enable.');
if(DB_FORCE_SQL_MODE)$db->query("SET SESSION sql_mode='".$db->real_escape_string(DB_SET_SQL_MODE)."'");
function vdConversionRegister(mysqli $db,$role,$id) {
    $s=$db->prepare('INSERT INTO app_vd_config_schema(role,native_id) VALUES(?,?) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)');
    $s->bind_param('si',$role,$id);$s->execute();
}
function vdConversionInsert(mysqli $db,$table,array $values) {
    $row=[];
    foreach($db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC) as $column) {
        if($column['Field']==='id')continue;
        $row[$column['Field']]=$column['Default']??($column['Null']==='YES'?null:
            (preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint|mediumint)/',$column['Type'])?0:''));
    }
    foreach($values as $key=>$value) {
        if(!array_key_exists($key,$row))throw new RuntimeException('Coluna nativa desconhecida: '.$table.'.'.$key);
        $row[$key]=$value;
    }
    db_perform($table,$row);return (int)db_insert_id();
}
function vdConversionField(mysqli $db,$key,$name,$type,array $configuration=[]) {
    $schema=vd_config_schema($db);
    if(isset($schema['config.'.$key]))return $schema['config.'.$key];
    $entity=$schema['config.entity'];
    $tab=(int)$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity ORDER BY id LIMIT 1")->fetch_row()[0];
    $template=$db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();unset($template['id']);
    $id=vdConversionInsert($db,'app_fields',array_replace($template,[
        'entities_id'=>$entity,'forms_tabs_id'=>$tab,'name'=>$name,'type'=>$type,'is_heading'=>0,'is_required'=>0,
        'listing_status'=>1,'sort_order'=>20,'listing_sort_order'=>20,'forms_rows_position'=>'',
        'configuration'=>json_encode($configuration+['width'=>'input-xlarge'],JSON_THROW_ON_ERROR)
    ]));
    entities::prepare_field($entity,$id,$type);
    vdConversionRegister($db,'config.'.$key,$id);return $id;
}
function vdConversionProcess(mysqli $db,$role,$name,$position,$code) {
    $schema=vd_config_schema($db);
    $values=['entities_id'=>$schema['config.entity'],'name'=>$name,'button_title'=>'Criar orçamento em rascunho',
        'button_position'=>$position,'button_color'=>'#428bca','button_icon'=>'fa-file-text-o','users_groups'=>'','assigned_to_all'=>1,
        'is_active'=>0,'success_message_status'=>0,'allow_comments'=>0,'disable_comments'=>1,'sort_order'=>10,
        'apply_fields_access_rules'=>1,'apply_fields_display_rules'=>1,
        'confirmation_text'=>$position==='default'?'Criar um orçamento em rascunho com os requisitos deste pedido? Não são calculados preços nem enviados documentos.':''];
    if(isset($schema[$role])){$id=$schema[$role];db_perform('app_ext_processes',$values,'update','id='.$id);}
    else {$id=vdConversionInsert($db,'app_ext_processes',$values);vdConversionRegister($db,$role,$id);}
    $actions=$db->query("SELECT id FROM app_ext_processes_actions WHERE process_id=$id")->fetch_all(MYSQLI_ASSOC);
    if(count($actions)>1)throw new RuntimeException('O processo tem ações adicionais; rever manualmente: '.$id);
    $action=['process_id'=>$id,'is_active'=>1,'type'=>'runphp_item_entity_'.$schema['config.entity'],
        'description'=>'Conversão partilhada e idempotente para orçamento em rascunho','sort_order'=>10,
        'settings'=>json_encode(['php_code'=>$code,'debug_mode'=>'0'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
    if($actions)db_perform('app_ext_processes_actions',$action,'update','id='.(int)$actions[0]['id']);
    else vdConversionInsert($db,'app_ext_processes_actions',$action);
    return $id;
}
$schema=vd_config_schema($db);
if(empty($schema['config.native_storage']))throw new RuntimeException('Persistência nativa deve estar ativa.');
$status=vdConversionField($db,'status','Estado do pedido','fieldtype_dropdown',['display_as'=>'dropdown']);
$quote=vdConversionField($db,'quote','Orçamento associado','fieldtype_entity',['entity_id'=>146,'display_as'=>'dropdown','display_as_link'=>1]);
vdConversionField($db,'conversion_error','Erro na conversão','fieldtype_textarea');
foreach(['preparing'=>'Em preparação','ready'=>'Pronto para orçamentar','converted'=>'Orçamento em andamento'] as $key=>$name) {
    $schema=vd_config_schema($db);
    if(!isset($schema['conversion.'.$key])) {
        $id=vdConversionInsert($db,'app_fields_choices',['fields_id'=>$status,'name'=>$name,'is_default'=>$key==='preparing'?1:0,'sort_order'=>['preparing'=>10,'ready'=>20,'converted'=>30][$key]]);
        vdConversionRegister($db,'conversion.'.$key,$id);
    }
}
$schema=vd_config_schema($db);
$db->query('UPDATE app_entity_'.$schema['config.entity'].' SET field_'.$status.'='.$schema['conversion.preparing']." WHERE field_$status IS NULL OR field_$status='' OR field_$status='0'");
$require="require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/product-request-conversion.php';\n";
$manual=vdConversionProcess($db,'conversion.manual_process','Pedido — criar orçamento em rascunho','default',
    $require.'vd_config_conversion_process((int)$item_id,$app_user,false);');
$automatic=vdConversionProcess($db,'conversion.automatic_process','Pedido pronto — criar orçamento automaticamente','run_after_update',
    $require.'vd_config_conversion_process((int)$item_id,$app_user,true);');
$reports=$db->query("SELECT id FROM app_reports WHERE reports_type='process$automatic' AND entities_id=".$schema['config.entity'])->fetch_all(MYSQLI_ASSOC);
if(count($reports)>1)throw new RuntimeException('Filtros duplicados no automatismo.');
$report=$reports?(int)$reports[0]['id']:vdConversionInsert($db,'app_reports',['entities_id'=>$schema['config.entity'],'reports_type'=>'process'.$automatic,'name'=>'Pedido pronto para orçamentar']);
$filters=$db->query("SELECT id FROM app_reports_filters WHERE reports_id=$report")->fetch_all(MYSQLI_ASSOC);
if(count($filters)>1)throw new RuntimeException('O automatismo tem filtros adicionais; rever manualmente.');
$filter=['reports_id'=>$report,'fields_id'=>$status,'filters_values'=>$schema['conversion.ready'],'filters_condition'=>'include','is_active'=>1];
if($filters)db_perform('app_reports_filters',$filter,'update','id='.(int)$filters[0]['id']);
else vdConversionInsert($db,'app_reports_filters',$filter);
$buttonConfiguration=['process_button'=>[(string)$manual],'display_as'=>'inline','width'=>'input-xlarge'];
$button=vdConversionField($db,'convert_button','Criar orçamento','fieldtype_process_button',$buttonConfiguration);
db_perform('app_fields',['configuration'=>json_encode($buttonConfiguration,JSON_THROW_ON_ERROR)],'update','id='.$button);
echo json_encode(['status'=>$status,'quote'=>$quote,'manual_process'=>$manual,'automatic_process'=>$automatic,'filter_report'=>$report],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";

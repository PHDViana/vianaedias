<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require 'config/server.php';
require_once 'includes/functions/database.php';
require_once 'includes/classes/model/entities.php';
require_once 'includes/classes/model/fields.php';
require_once 'includes/classes/fields_types.php';
require_once 'includes/classes/model/app_logs.php';
require_once __DIR__.'/product-configurator.php';
require_once __DIR__.'/product-configurator-native.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$timezone=$db->query("SELECT configuration_value FROM app_configuration WHERE configuration_name='CFG_APP_TIMEZONE'")->fetch_assoc();
if(!$timezone||!date_default_timezone_set($timezone['configuration_value']))throw new RuntimeException('Fuso horário nativo indisponível.');
$db_link=$db;
if(DB_FORCE_SQL_MODE)$db->query("SET SESSION sql_mode='". $db->real_escape_string(DB_SET_SQL_MODE)."'");
define('TEXT_INFO','Informação');
function vdNativeRegister(mysqli $db,$role,$id) {
    $s=$db->prepare('INSERT INTO app_vd_config_schema(role,native_id) VALUES(?,?) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)');
    $s->bind_param('si',$role,$id);$s->execute();
}
function vdNativeEntity(mysqli $db,$role,$name,$parent) {
    $schema=vd_config_schema($db);
    if(isset($schema[$role.'.entity']))return $schema[$role.'.entity'];
    $duplicate=$db->prepare('SELECT id FROM app_entities WHERE parent_id=? AND name=?');
    $duplicate->bind_param('is',$parent,$name);$duplicate->execute();
    if($duplicate->get_result()->num_rows)throw new RuntimeException('Subentidade sem registo de migração: '.$name);
    db_perform('app_entities',['parent_id'=>$parent,'name'=>$name,'notes'=>'','group_id'=>0,'display_in_menu'=>0,'sort_order'=>10]);
    $id=(int)db_insert_id();
    entities::prepare_tables($id);
    $tab=entities::insert_default_form_tab($id);
    entities::insert_reserved_fields($id,$tab);
    vdNativeRegister($db,$role.'.entity',$id);
    foreach($db->query("SELECT * FROM app_entities_access WHERE entities_id=$parent")->fetch_all(MYSQLI_ASSOC) as $access) {
        unset($access['id']);$access['entities_id']=$id;db_perform('app_entities_access',$access);
    }
    return $id;
}
function vdNativeField(mysqli $db,$prefix,$key,$label,$type='fieldtype_input',$technical=false,array $configuration=[]) {
    $schema=vd_config_schema($db);$role=$prefix.'.'.$key;
    if(isset($schema[$role])) {
        fields::check_if_type_changed($schema[$role],$type);
        db_perform('app_fields',['type'=>$type],'update','id='.(int)$schema[$role]);
        return;
    }
    $entity=$schema[$prefix.'.entity'];
    $tab=$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity ORDER BY id LIMIT 1")->fetch_row()[0];
    $template=$db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();
    unset($template['id']);
    $template=array_replace($template,[
        'entities_id'=>$entity,'forms_tabs_id'=>$tab,'name'=>$label,'type'=>$type,'is_heading'=>$key==='name'?1:0,
        'listing_status'=>$technical?0:1,'is_required'=>0,'sort_order'=>100,'listing_sort_order'=>100,
        'forms_rows_position'=>'','configuration'=>json_encode($configuration+['width'=>'input-xlarge'],JSON_THROW_ON_ERROR)
    ]);
    db_perform('app_fields',$template);$id=(int)db_insert_id();
    entities::prepare_field($entity,$id,$type);
    vdNativeRegister($db,$role,$id);
}
function vdNativeFieldPresentation(mysqli $db,$prefix,$key,$technical) {
    $schema=vd_config_schema($db);$entity=$schema[$prefix.'.entity'];$field=$schema[$prefix.'.'.$key];
    if($technical) {
        $tab=$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity AND name='Metadados técnicos'")->fetch_assoc();
        if(!$tab) {
            db_perform('app_forms_tabs',['name'=>'Metadados técnicos','entities_id'=>$entity]);
            $tab=['id'=>db_insert_id()];
        }
        db_perform('app_fields',['forms_tabs_id'=>$tab['id'],'listing_status'=>0],'update','id='.$field);
    }
}
$mode=$argv[1]??'--inspect';
if($mode==='--activate') {
    $current=vd_config_schema($db);
    if(empty($current['config.storage_locked'])||empty($current['config.native_verified']))throw new RuntimeException('Migração bloqueada e verificada necessária antes de ativar.');
    vdNativeRegister($db,'config.native_storage',1);
    vdNativeRegister($db,'config.storage_locked',0);
    echo "Native storage activated; configurator writes unlocked.\n";exit;
}
if($mode==='--lock'||$mode==='--unlock') {
    vdNativeRegister($db,'config.storage_locked',$mode==='--lock'?1:0);
    if($mode==='--lock') {
        $schema=vd_config_schema($db);
        $db->begin_transaction();
        $db->query('SELECT id FROM app_entity_'.$schema['config.entity'].' FOR UPDATE');
        $db->commit();
    }
    echo $mode==="--lock"?"Configurator writes locked.\n":"Configurator writes unlocked.\n";exit;
}
if($mode==='--inspect') {
    $counts=[];
    foreach(['app_vd_product_drafts','app_vd_product_attachments','app_vd_product_submissions','app_vd_product_quote_sources'] as $table)
        $counts[$table]=(int)$db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
    echo json_encode(['counts'=>$counts,'native_schema'=>vd_config_schema($db),
        'operation_product_fields'=>$db->query("SELECT id,name,type,configuration FROM app_fields WHERE entities_id=36 AND (name LIKE '%produto%' OR configuration LIKE '%\"24\"%')")->fetch_all(MYSQLI_ASSOC),
        'operation_products'=>$db->query('SELECT id,field_6442 product_types FROM app_entity_36 ORDER BY id')->fetch_all(MYSQLI_ASSOC),
        'menu'=>$db->query('SELECT id,type,url,entities_list,reports_list FROM app_entities_menu WHERE id=61')->fetch_assoc()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";exit;
}
if(!in_array($mode,['--schema','--migrate','--verify'],true))throw new RuntimeException('Use --inspect, --schema, --migrate ou --verify.');
if($mode==='--schema') {
    $schema=vd_config_schema($db);
    vdNativeRegister($db,'quote.entity',146);
    foreach(['operation'=>36,'quote_part'=>262,'production_part'=>159] as $prefix=>$entity)vdNativeRegister($db,$prefix.'.entity',$entity);
    foreach(['part'=>'Componentes do pedido','quantity'=>'Quantidades do pedido','finish'=>'Acabamentos do pedido','document'=>'Documentos do pedido','submission'=>'Registos de submissão','piece'=>'Dados técnicos complementares','rubric'=>'Rubricas do pedido'] as $role=>$label)
        vdNativeEntity($db,$role,$label,$schema['config.entity']);
    $fields=[
        'config'=>[
            'structure'=>'Estrutura do produto','other_product'=>'Outro produto — especificação',
            'delivery'=>'Data pretendida','contact'=>'Contacto do pedido','contact_id'=>'Contacto do cliente',
            'book_start'=>'Documentos — número de início','book_sets'=>'Documentos — conjuntos por livro',
            'book_copies'=>'Documentos — vias por conjunto','book_colors'=>'Documentos — cores do papel por via',
            'book_binding'=>'Documentos — picotado ou colado','book_unit'=>'Documentos — unidade',
            'box_length'=>'Caixa montada — comprimento (mm)','box_width'=>'Caixa montada — largura (mm)',
            'box_height'=>'Caixa montada — altura (mm)','box_measurement'=>'Caixa montada — medidas interiores / exteriores',
            'final_width'=>'Produto fechado — largura (mm)','final_height'=>'Produto fechado — altura (mm)',
            'stage_version'=>'Versão do fluxo por etapas',
            'final_thickness'=>'Produto fechado — espessura a confirmar (mm)',
            'total_pages'=>'Páginas totais — incluindo capa','box_model'=>'Modelo da caixa',
            'box_description'=>'Descrição da caixa personalizada',
            'finishing'=>'Outros requisitos dos acabamentos','dispatch'=>'Expedição',
            'revision'=>'Revisão do pedido','hash'=>'Integridade técnica','updated_by'=>'Atualizado por',
            'updated_at'=>'Atualização técnica','technical'=>'Estrutura técnica complementar'
        ],
        'part'=>[
            'local_id'=>'Número do componente','name'=>'Componente','kind'=>'Tipo de componente',
            'units'=>'Unidades por produto','inputs'=>'Componentes de entrada',
            'width'=>'Largura (mm)','height'=>'Altura (mm)','pages'=>'Páginas','faces'=>'Faces de impressão',
            'lamination_front'=>'Plastificação frente','lamination_back'=>'Plastificação verso',
            'colors'=>'Cores','paper'=>'Papel / material pretendido','description'=>'Descrição complementar',
            'printing'=>'Impressão','pantone'=>'Referências Pantone','lamination'=>'Plastificação',
            'die_status'=>'Cortante novo / usado','die_number'=>'Número do cortante',
            'corrugated_thickness'=>'Cartão canelado — espessura (mm)',
            'role'=>'Papel do componente','stage'=>'Etapa','flap_left'=>'Aba esquerda (mm)','flap_right'=>'Aba direita (mm)','spine'=>'Lombada (mm)',
            'technical'=>'Estrutura técnica complementar'
        ],
        'quantity'=>['amount'=>'Quantidade','number_end'=>'Documentos — número de fim'],
        'rubric'=>['quantity'=>'Quant.','description'=>'Descrição','code'=>'Código'],
        'operation'=>['stage'=>'Etapa de aplicação'],
        'quote_part'=>['stage'=>'Etapa do produto','requirements'=>'Requisitos da etapa / componente'],
        'production_part'=>['stage'=>'Etapa do produto','requirements'=>'Requisitos fixados na orçamentação'],
        'finish'=>[
            'name'=>'Acabamento','operation'=>'Operação','scope'=>'Âmbito técnico','component'=>'Componente / conjunto',
            'thread_type'=>'Tipo de fio','thread_color'=>'Cor do fio','thread_length'=>'Comprimento do fio (mm)',
            'tape_quantity'=>'Biadesivo — tiras por exemplar','tape_x'=>'Biadesivo — X (mm)','tape_y'=>'Biadesivo — Y (mm)',
            'technical'=>'Estrutura técnica complementar'
        ],
        'document'=>['name'=>'Documento','file'=>'Anexo','mime'=>'Tipo de ficheiro','size'=>'Tamanho (bytes)','hash'=>'Integridade','legacy_id'=>'Identificador anterior do anexo'],
        'submission'=>['request'=>'Identificador de submissão','hash'=>'Integridade da submissão'],
        'piece'=>['token'=>'Referência técnica','position'=>'Parte','content'=>'Dados técnicos','hash'=>'Integridade'],
        'quote'=>['revision'=>'Revisão do configurador de origem','snapshot'=>'Configuração fixada na origem','hash'=>'Integridade da configuração de origem','created_at'=>'Data da configuração de origem']
    ];
    foreach($fields as $prefix=>$definitions)foreach($definitions as $key=>$label) {
        $technical=in_array($key,['technical','hash','updated_by','updated_at','scope','snapshot','created_at','legacy_id'],true)||$prefix==='submission';
        $type=in_array($key,['technical','snapshot','finishing','description','paper','pantone','thread_type','thread_color','contact','other_product','content','book_colors','requirements'],true)?'fieldtype_textarea':'fieldtype_input';
        if($prefix==='finish'&&$key==='name')$type='fieldtype_textarea';
        if($prefix==='rubric')$type=$key==='quantity'?'fieldtype_input_numeric':'fieldtype_input';
        $configuration=[];
        if($prefix==='document'&&$key==='file')$type='fieldtype_attachments';
        if($prefix==='finish'&&$key==='operation'){$type='fieldtype_entity_ajax';$configuration=['entity_id'=>'36','hide_plus_button'=>'1'];}
        if($prefix==='config'&&$key==='contact_id'){$type='fieldtype_entity_ajax';$configuration=['entity_id'=>'90','hide_plus_button'=>'1'];}
        vdNativeField($db,$prefix,$key,$label,$type,$technical,$configuration);
        vdNativeFieldPresentation($db,$prefix,$key,$technical);
    }
    $schema=vd_config_schema($db);
    $stages=['assembly'=>[9,19,20,23,24,25,26,31,32,39],'packaging'=>[29,46],'dispatch'=>[10,12],'support'=>[44]];
    foreach($db->query('SELECT id FROM app_entity_36')->fetch_all(MYSQLI_ASSOC) as $operation) {
        $id=(int)$operation['id'];$stage='component';
        foreach($stages as $candidate=>$ids)if(in_array($id,$ids,true))$stage=$candidate;
        $label=vd_product_stage_names()[$stage];$field=$schema['operation.stage'];
        $stmt=$db->prepare("UPDATE app_entity_36 SET field_$field=? WHERE id=? AND (field_$field IS NULL OR field_$field='')");
        $stmt->bind_param('si',$label,$id);$stmt->execute();
    }
    $fixed=['quote.config','quote.revision','quote.snapshot','quote.hash','quote.created_at'];
    $changes=[];
    foreach($fixed as $role)$changes[]='NOT(OLD.field_'.$schema[$role].'<=>NEW.field_'.$schema[$role].')';
    $statement="BEGIN IF COALESCE(OLD.field_".$schema['quote.snapshot'].",'')<>'' AND (".implode(' OR ',$changes).") THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Configuracao de origem imutavel: gerar nova revisao'; END IF; END";
    $trigger=$db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='vd_native_config_source_immutable'")->fetch_assoc();
    if($trigger&&$trigger['ACTION_STATEMENT']!==$statement)throw new RuntimeException('Proteção da origem nativa alterada externamente.');
    if(!$trigger)$db->query('CREATE TRIGGER vd_native_config_source_immutable BEFORE UPDATE ON app_entity_146 FOR EACH ROW '.$statement);
    echo "Native fields and subentities created through entities::prepare_tables / prepare_field.\n";exit;
}
$schema=vd_config_schema($db);$store=new VDConfigNativeStore($db,$schema);
$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
if(empty($schema['part.entity']))throw new RuntimeException('Execute --schema primeiro.');
if($mode==='--migrate') {
    if(function_exists('posix_geteuid')&&posix_geteuid()===0)throw new RuntimeException('Execute --migrate como utilizador do alojamento, não como root, para preservar as permissões dos anexos nativos.');
    if(!empty($schema['config.native_storage']))throw new RuntimeException('A origem nativa já está ativa; recusar reimportação das tabelas antigas.');
    if(empty($schema['config.storage_locked']))throw new RuntimeException('Bloqueie as escritas antes da migração.');
    $db->begin_transaction();
    try {
        foreach($db->query('SELECT * FROM app_vd_product_drafts ORDER BY config_id FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $row) {
            if(!hash_equals($row['hash'],hash('sha256',$row['payload'])))throw new RuntimeException('Configuração antiga com integridade inválida.');
            $id=(int)$row['config_id'];
            if(!$db->query('SELECT id FROM app_entity_'.$schema['config.entity']." WHERE id=$id FOR UPDATE")->num_rows)throw new RuntimeException('Configuração órfã.');
            $store->save($id,json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR),(int)$row['revision'],(int)$row['updated_by'],(int)$row['updated_at']);
        }
        foreach($db->query('SELECT * FROM app_vd_product_submissions ORDER BY request_id')->fetch_all(MYSQLI_ASSOC) as $row) {
            $existing=$store->submissions($row['request_id']);
            if(!$existing)$store->insert('submission',(int)$row['config_id'],['request'=>$row['request_id'],'hash'=>$row['hash']],(int)$row['actor_id']);
            elseif(count($existing)!==1||$existing[0]['hash']!==$row['hash']||(int)$existing[0]['config_id']!==(int)$row['config_id'])throw new RuntimeException('Submissão nativa divergente.');
        }
        foreach($db->query('SELECT * FROM app_vd_product_attachments ORDER BY id')->fetch_all(MYSQLI_ASSOC) as $row) {
            if(!hash_equals($row['hash'],hash('sha256',$row['content'])))throw new RuntimeException('Anexo antigo com integridade inválida.');
            $existing=$store->rows('SELECT id FROM app_entity_'.$store->entity('document').' WHERE parent_item_id=? AND '.$store->field('document','legacy_id').'=?',[$row['config_id'],$row['id']]);
            if(!$existing) {
                $document=$store->addDocument((int)$row['config_id'],$row,(int)$row['created_by'],(int)$row['created_at']);
                $store->update('document',$document,['legacy_id'=>$row['id']]);
            }
        }
        foreach($db->query('SELECT * FROM app_vd_product_quote_sources')->fetch_all(MYSQLI_ASSOC) as $row)
            $store->saveSource((int)$row['quote_id'],(int)$row['config_id'],(int)$row['revision'],$row['payload'],$row['hash'],(int)$row['created_at']);
        $db->commit();$store->finishFiles(true);
    }catch(Throwable $error){$db->rollback();$store->finishFiles(false);throw $error;}
}
$checked=['requests'=>0,'attachments'=>0,'sources'=>0,'submissions'=>0];
foreach($db->query('SELECT * FROM app_vd_product_drafts ORDER BY config_id')->fetch_all(MYSQLI_ASSOC) as $row) {
    $native=$db->query('SELECT * FROM app_entity_'.$schema['config.entity'].' WHERE id='.(int)$row['config_id'])->fetch_assoc();
    if($store->payload($native)!==json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR)||(int)$native[$store->field('config','revision')]!==(int)$row['revision'])
        throw new RuntimeException('Verificação nativa divergente no pedido #'.$row['config_id']);
    $checked['requests']++;
}
foreach($db->query('SELECT * FROM app_vd_product_attachments')->fetch_all(MYSQLI_ASSOC) as $row) {
    $native=$store->rows('SELECT id FROM app_entity_'.$store->entity('document').' WHERE parent_item_id=? AND '.$store->field('document','legacy_id').'=?',[$row['config_id'],$row['id']]);
    if(count($native)!==1||$store->document((int)$row['config_id'],(int)$native[0]['id'])['content']!==$row['content'])throw new RuntimeException('Anexo nativo divergente.');
    $checked['attachments']++;
}
foreach($db->query('SELECT * FROM app_vd_product_quote_sources')->fetch_all(MYSQLI_ASSOC) as $row) {
    $native=$store->source((int)$row['quote_id']);
    if(!$native||$native['payload']!==$row['payload']||$native['hash']!==$row['hash']||(int)$native['config_id']!==(int)$row['config_id']||(int)$native['revision']!==(int)$row['revision'])throw new RuntimeException('Origem nativa divergente.');
    $checked['sources']++;
}
foreach($db->query('SELECT * FROM app_vd_product_submissions')->fetch_all(MYSQLI_ASSOC) as $row) {
    $native=$store->submissions($row['request_id']);
    if(count($native)!==1||$native[0]['hash']!==$row['hash']||(int)$native[0]['actor_id']!==(int)$row['actor_id']||(int)$native[0]['config_id']!==(int)$row['config_id'])throw new RuntimeException('Submissão nativa divergente.');
    $checked['submissions']++;
}
echo 'Native migration verified: '.json_encode($checked,JSON_THROW_ON_ERROR),"\n";
vdNativeRegister($db,'config.native_verified',1);

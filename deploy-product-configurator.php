<?php
if (PHP_SAPI!=='cli') exit(1);
$base=__DIR__;
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
function vdConfigInsert(mysqli $db,$table,array $row) {
    unset($row['id']);
    $s=$db->prepare("INSERT INTO $table (`".implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')');
    $v=array_values($row);$s->bind_param(str_repeat('s',count($v)),...$v);$s->execute();return (int)$db->insert_id;
}
function vdConfigProductTypes(mysqli $db) {
    $additions=[
        3707=>['Blocos de notas','Pastas de apresentação','Papel de carta','Cartões de agradecimento','Pastas de arquivo'],
        3712=>['Espiral','Wire-O'],
        3743=>['Sacos de papel','Saquetas','Rótulos','Tags / etiquetas penduradas','Embalagem de cartão canelado'],
        3752=>['Faturas/Recibo','Guias de Remessa','Orçamentos','Notas de Crédito','Notas de Débito','Notas de Encomenda','Requisições','Folhas de Obra'],
        3756=>['Espiral','Wire-O','Argolas'],
        3800=>['Convites','Postais','Calendários','Marcadores de livros','Menus / ementas','Diplomas / certificados','Bilhetes / senhas']
    ];
    if((int)$db->query("SELECT GET_LOCK('vd_product_types',10)")->fetch_row()[0]!==1)throw new RuntimeException('Product types lock unavailable.');
    try {
        $db->begin_transaction();
        $before=$db->query('SELECT * FROM app_global_lists_choices WHERE lists_id=24 ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        $byId=array_column($before,null,'id');$created=[];
        foreach($additions as $parent=>$names) {
            if(!isset($byId[$parent])||(int)$byId[$parent]['parent_id']!==0||(int)$byId[$parent]['is_active']!==1)
                throw new RuntimeException("Categoria de produto indisponível: $parent.");
        }
        if(!isset($byId[3754])||(int)$byId[3754]['parent_id']!==3752||!in_array($byId[3754]['name'],['Doc. Transporte','Guias de Transporte'],true))
            throw new RuntimeException('A opção de transporte foi alterada; rever antes de atualizar.');
        $db->query("UPDATE app_global_lists_choices SET name='Guias de Transporte' WHERE id=3754 AND lists_id=24");
        foreach($additions as $parent=>$names) {
            $sort=0;
            foreach($before as $row)if((int)$row['parent_id']===$parent)$sort=max($sort,(int)$row['sort_order']);
            foreach($names as $name) {
                $s=$db->prepare('SELECT id,is_active FROM app_global_lists_choices WHERE lists_id=24 AND parent_id=? AND name=?');
                $s->bind_param('is',$parent,$name);$s->execute();$matches=$s->get_result()->fetch_all(MYSQLI_ASSOC);
                if(count($matches)>1||($matches&&(int)$matches[0]['is_active']!==1))throw new RuntimeException("Opção duplicada ou inativa: $name.");
                if($matches)continue;
                $sort+=10;
                $created[]=vdConfigInsert($db,'app_global_lists_choices',array_replace($byId[$parent],[
                    'parent_id'=>$parent,'name'=>$name,'is_default'=>0,'sort_order'=>$sort,'notes'=>'',
                    'icon'=>'','bg_color'=>'','value'=>'','users'=>''
                ]));
            }
        }
        $after=$db->query('SELECT * FROM app_global_lists_choices WHERE lists_id=24 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $afterById=array_column($after,null,'id');
        foreach($before as $row) {
            if((int)$row['id']===3754)$row['name']='Guias de Transporte';
            if(($afterById[$row['id']]??null)!==$row)throw new RuntimeException('Uma opção existente foi alterada inesperadamente.');
        }
        if(count($after)!==count($before)+count($created))throw new RuntimeException('Contagem inesperada de tipos de produto.');
        $corrugated=(int)$db->query("SELECT id FROM app_global_lists_choices WHERE lists_id=24 AND parent_id=3743 AND name='Embalagem de cartão canelado'")->fetch_row()[0];
        $s=$db->prepare("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.corrugated_type',?) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)");
        $s->bind_param('i',$corrugated);$s->execute();
        $db->commit();
        return ['created'=>$created,'preserved'=>count($before),'renamed'=>3754,'total'=>count($after)];
    }catch(Throwable $error) {
        $db->rollback();throw $error;
    }finally {$db->query("SELECT RELEASE_LOCK('vd_product_types')");}
}
function vdConfigFinishingCatalog(mysqli $db) {
    if((int)$db->query("SELECT GET_LOCK('vd_finishing_catalog',10)")->fetch_row()[0]!==1)throw new RuntimeException('Finishing catalog lock unavailable.');
    try {
        $db->begin_transaction();
        $extra=$db->query("SELECT id FROM app_fields_choices WHERE fields_id=6292 AND name='Extra'")->fetch_all(MYSQLI_ASSOC);
        if(count($extra)!==1)throw new RuntimeException('A classificação Extra não está definida de forma única.');
        $rows=$db->query("SELECT id,field_6292,field_6129 FROM app_entity_36 WHERE field_1982='Produção de cortante' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if(count($rows)>1)throw new RuntimeException('Existem operações Produção de cortante duplicadas.');
        $created=false;
        if($rows) {
            if((int)$rows[0]['field_6292']!==(int)$extra[0]['id']||$rows[0]['field_6129']!=='true')throw new RuntimeException('A operação Produção de cortante existe mas não está ativa como Extra.');
            $id=(int)$rows[0]['id'];
        }
        else {
            $row=[];
            foreach($db->query('SHOW COLUMNS FROM app_entity_36') as $column) {
                if($column['Field']==='id')continue;
                $row[$column['Field']]=$column['Default']??($column['Null']==='YES'?null:(preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint)/',$column['Type'])?0:''));
            }
            $id=vdConfigInsert($db,'app_entity_36',array_replace($row,[
                'date_added'=>time(),'created_by'=>1,'field_1982'=>'Produção de cortante',
                'field_1981'=>39,'field_2266'=>3706,'field_2529'=>3689,'field_6128'=>'Produção de cortante',
                'field_6129'=>'true','field_6130'=>'serviço','field_6131'=>'cortantes',
                'field_6292'=>$extra[0]['id'],'field_6293'=>'Cortante — produção de novo',
                'field_6134'=>'Novo cortante: confirmar desenho, dimensões, fornecedor, custo e prazo no orçamento. Serviço externo a confirmar. Não inclui a execução do corte e vinco. Sem preço ou produtividade estimados.'
            ]));$created=true;
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.die_operation',$id) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)");
        $enveloping=$db->query("SELECT id,field_6292,field_6129 FROM app_entity_36 WHERE field_1982='Envelopar' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if(count($enveloping)>1)throw new RuntimeException('Existem operações Envelopar duplicadas.');
        $envelopingCreated=false;
        if($enveloping) {
            if((int)$enveloping[0]['field_6292']!==(int)$extra[0]['id']||$enveloping[0]['field_6129']!=='true')throw new RuntimeException('A operação Envelopar existe mas não está ativa como Extra.');
            $envelopingId=(int)$enveloping[0]['id'];
        }else {
            $row=[];
            foreach($db->query('SHOW COLUMNS FROM app_entity_36') as $column) {
                if($column['Field']==='id')continue;
                $row[$column['Field']]=$column['Default']??($column['Null']==='YES'?null:(preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint)/',$column['Type'])?0:''));
            }
            $envelopingId=vdConfigInsert($db,'app_entity_36',array_replace($row,[
                'date_added'=>time(),'created_by'=>1,'field_1982'=>'Envelopar','field_6293'=>'Envelopar',
                'field_1981'=>39,'field_2266'=>3705,'field_2529'=>3684,'field_6128'=>'Envelopar',
                'field_6129'=>'true','field_6130'=>'unidade','field_6131'=>'envelopes',
                'field_6292'=>$extra[0]['id'],
                'field_6134'=>'Inserir as peças em envelopes; não inclui fabricar envelopes. Confirmar formato, número de peças por envelope, fecho, materiais, custo e produtividade no orçamento. Sem valores estimados.'
            ]));$envelopingCreated=true;
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.enveloping_operation',$envelopingId) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)");
        $db->commit();return ['die_operation'=>$id,'created'=>$created,'enveloping_operation'=>$envelopingId,'enveloping_created'=>$envelopingCreated];
    }catch(Throwable $error){$db->rollback();throw $error;}
    finally{$db->query("SELECT RELEASE_LOCK('vd_finishing_catalog')");}
}
function vdConfigInternalCustomer(mysqli $db) {
    if((int)$db->query("SELECT GET_LOCK('vd_internal_customer',10)")->fetch_row()[0]!==1)throw new RuntimeException('Internal customer lock unavailable.');
    try {
        $db->begin_transaction();
        $rows=$db->query("SELECT id,field_224 FROM app_entity_26 WHERE LOWER(TRIM(field_221))='interno' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if(count($rows)>1)throw new RuntimeException('Existem entidades Interno duplicadas.');
        $created=false;
        if($rows) {
            if(!in_array('176',explode(',',$rows[0]['field_224']),true))throw new RuntimeException('A entidade Interno existente não é do tipo Cliente.');
            $id=(int)$rows[0]['id'];
        }else {
            $row=[];
            foreach($db->query('SHOW COLUMNS FROM app_entity_26') as $column) {
                if($column['Field']==='id')continue;
                $row[$column['Field']]=$column['Default']??($column['Null']==='YES'?null:(preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint)/',$column['Type'])?0:''));
            }
            $id=vdConfigInsert($db,'app_entity_26',array_replace($row,[
                'date_added'=>time(),'created_by'=>1,'field_221'=>'Interno','field_224'=>'176'
            ]));$created=true;
        }
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.internal_customer',$id) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)");
        $db->commit();return ['internal_customer'=>$id,'created'=>$created];
    }catch(Throwable $error){$db->rollback();throw $error;}
    finally{$db->query("SELECT RELEASE_LOCK('vd_internal_customer')");}
}
if(in_array('--internal-customer-only',$argv,true)) {
    echo json_encode(vdConfigInternalCustomer($db),JSON_THROW_ON_ERROR)."\n";exit;
}
if(in_array('--finishing-catalog-only',$argv,true)) {
    echo json_encode(vdConfigFinishingCatalog($db),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";exit;
}
if(in_array('--product-types-only',$argv,true)) {
    echo json_encode(vdConfigProductTypes($db),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";exit;
}
foreach(['app_vd_config_schema','app_vd_variance_queue','app_vd_production_evaluations'] as $table)
    if(!$db->query("SHOW TABLES LIKE '$table'")->num_rows)throw new RuntimeException('Infraestrutura partilhada existente indisponível: '.$table.'. Este instalador não cria tabelas auxiliares.');
require_once 'config/server.php';
require_once 'includes/functions/database.php';
require_once 'includes/classes/model/app_logs.php';
require_once 'includes/classes/model/entities.php';
require_once 'includes/classes/fields_types.php';
$db_link=$db;
if ((int)$db->query("SELECT GET_LOCK('vd_product_config_schema',10)")->fetch_row()[0]!==1) throw new RuntimeException('Schema lock unavailable.');
try {
    vdConfigProductTypes($db);
    vdConfigFinishingCatalog($db);
    vdConfigInternalCustomer($db);
    $other=$db->query("SELECT id FROM app_global_lists_choices WHERE lists_id=24 AND parent_id=0 AND name='Outro' AND is_active=1")->fetch_all(MYSQLI_ASSOC);
    if(count($other)>1)throw new RuntimeException('Existem opções Outro duplicadas na lista de produtos.');
    if(!$other) {
        $template=$db->query('SELECT * FROM app_global_lists_choices WHERE id=3707 AND lists_id=24')->fetch_assoc();
        if(!$template)throw new RuntimeException('Lista de tipos de produto indisponível.');
        $otherId=vdConfigInsert($db,'app_global_lists_choices',array_replace($template,['name'=>'Outro','sort_order'=>100,'is_default'=>0,'notes'=>'Indicar o produto no campo Especifique do configurador.']));
    }else $otherId=(int)$other[0]['id'];
    $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.other_type',$otherId) ON DUPLICATE KEY UPDATE native_id=VALUES(native_id)");
    foreach(['config'=>'Configurador de Produto','evaluation'=>'Avaliação de desvios'] as $role=>$label) {
        $registered=$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$role.entity'")->fetch_assoc();
        if ($registered) $entity=(int)$registered['native_id'];
        else {
            $template=$db->query('SELECT * FROM app_entities WHERE id=146')->fetch_assoc();
            $entity=vdConfigInsert($db,'app_entities',array_replace($template,['parent_id'=>0,'name'=>$label,'notes'=>'Fluxo técnico independente; acesso interno.','display_in_menu'=>1,'sort_order'=>$role==='config'?1:4]));
            $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$role.entity',$entity)");
        }
        if(!$db->query("SHOW TABLES LIKE 'app_entity_$entity'")->num_rows)entities::prepare_tables($entity);
        $tab=$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity LIMIT 1")->fetch_assoc();
        $tabId=$tab?(int)$tab['id']:vdConfigInsert($db,'app_forms_tabs',array_replace($db->query('SELECT * FROM app_forms_tabs WHERE id=198')->fetch_assoc(),['entities_id'=>$entity,'name'=>'Identificação']));
        foreach($db->query("SELECT * FROM app_fields WHERE entities_id=172 AND type IN('fieldtype_action','fieldtype_id','fieldtype_date_added','fieldtype_date_updated','fieldtype_created_by')")->fetch_all(MYSQLI_ASSOC) as $field) {
            $type=$db->real_escape_string($field['type']);
            if (!$db->query("SELECT id FROM app_fields WHERE entities_id=$entity AND type='$type'")->num_rows) vdConfigInsert($db,'app_fields',array_replace($field,['entities_id'=>$entity,'forms_tabs_id'=>$tabId,'forms_rows_position'=>'']));
        }
        $fields=$role==='config'?[
            'name'=>['Produto / pedido','fieldtype_input',[]],
            'customer'=>['Cliente','fieldtype_entity_ajax',['entity_id'=>'26']],
            'reference'=>['Referência','fieldtype_input',[]],
            'product_type'=>['Tipo de produto','fieldtype_dropdown_multilevel',['use_global_list'=>'24','level_settings'=>"Tipo, Escolha o Tipo\r\nSubtipo"]],
            'notes'=>['Observações do pedido','fieldtype_textarea',[]]
        ]:[
            'name'=>['Avaliação','fieldtype_input',[]],
            'order'=>['Ordem de Produção','fieldtype_entity_ajax',['entity_id'=>'121']],
            'quote'=>['Orçamento','fieldtype_entity_ajax',['entity_id'=>'146']],
            'config'=>['Configurador de Produto','fieldtype_entity_ajax',['entity_id'=>(string)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.entity'")->fetch_row()[0]]],
            'review'=>['Revisão / conclusões','fieldtype_textarea',[]]
        ];
        foreach($fields as $fieldRole=>$definition) {
            $key="$role.$fieldRole";$known=$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$key'")->fetch_assoc();
            if ($known)continue;
            $template=$db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();
            $field=vdConfigInsert($db,'app_fields',array_replace($template,['entities_id'=>$entity,'forms_tabs_id'=>$tabId,'name'=>$definition[0],'type'=>$definition[1],
                'is_heading'=>$fieldRole==='name'?1:0,'listing_status'=>1,'is_required'=>$fieldRole==='name'?1:0,
                'forms_rows_position'=>'','configuration'=>json_encode($definition[2]+['width'=>'input-xlarge','display_as'=>'dropdown','display_as_link'=>'1','hide_plus_button'=>'1'],JSON_THROW_ON_ERROR)]));
            entities::prepare_field($entity,$field,$definition[1]);
            $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$key',$field)");
        }
        $reportGroups=['0'];
        foreach($db->query("SELECT * FROM app_entities_access WHERE entities_id=146")->fetch_all(MYSQLI_ASSOC) as $access) {
            $group=(int)$access['access_groups_id'];
            if(!in_array('view',explode(',',$access['access_schema']),true))continue;
            $reportGroups[]=(string)$group;
            if (!$db->query("SELECT id FROM app_entities_access WHERE entities_id=$entity AND access_groups_id=$group")->num_rows)
                vdConfigInsert($db,'app_entities_access',array_replace($access,['entities_id'=>$entity,'access_schema'=>implode(',',array_intersect(explode(',',$access['access_schema']),$role==='config'?['view','create','update']:['view','update']))]));
        }
        foreach(['menu_icon'=>$role==='config'?'fa-cubes':'fa-balance-scale'] as $key=>$value) if (!$db->query("SELECT id FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='$key'")->num_rows) {
            vdConfigInsert($db,'app_entities_configuration',['entities_id'=>$entity,'configuration_name'=>$key,'configuration_value'=>$value]);
        }
        $report=$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$role.report'")->fetch_assoc();
        if ($report) $reportId=(int)$report['native_id'];
        else {
            $template=$db->query('SELECT * FROM app_ext_report_page WHERE id=25')->fetch_assoc();
            $reportId=vdConfigInsert($db,'app_ext_report_page',array_replace($template,['entities_id'=>$entity,'name'=>$label,'button_title'=>$role==='config'?'Configurar produto':'Analisar desvios',
                'type'=>'page','save_as'=>'','save_filename'=>'','description'=>'','css'=>'','use_editor'=>0,'users_groups'=>implode(',',array_unique($reportGroups)),
                'settings'=>'{"hide_page_title":"0","hide_print_button":"1"}']));
            $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$role.report',$reportId)");
        }
        $code="require_once 'includes/vd_quote_workflow/product-configurator-view.php'; vd_product_configurator_report('".$role."', ".($role==='config'?'0':"(int)'[current_item_id]'").");";
        $settings=json_encode(['php_code'=>$code],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $block=$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$role.block'")->fetch_assoc();
        if (!$block) {
            $blockId=vdConfigInsert($db,'app_ext_report_page_blocks',['parent_id'=>0,'report_id'=>$reportId,'block_type'=>'php','name'=>$label,'field_id'=>0,'settings'=>$settings,'sort_order'=>10]);
            $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$role.block',$blockId)");
            $s=$db->prepare('UPDATE app_ext_report_page SET description=? WHERE id=?');$description='${'.$blockId.'}';$s->bind_param('si',$description,$reportId);$s->execute();
        }
        elseif($role==='config') {
            $old=$db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id='.(int)$block['native_id'])->fetch_row()[0];
            $previous=json_decode($old,true,512,JSON_THROW_ON_ERROR);
            $expected="require_once 'includes/vd_quote_workflow/product-configurator-view.php'; vd_product_configurator_report('config', (int)'[current_item_id]');";
            if($old!==$settings) {
                if(($previous['php_code']??'')!==$expected)throw new RuntimeException('Report block changed externally.');
                $s=$db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=? AND settings=?');
                $s->bind_param('sis',$settings,$block['native_id'],$old);$s->execute();
            }
        }
        if($role==='config') {
            $db->query("UPDATE app_ext_report_page SET entities_id=0 WHERE id=$reportId AND entities_id IN(0,$entity)");
            $db->query("UPDATE app_entities SET display_in_menu=0 WHERE id=$entity");
            $db->query("DELETE FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='redirect_after_click_heading' AND configuration_value='report_page$reportId'");
            if(!$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.menu'")->num_rows) {
                $menuId=vdConfigInsert($db,'app_entities_menu',['parent_id'=>0,'name'=>'Configurador de Produto','icon'=>'fa-cubes','icon_color'=>'','bg_color'=>'',
                    'entities_list'=>(string)$entity,'reports_list'=>'report_page'.$reportId,'pages_list'=>'','type'=>'url','url'=>'index.php?module=report_page/view&id='.$reportId,
                    'users_groups'=>implode(',',array_unique($reportGroups)),'assigned_to'=>'','sort_order'=>0]);
                $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('config.menu',$menuId)");
            }
            $menuId=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.menu'")->fetch_row()[0];
            // Listing the support entity here excludes its duplicate automatic root menu.
            $db->query("UPDATE app_entities_menu SET entities_list='$entity' WHERE id=$menuId AND entities_list='' AND type='url' AND url='index.php?module=report_page/view&id=$reportId'");
            $customerField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.customer'")->fetch_row()[0];
            $db->query("UPDATE app_fields SET is_required=1 WHERE id=$customerField AND entities_id=$entity");
            $typeField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.product_type'")->fetch_row()[0];
            $db->query("UPDATE app_fields SET is_required=1 WHERE id=$typeField AND entities_id=$entity");
        }
        if ($role!=='config'&&!$db->query("SELECT id FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='redirect_after_click_heading'")->num_rows) {
            vdConfigInsert($db,'app_entities_configuration',['entities_id'=>$entity,'configuration_name'=>'redirect_after_click_heading','configuration_value'=>'report_page'.$reportId]);
        }
        echo "$role entity=$entity report=$reportId\n";
    }
    foreach(['quote.config'=>146,'order.config'=>121] as $role=>$entity) {
        if($db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$role'")->num_rows)continue;
        $configEntity=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='config.entity'")->fetch_row()[0];
        $tab=(int)$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity LIMIT 1")->fetch_row()[0];
        $template=$db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();
        $field=vdConfigInsert($db,'app_fields',array_replace($template,['entities_id'=>$entity,'forms_tabs_id'=>$tab,'name'=>'Configurador de Produto (origem)','type'=>'fieldtype_entity_ajax',
            'is_heading'=>0,'is_required'=>0,'listing_status'=>1,'forms_rows_position'=>'',
            'configuration'=>json_encode(['entity_id'=>(string)$configEntity,'width'=>'input-large','display_as'=>'dropdown','display_as_link'=>'1','hide_plus_button'=>'1'],JSON_THROW_ON_ERROR)]));
        entities::prepare_field($entity,$field,'fieldtype_entity_ajax');
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$role',$field)");
    }
    // Explicit actual measurements: blank is unknown; zero is a recorded measurement.
    foreach(['actual_material'=>[394,'Consumo real confirmado','fieldtype_input_numeric'],
        'actual_material_price'=>[394,'Custo unitário real (€)','fieldtype_input_numeric'],
        'actual_operation_cost'=>[170,'Custo real confirmado (€)','fieldtype_input_numeric'],
        'actual_order_cost'=>[121,'Custo real global confirmado (€)','fieldtype_input_numeric']] as $role=>$definition) {
        if ($db->query("SELECT native_id FROM app_vd_config_schema WHERE role='$role'")->num_rows)continue;
        [$entity,$label,$type]=$definition;
        $tab=(int)$db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity LIMIT 1")->fetch_row()[0];
        $template=$db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();
        $field=vdConfigInsert($db,'app_fields',array_replace($template,['entities_id'=>$entity,'forms_tabs_id'=>$tab,'name'=>$label,'type'=>$type,'is_heading'=>0,'is_required'=>0,'listing_status'=>0,'forms_rows_position'=>'',
            'tooltip'=>'Valor real confirmado para avaliação de desvios. Vazio = não medido; zero = medido como zero. Não movimenta stock.',
            'configuration'=>'{"width":"input-small","number_format":"6/./ "}']));
        entities::prepare_field($entity,$field,$type);
        $db->query("INSERT INTO app_vd_config_schema(role,native_id) VALUES('$role',$field)");
    }
    $queue="INSERT INTO app_vd_variance_queue(order_id,queued_at,error) VALUES(NEW.id,UNIX_TIMESTAMP(),NULL) ON DUPLICATE KEY UPDATE queued_at=VALUES(queued_at),error=NULL;";
    $totalField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='actual_order_cost'")->fetch_row()[0];
    $previousOrderStatement="BEGIN IF NEW.field_2437=883 AND NOT(OLD.field_2437<=>NEW.field_2437) AND EXISTS(SELECT 1 FROM app_vd_quote_production_orders WHERE order_id=NEW.id) THEN $queue END IF; END";
    $triggers=[
        'vd_config_source_immutable'=>"BEFORE UPDATE ON app_vd_product_quote_sources FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Configuracao de origem imutavel: gerar nova revisao'",
        'vd_evaluate_completed_order'=>"AFTER UPDATE ON app_entity_121 FOR EACH ROW BEGIN IF NEW.field_2437=883 AND (NOT(OLD.field_2437<=>NEW.field_2437) OR NOT(NEW.field_$totalField<=>OLD.field_$totalField)) AND EXISTS(SELECT 1 FROM app_vd_quote_production_orders WHERE order_id=NEW.id) THEN $queue END IF; IF NEW.field_2437<>883 THEN DELETE FROM app_vd_variance_queue WHERE order_id=NEW.id; END IF; END"
    ];
    $materialField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='actual_material'")->fetch_row()[0];
    $priceField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='actual_material_price'")->fetch_row()[0];
    $costField=(int)$db->query("SELECT native_id FROM app_vd_config_schema WHERE role='actual_operation_cost'")->fetch_row()[0];
    $enqueue=static function($select){return "INSERT INTO app_vd_variance_queue(order_id,queued_at,error) $select ON DUPLICATE KEY UPDATE queued_at=VALUES(queued_at),error=NULL;";};
    $triggers['vd_evaluate_actual_material']="AFTER UPDATE ON app_entity_394 FOR EACH ROW BEGIN IF NOT(NEW.field_$materialField<=>OLD.field_$materialField) OR NOT(NEW.field_$priceField<=>OLD.field_$priceField) THEN ".$enqueue("SELECT o.id,UNIX_TIMESTAMP(),NULL FROM app_entity_159 c JOIN app_entity_121 o ON o.id=c.parent_item_id JOIN app_vd_quote_production_orders p ON p.order_id=o.id WHERE c.id=NEW.parent_item_id AND o.field_2437=883")." END IF; END";
    $triggers['vd_evaluate_actual_operation']="AFTER UPDATE ON app_entity_170 FOR EACH ROW BEGIN IF NOT(NEW.field_$costField<=>OLD.field_$costField) OR NOT(NEW.field_2425<=>OLD.field_2425) THEN ".$enqueue("SELECT o.id,UNIX_TIMESTAMP(),NULL FROM app_entity_159 c JOIN app_entity_121 o ON o.id=c.parent_item_id JOIN app_vd_quote_production_orders p ON p.order_id=o.id WHERE c.id=NEW.parent_item_id AND o.field_2437=883")." END IF; END";
    foreach(['INSERT','UPDATE','DELETE'] as $event) {
        $ref=$event==='DELETE'?'OLD':'NEW';
        $triggers['vd_evaluate_time_'.strtolower($event)]="AFTER $event ON app_entity_171 FOR EACH ROW BEGIN ".
            $enqueue("SELECT o.id,UNIX_TIMESTAMP(),NULL FROM app_entity_170 t JOIN app_entity_159 c ON c.id=t.parent_item_id JOIN app_entity_121 o ON o.id=c.parent_item_id JOIN app_vd_quote_production_orders p ON p.order_id=o.id WHERE t.id=$ref.parent_item_id AND o.field_2437=883")." END";
    }
    foreach($triggers as $name=>$body) {
        $old=$db->query("SHOW TRIGGERS WHERE `Trigger`='$name'")->fetch_assoc();
        $statement=preg_replace('/^(?:AFTER|BEFORE) (?:INSERT|UPDATE|DELETE) ON \w+ FOR EACH ROW /','',$body);
        if($old) {
            if(preg_replace('/\s+/','',$old['Statement'])!==preg_replace('/\s+/','',$statement)) {
                if($name!=='vd_evaluate_completed_order'||preg_replace('/\s+/','',$old['Statement'])!==preg_replace('/\s+/','',$previousOrderStatement))throw new RuntimeException('Evaluation trigger changed: '.$name);
                $db->query("DROP TRIGGER $name");$db->query("CREATE TRIGGER $name $body");
            }
        }else $db->query("CREATE TRIGGER $name $body");
    }
} finally {$db->query("SELECT RELEASE_LOCK('vd_product_config_schema')");}
if(in_array('--enable-worker',$argv,true)) {
    $worker='/home/vianaedias/public_html/includes/vd_quote_workflow/production-evaluation-worker.php';
    if(!is_file($worker)||hash_file('sha256',$worker)!==hash_file('sha256',__DIR__.'/production-evaluation-worker.php'))throw new RuntimeException('Publish the verified worker before enabling automation.');
    $cron="* * * * * root /opt/cpanel/ea-php74/root/usr/bin/php -d display_errors=stderr $worker >> /var/log/vd-production-evaluation.log 2>&1\n";
    $path='/etc/cron.d/vd-production-evaluation';
    if(is_file($path)&&file_get_contents($path)!==$cron)throw new RuntimeException('Existing evaluation schedule changed.');
    if(!is_file($path)&&file_put_contents($path,$cron)!==strlen($cron))throw new RuntimeException('Cannot persist evaluation schedule.');
    if(!chmod($path,0644))throw new RuntimeException('Cannot set evaluation schedule permissions.');
    echo "Automatic evaluation worker enabled (every minute).\n";
}

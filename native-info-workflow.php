<?php
/**
 * Этот файл является частью программы "CRM Руководитель" - конструктор CRM систем для бизнеса
 * https://www.rukovoditel.net.ru/
 * 
 * CRM Руководитель - это свободное программное обеспечение, 
 * распространяемое на условиях GNU GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * 
 * Автор и правообладатель программы: Харчишина Ольга Александровна (RU), Харчишин Сергей Васильевич (RU).
 * Государственная регистрация программы для ЭВМ: 2023664624
 * https://fips.ru/EGD/3b18c104-1db7-4f2d-83fb-2d38e1474ca3
 */
?>

<?php
if ((int) $current_entity_id === 395) {
    require_once 'includes/vd_quote_workflow/product-request-view.php';
    vd_config_request_info((int) $current_item_id);
    return;
}
?>
<?php require(component_path('items/navigation')) ?>
<?php if ((int) $current_entity_id === 121): ?>
<?php $vdProductionSource = db_fetch_array(db_query("SELECT document_id,quantity,unassigned_materials FROM app_vd_quote_production_orders WHERE order_id=" . (int) $current_item_id)); ?>
<?php if ($vdProductionSource): ?>
<div class="alert alert-info">OP criada a partir da proposta #<?= (int) $vdProductionSource['document_id'] ?>, alternativa de <?= htmlspecialchars($vdProductionSource['quantity'], ENT_QUOTES, 'UTF-8') ?> unidades. Componentes, rubricas, planos, operações e materiais foram fixados sem movimentar stock. O restabelecimento da adjudicação está bloqueado enquanto existir OP.</div>
<?php if ((int) $vdProductionSource['unassigned_materials'] > 0): ?>
<div class="alert alert-warning"><?= (int) $vdProductionSource['unassigned_materials'] ?> material(is) têm referência/consumo previstos mas não um artigo específico fixado. Reveja os materiais antes de reservar stock.</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>


<?php if ((int) $current_entity_id === 158): ?>
<?php
require_once 'includes/vd_quote_workflow/quote-workflow-store.php';
$vdProductionDb = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$vdProductionDb->set_charset('utf8mb4');
$vdProductionWorkflow = new VDQuoteWorkflow($vdProductionDb, $app_user);
try {
    echo $vdProductionWorkflow->productionPresentation((int) $current_entity_id, (int) $current_item_id);
} catch (Throwable $vdProductionError) {
    error_log('VD production presentation: ' . $vdProductionError->getMessage());
    echo '<div class="alert alert-danger">Não foi possível apresentar os materiais ou o gráfico da imposição. Consulte o registo técnico.</div>';
}
$vdProductionDb->close();
?>
<?php endif; ?>
<?php if ((int) $current_entity_id === 121): ?>
<?php
$vdFlowOrder = (int) $current_item_id;
$vdConfigOriginField = (int) db_fetch_array(db_query("SELECT native_id FROM app_vd_config_schema WHERE role='quote.config'"))['native_id'];
$vdFlowOrigin = db_fetch_array(db_query("SELECT p.quote_id,q.field_$vdConfigOriginField config_id FROM app_vd_quote_production_orders p JOIN app_entity_146 q ON q.id=p.quote_id WHERE p.order_id=$vdFlowOrder"));
$vdFlowEvaluation = db_fetch_array(db_query("SELECT native_id FROM app_vd_production_evaluations WHERE order_id=$vdFlowOrder"));
$vdFlowQueue = db_fetch_array(db_query("SELECT error FROM app_vd_variance_queue WHERE order_id=$vdFlowOrder"));
?>
<?php if ($vdFlowOrigin): ?>
<div class="vd-production-flow" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px">
<a class="btn btn-default btn-sm" href="<?= htmlspecialchars(url_for('items/info','path=146-'.(int)$vdFlowOrigin['quote_id']),ENT_QUOTES,'UTF-8') ?>">Orçamento de origem</a>
<?php if (!empty($vdFlowOrigin['config_id'])): ?>
<?php $vdFlowConfigReport = db_fetch_array(db_query("SELECT native_id FROM app_vd_config_schema WHERE role='config.report'")); ?>
<a class="btn btn-default btn-sm" href="<?= htmlspecialchars(url_for('report_page/view','id='.(int)$vdFlowConfigReport['native_id']).'#configuration='.(int)$vdFlowOrigin['config_id'],ENT_QUOTES,'UTF-8') ?>">Configurador de Produto</a>
<?php endif; ?>
<?php if ($vdFlowEvaluation): ?>
<?php $vdFlowEvaluationEntity = db_fetch_array(db_query("SELECT native_id FROM app_vd_config_schema WHERE role='evaluation.entity'")); ?>
<a class="btn btn-info btn-sm" href="<?= htmlspecialchars(url_for('items/info','path='.(int)$vdFlowEvaluationEntity['native_id'].'-'.(int)$vdFlowEvaluation['native_id']),ENT_QUOTES,'UTF-8') ?>">Avaliação de desvios</a>
<?php endif; ?>
<?php if ($vdFlowQueue): ?><span class="alert <?= !empty($vdFlowQueue['error'])?'alert-danger':'alert-info' ?>"><?= !empty($vdFlowQueue['error'])?'Não foi possível atualizar a avaliação. O erro ficou registado para intervenção técnica.':'Avaliação de desvios: atualização automática pendente.' ?></span><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if (in_array((int) $current_entity_id, [146,262], true) && users::has_access_to_entity(146, 'view')): ?>
<?php
$vdActionQuote = (int) $current_entity_id === 146 ? (int) $current_item_id : (int) $item_info['parent_item_id'];
$vdActionControl = db_fetch_array(db_query("SELECT production_document FROM app_vd_quote_control WHERE quote_id=$vdActionQuote"));
$vdAdjudicated = !empty($vdActionControl['production_document']);
$vdActionOrder = db_fetch_array(db_query("SELECT p.order_id,o.field_1992 delivery_date FROM app_vd_quote_production_orders p JOIN app_entity_121 o ON o.id=p.order_id WHERE p.quote_id=$vdActionQuote"));
$vdConfigOriginField = (int) db_fetch_array(db_query("SELECT native_id FROM app_vd_config_schema WHERE role='quote.config'"))['native_id'];
$vdConfigSource = db_fetch_array(db_query("SELECT field_$vdConfigOriginField config_id FROM app_entity_146 WHERE id=$vdActionQuote AND field_$vdConfigOriginField+0>0"));
$vdDiscountStale = null;
$vdStaleDocument = null;
if (!$vdAdjudicated && !db_fetch_array(db_query("SELECT id FROM app_vd_quote_documents WHERE quote_id=$vdActionQuote AND (sent_at IS NOT NULL OR state IN('sending','send_unknown')) LIMIT 1"))) {
    $vdStaleDocument = db_fetch_array(db_query("SELECT id,snapshot FROM app_vd_quote_documents WHERE quote_id=$vdActionQuote AND state IN('approved','send_failed') ORDER BY id DESC LIMIT 1"));
    if ($vdStaleDocument) {
        require_once 'includes/vd_quote_workflow/quote-workflow-domain.php';
        $vdExtraRole = db_fetch_array(db_query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=146 AND role='extra_discount'"));
        $vdDiscountQuote = db_fetch_array(db_query("SELECT q.field_" . (int) $vdExtraRole['field_id'] . " extra,c.field_5124 customer FROM app_entity_146 q LEFT JOIN app_entity_26 c ON c.id=q.field_3923 WHERE q.id=$vdActionQuote"));
        $vdDocumentCustomer = json_decode($vdStaleDocument['snapshot'], true)['customer'] ?? [];
        try {
            $vdApprovedDiscount = [vd_workflow_discount_percent($vdDocumentCustomer['discountPercent'] ?? 0),
                vd_workflow_discount_percent($vdDocumentCustomer['extraDiscountPercent'] ?? 0)];
            $vdCurrentDiscount = [vd_workflow_discount_percent($vdDiscountQuote['customer'] ?? null),
                vd_workflow_discount_percent($vdDiscountQuote['extra'] ?? 0)];
            if ($vdApprovedDiscount !== $vdCurrentDiscount) $vdDiscountStale = [$vdApprovedDiscount, $vdCurrentDiscount];
        } catch (DomainException $vdDiscountError) {
            $vdDiscountStale = $vdDiscountError->getMessage();
        }
    }
}
$vdPercent = function (array $values) {
    return implode(' + ', array_map(function ($value) { return str_replace('.', ',', (string) (0 + $value)) . ' %'; }, $values));
};
?>
<?php if ($vdDiscountStale): ?>
<div class="alert alert-warning vd-discount-stale"><strong>Desconto alterado depois da aprovação.</strong>
<?php if (is_array($vdDiscountStale)): ?>
A proposta #<?= (int) $vdStaleDocument['id'] ?> foi aprovada com <?= htmlspecialchars($vdPercent($vdDiscountStale[0]), ENT_QUOTES, 'UTF-8') ?>; o desconto atual é <?= htmlspecialchars($vdPercent($vdDiscountStale[1]), ENT_QUOTES, 'UTF-8') ?>.
<?php else: ?>
<?= htmlspecialchars($vdDiscountStale, ENT_QUOTES, 'UTF-8') ?>
<?php endif; ?>
A proposta e o PDF aprovados não refletem este desconto e não podem ser enviados.
<?= link_to_modalbox('Aprovar novamente com o desconto atual', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1'), ['class' => 'btn btn-warning btn-sm']) ?>
</div>
<?php endif; ?>
<div class="vd-quote-actions" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:15px">
<?php if ($vdConfigSource): ?>
<?php $vdConfigReport = db_fetch_array(db_query("SELECT native_id FROM app_vd_config_schema WHERE role='config.report'")); ?>
<a class="btn btn-default btn-sm" href="<?= htmlspecialchars(url_for('report_page/view','id='.(int)$vdConfigReport['native_id']).'#configuration='.(int)$vdConfigSource['config_id'],ENT_QUOTES,'UTF-8') ?>">Abrir Configurador de Produto</a>
<?php endif; ?>
<?= link_to_modalbox('Produto, componentes e etapas', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&product=1'), ['class' => 'btn btn-default btn-sm']) ?>
<?= link_to_modalbox('Análise de tinta (PDF)', url_for('items/vd_quote_ink', 'path=' . urlencode($current_path)), ['class' => 'btn btn-default btn-sm']) ?>
<?= link_to_modalbox('Versões, aprovação e proposta comercial do orçamento', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1'), ['class' => 'btn btn-info btn-sm']) ?>
<?php if ($vdDiscountStale): ?>
<button type="button" class="btn btn-primary btn-sm" disabled title="Aprove novamente com o desconto atual">Descarregar orçamento em PDF</button>
<?php else: ?>
<a class="btn btn-primary btn-sm" href="<?= htmlspecialchars(url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&pdf=1'), ENT_QUOTES, 'UTF-8') ?>">Descarregar orçamento em PDF</a>
<?php endif; ?>
<?php if ($vdAdjudicated): ?>
<button type="button" class="btn btn-success btn-sm" disabled title="Orçamento adjudicado">Enviar ao cliente</button>
<?php else: ?>
<?= link_to_modalbox('Enviar ao cliente', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1&send_only=1'), ['class' => 'btn btn-success btn-sm']) ?>
<?php endif; ?>
<?php if ((int) $current_entity_id === 146): ?>
<?php if ($vdAdjudicated): ?>
<button type="button" class="btn btn-primary btn-sm" disabled title="Orçamento adjudicado">Adjudicar orçamento</button>
<?php else: ?>
<?= link_to_modalbox('Adjudicar orçamento', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1&accept_only=1'), ['class' => 'btn btn-primary btn-sm']) ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($vdActionOrder && users::has_access_to_entity(121, 'view')): ?>
<a class="btn btn-info btn-sm" href="<?= htmlspecialchars(url_for('items/info', 'path=121-' . (int) $vdActionOrder['order_id']), ENT_QUOTES, 'UTF-8') ?>">Abrir ordem de produção</a>
<?php if (!(int) $vdActionOrder['delivery_date']): ?>
<?= link_to_modalbox('Definir data prevista de entrega', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1&accept_only=1'), ['class' => 'btn btn-warning btn-sm']) ?>
<?php endif; ?>
<?php elseif (!$vdActionOrder && $vdAdjudicated): ?>
<?= link_to_modalbox('Gerar ordem de produção', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1&accept_only=1'), ['class' => 'btn btn-info btn-sm']) ?>
<?php endif; ?>
<?php if ($vdAdjudicated && !$vdActionOrder && (int) $app_user['group_id'] === 0): ?>
<?= link_to_modalbox('Restabelecer envio e adjudicação', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1&accept_only=1'), ['class' => 'btn btn-warning btn-sm']) ?>
<?php endif; ?>
</div>
<?php endif; ?>


<?php

$help_pages = new help_pages($current_entity_id);
echo $help_pages->render_announcements('info', $current_item_id);

$current_item_info = [];
$item_page_columns = explode('-', $entity_cfg -> get('item_page_columns_size', '8-4'));
?>

<!-- include form fields display rules in info page  -->
<?php 
$forms_fields_rules = new forms_fields_rules($current_entity_id);                    
echo $forms_fields_rules->apply();
?>

<div class="row">

    <!-- First Column  -->
    <div class="col-md-<?php echo $item_page_columns[0] ?> project-info">

        <?php $portlets = new portlets('item_info_' . $current_item_id, ($entity_cfg->get('record_info_collapsed')==1 ? true:false)) ?>
        <div class="portlet portlet-item-description">
            <div class="portlet-title">
                <div class="caption" >        
                    <?php echo $app_breadcrumb[count($app_breadcrumb) - 1]['title'] ?>             
                </div>
                
                <div class="tools">
                    <?php
                    
                    $favorites = new favorites($current_entity_id,$current_item_id);
                    echo $favorites->render_icon();
                    
                    
                    echo $help_pages -> render_icon('info');                                        
                    ?>

                    <a href="javascript:;" class="<?php echo $portlets->button_css() ?>"></a>
                </div>    
                
            </div>
            <div class="portlet-body" <?php echo $portlets->render_body() ?>>



                <!-- Inlucde timer from Extension -->          
                <?php
                $access_rules = new access_rules($current_entity_id, $item_info);

                $item_actions_menu = '';

                if(is_ext_installed())
                {
                    $timer = new timer($current_entity_id, $current_item_id);
                    $item_actions_menu .= $timer -> render_button();
                }

                if(users::has_access('update', $access_rules -> get_access_schema()))
                {
                    $check = true;

                    if(users::has_access('update_creator', $access_rules -> get_access_schema()) and $item_info['created_by'] != $app_user['id'])
                    {
                        $check = false;
                    }

                    if($check)
                    {
                        $item_actions_menu .= '<li>' . button_tag(TEXT_BUTTON_EDIT, url_for('items/form', 'id=' . $current_item_id . '&entity_id=' . $current_entity_id . '&path=' . $_GET['path'] . '&redirect_to=items_info'), true, array('class' => 'btn btn-primary btn-sm'), 'fa-edit') . '</li>';
                    }
                }

                $export_templates_menu = '';
                
                if(is_ext_installed())
                {
                    $processes = new processes($current_entity_id);
                    $processes -> items_id = $current_item_id;
                    $item_actions_menu .= $processes -> render_buttons('default');

                    //print templates
                    $export_templates_menu .= export_templates::get_users_templates_by_position($current_entity_id, 'default')
                                              . report_page\report::get_buttons_by_position($current_entity_id, $current_item_id, 'default');
                    
                    $export_templates_menu_print = export_templates::get_users_templates_by_position($current_entity_id, 'menu_print')
                                                   . report_page\report::get_buttons_by_position($current_entity_id, $current_item_id, 'menu_print');
                    
                    if(strlen($export_templates_menu_print))
                    {
                        $export_templates_menu .= '
                            <li>
                                <div class="btn-group">
                                    <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" data-hover="dropdown">
                                        <i class="fa fa-print"></i> ' . TEXT_PRINT . ' <i class="fa fa-angle-down"></i>
                                    </button>
                                    <ul class="dropdown-menu" role="menu">                                       
                                        ' . $export_templates_menu_print . '												
                                    </ul>
                                </div>
                            </li>
                            ';
                    }
                    
                    $item_actions_menu .= $export_templates_menu;

                    $item_actions_menu .= xml_export::get_users_templates_by_position($current_entity_id, 'default');
                    $item_actions_menu .= xml_export::get_users_templates_by_position($current_entity_id, 'menu_export');

                    $item_actions_menu .= xml_import::get_users_templates_by_position($current_entity_id, 'default');
                }

                $more_actions_menu = '';

                if(is_ext_installed())
                    $more_actions_menu .= $processes -> render_buttons('menu_more_actions');

                $more_actions_menu .= plugins::render_simple_menu_items('more_actions');
                
                if((users::has_access('update', $access_rules -> get_access_schema()) or users::has_access('create', $access_rules -> get_access_schema())) and listing_types::has_tree_table($current_entity_id))
                {
                    $more_actions_menu .= '<li>' . link_to_modalbox('<i class="fa fa-sitemap"></i> ' . TEXT_CHANGE_PARENT_ITEM, url_for('items/change_parent', 'path=' . $_GET['path'])) . '</li>';
                }

                if(users::has_access('export', $access_rules -> get_access_schema()))
                {
                    $more_actions_menu .= '<li>' . link_to_modalbox('<i class="fa fa-file-pdf-o"></i> ' . TEXT_BUTTON_EXPORT, url_for('items/single_export', 'path=' . $_GET['path'])) . '</li>';
                }

                if(users::has_access('update', $access_rules -> get_access_schema()) and $current_entity_id == 1)
                {
                    $more_actions_menu .= '<li>' . link_to('<i class="fa fa-unlock-alt"></i> ' . TEXT_CHANGE_PASSWORD, url_for('items/change_user_password', 'path=' . $_GET['path'])) . '</li>';
                }

                if(users::has_access('delete', $access_rules -> get_access_schema()))
                {
                    $check = true;

                    if(users::has_access('delete_creator', $access_rules -> get_access_schema()) and $item_info['created_by'] != $app_user['id'])
                    {
                        $check = false;
                    }

                    if($check)
                    {
                        $more_actions_menu .= '<li><a href="#" onClick="open_dialog(\'' . url_for('items/delete', 'id=' . $current_item_id . '&entity_id=' . $current_entity_id . '&path=' . $_GET['path']) . '\'); return false;"><i class="fa fa-trash-o"></i> ' . TEXT_BUTTON_DELETE . '</a></li>';
                    }
                }

//check access to action with assigned only
                if(users::has_access('action_with_assigned'))
                {
                    if(!users::has_access_to_assigned_item($current_entity_id, $current_item_id))
                    {
                        $item_actions_menu = $more_actions_menu = '';
                        $item_actions_menu = $export_templates_menu;
                    }
                }

                if(strlen($more_actions_menu))
                {
                    $item_actions_menu .= '
			<li>
	  	 <div class="btn-group">
					<button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" data-hover="dropdown">
					' . TEXT_MORE_ACTIONS . ' <i class="fa fa-angle-down"></i>
					</button>
					<ul class="dropdown-menu" role="menu">                                       
					' . $more_actions_menu . '												
					</ul>
				</div>
			</li>
	';
                }

                if(strlen($item_actions_menu))
                {
                    echo '
		<div class="prolet-body-actions">        
          <ul class="list-inline">
					' . $item_actions_menu . '
          </ul>
        </div>
		';
                }


//Stages panels
                echo stages_panel::render($current_entity_id, $item_info);
                ?>    

                <!-- Inlucde timer from Extension -->          
                <?php
                if(is_ext_installed())
                {
                    echo $timer -> render();
                }
                ?>        

                <div class="item-content-box ckeditor-images-content-prepare">
                    <?php
                    if(in_array($entity_cfg -> get('item_page_details_columns'), ['one_column_tabs', 'one_column_accordion']))
                    {
                        $items_page = new items_page($current_entity_id, $current_item_id);
                        echo $items_page -> render($entity_cfg -> get('item_page_details_columns'));
                    }
                    elseif($entity_cfg -> get('item_page_details_columns', '2') == 1)
                    {
                        echo items::render_info_box($current_entity_id, $current_item_id, false, false);
                    }
                    else
                    {
                        echo items::render_content_box($current_entity_id, $current_item_id);
                    }
                    ?>
                </div>

            </div>
        </div>

        <?php
//include related emails    
        if(is_ext_installed())
        {
            $mail_related = new mail_related($current_entity_id, 'left_column');
            echo '<div id="mail_related_left_column">' . $mail_related -> render_list($current_item_id, $item_info) . '</div>';
        }

//include reladed records that displays as single list    
        $reladed_records = new related_records($current_entity_id, $current_item_id);
        echo $reladed_records -> render_as_single_list();
        
        echo tree_table::render_nested_items($current_entity_id, $current_item_id, 'left_column');
        
//includes subentity imtes listins if configure for item info page    
        $subentities_items_position = 'left_column';
        require(component_path('items/load_subentities_items'));

//includes field entity imtes listins if configure for item info page
        $field_entity_items_position = 'left_column';
        require(component_path('items/load_field_entity_items'));

        if(is_ext_installed())
        {
            $item_pivot_tables = new item_pivot_tables($current_entity_id, 'left_column');
            echo $item_pivot_tables -> render();
        }
        ?>

        <?php
//include items comments if user have access and comments enabled     
        if(users::has_comments_access('view', $access_rules -> get_comments_access_schema()) and $entity_cfg -> get('use_comments') == 1 and $entity_cfg -> get('item_page_comments_position', 'left_column') == 'left_column')
        {
            require(component_path('items/comments'));
        }
        ?>

    </div>

    <!-- Second Column  -->
    <div class="col-md-<?php echo $item_page_columns[1] ?>" style="position:static">

        <?php
//include related emails	    
        if(is_ext_installed())
        {
            $mail_related = new mail_related($current_entity_id, 'right_column');
            echo '<div id="mail_related_right_column">' . $mail_related -> render_list($current_item_id, $item_info) . '</div>';
        }

        //include related records in box    
        echo $reladed_records -> render_as_single_list(false);
        
        echo tree_table::render_nested_items($current_entity_id, $current_item_id, 'right_column');
        ?>

        <?php if($entity_cfg -> get('item_page_details_columns', '2') == 2 and strlen($info_box = items::render_info_box($current_entity_id, $current_item_id))): ?>
            <div class="panel panel-info item-details">
                <div class="panel-body item-details">            
                    <?php echo $info_box ?>
                </div>
            </div>
        <?php endif ?>

        <?php
//includes subentity imtes listins if configure for item info page    
        $subentities_items_position = 'right_column';
        require(component_path('items/load_subentities_items'));

//includes field entity imtes listins if configure for item info page
        $field_entity_items_position = 'right_column';
        require(component_path('items/load_field_entity_items'));

        if(is_ext_installed())
        {
            $item_pivot_tables = new item_pivot_tables($current_entity_id, 'right_column');
            echo $item_pivot_tables -> render();
        }
        ?>

        <?php
        //include items comments if user have access and comments enabled     
        if(users::has_comments_access('view') and $entity_cfg -> get('use_comments') == 1 and $entity_cfg -> get('item_page_comments_position', '') == 'right_column')
        {
            require(component_path('items/comments'));
        }
        ?>

    </div>
</div>  

<script>
    $(function ()
    {
        ckeditor_images_content_prepare();
        
        handleFieldtype3dviewer()
    })
</script>

<!-- inluce js to load item listing -->
<?php require(component_path('items/load_items_listing.js')); ?>

<?php require(component_path('items/item_page_custom_code')); ?>
<?php if ((int) $current_entity_id === 161): ?>
<?php $vdMaterialSource = db_fetch_array(db_query("SELECT id FROM app_vd_quote_production_rows WHERE entity_id=161 AND native_id=" . (int) $current_item_id)); ?>
<?php if ($vdMaterialSource): ?>
<div class="alert alert-info">Material previsto: <?= htmlspecialchars($item_info['field_2543'], ENT_QUOTES, 'UTF-8') ?>. Consulte o artigo, referência interna, quantidade de precisão e unidade previstos. Não existe reserva ou consumo de stock. O campo Stock legado não representa disponibilidade verificada deste artigo; a ligação ao armazém fica para a fase de aprovisionamento.</div>
<?php endif; ?>
<?php endif; ?>
<?php if ((int) $current_entity_id === 170 && users::has_access_to_entity(161, 'view')): ?>
<?php $vdOperationMaterials = db_fetch_array(db_query("SELECT COUNT(*) total FROM app_vd_quote_production_rows WHERE entity_id=161 AND parent_id=" . (int) $current_item_id)); ?>
<?php if ((int) $vdOperationMaterials['total'] > 0): ?>
<p><a class="btn btn-info btn-sm" href="<?= htmlspecialchars(url_for('items/items', 'path=' . $current_path . '/161'), ENT_QUOTES, 'UTF-8') ?>">Materiais previstos desta operação (<?= (int) $vdOperationMaterials['total'] ?>)</a></p>
<?php endif; ?>
<?php endif; ?>
<?php if ((int) $current_entity_id === 276 && (int) ($parent_entity_item_id ?? 0) > 0): ?>
<div class="alert alert-info">As linhas manuais configuram a simulação; as operações de produção só são materializadas após a aprovação do orçamento.</div>
<?php endif; ?>

<?php
require_once __DIR__.'/product-request-conversion.php';

function vd_config_request_report_html(array $state,array $catalog,$status,$actions,$report,$api) {
    $escape=static function($text){return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');};
    $payload=$state['payload'];
    if(!$payload)throw new DomainException('O pedido ainda não tem uma configuração guardada.');
    $types=array_column($catalog['product_types'],null,'id');$type=(int)$payload['product_type'];$path=[];$visited=[];
    while(isset($types[$type])&&!isset($visited[$type])) {
        $visited[$type]=true;array_unshift($path,$types[$type]['name']);$type=(int)$types[$type]['parent_id'];
    }
    $html='<div id="vd-product-request-summary"><h2>Resumo do pedido #'.(int)$state['id'].'</h2>'.
        '<h3>'.$escape($state['name']).'</h3><div class="well well-sm">'.
        '<strong>Cliente:</strong> '.$escape($state['customer_name']).' · <strong>Referência:</strong> '.$escape($state['reference']).
        '<br><strong>Produto:</strong> '.$escape(implode(' / ',$path)).
        '<br><strong>Quantidades:</strong> '.$escape(implode(', ',$payload['quantities'])).' · <strong>Revisão:</strong> '.(int)$state['revision'].
        '<br><strong>Estado:</strong> '.$escape($status).'</div>';
    $html.='<div class="noprint" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">'.
        '<a class="btn btn-default" href="'.$escape('index.php?module=report_page/view&id='.(int)$report.'#configuration='.(int)$state['id']).'">Editar pedido no configurador</a>'.
        (!empty($state['conversion']['quote'])?'<a class="btn btn-primary" href="'.$escape('index.php?module=report_page/view&id='.(int)$report.'#duplicate='.(int)$state['id']).'">Duplicar para novo orçamento</a>':'').
        $actions.'</div>';
    if(!empty($state['conversion']['error']))$html.='<div class="alert alert-danger" role="alert">'.$escape($state['conversion']['error']).'</div>';
    if(!empty($state['conversion']['quote']))$html.='<div class="alert alert-info">Orçamento associado: <a href="index.php?module=items/info&amp;path=146-'.(int)$state['conversion']['quote'].'">#'.(int)$state['conversion']['quote'].'</a>. Alterar o pedido não altera o orçamento nem o histórico já guardado.</div>';
    $summary=$state;
    $summary['payload']['components']=array_values(array_filter($payload['components'],static function($part){return $part['kind']!=='assembly';}));
    foreach(explode("\n\n",vd_config_request_summary($summary,$catalog['choices'])) as $section) {
        $lines=explode("\n",$section);$title=array_shift($lines);
        $html.='<section class="panel panel-default"><div class="panel-heading"><strong>'.$escape($title).'</strong></div>'.
            '<div class="panel-body">'.nl2br($escape(implode("\n",$lines))).'</div></section>';
    }
    $html.='<section class="panel panel-default"><div class="panel-heading"><strong>Documentos do pedido</strong></div><div class="panel-body">';
    if(!$state['attachments'])$html.='Sem documentos anexados.';
    foreach($state['attachments'] as $document)$html.='<p><a href="'.$escape($api.'&api=download&config_id='.(int)$state['id'].'&attachment_id='.(int)$document['id']).'">'.$escape($document['name']).'</a></p>';
    return $html.'</div></section></div>';
}

function vd_config_request_info($id) {
    global $app_user,$app_session_token;
    $db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
    $db->set_charset('utf8mb4');
    try {
        $service=new VDProductConfigurator($db,$app_user);$service->access();$state=$service->state((int)$id);
        $schema=$service->schema;$actions='';
        if(empty($state['conversion']['quote'])) {
            $processes=new processes($schema['config.entity']);$processes->items_id=(int)$id;
            foreach($processes->get_buttons_list('default',(string)$schema['conversion.manual_process']) as $process)
                if($processes->check_buttons_filters($process))
                    $actions.='<a class="btn btn-primary" href="'.htmlspecialchars(url_for('items/info','path='.(int)$schema['config.entity'].'-'.(int)$id.'&prepare_quote=1'),ENT_QUOTES,'UTF-8').'">Criar Orçamento em rascunho</a>';
        }
        $status=$db->query('SELECT name FROM app_fields_choices WHERE fields_id='.(int)$schema['config.status'].' AND id='.(int)$state['conversion']['status'])->fetch_assoc();
        if(!$status)throw new DomainException('Estado do pedido indisponível. Reveja o estado na configuração nativa.');
        $api=url_for('items/vd_product_configurator','path='.(int)$schema['config.entity']);
        if(!empty($_GET['prepare_quote'])&&empty($state['conversion']['quote'])) {
            require_once __DIR__.'/product-quote-preparation.php';
            vd_config_quote_preparation_access($service,(int)$id);
            $model=vd_config_quote_preparation_state($service,(int)$id);
            echo '<div id="vd-quote-preparation" class="well"></div><script type="application/json" id="vd-quote-preparation-data">'.
                json_encode(['id'=>(int)$id,'model'=>$model,'url'=>$api,'token'=>$app_session_token],
                    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>'.
                '<script src="js/vd-quote-preparation.js?v='.hash_file('sha256','js/vd-quote-preparation.js').'"></script>';
            $actions='';
        }
        echo vd_config_request_report_html($state,$service->catalog(),$status['name'],$actions,$schema['config.report'],$api);
    }catch(DomainException $error){
        echo '<div class="alert alert-danger" role="alert">'.htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').'</div>';
    }catch(Throwable $error){
        error_log('VD request summary #'.(int)$id.': '.$error->getMessage());
        echo '<div class="alert alert-danger" role="alert">Não foi possível apresentar o resumo do pedido. Consulte o registo técnico.</div>';
    }finally{$db->close();}
}

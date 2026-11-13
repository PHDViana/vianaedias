<?php
require_once __DIR__.'/product-configurator.php';

function vd_evaluation_delta($planned,$actual) {
    if($actual===null||$planned===null)return ['planned'=>$planned,'actual'=>$actual,'difference'=>null,'percent'=>null,'status'=>'missing'];
    $difference=$actual-$planned;
    return ['planned'=>$planned,'actual'=>$actual,'difference'=>$difference,
        'percent'=>$planned!=0?$difference/$planned*100:null,'status'=>abs($difference)>0.000001?'deviation':'equal'];
}
function vd_evaluation_actual($value,$label) {
    if($value===null||trim((string)$value)==='')return null;
    return vd_product_number($value,$label);
}
function vd_evaluation_time(array $times) {
    if(!$times)return ['elapsed_minutes'=>null,'labor_minutes'=>null,'missing'=>true,'reason'=>'Sem registos de tempo.'];
    $intervals=[];$labor=0;
    foreach($times as $time) {
        $start=(int)$time['field_2446'];$end=(int)$time['field_2447'];
        if($start<=0||$end<$start||$end===0)return ['elapsed_minutes'=>null,'labor_minutes'=>null,'missing'=>true,'reason'=>'Existem tempos abertos ou sem início/fim válido.'];
        $intervals[]=[$start,$end];$labor+=($end-$start)/60;
    }
    usort($intervals,static function($a,$b){return $a[0]<=>$b[0];});
    [$start,$end]=$intervals[0];$seconds=0;
    foreach(array_slice($intervals,1) as $interval) {
        if($interval[0]<=$end)$end=max($end,$interval[1]);
        else{$seconds+=$end-$start;[$start,$end]=$interval;}
    }
    $seconds+=$end-$start;
    return ['elapsed_minutes'=>$seconds/60,'labor_minutes'=>$labor,'missing'=>false,
        'reason'=>'Duração decorrida = união dos intervalos; horas-pessoa são apresentadas separadamente.'];
}
function vd_evaluation_build(mysqli $db,$orderId) {
    $orderId=(int)$orderId;$schema=vd_config_schema($db);
    $order=$db->query("SELECT * FROM app_entity_121 WHERE id=$orderId")->fetch_assoc();
    if(!$order||(int)$order['field_2437']!==883)throw new DomainException('A avaliação só é criada para uma OP concluída.');
    $origin=$db->query("SELECT * FROM app_vd_quote_production_orders WHERE order_id=$orderId")->fetch_assoc();
    if(!$origin)throw new DomainException('OP sem orçamento adjudicado fixado; não é possível reconstruir a previsão com segurança.');
    if(!hash_equals($origin['hash'],hash('sha256',$origin['snapshot'])))throw new RuntimeException('Origem da OP inválida.');
    $source=json_decode($origin['snapshot'],true,512,JSON_THROW_ON_ERROR);
    $quote=(int)$origin['quote_id'];
    $configuration=(new VDConfigNativeStore($db,$schema))->source($quote);
    if($configuration&&!hash_equals($configuration['hash'],hash('sha256',$configuration['payload'])))throw new RuntimeException('Origem do configurador inválida.');
    $missing=[];$operations=[];$materials=[];
    $costField=$schema['actual_operation_cost'];$materialField=$schema['actual_material'];$priceField=$schema['actual_material_price'];
    $totalActual=0;$complete=true;
    $confirmedTotal=vd_evaluation_actual($order['field_'.$schema['actual_order_cost']],'custo real global da OP');
    foreach($db->query("SELECT r.*,o.field_$costField actual_cost,o.field_2425 status,o.field_2450 name FROM app_vd_quote_production_rows r JOIN app_entity_170 o ON r.entity_id=170 AND r.native_id=o.id WHERE r.order_id=$orderId ORDER BY r.id") as $row) {
        if(!hash_equals($row['hash'],hash('sha256',$row['snapshot'])))throw new RuntimeException('Origem de operação inválida.');
        $detail=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $operationId=(int)$row['native_id'];
        $times=$db->query("SELECT id,created_by,field_2446,field_2447 FROM app_entity_171 WHERE parent_item_id=$operationId ORDER BY field_2446,id")->fetch_all(MYSQLI_ASSOC);
        $actual=vd_evaluation_time($times);
        $plannedMinutes=isset($detail['hours'])?(float)$detail['hours']*60:null;
        $plannedCost=isset($detail['lines'])?0:null;
        foreach($detail['lines']??[] as $line)if(!isset($line['parentOperationRow']))$plannedCost+=(float)$line['cost'];
        $actualCost=vd_evaluation_actual($row['actual_cost'],'custo real da operação #'.$operationId);
        if($actual['missing'])$missing[]='Operação #'.$operationId.': '.$actual['reason'];
        if($actualCost===null){$missing[]='Operação #'.$operationId.': custo real não confirmado.';$complete=false;}
        else $totalActual+=$actualCost;
        if(!in_array((int)$row['status'],[39,3790],true))$missing[]='Operação #'.$operationId.': não concluída apesar do fecho da OP.';
        $operations[]=['id'=>$operationId,'component'=>(int)$row['parent_id'],'name'=>$row['name'],'time'=>vd_evaluation_delta($plannedMinutes,$actual['elapsed_minutes']),
            'labor_minutes'=>$actual['labor_minutes'],'cost'=>vd_evaluation_delta($plannedCost,$actualCost),'time_records'=>$times];
    }
    foreach($db->query("SELECT r.*,p.native_id material_id,m.field_$materialField actual_quantity,m.field_$priceField actual_price FROM app_vd_quote_production_rows r JOIN app_vd_component_material_rows p ON p.source_id=r.native_id JOIN app_entity_394 m ON m.id=p.native_id WHERE r.order_id=$orderId AND r.entity_id=161 ORDER BY r.id") as $row) {
        if(!hash_equals($row['hash'],hash('sha256',$row['snapshot'])))throw new RuntimeException('Origem de material inválida.');
        $detail=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $planned=isset($detail['quantity'])?(float)$detail['quantity']:(isset($detail['sheets'])?(float)$detail['sheets']:null);
        $actual=vd_evaluation_actual($row['actual_quantity'],'consumo real do material #'.$row['material_id']);
        $price=vd_evaluation_actual($row['actual_price'],'preço real do material #'.$row['material_id']);
        if($actual===null)$missing[]='Material #'.$row['material_id'].': consumo real não confirmado.';
        if($price===null)$missing[]='Material #'.$row['material_id'].': preço real não confirmado.';
        if($actual===null||$price===null)$complete=false;
        else $totalActual+=$actual*$price;
        $materials[]=['id'=>(int)$row['material_id'],'operation'=>(int)$row['parent_id'],'name'=>$detail['article']['name']??$detail['name']??$row['line_key'],
            'unit'=>$detail['unit']??'folhas','quantity'=>vd_evaluation_delta($planned,$actual),'actual_unit_cost'=>$price];
    }
    $quotedCost=0;$configCost=0;$hasConfig=(bool)$configuration;
    $accepted=(float)$origin['quantity'];
    foreach($source['versions'] as $version)$quotedCost+=(float)$version['snapshot']['calculation']['allOperations']['cost'];
    if($configuration) {
        $config=json_decode($configuration['payload'],true,512,JSON_THROW_ON_ERROR);
        foreach($config['payload']['components'] as $component) {
            $calc=$config['payload']['selections'][$component['id']][(string)(int)$accepted]['calculation']??null;
            if(!$calc){$hasConfig=false;break;}
            $configCost+=(float)$calc['allOperations']['cost'];
        }
    }else$missing[]='OP histórica sem Configurador de Produto de origem.';
    // Recorded costs exclude unmeasured administrative/other ledger costs: never present a partial total as final.
    if($confirmedTotal===null)$missing[]='Custo real global por confirmar: custos indiretos e linhas sem registo real não são inferidos dos preços atuais.';
    $deviations=0;
    foreach($operations as $operation)if($operation['time']['status']==='deviation'||$operation['cost']['status']==='deviation')$deviations++;
    foreach($materials as $material)if($material['quantity']['status']==='deviation')$deviations++;
    if($confirmedTotal!==null&&abs($confirmedTotal-$quotedCost)>0.000001)$deviations++;
    return ['order'=>$orderId,'quote'=>$quote,'config'=>$configuration?(int)$configuration['config_id']:null,
        'config_revision'=>$configuration?(int)$configuration['revision']:null,'quantity'=>$accepted,'generated_at'=>time(),'state'=>'pending_review',
        'operations'=>$operations,'materials'=>$materials,'missing'=>$missing,'deviation_count'=>$deviations,
        'costs'=>['configuration'=>$hasConfig?$configCost:null,'quotation'=>$quotedCost,'recorded_actual_subtotal'=>$totalActual,
            'recorded_actual_complete'=>$complete,'actual_total'=>$confirmedTotal,
            'quotation_vs_actual'=>vd_evaluation_delta($quotedCost,$confirmedTotal),
            'configuration_vs_actual'=>vd_evaluation_delta($hasConfig?$configCost:null,$confirmedTotal)],
        'baseline_hashes'=>['production'=>$origin['hash'],'configuration'=>$configuration['hash']??null]];
}
function vd_evaluation_process(mysqli $db,$orderId) {
    $orderId=(int)$orderId;$lock='vd_evaluation_'.$orderId;
    if((int)$db->query("SELECT GET_LOCK('$lock',5)")->fetch_row()[0]!==1)throw new RuntimeException('Evaluation lock unavailable.');
    try {
        $db->begin_transaction();
        $db->query("SELECT order_id FROM app_vd_variance_queue WHERE order_id=$orderId FOR UPDATE");
        $payload=vd_evaluation_build($db,$orderId);$schema=vd_config_schema($db);$now=time();
        $existing=$db->query("SELECT native_id FROM app_vd_production_evaluations WHERE order_id=$orderId FOR UPDATE")->fetch_assoc();
        if($existing)$nativeId=(int)$existing['native_id'];
        else $nativeId=vd_config_insert($db,$schema['evaluation.entity'],[
            'date_added'=>$now,'created_by'=>1,'field_'.$schema['evaluation.name']=>'Desvios · OP #'.$orderId,
            'field_'.$schema['evaluation.order']=>$orderId,'field_'.$schema['evaluation.quote']=>$payload['quote'],'field_'.$schema['evaluation.config']=>$payload['config']]);
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
        $s=$db->prepare('INSERT INTO app_vd_production_evaluations(order_id,native_id,payload,hash,created_at,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),hash=VALUES(hash),updated_at=VALUES(updated_at)');
        $s->bind_param('iissii',$orderId,$nativeId,$json,$hash,$now,$now);$s->execute();
        $db->query("DELETE FROM app_vd_variance_queue WHERE order_id=$orderId");$db->commit();
        return $nativeId;
    }catch(Throwable $error){$db->rollback();throw $error;}
    finally{$db->query("SELECT RELEASE_LOCK('$lock')");}
}
function vd_evaluation_html(mysqli $db,array $user,$id) {
    $workflow=new VDQuoteWorkflow($db,$user);$schema=vd_config_schema($db);
    $workflow->access($schema['evaluation.entity'],$id);
    $rows=$workflow->rows('SELECT order_id,payload,hash FROM app_vd_production_evaluations WHERE native_id=?',[$id]);
    if(!$rows)throw new DomainException('Avaliação inexistente.');
    $workflow->access(121,$rows[0]['order_id']);
    $data=json_decode($rows[0]['payload'],true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals($rows[0]['hash'],hash('sha256',$rows[0]['payload'])))throw new RuntimeException('Avaliação com integridade inválida.');
    $workflow->access(146,$data['quote']);
    if($data['config'])$workflow->access($schema['config.entity'],$data['config']);
    foreach($data['operations'] as $operation)$workflow->access(170,$operation['id']);
    foreach($data['materials'] as $material)$workflow->access(394,$material['id']);
    $escape=static function($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
    $num=static function($value){return $value===null?'SEM DADOS':number_format((float)$value,4,',',' ');};
    $html='<h2>Avaliação de desvios · para revisão</h2><p><a href="index.php?module=items/info&path=121-'.$data['order'].'">OP #'.$data['order'].'</a> · <a href="index.php?module=items/info&path=146-'.$data['quote'].'">Orçamento #'.$data['quote'].'</a>';
    if($data['config'])$html.=' · <a href="index.php?module=report_page/view&id='.$schema['config.report'].'#configuration='.$data['config'].'">Configuração #'.$data['config'].'</a> / revisão '.$data['config_revision'];
    $html.='</p><p>Dados atualizados automaticamente após registos reais. Valores em falta não são zero. Desvio = real − previsto. Não altera configurações, orçamentos nem stock.</p>';
    $status=(int)$workflow->rows('SELECT field_2437 FROM app_entity_121 WHERE id=?',[$data['order']])[0]['field_2437'];
    if($status!==883)$html.='<div class="alert alert-warning">A OP foi reaberta. Esta avaliação é do fecho anterior e não é atual.</div>';
    elseif($workflow->rows('SELECT order_id FROM app_vd_variance_queue WHERE order_id=?',[$data['order']]))$html.='<div class="alert alert-info">Existem novos registos: atualização automática pendente (até um minuto).</div>';
    $html.='<p><a href="index.php?module=items/form&amp;entity_id='.$schema['evaluation.entity'].'&amp;id='.(int)$id.'&amp;path='.$schema['evaluation.entity'].'-'.(int)$id.'">Registar revisão / conclusões</a></p>';
    $html.='<h3>Tempos (minutos)</h3><table class="table table-bordered"><tr><th>Operação</th><th>Previsto</th><th>Real decorrido</th><th>Desvio</th><th>Horas-pessoa (min.)</th></tr>';
    foreach($data['operations'] as $operation)$html.='<tr><td>'.$escape($operation['name']).'</td><td>'.$num($operation['time']['planned']).'</td><td>'.$num($operation['time']['actual']).'</td><td>'.$num($operation['time']['difference']).'</td><td>'.$num($operation['labor_minutes']).'</td></tr>';
    $html.='</table><h3>Consumos</h3><table class="table table-bordered"><tr><th>Material</th><th>Unidade</th><th>Previsto</th><th>Real confirmado</th><th>Desvio</th></tr>';
    foreach($data['materials'] as $material)$html.='<tr><td>'.$escape($material['name']).'</td><td>'.$escape($material['unit']).'</td><td>'.$num($material['quantity']['planned']).'</td><td>'.$num($material['quantity']['actual']).'</td><td>'.$num($material['quantity']['difference']).'</td></tr>';
    $html.='</table><h3>Custos (€)</h3><p>Configurador: '.$num($data['costs']['configuration']).' · Orçamento: '.$num($data['costs']['quotation']).
        ' · Subtotal real registado (não é custo final): '.$num($data['costs']['recorded_actual_subtotal']).
        ' · Custo real global confirmado: '.$num($data['costs']['actual_total']).
        ' · Desvio / orçamento: '.$num($data['costs']['quotation_vs_actual']['difference']).
        ' · Desvio / configurador: '.$num($data['costs']['configuration_vs_actual']['difference']).'</p><h3>Pendências</h3><ul>';
    foreach($data['missing'] as $missing)$html.='<li>'.$escape($missing).'</li>';
    return $html.'</ul><p>Registe consumos e custos reais nos materiais/operações da plataforma. As conclusões ficam no campo «Revisão / conclusões» da identificação da avaliação.</p>';
}

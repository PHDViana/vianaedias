<?php
require_once __DIR__.'/product-configurator.php';

function vd_config_request_summary(array $state,array $choices=[]) {
    $payload=$state['payload'];$request=$payload['request_details']??[];
    $lines=['Pedido do configurador #'.$state['id'].' — revisão '.$state['revision'],
        'Rascunho para preparação técnica; sem cálculos ou preços.',
        'Referência: '.$state['reference'],'Contacto: '.($request['contact']??''),
        'Data pretendida: '.($request['delivery']??'')];
    $dispatch=['pickup'=>'Levantar nas instalações','carrier'=>'Envio por Transportador','delivery'=>'Entrega (Sujeito a disponibilidade e confirmação)'];
    foreach($request['dispatch']??[] as $method)$lines[]='Expedição: '.($dispatch[$method]??$method);
    if(!empty($request['final_format']['width_mm'])||!empty($request['final_format']['height_mm']))
        $lines[]='Formato final fechado: '.(($request['final_format']['width_mm']??'')?:'por definir').' × '.(($request['final_format']['height_mm']??'')?:'por definir').' mm';
    if(isset($request['total_pages']))$lines[]='Páginas totais (incluindo capa): '.$request['total_pages'];
    if(!empty($request['final_format']['thickness_mm']))$lines[]='Espessura final indicada: '.$request['final_format']['thickness_mm'].' mm; confirmar tecnicamente.';
    if(!empty($request['other_product']))$lines[]='Outro produto: '.$request['other_product'];
    if(isset($request['box'])) {
        $box=$request['box'];
        if(isset($box['model']))$lines[]='Modelo da caixa: '.vd_config_box_models()[$box['model']].'. Vista 3D ilustrativa, não substitui desenho/cortante validado.';
        if(!empty($box['model_description']))$lines[]='Referência / descrição da caixa: '.$box['model_description'];
        $lines[]='Caixa montada — comprimento × largura × altura: '.$box['length_mm'].' × '.$box['width_mm'].' × '.$box['height_mm'].' mm';
        $lines[]='Dimensões da caixa: '.($box['measurement']==='internal'?'interiores':'exteriores');
    }
    if(isset($request['document_book'])) {
        $book=$request['document_book'];$ends=vd_config_book_numbering($book,$payload['quantities']);
        $lines[]='Unidade: 1 livro autocopiativo';
        $lines[]='Cada livro: '.$book['sets_per_book'].' conjuntos × '.$book['copies'].' vias ('.($book['sets_per_book']*$book['copies']).' folhas)';
        $lines[]='Numeração inicial: '.$book['start'].'; o mesmo número em todas as vias do conjunto.';
        foreach($ends as $quantity=>$end)$lines[]=$quantity.' livro(s): número de fim '.$end;
        foreach($book['paper_colors'] as $index=>$color)$lines[]='Papel autocopiativo — via '.($index+1).': '.$color;
        $lines[]='Acabamento do livro: '.($book['binding']==='perforated'?'Picotado':'Colado');
    }
    $describe=static function(array $operations) {
        $result=[];
        foreach($operations as $operation) {
            $text=$operation['name'];
            if((int)$operation['id']===42) {
                $tape=$operation['tape']??[];
                $text.=' ('.($tape['quantity']??'por definir').' tira(s) por exemplar; X: '.($tape['x_mm']??'por definir').' mm; Y: '.($tape['y_mm']??'por definir').' mm)';
            }
            if((int)$operation['id']===36) {
                $thread=$operation['thread']??[];
                $text.=' (tipo: '.($thread['type']??'por definir').'; cor: '.($thread['color']??'por definir').'; comprimento: '.($thread['length_mm']??'por definir').' mm)';
            }
            $result[]=$text;
        }
        return implode('; ',$result);
    };
    if(!empty($request['finishing_operations']))$lines[]='Acabamentos do conjunto: '.$describe($request['finishing_operations']);
    if(!empty($request['packaging_operations']))$lines[]='Embalamento individual, após o produto pronto: '.$describe($request['packaging_operations']).'. Confirmar espessura e capacidade do saco/envelope.';
    if(!empty($request['finishing']))$lines[]='Outros requisitos dos acabamentos: '.$request['finishing'];
    $labels=[];
    foreach($choices as $choice)$labels[(int)$choice['id']]=$choice['name'];
    $names=array_column($payload['components']??[],'name','id');
    foreach($payload['components']??[] as $part) {
        $requirements=$part['request']??[];$fields=$part['fields']??[];
        $lines[]='';$lines[]=$part['name'];
        if(isset($part['stage']))$lines[]='Etapa: '.vd_product_stage_names()[$part['stage']];
        if(isset($requirements['cover'])) {
            $cover=$requirements['cover'];
            $lines[]='Abas esquerda / direita: '.$cover['left_mm'].' / '.$cover['right_mm'].' mm; lombada: '.$cover['spine_mm'].' mm.';
            if(($part['role']??'')==='cover')$lines[]='Capa separada do miolo: 4 páginas, independentemente das faces impressas.';
        }
        $lines[]='Papel / material pretendido: '.($requirements['paper']??'por definir');
        if(isset($requirements['corrugated'])) {
            $lines[]='Espessura do cartão canelado: '.$requirements['corrugated']['thickness_mm'].' mm';
        }else $lines[]='Dimensões: '.($fields['4218']??'?').' × '.($fields['4219']??'?').' mm';
        foreach([4233=>'Páginas',4229=>'Faces de impressão',6267=>'Plastificação frente',6268=>'Plastificação verso'] as $field=>$label)
            if(isset($fields[$field])&&$fields[$field]!=='')$lines[]=$label.': '.($field===4233?$fields[$field]:($labels[(int)$fields[$field]]??$fields[$field]));
        if($part['kind']!=='printed')$lines[]='Unidades por produto: '.($part['units_per_product']??1);
        if(!empty($part['inputs']))$lines[]='Componentes de entrada: '.implode(', ',array_map(static function($input)use($names){return $names[$input];},$part['inputs']));
        $modes=['cmyk'=>'CMYK','pantone'=>'Pantone','cmyk_pantone'=>'CMYK + Pantone','white'=>'Branco','none'=>'Sem impressão','combined'=>'Combinar cores','black'=>'Preto (histórico)'];
        $printing=$requirements['printing']??[];
        if($part['kind']==='printed')$lines[]='Impressão: '.($modes[$printing['mode']??'']??'por definir');
        if(!empty($printing['pantone']))$lines[]='Pantone(s): '.$printing['pantone'];
        if(!empty($requirements['finishing_operations']))$lines[]='Acabamentos: '.$describe($requirements['finishing_operations']);
        if(!empty($requirements['description']))$lines[]='Descrição: '.$requirements['description'];
        if(($requirements['die']['status']??'none')!=='none')
            $lines[]='Cortante: '.($requirements['die']['status']==='used'?'usado, nº '.$requirements['die']['number']:'novo');
    }
    if(trim((string)$state['notes'])!==''){$lines[]='';$lines[]='Observações do pedido:';$lines[]=$state['notes'];}
    if(!empty($payload['rubrics'])) {
        $lines[]='';$lines[]='Rubricas (quant. · descrição · código)';
        foreach($payload['rubrics'] as $rubric)
            $lines[]=$rubric['quantity'].' · '.$rubric['description'].(($rubric['code']??'')!==''?' · '.$rubric['code']:'');
        $lines[]='Total: '.array_sum(array_column($payload['rubrics'],'quantity'));
    }
    return implode("\n",$lines);
}
function vd_config_request_to_draft(mysqli $db,array $user,$id,$automatic=false,array $answers=[],$revision=null) {
    $schema=vd_config_schema($db);
    if(empty($schema['conversion.manual_process'])||empty($schema['config.status']))throw new DomainException('Conversão para orçamento ainda não configurada.');
    if(!empty($schema['config.storage_locked']))throw new DomainException('Configurador em manutenção. Tente novamente.');
    $workflow=new VDQuoteWorkflow($db,$user);$service=new VDProductConfigurator($db,$user);$store=new VDConfigNativeStore($db,$schema);
    $workflow->access($schema['config.entity'],(int)$id,'update');
    $lock='vd_product_config_'.(int)$id;
    $s=$db->prepare('SELECT GET_LOCK(?,10)');$s->bind_param('s',$lock);$s->execute();
    if((int)$s->get_result()->fetch_row()[0]!==1)throw new DomainException('Pedido em conversão. Aguarde e tente novamente.');
    $db->begin_transaction();
    try {
        $rows=$store->rows('SELECT * FROM app_entity_'.$schema['config.entity'].' WHERE id=? FOR UPDATE',[(int)$id]);
        if(!$rows)throw new DomainException('Pedido inexistente.');
        $record=$rows[0];$linked=(int)$record[$store->field('config','quote')];
        if($linked) {
            $workflow->access(146,$linked);
            $source=$store->source($linked);
            if(!$source||(int)$source['config_id']!==(int)$id)throw new DomainException('O orçamento associado não tem origem neste pedido. Corrija a associação antes de converter.');
            $store->update('config',(int)$id,['status'=>$schema['conversion.converted'],'conversion_error'=>'']);
            $db->commit();return ['quote'=>$linked,'already_created'=>true];
        }
        if($automatic&&(int)$record[$store->field('config','status')]!==$schema['conversion.ready']) {
            $db->commit();return ['skipped'=>true];
        }
        $existing=$store->quotes((int)$id);
        if(count($existing)>1)throw new DomainException('Este pedido já tem vários orçamentos de origem. Associe explicitamente o orçamento pretendido antes de converter.');
        if($existing) {
            $linked=(int)$existing[0]['quote_id'];$workflow->access(146,$linked);
            $store->update('config',(int)$id,['quote'=>$linked,'status'=>$schema['conversion.converted'],'conversion_error'=>'']);
            $db->commit();return ['quote'=>$linked,'already_created'=>true];
        }
        $state=$service->state((int)$id,'update');$payload=$state['payload'];
        if($revision!==null&&$state['revision']!==(int)$revision)throw new DomainException('Pedido alterado por outro utilizador. Atualize a preparação antes de converter.');
        if(!$payload||$state['revision']<1)throw new DomainException('Guarde o pedido no configurador antes de criar o orçamento.');
        $catalog=$service->catalog();$types=$catalog['product_types'];
        $payload=vd_config_prepare_book($payload,$types);
        $payload=vd_config_prepare_packaging($payload,$schema);
        $payload=vd_config_prepare_requirements($payload,$types,$schema);
        $payload=vd_config_prepare_stages($payload,$schema);
        $payload=vd_config_derive_assembly($payload,$types,$schema,$catalog['finishing_operations']);
        $state['payload']=$payload;
        vd_config_validate($payload);
        vd_config_request_validate($payload,false);
        $catalog=$service->catalog();$types=$catalog['product_types'];$product=(int)$payload['product_type'];
        $draftPayload=$payload;
        foreach($types as $type)if((int)$type['parent_id']===$product)throw new DomainException('Escolha o subtipo do produto antes de criar o orçamento.');
        if(trim($state['name'])===''||!(int)$state['customer'])throw new DomainException('Indique o nome do trabalho e o cliente antes de criar o orçamento.');
        $customer=$workflow->access(26,(int)$state['customer']);
        if(!in_array('176',explode(',',(string)$customer['field_224']),true))throw new DomainException('A entidade do pedido não é um Cliente.');
        $review=$service->finishingReview($payload,(int)$id);
        if($review['errors'])throw new DomainException(implode("\n",$review['errors']));
        $state=$service->state((int)$id,'update');
        $draft=$state;$draft['payload']=$draftPayload;
        $draft['payload']=vd_config_quote_payload($draft['payload'],$state['name']);
        require_once __DIR__.'/product-quote-preparation.php';
        $preparation=vd_config_quote_preparation($draft['payload'],$service->quotePreparationCatalog(),$answers);
        if($preparation['missing'])throw new DomainException("Complete os dados de orçamentação antes de criar o rascunho:\n".
            implode("\n",array_column($preparation['missing'],'label')));
        $draft['payload']=$preparation['payload'];
        vd_config_validate($draft['payload']);vd_config_request_validate($draft['payload']);
        $draft['payload']=vd_config_assign_rubrics($draft['payload']);
        $quote=$workflow->importProductConfiguration($draft,$schema,true);
        $store->copyDocumentsToQuote((int)$id,$quote);
        $snapshot=vd_config_native_json($state);$hash=hash('sha256',$snapshot);
        $store->saveSource($quote,(int)$id,$state['revision'],$snapshot,$hash,time());
        $store->update('config',(int)$id,['quote'=>$quote,'status'=>$schema['conversion.converted'],'conversion_error'=>'']);
        $db->commit();$store->finishFiles(true);
        return ['quote'=>$quote,'already_created'=>false];
    }catch(Throwable $error) {
        $db->rollback();$store->finishFiles(false);throw $error;
    }finally {
        $s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();
    }
}
function vd_config_conversion_process($id,array $user,$automatic=false) {
    global $alerts,$app_redirect_to;
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
    $db->set_charset('utf8mb4');
    try {
        if(!$automatic) {
            $service=new VDProductConfigurator($db,$user);$state=$service->state((int)$id,'update');
            $quote=(int)$state['conversion']['quote'];
            $app_redirect_to=$quote?'item_info_page146-'.$quote:
                'item_info_page'.(int)$service->schema['config.entity'].'-'.(int)$id.'&prepare_quote=1';
            return ['preparation_required'=>!$quote,'quote'=>$quote];
        }
        $result=vd_config_request_to_draft($db,$user,(int)$id,$automatic);
        if(!empty($result['skipped']))return $result;
        $message=($result['already_created']?'Já existe o orçamento':'Criado o orçamento em rascunho').' #'.$result['quote'].'. Consulte o campo Orçamento associado do pedido.';
        $alerts->add($message,'success');
        if(!$automatic)$app_redirect_to='item_info_page146-'.(int)$result['quote'];
        return $result;
    }catch(Throwable $error) {
        error_log('VD native request conversion #'.(int)$id.': '.get_class($error).': '.$error->getMessage());
        $message=$error instanceof DomainException?$error->getMessage():'Falha interna na conversão. Consulte o registo técnico.';
        $schema=vd_config_schema($db);
        $store=new VDConfigNativeStore($db,$schema);
        $alerts->add('Pedido não convertido: '.htmlspecialchars($message,ENT_QUOTES,'UTF-8'),'error');
        try {(new VDQuoteWorkflow($db,$user))->access($schema['config.entity'],(int)$id,'update');}
        catch(DomainException $accessError) {return ['error'=>$message];}
        $store->update('config',(int)$id,['conversion_error'=>$message]);
        return ['error'=>$message];
    }finally {$db->close();}
}

<?php
require_once __DIR__.'/product-configurator.php';

function vd_config_paper_description($description) {
    $text=mb_strtolower(trim((string)$description),'UTF-8');
    $text=str_replace(['²','×'],['2','x'],$text);
    $text=preg_replace('/\s+/u',' ',$text);
    $weight=null;
    if(preg_match('/\b(\d+(?:[.,]\d+)?)\s*(?:g\/m2|gsm|gr|g)\b/u',$text,$match)) {
        $weight=(float)str_replace(',','.',$match[1]);
        $text=preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:g\/m2|gsm|gr|g)\b/u',' ',$text);
    }
    $format=null;
    if(preg_match('/\b(\d+(?:[.,]\d+)?)\s*x\s*(\d+(?:[.,]\d+)?)(?:\s*(mm|cm)\b)?/u',$text,$match)) {
        $factor=($match[3]??'')==='mm'?1:10;
        $format=[(float)str_replace(',','.',$match[1])*$factor,(float)str_replace(',','.',$match[2])*$factor];sort($format);
        $text=preg_replace('/\b\d+(?:[.,]\d+)?\s*x\s*\d+(?:[.,]\d+)?(?:\s*(?:mm|cm)\b)?/u',' ',$text);
    }
    $family=trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$text));
    return ['family'=>$family,'weight'=>$weight,'format'=>$format];
}

function vd_config_paper_matches($description,array $articles) {
    $requested=vd_config_paper_description($description);
    if($requested['weight']===null||$requested['family']===''||
        !array_diff(explode(' ',$requested['family']),['papel','cartao','cartão','cartolina','couche','couché','offset','autocopiativo','material']))
        return [];
    $matches=[];
    foreach($articles as $article) {
        if(empty($article['is_paper'])||empty($article['internal_id'])||
            !((float)$article['width']>0&&(float)$article['height']>0)||
            (float)$article['weight']!==$requested['weight'])continue;
        $candidate=vd_config_paper_description($article['name']);
        if($candidate['family']!==$requested['family']&&strpos($candidate['family'],$requested['family'].' ')!==0)continue;
        if($requested['format']!==null) {
            $format=[(float)$article['width'],(float)$article['height']];sort($format);
            if($format!==$requested['format'])continue;
        }
        $matches[]=$article;
    }
    return $matches;
}

function vd_config_quote_base(VDProductConfigurator $service,array $state) {
    $catalog=$service->catalog();$payload=$state['payload'];$schema=$service->schema;
    if(!$payload||$state['revision']<1)throw new DomainException('Guarde o pedido no configurador antes de criar o orçamento.');
    $payload=vd_config_prepare_book($payload,$catalog['product_types']);
    $payload=vd_config_prepare_packaging($payload,$schema);
    $payload=vd_config_prepare_requirements($payload,$catalog['product_types'],$schema);
    $payload=vd_config_prepare_stages($payload,$schema);
    $payload=vd_config_derive_assembly($payload,$catalog['product_types'],$schema,$catalog['finishing_operations']);
    return vd_config_quote_payload($payload,$state['name']);
}

function vd_config_quote_preparation_catalog(mysqli $db,VDProductConfigurator $service) {
    $catalog=$service->catalog();
    $catalog['choices']=$db->query('SELECT fields_id,id,name,value FROM app_fields_choices WHERE fields_id IN(4220,4229,6110,6267,6268) ORDER BY fields_id,sort_order,id')->fetch_all(MYSQLI_ASSOC);
    $catalog['operations']=vd_product_stage_catalog($db);
    $catalog['machines']=$db->query('SELECT id,field_1281 name FROM app_entity_78 ORDER BY field_1281,id')->fetch_all(MYSQLI_ASSOC);
    $catalog['equipment']=$db->query('SELECT parent_item_id operation_id,field_2403 machine_id,field_2413 speed,field_6143 unit,field_6144 setup FROM app_entity_168 ORDER BY parent_item_id,id')->fetch_all(MYSQLI_ASSOC);
    $catalog['defaults']=[];
    foreach($db->query('SELECT id,configuration FROM app_fields WHERE id IN(6119,6120,6121,6169,6170)') as $field) {
        $configuration=json_decode($field['configuration'],true,512,JSON_THROW_ON_ERROR);
        $catalog['defaults'][(int)$field['id']]=$configuration['default_value']??'';
    }
    $catalog['articles']=$db->query("SELECT id,field_1020 name,field_3632 internal_id,field_1374 unit,field_1021 width,field_1022 height,field_1023 weight,field_1024 thickness,FIND_IN_SET('190',field_1019)>0 is_paper,FIND_IN_SET('322',field_1019)>0 is_sticker FROM app_entity_99 ORDER BY field_1020,id")->fetch_all(MYSQLI_ASSOC);
    return $catalog;
}

function vd_config_article_is_print_material(array $article,array $payload) {
    return !empty($article['is_paper'])||
        ((int)($payload['product_type']??0)===3761&&!empty($article['is_sticker']));
}

function vd_config_quote_preparation(array $base,array $catalog,array $answers=[]) {
    $payload=$base;$fields=[];$used=[];$resolved=[];
    $operations=array_column($catalog['operations'],null,'id');
    $machines=array_column($catalog['machines'],null,'id');
    $articles=array_column($catalog['articles'],null,'id');
    $cutting=vd_config_sticker_cutting_choices()[$payload['request_details']['cutting_operation']??'']??null;
    if((int)($payload['product_type']??0)===3761) {
        if(!$cutting)throw new DomainException('Escolha a operação de corte pretendida para os autocolantes.');
        $operationIds=array_values(array_filter($cutting['operation_ids'],static function($operationId)use($operations){return isset($operations[$operationId]);}));
        if(!$operationIds)throw new DomainException('A operação de corte escolhida não está disponível no catálogo de produção.');
        foreach($payload['components'] as &$part)if($part['kind']==='printed') {
            $selectedIds=array_map('intval',array_column($part['request']['finishing_operations']??[],'id'));
            if(!array_intersect($operationIds,$selectedIds)) {
                $part['request']['finishing_operations'][]=[
                    'id'=>$operationIds[0],'name'=>$cutting['label'],'variants'=>$operationIds
                ];
            }
        }
        unset($part);
    }elseif($cutting)throw new DomainException('A operação de corte indicada só se aplica a autocolantes.');
    $add=static function($key,$label,$kind,$value,array $options=[],$min=0)use(&$fields,&$answers,&$used) {
        $missing=$value===null||$value==='';
        if($missing&&array_key_exists($key,$answers)) {
            $used[$key]=true;$value=$answers[$key];
        }
        if(!is_scalar($value)&&$value!==null)throw new DomainException($label.': valor inválido.');
        if($value!==null&&$value!=='') {
            if($kind==='select') {
                if(!in_array((string)$value,array_map('strval',array_column($options,'id')),true))
                    throw new DomainException($label.': escolha indisponível.');
            }else {
                if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<$min||(float)$value>1000000000)
                    throw new DomainException($label.': indique um número válido, mínimo '.$min.'.');
            }
        }
        if(!$missing)return $value;
        $fields[]=['key'=>$key,'label'=>$label,'kind'=>$kind,'value'=>$value,'options'=>$options,'min'=>$min,
            'missing'=>$value===null||$value===''];
        return $value;
    };
    foreach($payload['components'] as &$part) {
        $id=(int)$part['id'];$name=$part['name'];$part['technical_operations']=[];
        $field=function($number,$label,$kind='number',$min=0)use(&$part,$id,$catalog,$add,$name) {
            $options=array_values(array_filter($catalog['choices'],static function($row)use($number){return (int)$row['fields_id']===$number;}));
            if($kind==='boolean'){$kind='select';$options=[['id'=>'true','name'=>'Sim'],['id'=>'false','name'=>'Não']];}
            $part['fields'][$number]=$add("$id.field.$number",$name.' — '.$label,$kind,$part['fields'][$number]??'',$options,$min);
        };
        if($part['kind']==='printed') {
            $field(4218,'Largura (mm)','number',0.001);$field(4219,'Altura (mm)','number',0.001);
            if(($part['colors']??[])!==[3804])$field(4229,'Faces de impressão','select');
            foreach($catalog['defaults'] as $number=>$value)if(!isset($part['fields'][$number])||$part['fields'][$number]==='')
                $part['fields'][$number]=$value;
            $field(6119,'Sangria exterior (mm)');$field(6120,'Espaçamento entre cortes (mm)');
            $field(6169,'Ficheiros prontos','boolean');$field(6170,'Papel já cortado','boolean');
            $die=$part['request']['die']??null;
            if(empty($part['fields'][4220])&&$die!==null)$part['fields'][4220]=['none'=>1565,'new'=>1249,'used'=>1248][$die['status']];
            if(empty($part['fields'][4221])&&($die['status']??'')==='used')$part['fields'][4221]=$die['number'];
            $field(4220,'Corte / vinco','select');
            if(empty($part['fields'][6110]))$part['fields'][6110]=1544;
            foreach([6267=>1600,6268=>1604] as $number=>$none) {
                if(empty($part['fields'][$number])&&empty($part['request']['lamination']))$part['fields'][$number]=$none;
                $field($number,$number===6267?'Plastificação — frente':'Plastificação — verso','select');
            }
            if((int)($part['fields'][4220]??0)===1248)$field(4221,'Número do cortante','number',1);
        }
        if($part['kind']!=='assembly') {
            $paper=$part['papers']??[];
            if(!$paper&&!array_key_exists("$id.paper",$answers)&&$part['kind']==='printed') {
                $matches=vd_config_paper_matches($part['request']['paper']??'',$catalog['articles']);
                if(count($matches)===1) {
                    $paper=$part['papers']=[(int)$matches[0]['id']];
                    $resolved[]=$name.' — Papel: '.$matches[0]['name'].' (descrição e gramagem do pedido)';
                }
            }
            foreach($paper as $article)if(!isset($articles[$article])||
                ($part['kind']==='printed'&&!vd_config_article_is_print_material($articles[$article],$payload)))
                throw new DomainException($name.': artigo/material guardado indisponível no catálogo.');
            if(!$paper) {
                $options=array_values(array_filter($catalog['articles'],static function($row)use($part,$payload){
                    return $part['kind']!=='printed'||vd_config_article_is_print_material($row,$payload);
                }));
                $sticker=(int)($payload['product_type']??0)===3761;
                $article=$add("$id.paper",$name.' — '.($part['kind']==='printed'?($sticker?'Papel/material':'Papel').' do catálogo (pedido: '.($part['request']['paper']??'não indicado').')':'Material do catálogo'),'select','',$options);
                if($article!==null&&$article!=='')$part['papers']=[(int)$article];
            }
            if($part['kind']==='printed')foreach($part['papers']??[] as $article) {
                $paper=$articles[$article];
                foreach(['internal_id','width','height','weight'] as $attribute)
                    if(!is_numeric($paper[$attribute])||(float)$paper[$attribute]<=0)
                        throw new DomainException($name.': corrija a família, formato e gramagem do papel #'.$article.' no catálogo antes de converter.');
                $thickness=$part['request']['corrugated']['thickness_mm']??$paper['thickness'];
                if(isset($part['request']['corrugated']['thickness_mm']))
                    $part['request']['quote_preparation']['paper_thickness'][$article]=(float)$thickness;
                if((!is_numeric($thickness)||(float)$thickness<=0)&&!array_key_exists("$id.thickness.$article",$answers)) {
                    $values=[];
                    foreach($catalog['articles'] as $candidate)if(vd_config_article_is_print_material($candidate,$payload)&&
                        (int)$candidate['internal_id']===(int)$paper['internal_id']&&
                        (float)$candidate['weight']===(float)$paper['weight']&&
                        is_numeric($candidate['thickness'])&&(float)$candidate['thickness']>0&&(float)$candidate['thickness']<=80)
                        $values[(string)(float)$candidate['thickness']]=(float)$candidate['thickness'];
                    if(count($values)===1) {
                        $thickness=array_values($values)[0];
                        $part['request']['quote_preparation']['paper_thickness'][$article]=$thickness;
                        $resolved[]=$name.' — Espessura: '.$thickness.' mm (mesma referência interna e gramagem no catálogo)';
                    }
                }
                if(!is_numeric($thickness)||(float)$thickness<=0||(float)$thickness>80) {
                    $thickness=$add("$id.thickness.$article",$name.' — Espessura real do material (mm): '.$paper['name'],'number','',[],0.001);
                    if($thickness!==null&&$thickness!=='') {
                        if((float)$thickness>80)throw new DomainException('A espessura real do papel não pode exceder 80 mm.');
                        $part['request']['quote_preparation']['paper_thickness'][$article]=(float)$thickness;
                    }
                }
            }
        }
        $selected=[];
        $originalOperationIds=array_map('intval',array_column($part['request']['finishing_operations']??[],'id'));
        foreach($part['request']['finishing_operations']??[] as $index=>$requirement) {
            $variants=array_values(array_unique(array_map('intval',$requirement['variants']??[$requirement['id']])));
            $options=[];
            foreach($variants as $variant)if(isset($operations[$variant]))$options[]=$operations[$variant];
            if(!$options)throw new DomainException($name.': operação '.$requirement['name'].' indisponível.');
            $operation=count($options)===1?$options[0]['id']:
                $add("$id.variant.$index",$name.' — Variante: '.$requirement['name'],'select','',$options);
            if($operation===null||$operation==='')continue;
            $operation=(int)$operation;$selected[$operation]=$requirement;
            $part['request']['finishing_operations'][$index]=array_replace($requirement,['id'=>$operation,'name'=>$operations[$operation]['name'],'variants'=>[$operation]]);
        }
        if(($part['stage']??'')==='assembly')$selected[9]=[];
        if(($part['stage']??'')==='dispatch') {
            $selected[10]=[];
            if(in_array('delivery',$payload['request_details']['dispatch']??[],true))$selected[12]=[];
        }
        if($part['kind']==='printed') {
            if(isset($part['request']['folding'])) {
                $creaseIds=$catalog['finishing_rules']['crease']??[18];
                $heavyPaper=false;
                foreach($part['papers']??[] as $article)if((float)$articles[$article]['weight']>150)$heavyPaper=true;
                if($heavyPaper&&!array_intersect(array_keys($selected),$creaseIds)) {
                    $selected[18]=[];
                    if(!in_array((int)($part['fields'][4220]??0),[1248,1249],true))$part['fields'][4220]=1608;
                }
            }
            if(($part['fields'][6169]??'')==='false')$selected[1]=[];
            if(($part['fields'][6170]??'')==='false')$selected[2]=[];
            if(in_array((int)($part['fields'][4220]??0),[1248,1249],true))
                $selected[min((float)$part['fields'][4218],(float)$part['fields'][4219])<=250&&max((float)$part['fields'][4218],(float)$part['fields'][4219])<=350?11:8]=[];
            if((int)($part['fields'][4220]??0)===1608)$selected[18]=[];
            if(in_array((int)($part['fields'][6267]??0),[1601,1602,1603],true)||in_array((int)($part['fields'][6268]??0),[1605,1606,1607],true))$selected[13]=[];
            $binding=[1545=>21,1546=>23,1547=>25,1548=>26][(int)($part['fields'][6110]??0)]??0;
            if($binding)$selected[$binding]=$selected[$binding]??[];
            foreach(array_filter(explode(',',(string)($part['fields'][6291]??''))) as $extra) {
                $extra=(int)$extra;
                if(!in_array($extra,$originalOperationIds,true))$selected[$extra]=$selected[$extra]??[];
            }
            $part['fields'][6291]=implode(',',array_keys($selected));
        }
        foreach($selected as $operation=>$requirement) {
            if(!isset($operations[$operation]))throw new DomainException($name.': operação #'.$operation.' inativa ou inexistente.');
            $row=$operations[$operation];$equipment=[];
            foreach(array_filter(explode(',',(string)$row['machines'])) as $machine) {
                if(isset($machines[$machine]))$equipment[]=$machines[$machine];
            }
            if(trim((string)$row['machines'])!==''&&!$equipment)throw new DomainException($name.': nenhum equipamento disponível para '.$row['name'].'. Reveja o catálogo.');
            $machine=count($equipment)===1?$equipment[0]['id']:'';
            $parameters=[];
            foreach($catalog['equipment']??[] as $resource)if((int)$resource['operation_id']===$operation)
                $parameters[]=[$resource['unit'],is_numeric($resource['speed'])?(float)$resource['speed']:null,is_numeric($resource['setup'])?(float)$resource['setup']:null];
            $shared=$part['kind']==='printed'&&count($parameters)>1&&
                !in_array($operation,$catalog['finishing_rules']['crease']??[18],true)&&
                count(array_unique(array_map('serialize',$parameters)))===1;
            if(count($equipment)>1&&!$shared)$machine=$add("$id.equipment.$operation",$name.' — Equipamento: '.$row['name'],'select','',$equipment);
            $settings=['operation_id'=>(int)$operation,'equipment'=>(string)($machine??'')];
            if(isset($requirement['tape']))$settings+=['adhesive_per_copy'=>$requirement['tape']['quantity'],
                'adhesive_width'=>$requirement['tape']['x_mm'],'adhesive_height'=>$requirement['tape']['y_mm']];
            if($part['kind']!=='printed'||isset($requirement['tape'])||(count($equipment)>1&&!$shared)||
                (isset($part['request']['folding'])&&in_array($operation,[18,21,33,35],true)))
                $part['technical_operations'][]=$settings;
        }
    }
    unset($part);
    foreach($answers as $key=>$value)if(!isset($used[$key])&&$value!==null&&$value!=='')
        throw new DomainException('O dado de preparação já não é aplicável: '.$key.'. Atualize o formulário.');
    return ['payload'=>$payload,'fields'=>$fields,'resolved'=>$resolved,'missing'=>array_values(array_filter($fields,static function($field){return $field['missing'];}))];
}

function vd_config_quote_preparation_access(VDProductConfigurator $service,$id) {
    $service->state((int)$id,'update');
    $processes=new processes($service->schema['config.entity']);$processes->items_id=(int)$id;
    foreach($processes->get_buttons_list('default',(string)$service->schema['conversion.manual_process']) as $process)
        if($processes->check_buttons_filters($process))return;
    throw new DomainException('Sem autorização para converter este pedido em orçamento.');
}

function vd_config_quote_preparation_state(VDProductConfigurator $service,$id,array $answers=[],$revision=null) {
    $state=$service->state((int)$id,'update');
    if($revision!==null&&$state['revision']!==(int)$revision)throw new DomainException('Pedido alterado por outro utilizador. Atualize a preparação antes de converter.');
    if(!empty($state['conversion']['quote']))return ['quote'=>(int)$state['conversion']['quote']];
    $base=vd_config_quote_base($service,$state);
    $result=vd_config_quote_preparation($base,$service->quotePreparationCatalog(),$answers);
    return ['id'=>(int)$id,'revision'=>$state['revision'],'name'=>$state['name'],'fields'=>$result['fields'],'resolved'=>$result['resolved'],
        'missing'=>count($result['missing']),'components'=>array_map(static function($part){return ['name'=>$part['name'],'colors'=>$part['colors']??[]];},$base['components'])];
}

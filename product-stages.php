<?php
require_once __DIR__.'/product-requirements.php';
function vd_product_stage_names() {
    return ['component'=>'Componente','assembly'=>'Assemblagem','packaging'=>'Embalamento individual','dispatch'=>'Expedição','support'=>'Suporte'];
}
function vd_product_stage_schema(mysqli $db) {
    return array_map('intval',array_column($db->query('SELECT role,native_id FROM app_vd_config_schema')->fetch_all(MYSQLI_ASSOC),'native_id','role'));
}
function vd_product_stage_catalog(mysqli $db) {
    $schema=vd_product_stage_schema($db);
    $stage=isset($schema['operation.stage'])?'field_'.$schema['operation.stage']:"''";
    return $db->query("SELECT id,field_1982 name,field_6128 base,field_1983 machines,field_6131 unit,field_6132 setup,$stage stage FROM app_entity_36 WHERE field_6129='true' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}
function vd_product_stage_simulation(mysqli $db,array &$simulation,$component) {
    $quote=(int)$simulation['workflow_component'][0]['parent_item_id'];
    $metadata=vd_product_component($db,$quote,$component);
    if(empty($metadata['stage']))return;
    $folding=$metadata['requirements']['details']['folding']??null;
    if($folding!==null) {
        $simulation['component'][0]['flat_folding']=true;
        $simulation['component'][0]['folding_count']=$folding['count'];
    }
    $quantities=$metadata['requirements']['details']['quote_preparation']['operation_quantities']??[];
    if($quantities)$simulation['component'][0]['operation_quantities']=$quantities;
    $thickness=$metadata['requirements']['details']['quote_preparation']['paper_thickness']??[];
    $papers=array_column($simulation['papers']??[],null,'id');
    foreach($simulation['papers']??[] as $index=>$paper)foreach($thickness as $article=>$mm) {
        $source=$papers[$article]??null;
        if((int)$paper['id']===(int)$article||($source&&!empty($source['internal_id'])&&
            (int)($paper['internal_id']??0)===(int)$source['internal_id']&&
            (float)($paper['weight']??0)===(float)($source['weight']??0)))
            $simulation['papers'][$index]['thickness']=$mm;
    }
    if($metadata['type']!=='standard'||$db->query("SELECT component_id FROM app_vd_quote_product_components WHERE quote_id=$quote AND kind='assembly' LIMIT 1")->num_rows) {
        $simulation['workflow_component'][0]['product_kind']=$metadata['kind'];
        $simulation['workflow_component'][0]['product_stage']=$metadata['stage'];
    }
    $catalog=array_column(vd_product_stage_catalog($db),null,'id');
    foreach($simulation['costing_catalog'] as &$operation)if(isset($catalog[$operation['id']]))$operation+=$catalog[$operation['id']];
    unset($operation);
}
function vd_product_stage_code($value) {
    return array_search($value,vd_product_stage_names(),true)?:'';
}
function vd_product_stage_read(mysqli $db,$component) {
    $schema=vd_product_stage_schema($db);
    if(empty($schema['quote_part.stage']))return null;
    $result=$db->query('SELECT field_'.$schema['quote_part.stage'].' stage,field_'.$schema['quote_part.requirements'].' requirements FROM app_entity_262 WHERE id='.(int)$component)->fetch_assoc();
    if(!$result||$result['stage']==='')return null;
    if(!isset(vd_product_stage_names()[$result['stage']]))throw new DomainException('Etapa do componente inválida.');
    return ['stage'=>$result['stage'],'requirements'=>json_decode($result['requirements'],true,512,JSON_THROW_ON_ERROR)];
}
function vd_product_stage_write(mysqli $db,$entity,$prefix,$id,array $metadata) {
    $schema=vd_product_stage_schema($db);
    if(empty($schema[$prefix.'.stage'])||empty($schema[$prefix.'.requirements']))throw new RuntimeException('Instale os campos nativos das etapas.');
    $stmt=$db->prepare('UPDATE app_entity_'.(int)$entity.' SET field_'.$schema[$prefix.'.stage'].'=?,field_'.$schema[$prefix.'.requirements'].'=? WHERE id=?');
    $json=json_encode($metadata['requirements'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $stmt->bind_param('ssi',$metadata['stage'],$json,$id);$stmt->execute();
}
function vd_product_stage_graph(array $components) {
    vd_product_graph($components);
    $sources=$assemblies=$packages=$dispatches=[];
    foreach($components as $part) {
        $stage=$part['stage']??'component';
        if($stage==='component') {
            if($part['kind']==='assembly')throw new DomainException('Componente físico com tipo de assemblagem.');
            $sources[]=(int)$part['id'];
        }elseif($stage==='assembly')$assemblies[]=$part;
        elseif($stage==='packaging')$packages[]=$part;
        elseif($stage==='dispatch')$dispatches[]=$part;
        else throw new DomainException('Etapa de produto inválida.');
    }
    if(!$sources||count($assemblies)>1||count($packages)>1||count($dispatches)>1)throw new DomainException('Indique componentes e no máximo uma etapa de assemblagem, uma de embalamento individual e uma de expedição.');
    foreach($assemblies as $part) {
        $actual=array_map('intval',$part['inputs']);sort($actual);$expected=$sources;sort($expected);
        if($part['kind']!=='assembly'||$actual!==$expected)throw new DomainException('A assemblagem deve receber todos os componentes físicos.');
    }
    foreach($packages as $part) {
        $expected=$assemblies?[(int)$assemblies[0]['id']]:$sources;$actual=array_map('intval',$part['inputs']);sort($actual);sort($expected);
        if($part['kind']!=='assembly'||$actual!==$expected)throw new DomainException('O embalamento individual deve receber o produto terminado.');
    }
    foreach($dispatches as $part) {
        $expected=$packages?[(int)$packages[0]['id']]:($assemblies?[(int)$assemblies[0]['id']]:$sources);
        $actual=array_map('intval',$part['inputs']);sort($actual);sort($expected);
        if($part['kind']!=='assembly'||$actual!==$expected)throw new DomainException('A expedição deve receber o lote de produtos terminados.');
    }
}
function vd_config_prepare_stages(array $payload,array $schema=[]) {
    if(!in_array($payload['request_details']['workflow_version']??null,[1,2],true))return $payload;
    $details=$payload['request_details'];$physical=[];
    foreach($payload['components']??[] as $part)if($part['kind']!=='assembly') {
        if($payload['type']==='magazine'&&(($part['role']??'')==='cover'||$part['name']==='Capa')) {
            $part['role']='cover';$part['fields']['4233']='4';
        }
        $part['stage']='component';$physical[]=$part;
    }
    $ids=array_map('intval',array_column($physical,'id'));$next=$ids?max($ids)+1:1;
    if($payload['type']==='magazine') {
        $covers=array_filter($physical,static function($part){return ($part['role']??'')==='cover';});
        if(count($covers)!==1||count($physical)<2)throw new DomainException('O produto deve ter uma capa separada do miolo.');
        foreach($covers as $cover)if(!isset($cover['request']['cover']))throw new DomainException('Indique as abas e a lombada da capa, mesmo que sejam zero.');
    }
    $assembly=$details['finishing_operations']??[];$packaging=$details['packaging_operations']??[];
    $jacket=array_filter($physical,static function($part){return ($part['role']??'')==='jacket';});
    if($jacket) {
        if($payload['type']!=='magazine'||count($jacket)>1)throw new DomainException('Indique no máximo uma sobrecapa, num produto com capa e miolo.');
        if(empty($schema['stages.jacket_operation']))throw new DomainException('Instale a operação de colocação da sobrecapa.');
        $id=$schema['stages.jacket_operation'];
        if(!in_array($id,array_column($assembly,'id'),true))$assembly[]=['id'=>$id,'name'=>'Colocar sobrecapa','variants'=>[$id]];
        $payload['request_details']['finishing_operations']=$assembly;
    }
    $corrugated=!empty($schema['config.corrugated_type'])&&(int)$payload['product_type']===$schema['config.corrugated_type'];
    $needAssembly=!$corrugated||$payload['type']!=='standard'||count($physical)>1||count($assembly)>0;
    if($needAssembly&&$physical) {
        $physical[]=['id'=>$next,'name'=>'Assemblagem / Encadernação','kind'=>'assembly','stage'=>'assembly',
            'units_per_product'=>1,'inputs'=>$ids,'fields'=>[],'papers'=>[],'colors'=>[],'rubrics'=>[],
            'request'=>['finishing_operations'=>$assembly]];
        $ids=[$next++];
    }
    if($packaging&&$physical) {
        $physical[]=['id'=>$next,'name'=>'Embalamento individual','kind'=>'assembly','stage'=>'packaging',
        'units_per_product'=>1,'inputs'=>$ids,'fields'=>[],'papers'=>[],'colors'=>[],'rubrics'=>[],
        'request'=>['finishing_operations'=>$packaging]];
        $ids=[$next++];
    }
    if($physical)$physical[]=['id'=>$next,'name'=>'Embalamento do lote / Expedição','kind'=>'assembly','stage'=>'dispatch',
        'units_per_product'=>1,'inputs'=>$ids,'fields'=>[],'papers'=>[],'colors'=>[],'rubrics'=>[],'request'=>[]];
    $payload['components']=$physical;
    if($physical)vd_product_stage_graph($physical);
    $format=$details['final_format']??[];
    if(isset($format['thickness_mm'])&&$format['thickness_mm']!=='')vd_product_number($format['thickness_mm'],'espessura final',false);
    if($packaging||$payload['type']==='magazine') {
        foreach(['width_mm','height_mm'] as $key)vd_product_number($format[$key]??null,'formato final fechado',false);
        if($packaging&&!(max((float)$format['width_mm'],(float)$format['height_mm'])<324&&min((float)$format['width_mm'],(float)$format['height_mm'])<254))
            throw new DomainException('Ensacar / Envelopar: o formato final fechado deve ser inferior a 324 × 254 mm.');
    }
    foreach($physical as $part) {
        $cover=$part['request']['cover']??null;
        if($cover===null)continue;
        foreach(['left_mm','right_mm','spine_mm'] as $key)vd_product_number($cover[$key]??null,'abas / lombada',true);
        if(($cover['left_mm']>0||$cover['right_mm']>0)&&$payload['type']!=='magazine')throw new DomainException('Abas de capa apenas em produtos com capa/miolo.');
        if(in_array($part['role']??'',['cover','jacket'],true)) {
            foreach(['width_mm','height_mm'] as $key)vd_product_number($format[$key]??null,'formato final fechado para capa/sobrecapa',false);
            $width=2*(float)$format['width_mm']+(float)$cover['spine_mm']+(float)$cover['left_mm']+(float)$cover['right_mm'];
            if(abs((float)($part['fields']['4218']??0)-$width)>0.000001||abs((float)($part['fields']['4219']??0)-(float)$format['height_mm'])>0.000001)
                throw new DomainException('Formato aberto da capa/sobrecapa não coincide com formato fechado, lombada e abas.');
        }
    }
    return $payload;
}
function vd_product_stage_validate_operations(array $metadata,array $operations,array $catalog) {
    if(empty($metadata['stage']))return;
    $stage=$metadata['stage'];$included=[];$bases=[];
    foreach($operations as $operation) {
        $id=(int)$operation['operation_id'];
        if(!isset($catalog[$id]))throw new DomainException('Operação da etapa inexistente.');
        $actual=vd_product_stage_code($catalog[$id]['stage']??'');
        if($actual!==$stage&& !($stage==='component'&&$actual==='support'))
            throw new DomainException('A operação '.$catalog[$id]['name'].' não pertence à etapa '.(vd_product_stage_names()[$stage]??$stage).'.');
        $base=trim($catalog[$id]['base']??'')?:$catalog[$id]['name'];
        if($stage!=='component'&&isset($bases[$base]))throw new DomainException('Escolha apenas uma variante técnica de '.$base.' nesta etapa.');
        $bases[$base]=true;$included[]=$id;
    }
    foreach($metadata['requirements']['operations']??[] as $requirement)
        if(!array_intersect($included,array_map('intval',$requirement['variants']??[$requirement['id']])))
            throw new DomainException('Escolha explicitamente uma variante para '.$requirement['name'].'.');
}

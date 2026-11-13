<?php
function vd_config_box_models() {
    return ['folding_flaps'=>'Dobrável com abas','automatic_bottom'=>'Fundo automático','lid_base'=>'Tampa e fundo',
        'mailer'=>'Caixa de envio','rigid'=>'Rígida','insert'=>'Com berço / divisórias','custom'=>'Personalizada'];
}
function vd_config_product_rules(array $schema) {
    $binding=[3713=>[23],3714=>[23],3806=>[23],3830=>[32],3831=>[32],
        3715=>[25],3716=>[26,25],3757=>[26,25],3758=>[26,25],3759=>[25],3760=>[25],3844=>[32],3845=>[32]];
    foreach(['stapled'=>23,'spiral'=>32,'sewn'=>26] as $key=>$operation)
        foreach(['menu','brochure'] as $family)if(!empty($schema['product.'.$family.'_'.$key]))$binding[$schema['product.'.$family.'_'.$key]]=[$operation];
    return ['binding'=>$binding,'page_categories'=>array_values(array_filter([3712,3756,3851,$schema['product.brochure_category']??0])),'box_models'=>vd_config_box_models(),
        'brochure_category'=>$schema['product.brochure_category']??0,'brochure_simple'=>$schema['product.brochure_simple']??0,
        'menu_simple'=>$schema['product.menu_simple']??0,'folding_types'=>[3801]];
}
function vd_config_product_family($id,array $types,array $schema=[]) {
    $byId=array_column($types,null,'id');$visited=[];
    while($id) {
        $id=(int)$id;
        if(isset($visited[$id])||!isset($byId[$id]))throw new DomainException('Hierarquia de produtos inválida.');
        if(in_array($id,vd_config_product_rules($schema)['page_categories'],true))return $id;
        $visited[$id]=true;$id=(int)$byId[$id]['parent_id'];
    }
    return 0;
}
function vd_config_prepare_requirements(array $payload,array $types,array $schema) {
    if(($payload['request_details']['workflow_version']??0)!==2)return $payload;
    $rules=vd_config_product_rules($schema);$family=vd_config_product_family((int)$payload['product_type'],$types,$schema);
    if(in_array((int)$payload['product_type'],$rules['folding_types'],true)) {
        $folding=$payload['request_details']['folding']??null;
        if(!is_array($folding)||!is_int($folding['count']??null)||$folding['count']<1)
            throw new DomainException('Indique o número de dobras do desdobrável (inteiro positivo).');
        if(!in_array($folding['delivery']??null,['open','closed'],true))
            throw new DomainException('Indique se o desdobrável é entregue aberto ou fechado.');
        foreach($payload['components']??[] as $index=>$part)if($part['kind']==='printed') {
            $creaseIds=[18];
            if(!empty($schema['operation.machine_crease']))$creaseIds[]=$schema['operation.machine_crease'];
            $selectedCreases=array_values(array_intersect(array_map('intval',array_column($part['request']['finishing_operations']??[],'id')),$creaseIds));
            if(count($selectedCreases)>1)throw new DomainException('Escolha apenas uma variante de vinco: manual ou máquina.');
            $creaseOperation=$selectedCreases[0]??null;
            $selectedFolds=array_values(array_intersect(array_map('intval',array_column($part['request']['finishing_operations']??[],'id')),[21,33,35]));
            if(count($selectedFolds)>1)throw new DomainException('Escolha apenas uma variante de dobra: manual ou máquina.');
            if($selectedFolds&&$folding['delivery']==='open')throw new DomainException('A entrega aberta não admite dobragem. Retire a dobra dos acabamentos ou escolha entrega fechada.');
            $foldOperation=$selectedFolds[0]??21;
            $part['request']['folding']=$folding;
            $die=$part['request']['die']['status']??null;
            if(in_array($die,['new','used'],true))$part['fields']['4220']=$die==='new'?1249:1248;
            elseif($die==='none'||!in_array((int)($part['fields']['4220']??0),[1248,1249],true))$part['fields']['4220']=$creaseOperation===18?1608:1565;
            $part['fields']['6110']=$folding['delivery']==='closed'&&$foldOperation===21?1545:1544;
            $part['fields']['6173']='false';
            $extras=array_diff(array_filter(array_map('intval',explode(',',(string)($part['fields']['6291']??'')))),array_merge($creaseIds,[21,33,35]));
            $part['fields']['6291']=implode(',',array_merge($extras,array_filter($folding['delivery']==='closed'?[$creaseOperation,$foldOperation]:[$creaseOperation])));
            $payload['components'][$index]=$part;
        }
    }else {
        if(isset($payload['request_details']['folding']))throw new DomainException('Os dados de dobras só se aplicam a desdobráveis.');
        foreach($payload['components']??[] as $index=>$part)if(isset($part['request']['folding'])) {
            unset($part['request']['folding'],$part['fields']['6173']);
            if((int)($part['fields']['4220']??0)===1608)unset($part['fields']['4220']);
            if(in_array((int)($part['fields']['6110']??0),[1544,1545],true))unset($part['fields']['6110']);
            $part['fields']['6291']=implode(',',array_diff(array_filter(explode(',',(string)($part['fields']['6291']??''))),[18,21,$schema['operation.machine_crease']??0]));
            $payload['components'][$index]=$part;
        }
    }
    if($family) {
        $total=$payload['request_details']['total_pages']??null;
        if(!is_int($total)||$total<1||$total>100000)throw new DomainException('Indique o número total de páginas, incluindo a capa (inteiro de 1 a 100 000).');
        $binding=$rules['binding'][(int)$payload['product_type']]??[];
        // Magazines may be perfect-bound (brochadas); only brochures and menus need staples, spiral or sewing above 4 pages.
        if(in_array($family,array_filter([3851,$schema['product.brochure_category']??0]),true)&&$total>4&&!array_intersect($binding,[23,26,32]))
            throw new DomainException('Com mais de 4 páginas, escolha um subtipo com agrafes, espiral ou costura.');
        if($family===3756&&!$binding)throw new DomainException('Escolha um subtipo que defina a encadernação do livro.');
        $physical=array_values(array_filter($payload['components']??[],static function($part){return $part['kind']!=='assembly';}));
        if($payload['type']==='magazine') {
            if($total<=4)throw new DomainException('Uma capa separada do miolo exige mais de 4 páginas totais.');
            $miolo=[];
            foreach($physical as $index=>$part)if(($part['role']??'')!=='jacket'&&($part['role']??'')!=='cover'&&$part['name']!=='Capa')$miolo[]=$index;
            if(count($miolo)!==1)throw new DomainException('Indique um miolo para o número total de páginas.');
            $physical[$miolo[0]]['fields']['4233']=(string)($total-4);
        }else {
            if(count($physical)!==1)throw new DomainException('O produto sem capa separada deve ter um componente físico.');
            $physical[0]['fields']['4233']=(string)$total;
        }
        $payload['components']=$physical;
    }elseif(isset($payload['request_details']['total_pages']))throw new DomainException('Paginação total só se aplica a revistas, brochuras, livros e ementas.');
    // Historical requirements remain in the immutable source; new assembly is derived at conversion.
    $payload['request_details']['finishing_operations']=[];
    return $payload;
}
function vd_config_derive_assembly(array $payload,array $types,array $schema,array $catalog) {
    if(($payload['request_details']['workflow_version']??0)!==2)return $payload;
    $rules=vd_config_product_rules($schema);$family=vd_config_product_family((int)$payload['product_type'],$types,$schema);
    $ids=$rules['binding'][(int)$payload['product_type']]??[];
    if($family&&($payload['request_details']['total_pages']??0)>4)$ids=array_merge([19],$ids);
    if(array_filter($payload['components'],static function($part){return ($part['role']??'')==='jacket';}))
        $ids[]=$schema['stages.jacket_operation'];
    $catalog=array_column($catalog,null,'id');$requirements=[];
    foreach(array_unique($ids) as $id) {
        if(!isset($catalog[$id])||($catalog[$id]['stage']??'')!=='assembly'||
            !vd_config_finishing_applies((int)$payload['product_type'],$catalog[$id]['product_types']??[],$types))
            throw new DomainException('Operação de assemblagem #'.$id.' não está ativa/associada ao tipo de produto. Reveja as operações.');
        $base=$catalog[$id]['base']?:$catalog[$id]['name'];$variants=[];
        foreach($catalog as $candidate)if($candidate['stage']==='assembly'&&($candidate['base']?:$candidate['name'])===$base&&
            vd_config_finishing_applies((int)$payload['product_type'],$candidate['product_types']??[],$types))$variants[]=(int)$candidate['id'];
        $requirements[]=['id'=>$id,'name'=>$base,'variants'=>$variants];
    }
    foreach($payload['components'] as &$part)if(($part['stage']??'')==='assembly')$part['request']['finishing_operations']=$requirements;
    unset($part);
    return $payload;
}
function vd_config_quote_payload(array $payload,$title) {
    if(($payload['request_details']['workflow_version']??0)!==2||$payload['type']!=='standard')return $payload;
    $physical=array_values(array_filter($payload['components'],static function($part){return $part['kind']!=='assembly';}));
    if(count($physical)!==1||$physical[0]['kind']!=='printed'||!empty($payload['request_details']['packaging_operations']))return $payload;
    foreach($payload['components'] as $part)if($part['kind']==='assembly'&&
        (($part['stage']??'')==='packaging'||!empty($part['request']['finishing_operations'])))return $payload;
    $part=$physical[0];$part['name']=$title;$part['inputs']=[];
    $operations=$part['request']['finishing_operations']??[];
    $operations[]=['id'=>9,'name'=>'Corte final','variants'=>[9]];
    $operations[]=['id'=>10,'name'=>'Embalamento do lote','variants'=>[10]];
    if(in_array('delivery',$payload['request_details']['dispatch']??[],true))
        $operations[]=['id'=>12,'name'=>'Entrega','variants'=>[12]];
    $part['request']['finishing_operations']=$operations;
    $extras=array_filter(array_map('intval',explode(',',(string)($part['fields']['6291']??''))));
    $part['fields']['6291']=implode(',',array_unique(array_merge($extras,array_column($operations,'id'))));
    $payload['components']=[$part];
    return $payload;
}

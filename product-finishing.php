<?php
require_once __DIR__.'/product-requirements.php';
function vd_config_prepare_packaging(array $payload,array $schema) {
    $corrugated=!empty($schema['config.corrugated_type'])&&(int)($payload['product_type']??0)===$schema['config.corrugated_type'];
    $box=(int)($payload['product_type']??0)===3751||$corrugated;
    if($box) {
        $dimensions=$payload['request_details']['box']??[];
        foreach(['length_mm'=>'comprimento','width_mm'=>'largura','height_mm'=>'altura'] as $key=>$label)
            vd_product_number($dimensions[$key]??null,$label.' da caixa montada',false);
        if(!in_array($dimensions['measurement']??'', ['internal','external'],true))throw new DomainException('Caixa: indique se as dimensões são interiores ou exteriores.');
        if(($payload['request_details']['workflow_version']??0)===2) {
            if(!isset(vd_config_box_models()[$dimensions['model']??'']))throw new DomainException('Escolha o modelo da caixa.');
            $description=$dimensions['model_description']??'';
            if(!is_string($description)||strlen($description)>1000||($dimensions['model']==='custom'&&trim($description)===''))
                throw new DomainException('Descreva a caixa personalizada (até 1000 caracteres).');
            $structures=['folding_flaps'=>'folding_box','automatic_bottom'=>'folding_box','lid_base'=>'lid_base_box',
                'mailer'=>'folding_box','rigid'=>'rigid_box','insert'=>'accessory_box'];
            if(!$corrugated&&isset($structures[$dimensions['model']])&&$payload['type']!==$structures[$dimensions['model']])
                throw new DomainException('A estrutura dos componentes não corresponde ao modelo da caixa.');
        }
    }elseif(isset($payload['request_details']['box']))throw new DomainException('As dimensões da caixa montada só se aplicam a caixas.');
    if(empty($schema['config.corrugated_type'])||(int)($payload['product_type']??0)!==$schema['config.corrugated_type'])return $payload;
    if(in_array($payload['request_details']['workflow_version']??null,[1,2],true))
        $payload['components']=array_values(array_filter($payload['components']??[],static function($part){return ($part['kind']??'')!=='assembly';}));
    if(($payload['type']??'')!=='standard'||count($payload['components']??[])!==1)throw new DomainException('Cartão canelado: indique uma caixa por produto.');
    foreach($payload['components'] as &$part) {
        if(($part['kind']??'')!=='printed')throw new DomainException('Cartão canelado: estrutura da caixa inválida.');
        foreach(['thickness_mm'=>'espessura do cartão canelado'] as $key=>$label)
            vd_product_number($part['request']['corrugated'][$key]??null,$label,false);
        if(($part['colors']??[])!==[3804]||($part['request']['printing']['mode']??'')!=='none'||trim($part['request']['printing']['pantone']??'')!=='')
            throw new DomainException('A embalagem de cartão canelado é sem impressão.');
        $part['request']['paper']='Cartão canelado';
        $part['fields']['4229']='';
    }
    unset($part);
    return $payload;
}
function vd_config_document_product($product,array $types) {
    return vd_config_finishing_applies((int)$product,[3752],$types);
}
function vd_config_book_numbering(array $book,array $quantities) {
    $start=$book['start']??null;$sets=$book['sets_per_book']??null;$copies=$book['copies']??null;
    if(!is_string($start)||!preg_match('/^[0-9]{1,12}$/D',$start))throw new DomainException('Documentos: indique o número de início (até 12 algarismos).');
    foreach(['conjuntos por livro'=>$sets,'vias por conjunto'=>$copies] as $label=>$value)
        if(!is_int($value)||$value<1||$value>($label==='vias por conjunto'?20:10000))throw new DomainException('Documentos: indique '.$label.' como inteiro positivo (máximo '.($label==='vias por conjunto'?20:10000).').');
    if(!in_array($book['binding']??'', ['perforated','glued'],true))throw new DomainException('Documentos: escolha picotado ou colado.');
    $colors=$book['paper_colors']??null;
    if(!is_array($colors)||array_values($colors)!==$colors||count($colors)!==$copies)throw new DomainException('Documentos: indique a cor do papel de cada via.');
    foreach($colors as $color)if(!is_string($color)||trim($color)===''||strlen($color)>80||preg_match('/[,\r\n]/',$color))throw new DomainException('Documentos: indique uma cor de papel por via, até 80 caracteres, sem vírgulas.');
    $ends=[];
    foreach($quantities as $quantity) {
        if(!is_int($quantity)||$quantity<1||$quantity>10000000)throw new DomainException('Documentos: a quantidade deve indicar um número inteiro de livros.');
        $end=(int)$start+$sets*$quantity-1;
        if($end>999999999999)throw new DomainException('Documentos: a numeração final excede 12 algarismos.');
        $ends[$quantity]=str_pad((string)$end,strlen($start),'0',STR_PAD_LEFT);
    }
    return $ends;
}
function vd_config_prepare_book(array $payload,array $types) {
    $book=$payload['request_details']['document_book']??null;
    if(!vd_config_document_product($payload['product_type']??0,$types)) {
        if($book!==null)throw new DomainException('Livros autocopiativos só se aplicam ao tipo de produto Documentos.');
        return $payload;
    }
    if(!is_array($book))throw new DomainException('Documentos: preencha a configuração do livro autocopiativo.');
    vd_config_book_numbering($book,$payload['quantities']??[]);
    $payload['request_details']['document_book']['unit']='livro';
    foreach($payload['components']??[] as $index=>$part)if(($part['kind']??'')==='printed')
        $payload['components'][$index]['request']['paper']='Autocopiativo';
    return $payload;
}
function vd_config_finishing_rules($dieId=0,$envelopingId=0) {
    $rules=[
        'version'=>2,
        'binding'=>[23,25,26,32,39],
        'lamination'=>[13,14],
        'exclusive'=>[[23,25,32]],
        'warning_pairs'=>[
            ['left'=>[13,14],'right'=>[6,22,24,42],'message'=>'Plastificação com verniz, termoestampagem ou colagem: confirmar aderência, película e sequência de produção.'],
            ['left'=>[26],'right'=>[32],'message'=>'Costura com espiral: confirmar se são etapas ou peças distintas.'],
            ['left'=>[8,11,30,40,41],'right'=>[8,11,30,40,41],'message'=>'Foram escolhidas várias operações de corte especial; confirmar se são etapas distintas ou alternativas.']
        ],
        'binding_hints'=>[3713=>[23],3714=>[23],3715=>[25],3716=>[26,25],3806=>[23],3757=>[26,25],3758=>[26,25],3759=>[25],3760=>[25],3830=>[32],3844=>[32]]
    ];
    $rules['die_operation']=(int)$dieId;
    $rules['enveloping_operation']=(int)$envelopingId;
    $rules['size_limited_operations']=[29,(int)$envelopingId];
    $rules['envelope_max']=[324,254];
    return $rules;
}
function vd_config_finishing_applies($type,array $associations,array $types) {
    $byId=array_column($types,null,'id');$visited=[];
    while($type) {
        $type=(int)$type;
        if(!isset($byId[$type])||in_array($type,$visited,true))throw new DomainException('Tipo de produto inexistente ou hierarquia inválida.');
        if(in_array($type,$associations,true))return true;
        $visited[]=$type;$type=(int)$byId[$type]['parent_id'];
    }
    return false;
}
function vd_config_finishing_review(array $payload,array $types,array $operations,array $choices,array $rules) {
    $product=(int)($payload['product_type']??0);
    if(!$product)throw new DomainException('Tipo de produto inexistente.');
    vd_config_finishing_applies($product,[],$types);
    $available=array_column($operations,null,'id');
    $errors=[];$warnings=[];$scopes=[];$allIds=[];
    $scopes[]=['name'=>'Conjunto','operations'=>$payload['request_details']['finishing_operations']??[],'component'=>null];
    $scopes[]=['name'=>'Embalamento individual','operations'=>$payload['request_details']['packaging_operations']??[],'component'=>null];
    foreach($payload['components']??[] as $component) {
        if(in_array($payload['request_details']['workflow_version']??null,[1,2],true)&&$component['kind']==='assembly')continue;
        $scopes[]=['name'=>$component['name']??'Componente','operations'=>$component['request']['finishing_operations']??[],'component'=>$component];
    }
    foreach($scopes as $scope) {
        $prefix=$scope['name'].': ';$ids=[];
        if(!is_array($scope['operations'])||array_values($scope['operations'])!==$scope['operations']||count($scope['operations'])>100) {
            $errors[]=$prefix.'seleção de acabamentos inválida.';continue;
        }
        foreach($scope['operations'] as $operation) {
            if(!is_array($operation)||!is_int($operation['id']??null)||!is_string($operation['name']??null)||strlen($operation['name'])>500) {
                $errors[]=$prefix.'acabamento inválido.';continue;
            }
            $id=$operation['id'];
            if(!isset($available[$id])||in_array($id,$ids,true)) {
                $errors[]=$prefix.'acabamento inexistente, inativo, repetido ou sem acesso.';continue;
            }
            $ids[]=$id;
            if(in_array($payload['request_details']['workflow_version']??null,[1,2],true)) {
                $expected=$scope['component']?'component':($scope['name']==='Conjunto'?'assembly':'packaging');
                if(($available[$id]['stage']??'component')!==$expected)$errors[]=$prefix.'operação numa etapa incorreta.';
                $variants=$operation['variants']??[$id];
                if(!is_array($variants)||!in_array($id,$variants,true))$errors[]=$prefix.'variantes técnicas inválidas.';
                else foreach($variants as $variant) {
                    if(!is_int($variant)||!isset($available[$variant])||($available[$variant]['base']??'')!==($available[$id]['base']??'')||
                        ($available[$variant]['stage']??'component')!==$expected||
                        !vd_config_finishing_applies($product,$available[$variant]['product_types']??[],$types))$errors[]=$prefix.'variante técnica não aplicável ao produto/etapa.';
                }
            }
            if($id===42) {
                $tape=$operation['tape']??[];
                $quantity=$tape['quantity']??null;
                if(!is_int($quantity)||$quantity<1||$quantity>10000)$errors[]=$prefix.'indique a quantidade de tiras de biadesivo por exemplar (inteiro de 1 a 10000).';
                foreach(['x_mm'=>'X','y_mm'=>'Y'] as $key=>$label) {
                    $value=$tape[$key]??null;
                    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0)$errors[]=$prefix.'indique a dimensão '.$label.' do biadesivo em mm, superior a zero.';
                }
            }
            if($id===36) {
                $thread=$operation['thread']??null;
                foreach(['type'=>'tipo de fio','color'=>'cor do fio'] as $field=>$label) {
                    if(!is_array($thread)||!is_string($thread[$field]??null)||trim($thread[$field])===''||strlen($thread[$field])>500)
                        $errors[]=$prefix.'indique '.$label.' (até 500 caracteres) para a aplicação de fio.';
                }
                $length=is_array($thread)?($thread['length_mm']??null):null;
                if(!is_numeric($length)||!is_finite((float)$length)||(float)$length<=0)
                    $errors[]=$prefix.'indique um comprimento do fio em mm superior a zero.';
            }
            if(in_array($id,$rules['size_limited_operations'],true)) {
                $parts=in_array($payload['request_details']['workflow_version']??null,[1,2],true)?
                    [['fields'=>['4218'=>$payload['request_details']['final_format']['width_mm']??null,'4219'=>$payload['request_details']['final_format']['height_mm']??null]]]:
                    ($scope['component']?[$scope['component']]:array_values(array_filter($payload['components']??[],static function($part){return ($part['kind']??'')!=='assembly';})));
                $fits=(bool)$parts;
                foreach($parts as $part) {
                    $width=$part['fields']['4218']??null;$height=$part['fields']['4219']??null;
                    if(!is_numeric($width)||!is_numeric($height)||(float)$width<=0||(float)$height<=0||
                        !((max((float)$width,(float)$height)<$rules['envelope_max'][0])&&(min((float)$width,(float)$height)<$rules['envelope_max'][1])))$fits=false;
                }
                if(!$fits)$errors[]=$prefix.$operation['name'].' exige largura e altura positivas, estritamente inferiores a 324 × 254 mm, considerando a rotação.';
            }
            if(isset($available[$id]['is_extra'])&&!$available[$id]['is_extra'])$warnings[]=$prefix.$operation['name'].' está atualmente marcada como Base; escolha antiga mantida para revisão.';
            if(!vd_config_finishing_applies($product,$available[$id]['product_types']??[],$types)) {
                if(!empty($available[$id]['legacy_selected']))$warnings[]=$prefix.$operation['name'].' já estava guardado, mas não está associado a este tipo de produto nas operações; rever.';
                else $errors[]=$prefix.$operation['name'].' não está associado a este tipo de produto nas operações.';
            }
            if($id===$rules['die_operation'])$warnings[]=$prefix.'novo cortante: confirmar desenho, fornecedor, custo e prazo. A produção do cortante não substitui a operação de corte e vinco.';
        }
        $component=$scope['component'];
        if($component) {
            $die=$component['request']['die']??null;
            if($die!==null) {
                if(!is_array($die)||!in_array($die['status']??'', ['none','new','used'],true)||!is_string($die['number']??null)||strlen($die['number'])>100) {
                    $errors[]=$prefix.'dados do cortante inválidos.';
                }else {
                    if($die['status']==='used'&&trim($die['number'])==='')$errors[]=$prefix.'indique o número do cortante usado.';
                    if($die['status']==='new'&&!in_array($rules['die_operation'],$ids,true))$errors[]=$prefix.'selecione a produção do novo cortante.';
                    if($die['status']!=='new'&&in_array($rules['die_operation'],$ids,true))$errors[]=$prefix.'a produção de cortante só se aplica a um cortante novo.';
                    if($die['status']==='used')$warnings[]=$prefix.'confirmar o estado e a adequação do cortante nº '.$die['number'].' ao trabalho.';
                    if($die['status']==='used'&&!vd_config_finishing_applies($product,$available[$rules['die_operation']]['product_types']??[],$types)) {
                        if(in_array($component['id']??0,$rules['legacy_die']??[],true))$warnings[]=$prefix.'cortante usado já guardado, mas sem associação atual a este tipo de produto; rever.';
                        else $errors[]=$prefix.'cortante não está associado a este tipo de produto nas operações.';
                    }
                }
            }
            if($component['kind']==='assembly'&&$ids)$warnings[]=$prefix.'confirmar os acabamentos aplicados à montagem.';
            foreach($rules['binding'] as $id)if(in_array($id,$ids,true))$warnings[]=$prefix.'a encadernação deve ser confirmada para o conjunto.';
            $laminated=false;
            foreach(['6267','6268'] as $field) {
                $value=$component['fields'][$field]??'';
                if($value===''||$value===null)continue;
                $valid=null;
                foreach($choices as $choice)if((int)$choice['fields_id']===(int)$field&&(string)$choice['id']===(string)$value)$valid=$choice;
                if(!$valid){$errors[]=$prefix.'tipo de plastificação inválido para a face escolhida.';continue;}
                if($valid['value']!=='none')$laminated=true;
            }
            $enabled=$component['request']['lamination']??$laminated;
            if(!is_bool($enabled))$errors[]=$prefix.'escolha de plastificação inválida.';
            if($enabled&&!$laminated)$errors[]=$prefix.'escolha o tipo de plastificação em pelo menos uma face.';
            if($enabled===false&&$laminated)$errors[]=$prefix.'a plastificação está desmarcada mas existem faces plastificadas.';
            if($laminated) {
                if($component['kind']!=='printed')$errors[]=$prefix.'a plastificação deve ser associada a um componente impresso.';
                if(empty($rules['lamination_available']))$errors[]=$prefix.'nenhuma operação de plastificação está ativa ou acessível.';
                if(!vd_config_finishing_applies($product,$rules['lamination_products']??[],$types)) {
                    if(in_array($component['id']??0,$rules['legacy_lamination']??[],true))$warnings[]=$prefix.'plastificação já guardada, mas sem associação atual a este tipo de produto; rever.';
                    else $errors[]=$prefix.'plastificação não está associada a este tipo de produto nas operações.';
                }
                if(($component['name']??'')==='Miolo')$warnings[]=$prefix.'confirmar gramagem, abertura e encadernação antes de plastificar.';
                $ids[]=13;
            }
        }
        if(array_intersect($ids,$rules['lamination'])&&!$component)$warnings[]=$prefix.'plastificação guardada anteriormente; indique o tipo e as faces no componente respetivo.';
        foreach($rules['exclusive'] as $group)if(count(array_intersect($ids,$group))>1)$errors[]=$prefix.'agrafar, brochar e espiral são encadernações finais alternativas; escolha apenas uma.';
        foreach($rules['warning_pairs'] as $pair) {
            foreach(array_intersect($ids,$pair['left']) as $left)foreach(array_intersect($ids,$pair['right']) as $right)
                if($left!==$right)$warnings[]=$prefix.$pair['message'];
        }
        $allIds=array_merge($allIds,$ids);
    }
    $bindings=array_unique(array_intersect($allIds,[23,25,32]));
    if(count($bindings)>1)$errors[]='O produto tem encadernações finais alternativas em componentes diferentes. Confirme uma única encadernação do conjunto.';
    $hint=$rules['binding_hints'][(int)$payload['product_type']]??[];
    if($hint&&array_diff(array_intersect($allIds,$rules['binding']),$hint))$warnings[]='A encadernação escolhida difere da subcategoria do produto. Rever antes de orçamentar.';
    if(in_array((int)$payload['product_type'],[3831,3845,3846],true))$warnings[]='Wire-O / argolas: especificar nas observações; o catálogo ainda não tem uma operação própria correspondente.';
    return ['version'=>$rules['version'],'profile'=>'operations','errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings))];
}

<?php
require_once __DIR__.'/quote-workflow-store.php';
require_once __DIR__.'/product-configurator-queries.php';
require_once __DIR__.'/quote-workflow-discovery.php';
require_once __DIR__.'/product-finishing.php';
require_once __DIR__.'/product-configurator-native.php';

function vd_config_schema(mysqli $db) {
    return array_map('intval',array_column($db->query('SELECT role,native_id FROM app_vd_config_schema')->fetch_all(MYSQLI_ASSOC),'native_id','role'));
}
function vd_config_insert(mysqli $db,$entity,array $values) {
    $row=[];
    foreach($db->query("SHOW COLUMNS FROM app_entity_$entity") as $column) {
        if ($column['Field']==='id')continue;
        $row[$column['Field']]=$column['Default']??($column['Null']==='YES'?null:(preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint)/',$column['Type'])?0:''));
    }
    foreach($values as $key=>$value) {
        if (!array_key_exists($key,$row))throw new RuntimeException('Unknown native field '.$key);
        $row[$key]=$value;
    }
    $s=$db->prepare("INSERT INTO app_entity_$entity (`".implode('`,`',array_keys($row)).'`) VALUES('.implode(',',array_fill(0,count($row),'?')).')');
    $v=array_values($row);$s->bind_param(str_repeat('s',count($v)),...$v);$s->execute();return (int)$db->insert_id;
}
function vd_config_document($name,$content) {
        $name=basename(str_replace('\\','/',(string)$name));
        if($name===''||strlen($name)>240||preg_match('/[\x00-\x1f\x7f]/',$name))throw new DomainException('Nome de documento inválido.');
        $size=strlen($content);
        if(!$size||$size>10*1024*1024)throw new DomainException('Cada documento deve ter entre 1 byte e 10 MB.');
        $allowed=[
            'pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],
            'tif'=>['image/tiff'],'tiff'=>['image/tiff'],'txt'=>['text/plain'],
            'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pptx'=>['application/zip','application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'odt'=>['application/zip','application/vnd.oasis.opendocument.text'],
            'ods'=>['application/zip','application/vnd.oasis.opendocument.spreadsheet'],
            'zip'=>['application/zip','application/x-zip']
        ];
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $drawing=false;
        if($extension==='svg') {
            if(preg_match('/<!DOCTYPE|<!ENTITY/i',$content))throw new DomainException('SVG: referências DTD/entidades não são permitidas.');
            $previous=libxml_use_internal_errors(true);
            try {
                $xml=new DOMDocument();
                if(!$xml->loadXML($content,LIBXML_NONET)||!$xml->documentElement||$xml->documentElement->localName!=='svg')
                    throw new DomainException('O desenho SVG não é um documento SVG válido.');
                $xpath=new DOMXPath($xml);
                if($xpath->query('//*[local-name()="script" or local-name()="foreignObject"]')->length)
                    throw new DomainException('SVG: conteúdo ativo não é permitido no desenho.');
                foreach($xpath->query('//@*') as $attribute)
                    if(stripos($attribute->nodeName,'on')===0||preg_match('/(?:javascript:|https?:|file:|data:|url\s*\()/i',$attribute->value))
                        throw new DomainException('SVG: eventos e referências externas/ativas não são permitidos.');
            }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
            $drawing=true;$mime='image/svg+xml';
        }elseif($extension==='dxf') {
            if(strncmp($content,"AutoCAD Binary DXF\r\n\x1a\0",22)!==0&&
                !(preg_match('/(?:^|\r?\n)\s*0\s*\r?\nSECTION\s*(?:\r?\n)/',$content)&&preg_match('/(?:^|\r?\n)\s*0\s*\r?\nEOF\s*$/',$content)))
                throw new DomainException('O desenho DXF não tem uma estrutura DXF reconhecida.');
            $drawing=true;$mime='application/dxf';
        }elseif($extension==='ai') {
            if(strncmp($content,'%PDF-',5)!==0&&strncmp($content,'%!PS-Adobe-',11)!==0)
                throw new DomainException('AI: exporte um ficheiro Illustrator compatível com PDF ou PostScript.');
            if(!in_array($mime,['application/pdf','application/postscript','text/plain'],true))
                throw new DomainException('O conteúdo do desenho AI não é PDF/PostScript reconhecido.');
            $drawing=true;
        }
        if(!$drawing&&(!isset($allowed[$extension])||!in_array($mime,$allowed[$extension],true)))throw new DomainException('Formato não permitido ou conteúdo inválido: '.$name.'. Use PDF, SVG, DXF, AI, imagens, DOCX, XLSX, PPTX, ODT, ODS ou ZIP.');
        return ['name'=>$name,'mime'=>$mime,'size'=>$size,'hash'=>hash('sha256',$content),'content'=>$content];
    }
    function vd_config_uploads(array $files) {
        if(!$files)return [];
        if(!isset($files['error'])||!is_array($files['error'])||count($files['error'])>10)throw new DomainException('Pode anexar até 10 documentos por pedido.');
        $result=[];$total=0;
        foreach($files['error'] as $index=>$error) {
            if($error===UPLOAD_ERR_NO_FILE)continue;
            if($error!==UPLOAD_ERR_OK)throw new DomainException('Não foi possível carregar o documento. Verifique o ficheiro e o limite de 10 MB.');
            $path=$files['tmp_name'][$index]??'';
            if(!is_uploaded_file($path))throw new DomainException('Documento carregado inválido.');
            $size=filesize($path);
            if($size===false||$size>10*1024*1024)throw new DomainException('Cada documento pode ter no máximo 10 MB.');
            $content=file_get_contents($path);
            if($content===false)throw new RuntimeException('Não foi possível ler o documento carregado.');
            $result[]=vd_config_document($files['name'][$index]??'',$content);
            $total+=strlen($content);
            if($total>25*1024*1024)throw new DomainException('O conjunto dos documentos não pode ultrapassar 25 MB.');
        }
        return $result;
    }
function vd_config_rubric_types() {
    return [3708,3744,3745,3746,3761,3801,3834,3835];
}
function vd_config_sticker_cutting_choices() {
    return [
        'cut_and_crease'=>['label'=>'Corte e vinco','operation_ids'=>[8,11]],
        'plotter_a3'=>['label'=>'Corte de plotter A3','operation_ids'=>[30]],
        'cutting_table'=>['label'=>'Mesa de corte','operation_ids'=>[41]]
    ];
}
function vd_config_rubrics_validate(array $payload) {
    if(!array_key_exists('rubrics',$payload))return;
    $rubrics=$payload['rubrics'];
    if(!is_array($rubrics)||array_values($rubrics)!==$rubrics||count($rubrics)>500)throw new DomainException('Indique até 500 rubricas.');
    if(!$rubrics)return;
    if(!in_array((int)($payload['product_type']??0),vd_config_rubric_types(),true))
        throw new DomainException('Rubricas disponíveis apenas para Cartões, Etiquetas, Cintas, Autocolantes, Expositores, Desdobráveis, Rótulos e Tags.');
    $total=0;
    foreach($rubrics as $index=>$rubric) {
        $prefix='Rubrica '.($index+1).': ';
        if(!is_array($rubric)||array_diff(array_keys($rubric),['quantity','description','code']))throw new DomainException($prefix.'dados inválidos.');
        $quantity=$rubric['quantity']??null;
        if(!is_int($quantity)||$quantity<1||$quantity>10000000)throw new DomainException($prefix.'a quantidade deve ser inteira positiva.');
        $description=$rubric['description']??null;
        if(!is_string($description)||trim($description)===''||mb_strlen($description,'UTF-8')>200)throw new DomainException($prefix.'indique a descrição (até 200 caracteres).');
        $code=$rubric['code']??'';
        if(!is_string($code)||mb_strlen($code,'UTF-8')>100)throw new DomainException($prefix.'código até 100 caracteres.');
        $total+=$quantity;
    }
    if($total>10000000)throw new DomainException('A soma das rubricas não pode exceder 10 milhões.');
    if(($payload['quantities']??null)!==[$total])throw new DomainException('Com rubricas, a quantidade do pedido é a soma das rubricas ('.$total.').');
}
function vd_config_assign_rubrics(array $payload) {
    if(empty($payload['rubrics']))return $payload;
    $rubrics=array_map(static function($rubric){
        return ['quantity'=>$rubric['quantity'],'name'=>trim($rubric['description']),'code'=>trim($rubric['code']??'')];
    },$payload['rubrics']);
    foreach($payload['components'] as &$component)if($component['kind']==='printed')$component['rubrics']=$rubrics;
    unset($component);
    return $payload;
}
function vd_config_validate(array $payload,$incomplete=false) {
    if (!isset(vd_product_types()[$payload['type']??'']))throw new DomainException('Tipo de produto inválido.');
    $components=$payload['components']??[];
    if ((!$components&&!$incomplete)||count($components)>30)throw new DomainException('Indique até 30 componentes.');
    vd_product_graph($components);
    $quantities=$payload['quantities']??[];
    if ((!$quantities&&!$incomplete)||count($quantities)>12)throw new DomainException('Indique até 12 quantidades.');
    foreach($quantities as $q)if(!is_int($q)||$q<1||$q>10000000)throw new DomainException('Quantidades devem ser inteiras positivas, até 10 milhões.');
    if(count($quantities)!==count(array_unique($quantities)))throw new DomainException('Quantidade repetida.');
    vd_config_rubrics_validate($payload);
    if(in_array($payload['request_details']['workflow_version']??null,[1,2],true)&&$components) {
        vd_product_stage_graph($components);
    }elseif($components&&$payload['type']!=='standard') {
        $assembly=array_values(array_filter($components,static function($c){return $c['kind']==='assembly';}));
        if(count($assembly)!==1||count($assembly[0]['inputs'])!==count($components)-1)throw new DomainException('Uma assemblagem final deve receber todos os componentes.');
    } elseif(array_filter($components,static function($c){return $c['kind']!=='printed';}))throw new DomainException('Produto habitual: apenas componentes impressos.');
    foreach($components as $component) {
        if(!is_int($component['id'])||$component['id']>10000||trim($component['name']??'')===''||strlen($component['name'])>200)throw new DomainException('Nome/identificador de componente inválido.');
        foreach($component['inputs']??[] as $input)if(!is_int($input))throw new DomainException('Entrada de assemblagem inválida.');
        vd_product_number($component['units_per_product']??1,'unidades por produto',false);
        if(($component['kind']??'')!=='material'&&(float)($component['units_per_product']??1)!==1.0)throw new DomainException('Unidades por produto deve ser 1 na impressão e assemblagem.');
    }
    if(isset($payload['markup']))vd_product_number($payload['markup'],'acréscimo');
    if(strlen(json_encode($payload,JSON_THROW_ON_ERROR))>2000000)throw new DomainException('Configuração excede 2 MB.');
}
function vd_config_request_validate(array $payload,$requirePrintFaces=true) {
    $dispatch=$payload['request_details']['dispatch']??[];
    if(!is_array($dispatch)||array_values($dispatch)!==$dispatch)throw new DomainException('Opções de expedição inválidas.');
    foreach($dispatch as $method)if(!is_string($method)||!in_array($method,['pickup','carrier','delivery'],true))throw new DomainException('Opção de expedição inválida.');
    if(count($dispatch)!==count(array_unique($dispatch)))throw new DomainException('Opções de expedição repetidas.');
    if(count($dispatch)>1)throw new DomainException('Escolha apenas uma opção de expedição.');
    $cutting=$payload['request_details']['cutting_operation']??'';
    if((int)($payload['product_type']??0)===3761) {
        if(!is_string($cutting)||!isset(vd_config_sticker_cutting_choices()[$cutting]))
            throw new DomainException('Escolha a operação de corte pretendida para os autocolantes.');
    }elseif($cutting!=='')throw new DomainException('A operação de corte indicada só se aplica a autocolantes.');
    foreach($payload['components']??[] as $component) {
        foreach(['paper'=>1000,'description'=>2000] as $key=>$limit)if(isset($component['request'][$key])&&(!is_string($component['request'][$key])||strlen($component['request'][$key])>$limit))throw new DomainException('Descrição do produto demasiado longa ou inválida.');
        foreach([4218,4219,4233] as $field)if(isset($component['fields'][$field])&&$component['fields'][$field]!=='')vd_product_number($component['fields'][$field],'tamanho / páginas do produto',false);
        if(($component['kind']??'')!=='printed')continue;
        $prefix=($component['name']??'Componente').': ';
        $printing=$component['request']['printing']??null;
        $colors=$component['colors']??[];
        if(!is_array($colors)||!$colors||array_values($colors)!==$colors)throw new DomainException($prefix.'escolha a impressão.');
        if(in_array(3742,$colors,true))throw new DomainException($prefix.'verniz é um acabamento, não uma cor de impressão.');
        foreach($colors as $color)if(!is_int($color)||!in_array($color,[3728,3729,3730,3731,3732,3804,3820,3821],true))throw new DomainException($prefix.'cor de impressão inválida.');
        if(count($colors)!==count(array_unique($colors))||(in_array(3804,$colors,true)&&count($colors)>1))throw new DomainException($prefix.'combinação de cores inválida.');
        if($requirePrintFaces&&$colors!==[3804]&&
            !in_array($component['fields'][4229]??null,[1255,1256,1257,'1255','1256','1257'],true))
            throw new DomainException($prefix.'indique as faces de impressão: frente, frente e verso ou verso.');
        if($printing!==null) {
            if(!is_array($printing)||!in_array($printing['mode']??'', ['cmyk','black','pantone','cmyk_pantone','white','combined','none'],true))throw new DomainException($prefix.'escolha a impressão.');
            $presets=['cmyk'=>[3728,3729,3730,3731],'black'=>[3731],'pantone'=>[3732],'cmyk_pantone'=>[3728,3729,3730,3731,3732],'white'=>[3821],'none'=>[3804]];
            if(isset($presets[$printing['mode']])) {
                $expected=$presets[$printing['mode']];$actual=$colors;sort($expected);sort($actual);
                if($expected!==$actual)throw new DomainException($prefix.'as cores não correspondem à impressão escolhida.');
            }
        }
        $pantone=$printing['pantone']??'';
        if(!is_string($pantone)||strlen($pantone)>500)throw new DomainException($prefix.'identificação Pantone inválida.');
        if(array_intersect($colors,[3732,3820])&&trim($pantone)==='')throw new DomainException($prefix.'identifique o(s) Pantone(s).');
    }
}
class VDProductConfigurator {
    private $db;
    private $user;
    private $workflow;
    private $submitting=false;
    private $native;
    public $schema;
    function __construct(mysqli $db,array $user) {
        $this->db=$db;$this->user=$user;$this->workflow=new VDQuoteWorkflow($db,$user);$this->schema=vd_config_schema($db);
        $this->native=new VDConfigNativeStore($db,$this->schema);
    }
    function state($id,$action='view') {
        $native=$this->workflow->access($this->schema['config.entity'],$id,$action);
        $payload=$this->native->payload($native);
        return ['id'=>(int)$id,'name'=>$native['field_'.$this->schema['config.name']],
            'customer'=>$native['field_'.$this->schema['config.customer']],'reference'=>$native['field_'.$this->schema['config.reference']],
            'notes'=>$native['field_'.$this->schema['config.notes']],
            'customer_name'=>!empty($native['field_'.$this->schema['config.customer']])?$this->workflow->access(26,(int)$native['field_'.$this->schema['config.customer']])['field_221']:'',
            'revision'=>(int)($native[$this->native->field('config','revision')]??0),'payload'=>$payload,
            'attachments'=>$this->native->attachments($id),
            'conversion'=>empty($this->schema['config.status'])?null:[
                'status'=>(int)$native[$this->native->field('config','status')],
                'quote'=>(int)$native[$this->native->field('config','quote')],
                'error'=>(string)$native[$this->native->field('config','conversion_error')]
            ],
            'quotes'=>$this->native->quotes($id)];
    }
    function access() {
        if(!users::has_access_to_entity($this->schema['config.entity'],'view'))throw new DomainException('Sem acesso ao Configurador de Produto.');
    }
    function internalCustomer() {
        $this->access();
        $id=$this->schema['config.internal_customer']??0;
        if(!$id)throw new DomainException('Cliente Interno ainda não configurado.');
        $row=$this->workflow->access(26,$id);
        if(!in_array('176',explode(',',(string)$row['field_224']),true))throw new DomainException('A entidade Interno não é do tipo Cliente.');
        return ['id'=>$id,'name'=>$row['field_221']];
    }
    function customers($term) {
        $this->access();
        if(!users::has_access_to_entity(26,'view'))throw new DomainException('Sem acesso a clientes.');
        $term=trim((string)$term);
        if(strlen($term)<2||strlen($term)>100)throw new DomainException('Pesquise o cliente com entre 2 e 100 caracteres.');
        $rows=$this->workflow->rows("SELECT id,field_221 name FROM app_entity_26 WHERE FIND_IN_SET('176',field_224)>0 AND field_221 LIKE ? ORDER BY field_221,id LIMIT 30",['%'.$term.'%']);
        return array_values(array_filter($rows,function($row){try{$this->workflow->access(26,$row['id']);return true;}catch(DomainException $error){return false;}}));
    }
    function recent() {
        $this->access();$entity=$this->schema['config.entity'];$name=$this->schema['config.name'];$reference=$this->schema['config.reference'];
        $revision=$this->native->field('config','revision');$updated=$this->native->field('config','updated_at');
        $rows=$this->workflow->rows("SELECT id,field_$name name,field_$reference reference,$revision revision,$updated updated_at FROM app_entity_$entity WHERE $revision+0>0 ORDER BY $updated+0 DESC,id DESC LIMIT 50");
        return array_values(array_filter($rows,function($row){try{$this->workflow->access($this->schema['config.entity'],$row['id']);return true;}catch(DomainException $error){return false;}}));
    }
    function contacts($customer,$term='') {
        $this->access();
        $client=$this->workflow->access(26,(int)$customer);
        if(!in_array('176',explode(',',(string)$client['field_224']),true))throw new DomainException('A entidade escolhida não é do tipo Cliente.');
        if(!users::has_access_to_entity(90,'view'))throw new DomainException('Sem acesso aos contactos do cliente.');
        $term=trim((string)$term);
        if(strlen($term)>100)throw new DomainException('Pesquise o contacto com até 100 caracteres.');
        $rows=$this->workflow->rows("SELECT id,field_918 name,field_921 email,field_919 phone,field_920 mobile FROM app_entity_90 WHERE parent_item_id=? AND (field_918 LIKE ? OR field_921 LIKE ? OR field_919 LIKE ? OR field_920 LIKE ?) ORDER BY field_918,id LIMIT 30",
            [(int)$customer,'%'.$term.'%','%'.$term.'%','%'.$term.'%','%'.$term.'%']);
        $rows=array_values(array_filter($rows,function($row){try{$this->workflow->access(90,$row['id']);return true;}catch(DomainException $error){return false;}}));
        foreach($rows as &$row)$row['text']=implode(' — ',array_filter([$row['name'],$row['email'],$row['mobile']?:$row['phone']],static function($value){return trim((string)$value)!=='';}))?:'Contacto #'.$row['id'];
        unset($row);
        return $rows;
    }
    function finishingOperations() {
        $this->access();
        if(!users::has_access_to_entity(36,'view'))throw new DomainException('Sem acesso às operações de acabamento.');
        $extra=$this->workflow->rows("SELECT id FROM app_fields_choices WHERE fields_id=6292 AND name='Extra'");
        if(count($extra)!==1)throw new RuntimeException('A classificação Extra não está definida de forma única.');
        $productField=$this->operationProductField();
        $stageField=$this->schema['operation.stage']??0;
        $stageSelect=$stageField?"field_$stageField":"''";
        $rows=$this->workflow->rows("SELECT id,COALESCE(NULLIF(TRIM(field_6293),''),field_1982) name,field_6128 base,$stageSelect stage,field_$productField product_types FROM app_entity_36 WHERE field_6129='true' AND (field_6292=? OR id IN(18,21,33,35)".($stageField?" OR $stageSelect IN('Assemblagem','Embalamento individual')":"").") ORDER BY name,id",[$extra[0]['id']]);
        foreach($rows as &$row) {
            $row['product_types']=$this->operationProducts($row['product_types']);
            $code=vd_product_stage_code($row['stage']);
            if($stageField&&!$code)throw new DomainException('Indique uma etapa válida na operação #'.$row['id'].'.');
            $row['stage']=$code?:'component';
        }
        unset($row);
        return array_values(array_filter($rows,function($row){try{$this->workflow->access(36,$row['id']);return true;}catch(DomainException $error){return false;}}));
    }
    private function operationProductField() {
        $fields=$this->workflow->rows("SELECT id,configuration FROM app_fields WHERE entities_id=36 AND type='fieldtype_dropdown_multiple'");
        $matching=[];
        foreach($fields as $field)if((int)(json_decode($field['configuration'],true,512,JSON_THROW_ON_ERROR)['use_global_list']??0)===24)$matching[]=(int)$field['id'];
        if(count($matching)!==1)throw new RuntimeException('Defina um único campo múltiplo Tipo de produto aplicável, da lista global 24, nas operações.');
        return $matching[0];
    }
    private function operationProducts($value) {
        if(trim((string)$value)==='')return [];
        $ids=explode(',',$value);
        foreach($ids as $id)if(!ctype_digit(trim($id))||(int)$id<=0)throw new RuntimeException('Associação de tipo de produto inválida numa operação.');
        return array_values(array_unique(array_map('intval',$ids)));
    }
    function finishingRules() {
        $rules=vd_config_finishing_rules($this->schema['config.die_operation']??0,$this->schema['config.enveloping_operation']??0);
        $rules['crease']=[18];
        if(!empty($this->schema['operation.machine_crease']))$rules['crease'][]=$this->schema['operation.machine_crease'];
        $rules['lamination_available']=false;
        $rules['lamination_products']=[];
        $field=$this->operationProductField();
        foreach($this->workflow->rows("SELECT id,field_$field product_types FROM app_entity_36 WHERE id IN(13,14) AND field_6129='true'") as $row) {
            try{$this->workflow->access(36,$row['id']);$rules['lamination_available']=true;}catch(DomainException $error){continue;}
            $rules['lamination_products']=array_values(array_unique(array_merge($rules['lamination_products'],$this->operationProducts($row['product_types']))));
        }
        return $rules;
    }
    function finishingReview(array $payload,$existingId=0) {
        $operations=$this->finishingOperations();
        $rules=$this->finishingRules();
        if($existingId) {
            $existing=$this->state((int)$existingId)['payload'];
            $selected=$existing['request_details']['finishing_operations']??[];
            $selected=array_merge($selected,$existing['request_details']['packaging_operations']??[]);
            foreach($existing['components']??[] as $component)$selected=array_merge($selected,$component['request']['finishing_operations']??[]);
            foreach(array_unique(array_column($selected,'id')) as $operationId) {
                $index=array_search((int)$operationId,array_map('intval',array_column($operations,'id')),true);
                if($index!==false){$operations[$index]['legacy_selected']=true;continue;}
                $productField=$this->operationProductField();
                $rows=$this->workflow->rows("SELECT id,COALESCE(NULLIF(TRIM(field_6293),''),field_1982) name,field_$productField product_types FROM app_entity_36 WHERE id=? AND field_6129='true'",[(int)$operationId]);
                if(!$rows)continue;
                try{$this->workflow->access(36,$operationId);}catch(DomainException $error){continue;}
                $rows[0]['is_extra']=false;$rows[0]['legacy_selected']=true;
                $rows[0]['product_types']=$this->operationProducts($rows[0]['product_types']);$operations[]=$rows[0];
            }
            foreach($existing['components']??[] as $component) {
                if(!empty($component['request']['lamination']))$rules['legacy_lamination'][]=(int)$component['id'];
                if(($component['request']['die']['status']??'none')!=='none')$rules['legacy_die'][]=(int)$component['id'];
            }
        }
        return vd_config_finishing_review($payload,
            $this->workflow->rows('SELECT id,parent_id FROM app_global_lists_choices WHERE lists_id=24 AND is_active=1'),
            $operations,
            $this->workflow->rows('SELECT id,fields_id,value FROM app_fields_choices WHERE fields_id IN(6267,6268)'),
            $rules);
    }
    function document($id,$attachmentId) {
        $this->access();$this->workflow->access($this->schema['config.entity'],$id);
        return $this->native->document($id,$attachmentId);
    }
    function submit($id,$expected,array $identity,array $payload,$request,array $uploads=[],array $removed=[]) {
        $this->access();$id=(int)$id;
        if(isset($payload['markup'])||isset($payload['selections']))throw new DomainException('O configurador recolhe requisitos; cálculos e preços pertencem ao orçamento.');
        $productType=(int)($payload['product_type']??0);
        if(!$productType||in_array($productType,[3822,3823],true))throw new DomainException('Escolha o tipo de produto.');
        $payload=vd_config_prepare_book($payload,$this->catalog()['product_types']);
        $payload=vd_config_prepare_packaging($payload,$this->schema);
        $payload=vd_config_prepare_requirements($payload,$this->catalog()['product_types'],$this->schema);
        $payload=vd_config_prepare_stages($payload,$this->schema);
        $other=$payload['request_details']['other_product']??'';
        if(!is_string($other)||strlen($other)>500)throw new DomainException('A especificação do produto deve ter até 500 caracteres.');
        if($productType===$this->schema['config.other_type']&&trim($other)==='')throw new DomainException('Escolheu Outro: preencha o campo Especifique.');
        foreach(['delivery'=>10,'contact'=>500,'finishing'=>2000] as $key=>$limit) {
            $value=$payload['request_details'][$key]??'';
            if(!is_string($value)||strlen($value)>$limit)throw new DomainException('Dados do pedido inválidos: '.$key.'.');
        }
        $delivery=$payload['request_details']['delivery']??'';
        $review=$this->finishingReview($payload,$id);
        if($review['errors'])throw new DomainException(implode("\n",$review['errors']));
        vd_config_request_validate($payload);
        if($delivery!=='') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$delivery);
            if(!$date||$date->format('Y-m-d')!==$delivery)throw new DomainException('Data de entrega pretendida inválida.');
        }
        if(!preg_match('/^[a-zA-Z0-9-]{20,80}$/',(string)$request))throw new DomainException('Identificador de submissão inválido.');
        foreach(['name'=>200,'reference'=>200,'notes'=>20000] as $key=>$limit) {
            if(!is_string($identity[$key]??null)||strlen($identity[$key])>$limit)throw new DomainException('Identificação do pedido inválida.');
        }
        if(trim($identity['name'])==='')throw new DomainException('Preencha o nome do produto / pedido.');
        $customer=(int)($identity['customer']??0);
        if(!$customer)throw new DomainException('Escolha um cliente da tabela de entidades.');
        $customerRow=$this->workflow->access(26,$customer);
        if(!in_array('176',explode(',',(string)$customerRow['field_224']),true))throw new DomainException('A entidade escolhida não é do tipo Cliente.');
        $contact=$payload['request_details']['contact_id']??0;
        if(!is_int($contact)||$contact<0)throw new DomainException('Contacto inválido.');
        if($contact) {
            $contactRow=$this->workflow->access(90,$contact);
            if((int)$contactRow['parent_item_id']!==$customer)throw new DomainException('O contacto escolhido não pertence ao cliente.');
        }
        if(count($uploads)>10||count($removed)>10)throw new DomainException('Pode anexar até 10 documentos.');
        $documents=[];
        foreach($uploads as $file)$documents[]=vd_config_document($file['name'],$file['content']);
        foreach($removed as $attachmentId)if(!is_int($attachmentId)||$attachmentId<1)throw new DomainException('Documento a remover inválido.');
        $fileHashes=array_map(static function($file){return [$file['name'],$file['hash']];},$documents);
        $hash=hash('sha256',json_encode([$id,$expected,$identity,$payload,$fileHashes,$removed],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $actor=(int)$this->user['id'];$entity=$this->schema['config.entity'];
        if(!empty($this->schema['config.storage_locked']))throw new DomainException('Configurador em manutenção. Aguarde e tente novamente.');
        $lock='vd_cfg_submit_'.substr(hash('sha256',$request),0,48);
        $s=$this->db->prepare('SELECT GET_LOCK(?,10)');$s->bind_param('s',$lock);$s->execute();
        if((int)$s->get_result()->fetch_row()[0]!==1)throw new DomainException('Submissão em curso. Aguarde e tente novamente.');
        $this->db->begin_transaction();$this->submitting=true;
        try {
            $old=$this->native->submissions($request)[0]??null;
            if($old) {
                if((int)$old['actor_id']!==$actor||!hash_equals($old['hash'],$hash))throw new DomainException('Submissão já utilizada com outros dados.');
                $state=$this->state((int)$old['config_id']);$this->db->commit();return $state;
            }
            if($id) {
                $this->workflow->access($entity,$id,'update');
                $this->db->query("SELECT id FROM app_entity_$entity WHERE id=$id FOR UPDATE");
                if($this->state($id)['revision']!==(int)$expected)throw new DomainException('Pedido alterado por outro utilizador. Reabra-o antes de submeter.');
            }else{
                if((int)$expected!==0)throw new DomainException('Revisão de novo pedido inválida.');
                if(!users::has_access_to_entity($entity,'create'))throw new DomainException('Sem acesso para submeter novos pedidos.');
                $id=vd_config_insert($this->db,$entity,['created_by'=>$actor,'date_added'=>time()]);
                if(!empty($this->schema['config.status']))$this->native->update('config',$id,['status'=>$this->schema['conversion.preparing']]);
            }
            $values=['field_'.$this->schema['config.name']=>trim($identity['name']),'field_'.$this->schema['config.customer']=>$customer?:null,
                'field_'.$this->schema['config.reference']=>$identity['reference'],'field_'.$this->schema['config.notes']=>$identity['notes']];
            $s=$this->db->prepare("UPDATE app_entity_$entity SET ".implode(',',array_map(static function($column){return "$column=?";},array_keys($values))).' WHERE id=?');
            $v=array_merge(array_values($values),[$id]);$s->bind_param('ssssi',...$v);$s->execute();
            $state=$this->save($id,$expected,$payload);
            foreach(array_unique($removed) as $attachmentId) {
                $this->native->removeDocument($id,$attachmentId);
            }
            $attachments=$this->native->attachments($id);
            $totals=['count'=>count($attachments),'bytes'=>array_sum(array_column($attachments,'size'))];
            if((int)$totals['count']+count($documents)>10||(int)$totals['bytes']+array_sum(array_column($documents,'size'))>25*1024*1024)throw new DomainException('Limite por pedido: 10 documentos, até 25 MB no total.');
            foreach($documents as $file) {
                $this->native->addDocument($id,$file,$actor);
            }
            $state=$this->state($id);
            $this->native->insert('submission',$id,['request'=>$request,'hash'=>$hash],$actor);
            $this->db->commit();$this->native->finishFiles(true);
        }catch(Throwable $error){$this->db->rollback();$this->native->finishFiles(false);throw $error;}
        finally{
            $this->submitting=false;
            $s=$this->db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();
            $s->get_result()->fetch_row();$s->close();
        }
        if(!empty($this->schema['conversion.automatic_process'])) {
            $record=$this->workflow->access($entity,$id);
            if((int)$record[$this->native->field('config','status')]===$this->schema['conversion.ready']) {
                if(!class_exists('processes'))throw new RuntimeException('Pedido guardado, mas o motor de automatismos nativos não está disponível para criar o orçamento.');
                $processes=new processes($entity);
                $processes->run_after_update($id);
            }
        }
        return $this->state($id);
    }
    function save($id,$expected,array $payload) {
        $this->state($id,'update');
        vd_config_validate($payload,$this->submitting);
        $typeId=(int)($payload['product_type']??0);
        if(!$this->workflow->rows('SELECT id FROM app_global_lists_choices WHERE id=? AND lists_id=24 AND is_active=1',[$typeId]))throw new DomainException('Selecione um tipo de produto ativo da lista global 24.');
        // Technical edits invalidate selections; clients must select a fresh calculation for changed inputs.
        foreach($payload['selections']??[] as $componentId=>$selections)foreach($selections as $quantity=>$selection) {
            $component=$this->findComponent($payload,(int)$componentId);
            if(!in_array((int)$quantity,$payload['quantities'],true))throw new DomainException('Cálculo para uma quantidade inexistente.');
            if(($selection['technical_hash']??'')!==$this->technicalHash($payload,$component))throw new DomainException('Recalcule o componente alterado antes de guardar as escolhas.');
            vd_workflow_validate_calculation($selection['calculation'],true);
            if((float)$selection['calculation']['quantity']!==(float)$quantity)throw new DomainException('Quantidade do cálculo incoerente.');
        }
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $hash=hash('sha256',$json);$now=time();$actor=(int)$this->user['id'];
        if(!$this->submitting)$this->db->begin_transaction();
        try {
            $entity=$this->schema['config.entity'];
            $this->db->query("SELECT id FROM app_entity_$entity WHERE id=".(int)$id." FOR UPDATE");
            $current=$this->state($id,'update');
            if($current['revision']!==(int)$expected)throw new DomainException('Configuração alterada por outro utilizador. Atualize antes de guardar.');
            $this->native->save($id,$payload,(int)$expected+1,$actor,$now);
            $typeField=$this->schema['config.product_type'];
            $choice=$this->workflow->rows('SELECT parent_id FROM app_global_lists_choices WHERE id=? AND lists_id=24',[$typeId])[0];
            $typeValue=((int)$choice['parent_id']>0?$choice['parent_id'].',':'').$typeId;
            $s=$this->db->prepare("UPDATE app_entity_$entity SET field_$typeField=?,date_updated=? WHERE id=?");
            $s->bind_param('sii',$typeValue,$now,$id);$s->execute();
            if(!$this->submitting)$this->db->commit();
        }catch(Throwable $error){if(!$this->submitting)$this->db->rollback();throw $error;}
        return $this->state($id);
    }
    function findComponent(array $payload,$id) {
        foreach($payload['components'] as $component)if((int)$component['id']===$id)return $component;
        throw new DomainException('Componente inexistente.');
    }
    function technicalHash(array $payload,array $component) {
        return hash('sha256',json_encode(['type'=>$payload['type'],'product_type'=>$payload['product_type'],'markup'=>$payload['markup'],'components'=>$payload['components'],'component'=>$component],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }
    function catalog($id=0) {
        if($id)$this->state($id);else $this->access();
        foreach([36,78,99] as $entity)if(!users::has_access_to_entity($entity,'view'))throw new DomainException('Sem acesso aos catálogos necessários à configuração.');
        return ['types'=>vd_product_types(),
            'rubric_types'=>vd_config_rubric_types(),
            'product_rules'=>vd_config_product_rules($this->schema),
            'product_types'=>$this->workflow->rows('SELECT id,parent_id,name,notes FROM app_global_lists_choices WHERE lists_id=24 AND is_active=1 ORDER BY sort_order,id'),
            'finishing_rules'=>$this->finishingRules(),
            'finishing_operations'=>$this->finishingOperations(),
            'operations'=>$this->workflow->rows("SELECT id,field_1982 name,field_1983 machines,field_6131 unit FROM app_entity_36 WHERE field_6129='true' ORDER BY id"),
            'articles'=>$this->workflow->rows("SELECT id,field_1020 name,field_3632 internal_id,field_1374 unit,FIND_IN_SET('190',field_1019)>0 is_paper FROM app_entity_99 ORDER BY field_1020"),
            'choices'=>$this->workflow->rows('SELECT fields_id,id,name,value FROM app_fields_choices WHERE fields_id IN(4220,4229,6110,6267,6268) ORDER BY fields_id,sort_order,id'),
            'colors'=>$this->workflow->rows('SELECT id,name FROM app_global_lists_choices WHERE id IN(3728,3729,3730,3731,3732,3742,3804,3820,3821) ORDER BY id'),
            'templates'=>$this->workflow->rows("SELECT id,field_6200 name,field_6203 product_type FROM app_entity_389 WHERE field_6201 IN('1','true') ORDER BY id")];
    }
    function quotePreparationCatalog() {
        require_once __DIR__.'/product-quote-preparation.php';
        return vd_config_quote_preparation_catalog($this->db,$this);
    }
    function nativeComponent(array $component,$type) {
        $native=['id'=>$component['id'],'parent_item_id'=>1,'field_4225'=>$component['name']];
        foreach([4218,4219,4220,4229,4233,6110,6119,6120,6121,6169,6170,6172,6173,6174,6175,6267,6268,6291,4221,4242] as $field)
            $native['field_'.$field]=$component['fields'][(string)$field]??([4220=>1565,4229=>1255,6110=>1544,6119=>3,6120=>0,6169=>'true'][$field]??'');
        foreach(['lamination_front'=>6267,'lamination_back'=>6268] as $key=>$field) {
            $choices=$this->workflow->rows('SELECT value FROM app_fields_choices WHERE fields_id=? AND id=?',[$field,$native['field_'.$field]]);
            $native[$key]=$choices[0]['value']??'none';
        }
        if($type!=='standard')$native['product_kind']=$component['kind'];
        return $native;
    }
    private function derived($entity,array $rows) {
        $columns=$this->workflow->rows("SHOW COLUMNS FROM app_entity_$entity");
        $selects=[];
        foreach($rows as $row) {
            $parts=[];
            foreach($columns as $column) {
                $key=$column['Field'];
                $value=$row[$key]??$column['Default']??($column['Null']==='YES'?null:(preg_match('/^(int|bigint|decimal|float|double|tinyint)/',$column['Type'])?0:''));
                $parts[]=($value===null?'NULL':"'".$this->db->real_escape_string((string)$value)."'")." AS `$key`";
            }
            $selects[]='SELECT '.implode(',',$parts);
        }
        if(!$selects) {
            $selects[]='SELECT '.implode(',',array_map(static function($column){return "NULL AS `".$column['Field']."`";},$columns)).' WHERE 1=0';
        }
        return '('.implode(' UNION ALL ',$selects).')';
    }
    function simulation($id,$componentId) {
        $state=$this->state($id);$payload=$state['payload'];
        if(!$payload)throw new DomainException('Guarde a configuração primeiro.');
        $component=$this->findComponent($payload,$componentId);
        if($component['kind']!=='printed')throw new DomainException('Componente sem impressão.');
        $virtual=[146=>[['id'=>1,'field_6271'=>$payload['markup']]],262=>[],261=>[],277=>[],278=>[],280=>[],276=>[],388=>[]];
        foreach($payload['components'] as $c)$virtual[262][]=$this->nativeComponent($c,$payload['type']);
        foreach($payload['quantities'] as $quantity)$virtual[261][]=['id'=>$quantity,'parent_item_id'=>1,'field_3938'=>$quantity];
        foreach($component['papers']??[] as $index=>$article)$virtual[277][]=['id'=>$index+1,'parent_item_id'=>$componentId,'field_4278'=>$article];
        foreach($component['colors']??[] as $index=>$color)$virtual[278][]=['id'=>$index+1,'parent_item_id'=>$componentId,'field_4251'=>$color];
        foreach($component['rubrics']??[] as $index=>$rubric)$virtual[280][]=['id'=>$index+1,'parent_item_id'=>$componentId,'field_4320'=>$rubric['quantity'],'field_4321'=>$rubric['name'],'field_4322'=>$rubric['code']??''];
        $tables=[];$ctes=[];
        foreach($virtual as $entity=>$rows) {
            $tables['app_entity_'.$entity]='vd_config_'.$entity;
            $ctes[]='vd_config_'.$entity.' AS '.$this->derived($entity,$rows);
        }
        $queries=vd_config_costing_queries($componentId);$result=[];
        foreach($queries as $name=>$query) {
            if(in_array($name,['workflow_component','quote_selections','selections'],true))continue;
            if(!preg_match('/^SELECT\b/i',$query))throw new RuntimeException('Non-read-only costing query.');
            $query=preg_replace_callback('/\bapp_entity_(146|262|261|277|278|280|276|388)\b/',static function($match)use($tables){return $tables[$match[0]];},$query);
            $result[$name]=$this->workflow->rows('WITH '.implode(',',$ctes).' '.$query);
        }
        $native=$this->nativeComponent($component,$payload['type']);
        $result['workflow_component']=[$native];
        $result['costing_operations']=vd_workflow_discover_operations($native,[],$result['costing_catalog'],$result['workflow_rules']);
        $result['workflow_versions']=[];$result['quote_selections']=[];$result['selections']=[];
        $result['component'][0]['bindingName']='Encadernação do produto';
        $materials=[];
        foreach($component['papers']??[] as $articleId) {
            $article=$this->workflow->access(99,$articleId);
            $materials[]=['id'=>$article['field_3632'],'materialId'=>$articleId,'name'=>$article['field_1020'],'paper'=>true,'quantity'=>0];
        }
        return ['data'=>$result,'materials'=>$materials,'technical_hash'=>$this->technicalHash($payload,$component)];
    }
    function select($id,$expected,$componentId,$quantity,array $calculation) {
        $state=$this->state($id,'update');$payload=$state['payload'];
        if($state['revision']!==(int)$expected)throw new DomainException('Configuração alterada. Atualize.');
        $component=$this->findComponent($payload,$componentId);
        if(!in_array((int)$quantity,$payload['quantities'],true)||(float)($calculation['quantity']??0)!==(float)$quantity)throw new DomainException('Quantidade inválida.');
        vd_workflow_validate_calculation($calculation,true);
        vd_workflow_production_rows($calculation,1,$componentId,(int)$quantity,true);
        $payload['selections'][(string)$componentId][(string)$quantity]=['technical_hash'=>$this->technicalHash($payload,$component),'calculation'=>$calculation];
        return $this->save($id,$expected,$payload);
    }
    function nonprint($id,$expected,$componentId,$quantity,array $definition) {
        $state=$this->state($id,'update');
        $component=$this->findComponent($state['payload'],$componentId);
        $definition['quantity']=$quantity;
        $definition['markup']=$state['payload']['markup'];
        $metadata=['type'=>$state['payload']['type'],'kind'=>$component['kind'],'inputs'=>$component['inputs']??[],
            'units_per_product'=>(float)($component['units_per_product']??1)];
        foreach($definition['operations']??[] as $operation)$this->workflow->access(36,(int)$operation['operation_id']);
        foreach($definition['materials']??[] as $material)$this->workflow->access(99,(int)$material['article_id']);
        $calculation=vd_product_nonprint_calculation($definition,$metadata,
            array_column($this->workflow->rows("SELECT id,field_1982 name,field_1983 machines FROM app_entity_36 WHERE field_6129='true'"),null,'id'),
            array_column($this->workflow->rows('SELECT id,field_1020 name,field_3632 internal_id FROM app_entity_99'),null,'id'),
            array_column($this->workflow->rows('SELECT id FROM app_entity_78'),null,'id'));
        return $this->select($id,$expected,$componentId,$quantity,$calculation);
    }
    function generate($id,$expected) {
        $this->state($id,'update');
        $lock='vd_product_config_'.$id;
        $s=$this->db->prepare('SELECT GET_LOCK(?,5)');$s->bind_param('s',$lock);$s->execute();
        if((int)$s->get_result()->fetch_row()[0]!==1)throw new DomainException('Configuração em atualização.');
        $this->db->begin_transaction();
        try {
            $entity=$this->schema['config.entity'];
            $this->db->query("SELECT id FROM app_entity_$entity WHERE id=".(int)$id." FOR UPDATE");
            $state=$this->state($id,'update');
            if($state['revision']!==(int)$expected)throw new DomainException('Configuração alterada. Atualize.');
            $existing=array_values(array_filter($this->native->quotes($id),static function($row)use($expected){return (int)$row['revision']===(int)$expected;}));
            if($existing) {
                $this->workflow->access(146,$existing[0]['quote_id']);
                $this->db->commit();return ['quote'=>(int)$existing[0]['quote_id'],'already_generated'=>true];
            }
            $payload=$state['payload'];
            if(!$payload)throw new DomainException('Guarde a configuração.');
            vd_config_validate($payload);
            foreach($payload['components'] as $component)foreach($payload['quantities'] as $quantity) {
                $selection=$payload['selections'][$component['id']][$quantity]??null;
                if(!$selection||$selection['technical_hash']!==$this->technicalHash($payload,$component))throw new DomainException('Selecione um cálculo atualizado para todos os componentes e quantidades.');
                vd_workflow_validate_calculation($selection['calculation']);
                vd_workflow_production_rows($selection['calculation'],1,$component['id'],$quantity);
            }
            $quote=$this->workflow->importProductConfiguration($state,$this->schema);
            $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
            $hash=hash('sha256',$json);$now=time();
            $this->native->saveSource($quote,$id,$expected,$json,$hash,$now);
            $this->db->commit();
            return ['quote'=>$quote,'already_generated'=>false];
        }catch(Throwable $error){$this->db->rollback();throw $error;}
        finally{$s=$this->db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();}
    }
    function template($id,$templateId) {
        if($id)$this->state($id,'update');else $this->access();
        $template=$this->workflow->access(389,$templateId);
        if(!in_array((string)$template['field_6201'],['1','true'],true))throw new DomainException('Modelo inativo.');
        $components=[];
        $mapping=[6207=>4218,6208=>4219,6209=>4233,6212=>6119,6213=>6120,6246=>6121,6247=>6169,6248=>6170,6249=>6172,6250=>6173,6251=>6174,6252=>6175];
        foreach($this->workflow->rows('SELECT * FROM app_entity_390 WHERE parent_item_id=? ORDER BY field_6206+0,id',[$templateId]) as $native) {
            $this->workflow->access(390,$native['id']);
            $component=['id'=>count($components)+1,'name'=>$native['field_6205'],'kind'=>'printed','inputs'=>[],'units_per_product'=>1,'fields'=>[],'papers'=>[],'colors'=>[],'rubrics'=>[]];
            foreach($mapping as $from=>$to)$component['fields'][(string)$to]=$native['field_'.$from];
            foreach([6210=>4229,6211=>6110,6243=>4220] as $from=>$to) {
                $choice=$this->workflow->rows('SELECT name FROM app_fields_choices WHERE fields_id=? AND id=?',[$from,$native['field_'.$from]]);
                if($choice){$target=$this->workflow->rows('SELECT id FROM app_fields_choices WHERE fields_id=? AND name=?',[$to,$choice[0]['name']]);if(count($target)===1)$component['fields'][(string)$to]=(int)$target[0]['id'];}
            }
            foreach($this->workflow->rows('SELECT * FROM app_entity_391 WHERE parent_item_id=? ORDER BY field_6217+0,id',[$native['id']]) as $operation) {
                $this->workflow->access(391,$operation['id']);
                $component['fields']['6291']=($component['fields']['6291']??'').(($component['fields']['6291']??'')!==''?',':'').$operation['field_6216'];
            }
            foreach($this->workflow->rows('SELECT * FROM app_entity_392 WHERE parent_item_id=? ORDER BY id',[$native['id']]) as $color) {
                $this->workflow->access(392,$color['id']);$component['colors'][]=(int)$color['field_6233'];
            }
            foreach($this->workflow->rows('SELECT * FROM app_entity_393 WHERE parent_item_id=? ORDER BY id',[$native['id']]) as $material) {
                $this->workflow->access(393,$material['id']);if((int)$material['field_6241']>0)$component['papers'][]=(int)$material['field_6241'];
            }
            $components[]=$component;
        }
        return ['components'=>$components,'product_type'=>(int)array_slice(explode(',',$template['field_6203']),-1)[0],'template'=>$templateId];
    }
}

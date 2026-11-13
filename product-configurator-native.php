<?php
function vd_config_native_specs() {
    return [
        'config'=>[
            'structure'=>'type','other_product'=>'request_details/other_product',
            'delivery'=>'request_details/delivery','contact'=>'request_details/contact',
            'contact_id'=>'request_details/contact_id','finishing'=>'request_details/finishing',
            'dispatch'=>'request_details/dispatch','book_start'=>'request_details/document_book/start',
            'book_sets'=>'request_details/document_book/sets_per_book','book_copies'=>'request_details/document_book/copies',
            'book_colors'=>'request_details/document_book/paper_colors','book_binding'=>'request_details/document_book/binding',
            'book_unit'=>'request_details/document_book/unit','box_length'=>'request_details/box/length_mm',
            'box_width'=>'request_details/box/width_mm','box_height'=>'request_details/box/height_mm',
            'box_measurement'=>'request_details/box/measurement'
            ,'final_width'=>'request_details/final_format/width_mm','final_height'=>'request_details/final_format/height_mm',
            'stage_version'=>'request_details/workflow_version'
            ,'final_thickness'=>'request_details/final_format/thickness_mm'
            ,'total_pages'=>'request_details/total_pages','box_model'=>'request_details/box/model',
            'box_description'=>'request_details/box/model_description'
        ],
        'part'=>[
            'local_id'=>'id','name'=>'name','kind'=>'kind','units'=>'units_per_product','inputs'=>'inputs',
            'width'=>'fields/4218','height'=>'fields/4219','pages'=>'fields/4233','faces'=>'fields/4229',
            'lamination_front'=>'fields/6267','lamination_back'=>'fields/6268','colors'=>'colors',
            'paper'=>'request/paper','description'=>'request/description','printing'=>'request/printing/mode',
            'pantone'=>'request/printing/pantone','lamination'=>'request/lamination',
            'die_status'=>'request/die/status','die_number'=>'request/die/number',
            'corrugated_thickness'=>'request/corrugated/thickness_mm'
            ,'role'=>'role','stage'=>'stage','flap_left'=>'request/cover/left_mm','flap_right'=>'request/cover/right_mm','spine'=>'request/cover/spine_mm'
        ],
        'finish'=>['operation'=>'id','name'=>'name','thread_type'=>'thread/type','thread_color'=>'thread/color','thread_length'=>'thread/length_mm',
            'tape_quantity'=>'tape/quantity','tape_x'=>'tape/x_mm','tape_y'=>'tape/y_mm']
    ];
}
function vd_config_native_json($value) {
    return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
}
function vd_config_native_path(array $data,$path,&$found) {
    $value=$data;$found=true;
    foreach(explode('/',$path) as $key) {
        if(!is_array($value)||!array_key_exists($key,$value)){$found=false;return null;}
        $value=$value[$key];
    }
    return $value;
}
function vd_config_native_set(array &$data,$path,$value) {
    $target=&$data;$keys=explode('/',$path);$last=array_pop($keys);
    foreach($keys as $key) {
        if(!isset($target[$key])||!is_array($target[$key]))$target[$key]=[];
        $target=&$target[$key];
    }
    $target[$last]=$value;
}
function vd_config_native_pack(array $data,array $spec,array $labels=[]) {
    $values=[];$types=[];
    foreach($spec as $field=>$path) {
        $value=vd_config_native_path($data,$path,$found);
        $display=static function($item)use($labels,$field){
            $key=is_bool($item)?($item?'true':'false'):(string)$item;
            return $labels[$field][$key]??$key;
        };
        $values[$field]=$found?(is_array($value)?implode(',',array_map($display,$value)):$display($value)):'';
        if($found) {
            $types[$field]=is_array($value)?['type'=>'array','items'=>array_map('gettype',$value)]:gettype($value);
            vd_config_native_set($data,$path,null);
        }
    }
    $values['technical']=vd_config_native_json(['data'=>$data,'types'=>$types,'labels'=>$labels]);
    return $values;
}
function vd_config_native_unpack(array $row,$prefix,array $schema,array $spec) {
    $technical=$row['field_'.$schema[$prefix.'.technical']]??'';
    if($technical==='')throw new RuntimeException('Registo nativo sem estrutura técnica: '.$prefix.'.');
    $meta=json_decode($technical,true,512,JSON_THROW_ON_ERROR);$data=$meta['data'];
    foreach($spec as $field=>$path) {
        $raw=$row['field_'.$schema[$prefix.'.'.$field]]??'';
        $definition=$meta['types'][$field]??null;
        $type=is_array($definition)?$definition['type']:$definition;
        if($type===null&&$raw==='')continue;
        $labels=array_flip($meta['labels'][$field]??[]);
        if($type!=='array')$raw=$labels[$raw]??$raw;
        switch($type) {
            case 'integer':$value=(int)$raw;break;
            case 'double':$value=(float)$raw;break;
            case 'boolean':$value=in_array($raw,['true','1'],true);break;
            case 'array':
                $value=$raw===''?[]:explode(',',$raw);
                foreach($value as $index=>&$item) {
                    $item=$labels[$item]??$item;
                    $item=($definition['items'][$index]??'string')==='integer'?(int)$item:(string)$item;
                }
                unset($item);
                break;
            case 'NULL':$value=$raw===''?null:$raw;break;
            default:$value=(string)$raw;
        }
        vd_config_native_set($data,$path,$value);
    }
    return $data;
}
class VDConfigNativeStore {
    private $db;
    private $schema;
    private $createdFiles=[];
    private $removedFiles=[];
    private $labels;
    function __construct(mysqli $db,array $schema) {$this->db=$db;$this->schema=$schema;}
    function rows($sql,array $values=[]) {
        $s=$this->db->prepare($sql);
        if($values)$s->bind_param(str_repeat('s',count($values)),...$values);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($rows as &$row)$row=$this->expand($row);
        unset($row);
        return $rows;
    }
    function entity($prefix) {return $this->schema[$prefix.'.entity'];}
    function field($prefix,$name) {return 'field_'.$this->schema[$prefix.'.'.$name];}
    function insert($prefix,$parent,array $values,$actor=0,$created=0) {
        foreach(['technical','snapshot'] as $key)if(isset($values[$key]))$values[$key]=$this->storeText($parent,$values[$key],$actor);
        $row=['parent_item_id'=>$parent,'created_by'=>$actor,'date_added'=>$created?:time(),'date_updated'=>time()];
        foreach($values as $key=>$value)$row[$this->field($prefix,$key)]=$value;
        return vd_config_insert($this->db,$this->entity($prefix),$row);
    }
    function update($prefix,$id,array $values) {
        $old=$this->db->query('SELECT * FROM app_entity_'.$this->entity($prefix).' WHERE id='.(int)$id)->fetch_assoc();
        if(!$old)throw new RuntimeException('Registo nativo inexistente.');
        foreach(['technical','snapshot'] as $key)if(isset($values[$key])) {
            $parent=$prefix==='config'?$id:($prefix==='quote'?(int)$values['config']:(int)$old['parent_item_id']);
            $this->deleteText($old[$this->field($prefix,$key)]??'');
            $values[$key]=$this->storeText($parent,$values[$key],(int)$old['created_by']);
        }
        $fields=[];$args=[];
        foreach($values as $key=>$value){$fields[]=$this->field($prefix,$key).'=?';$args[]=$value;}
        $args[]=$id;
        $s=$this->db->prepare('UPDATE app_entity_'.$this->entity($prefix).' SET '.implode(',',$fields).' WHERE id=?');
        $s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();
    }
    function children($prefix,$parent) {
        return $this->rows('SELECT * FROM app_entity_'.$this->entity($prefix).' WHERE parent_item_id=? ORDER BY sort_order,id',[$parent]);
    }
    function clear($prefix,$parent) {
        foreach($this->db->query('SELECT * FROM app_entity_'.$this->entity($prefix).' WHERE parent_item_id='.(int)$parent)->fetch_all(MYSQLI_ASSOC) as $row)
            foreach(['technical','snapshot'] as $key)if(isset($this->schema[$prefix.'.'.$key]))$this->deleteText($row[$this->field($prefix,$key)]);
        $s=$this->db->prepare('DELETE FROM app_entity_'.$this->entity($prefix).' WHERE parent_item_id=?');
        $s->bind_param('i',$parent);$s->execute();
    }
    function payload(array $native) {
        $native=$this->expand($native);
        $revision=(int)($native[$this->field('config','revision')]??0);
        if(!$revision)return null;
        $spec=vd_config_native_specs();$data=vd_config_native_unpack($native,'config',$this->schema,$spec['config']);
        $data['product_type']=(int)array_slice(explode(',',$native[$this->field('config','product_type')]),-1)[0];
        $id=(int)$native['id'];$finishes=$this->children('finish',$id);
        $byScope=[];
        foreach($finishes as $row)$byScope[$row[$this->field('finish','scope')]][]=vd_config_native_unpack($row,'finish',$this->schema,$spec['finish']);
        if(array_key_exists('quantities',$data)) {
            $data['quantities']=[];
            foreach($this->children('quantity',$id) as $row)$data['quantities'][]=(int)$row[$this->field('quantity','amount')];
        }
        if(array_key_exists('rubrics',$data)) {
            $data['rubrics']=[];
            foreach($this->children('rubric',$id) as $row)$data['rubrics'][]=['quantity'=>(int)$row[$this->field('rubric','quantity')],
                'description'=>(string)$row[$this->field('rubric','description')],'code'=>(string)$row[$this->field('rubric','code')]];
        }
        if(array_key_exists('components',$data)) {
            $data['components']=[];
            foreach($this->children('part',$id) as $row) {
                $part=vd_config_native_unpack($row,'part',$this->schema,$spec['part']);
                vd_config_native_path($part,'request/finishing_operations',$found);
                if($found)$part['request']['finishing_operations']=$byScope['part:'.$part['id']]??[];
                $data['components'][]=$part;
            }
        }
        vd_config_native_path($data,'request_details/finishing_operations',$found);
        if($found)$data['request_details']['finishing_operations']=$byScope['set']??[];
        vd_config_native_path($data,'request_details/packaging_operations',$found);
        if($found)$data['request_details']['packaging_operations']=$byScope['packaging']??[];
        return $data;
    }
    private function storeText($parent,$text,$actor) {
        if(strlen($text)<=60000)return $text;
        $token=bin2hex(random_bytes(16));$hash=hash('sha256',$text);
        $offset=0;$index=0;
        while($offset<strlen($text)) {
            $chunk=mb_strcut($text,$offset,60000,'UTF-8');
            $this->insert('piece',$parent,['token'=>$token,'position'=>$index++,'content'=>$chunk,'hash'=>$hash],$actor);
            $offset+=strlen($chunk);
        }
        return 'VD_NATIVE_TEXT_'.$token;
    }
    private function deleteText($text) {
        if(!preg_match('/^VD_NATIVE_TEXT_([a-f0-9]{32})$/D',(string)$text,$match))return;
        $s=$this->db->prepare('DELETE FROM app_entity_'.$this->entity('piece').' WHERE '.$this->field('piece','token').'=?');
        $s->bind_param('s',$match[1]);$s->execute();
    }
    private function expand(array $row) {
        $keys=['payload'];
        foreach(['config.technical','part.technical','finish.technical','quote.snapshot'] as $role)
            if(isset($this->schema[$role]))$keys[]='field_'.$this->schema[$role];
        foreach($keys as $key) {
            if(!isset($row[$key])||!preg_match('/^VD_NATIVE_TEXT_([a-f0-9]{32})$/D',$row[$key],$match))continue;
            $chunks=$this->rows('SELECT '.$this->field('piece','content').' content,'.$this->field('piece','hash').' hash FROM app_entity_'.$this->entity('piece').' WHERE '.$this->field('piece','token').'=? ORDER BY '.$this->field('piece','position').'+0,id',[$match[1]]);
            $value=implode('',array_column($chunks,'content'));
            if(!$chunks||!hash_equals($chunks[0]['hash'],hash('sha256',$value)))throw new RuntimeException('Dados técnicos nativos incompletos ou inválidos.');
            $row[$key]=$value;
        }
        return $row;
    }
    function save($id,array $payload,$revision,$actor,$updated) {
        $spec=vd_config_native_specs();$root=$payload;
        foreach(['components','quantities','product_type','rubrics'] as $key)if(array_key_exists($key,$root))$root[$key]=null;
        vd_config_native_path($root,'request_details/finishing_operations',$found);
        if($found)$root['request_details']['finishing_operations']=null;
        vd_config_native_path($root,'request_details/packaging_operations',$found);
        if($found)$root['request_details']['packaging_operations']=null;
        $values=vd_config_native_pack($root,$spec['config'],$this->labels()['config']);
        $values+=['revision'=>$revision,'hash'=>hash('sha256',vd_config_native_json($payload)),'updated_by'=>$actor,'updated_at'=>$updated];
        $typeId=(int)$payload['product_type'];
        $type=$this->rows('SELECT parent_id FROM app_global_lists_choices WHERE id=? AND lists_id=24',[$typeId]);
        if(count($type)!==1)throw new RuntimeException('Tipo de produto nativo indisponível.');
        $values['product_type']=((int)$type[0]['parent_id']>0?$type[0]['parent_id'].',':'').$typeId;
        $this->update('config',$id,$values);
        $quantities=[];$parts=[];$finishes=[];
        $ends=isset($payload['request_details']['document_book'])?vd_config_book_numbering($payload['request_details']['document_book'],$payload['quantities']??[]):[];
        foreach($payload['quantities']??[] as $quantity)$quantities[]=['amount'=>$quantity,'number_end'=>$ends[$quantity]??''];
        foreach($payload['components']??[] as $part) {
            $copy=$part;
            vd_config_native_path($copy,'request/finishing_operations',$found);
            if($found)$copy['request']['finishing_operations']=null;
            $parts[]=vd_config_native_pack($copy,$spec['part'],$this->labels()['part']);
            $finishes=array_merge($finishes,$this->finishes('part:'.$part['id'],$part['name'],$part['request']['finishing_operations']??[]));
        }
        $finishes=array_merge($finishes,$this->finishes('set','Conjunto',$payload['request_details']['finishing_operations']??[]));
        $finishes=array_merge($finishes,$this->finishes('packaging','Embalamento individual',$payload['request_details']['packaging_operations']??[]));
        $this->sync('part',$id,$parts,['local_id'],$actor,$updated);
        $this->sync('quantity',$id,$quantities,['amount'],$actor,$updated);
        $this->sync('finish',$id,$finishes,['scope','operation'],$actor,$updated);
        if(isset($this->schema['rubric.entity'])) {
            $rubrics=array_map(static function($rubric){
                return ['quantity'=>$rubric['quantity'],'description'=>$rubric['description'],'code'=>$rubric['code']??''];
            },$payload['rubrics']??[]);
            $this->sync('rubric',$id,$rubrics,['description','code'],$actor,$updated);
        }elseif(!empty($payload['rubrics']))throw new RuntimeException('Subentidade Rubricas do pedido ainda não configurada.');
    }
    private function labels() {
        if($this->labels!==null)return $this->labels;
        $choices=$this->rows('SELECT id,fields_id,name FROM app_fields_choices WHERE fields_id IN(4229,6267,6268)');
        $byField=[];
        foreach($choices as $choice)$byField[$choice['fields_id']][$choice['id']]=$choice['name'];
        $colors=array_column($this->rows('SELECT id,name FROM app_global_lists_choices WHERE id IN(3728,3729,3730,3731,3732,3804,3820,3821,3742)'),'name','id');
        return $this->labels=[
            'config'=>[
                'structure'=>['standard'=>'Produto habitual','magazine'=>'Produto com vários componentes'],
                'dispatch'=>['pickup'=>'Levantar nas instalações','carrier'=>'Envio por Transportador','delivery'=>'Entrega (Sujeito a disponibilidade e confirmação)'],
                'book_binding'=>['perforated'=>'Picotado','glued'=>'Colado'],'book_unit'=>['livro'=>'Livro'],
                'box_measurement'=>['internal'=>'Interiores','external'=>'Exteriores']
            ],
            'part'=>[
                'kind'=>['printed'=>'Impresso','material'=>'Material','assembly'=>'Assemblagem'],
                'printing'=>['cmyk'=>'CMYK','pantone'=>'Pantone','cmyk_pantone'=>'CMYK + Pantone','white'=>'Branco','combined'=>'Combinar cores','none'=>'Sem impressão','black'=>'Preto (histórico)'],
                'die_status'=>['none'=>'Sem cortante','new'=>'Novo','used'=>'Usado'],
                'lamination'=>['true'=>'Sim','false'=>'Não'],
                'faces'=>$byField[4229]??[],'lamination_front'=>$byField[6267]??[],'lamination_back'=>$byField[6268]??[],
                'colors'=>$colors
            ]
        ];
    }
    private function finishes($scope,$label,array $operations) {
        $rows=[];
        foreach($operations as $operation)$rows[]=vd_config_native_pack($operation,vd_config_native_specs()['finish'])+['scope'=>$scope,'component'=>$label];
        return $rows;
    }
    private function sync($prefix,$parent,array $records,array $keys,$actor,$updated) {
        $existing=$this->children($prefix,$parent);$used=[];
        foreach($records as $position=>$values) {
            $match=null;
            foreach($existing as $row) {
                if(in_array((int)$row['id'],$used,true))continue;
                $same=true;
                foreach($keys as $key)if((string)$row[$this->field($prefix,$key)]!==(string)$values[$key])$same=false;
                if($same){$match=(int)$row['id'];break;}
            }
            if($match)$this->update($prefix,$match,$values);
            else $match=$this->insert($prefix,$parent,$values,$actor,$updated);
            $s=$this->db->prepare('UPDATE app_entity_'.$this->entity($prefix).' SET sort_order=?,date_updated=? WHERE id=?');
            $s->bind_param('iii',$position,$updated,$match);$s->execute();$used[]=$match;
        }
        foreach($existing as $row)if(!in_array((int)$row['id'],$used,true)) {
            $raw=$this->db->query('SELECT * FROM app_entity_'.$this->entity($prefix).' WHERE id='.(int)$row['id'])->fetch_assoc();
            if(isset($this->schema[$prefix.'.technical']))$this->deleteText($raw[$this->field($prefix,'technical')]);
            $this->db->query('DELETE FROM app_entity_'.$this->entity($prefix).' WHERE id='.(int)$row['id']);
        }
    }
    function attachments($id) {
        $result=[];
        foreach($this->children('document',$id) as $row)$result[]=[
            'id'=>(int)$row['id'],'name'=>$row[$this->field('document','name')],
            'size'=>(int)$row[$this->field('document','size')],'created_at'=>(int)$row['date_added']
        ];
        return $result;
    }
    function document($id,$document) {
        $rows=$this->rows('SELECT * FROM app_entity_'.$this->entity('document').' WHERE id=? AND parent_item_id=?',[$document,$id]);
        if(!$rows)throw new DomainException('Documento inexistente ou sem acesso.');
        $row=$rows[0];$name=$row[$this->field('document','file')];
        $path=$this->filePath($name);
        $content=file_get_contents($path);
        if($content===false)throw new RuntimeException('Não foi possível ler o documento nativo.');
        $hash=$row[$this->field('document','hash')];
        if(!hash_equals($hash,hash('sha256',$content)))throw new RuntimeException('Integridade do documento inválida.');
        return ['name'=>$row[$this->field('document','name')],'mime'=>$row[$this->field('document','mime')],'hash'=>$hash,'content'=>$content];
    }
    function filePath($name) {
        if(!preg_match('/^([0-9]+)_[^,\\\\\\/]+$/D',$name,$match))throw new RuntimeException('Referência de anexo nativo inválida.');
        return DIR_WS_ATTACHMENTS.date('Y/m/d',(int)$match[1]).'/'.sha1($name);
    }
    function addDocument($id,array $file,$actor,$created=0) {
        $created=$created?:time();
        $name=$this->writeDocument($file,$created);
        return $this->insert('document',$id,['name'=>$file['name'],'file'=>$name,'mime'=>$file['mime'],'hash'=>$file['hash'],'size'=>$file['size']],$actor,$created);
    }
    private function writeDocument(array $file,$created) {
        $created=$created?:time();
        $name=$created.'_'.bin2hex(random_bytes(12)).'_'.str_replace([',',' '],['_','_'],$file['name']);
        $path=$this->filePath($name);$folder=dirname($path);
        if(!is_dir($folder)&&!mkdir($folder,0755,true)&&!is_dir($folder))throw new RuntimeException('Não foi possível criar a pasta de anexos nativos.');
        $handle=fopen($path,'xb');
        if($handle===false)throw new RuntimeException('Não foi possível criar o anexo nativo.');
        $this->createdFiles[]=$path;
        try {
            try {$written=fwrite($handle,$file['content']);}
            finally {fclose($handle);}
            if($written!==strlen($file['content']))throw new RuntimeException('Gravação incompleta do anexo nativo.');
            if(!hash_equals($file['hash'],hash_file('sha256',$path)))throw new RuntimeException('Integridade do anexo nativo inválida.');
            return $name;
        }catch(Throwable $error) {
            if(is_file($path)&&!unlink($path))error_log('VD native configurator: incomplete attachment cleanup failed.');
            throw $error;
        }
    }
    function copyDocumentsToQuote($id,$quote) {
        $names=[];
        foreach($this->attachments($id) as $document) {
            $file=$this->document($id,$document['id']);
            $names[]=$this->writeDocument($file,time());
        }
        $s=$this->db->prepare('UPDATE app_entity_146 SET field_3929=? WHERE id=?');
        $files=implode(',',$names);$s->bind_param('si',$files,$quote);$s->execute();
    }
    function removeDocument($id,$document) {
        $rows=$this->rows('SELECT * FROM app_entity_'.$this->entity('document').' WHERE parent_item_id=? AND id=?',[$id,$document]);
        if(!$rows)throw new DomainException('O documento a remover já não existe neste pedido. Reabra-o antes de guardar.');
        $s=$this->db->prepare('DELETE FROM app_entity_'.$this->entity('document').' WHERE parent_item_id=? AND id=?');
        $s->bind_param('ii',$id,$document);$s->execute();
        $this->removedFiles[]=$this->filePath($rows[0][$this->field('document','file')]);
    }
    function finishFiles($committed) {
        foreach($committed?$this->removedFiles:$this->createdFiles as $path)
            if(is_file($path)&&!unlink($path))error_log('VD native configurator: attachment cleanup failed: '.$path);
        $this->createdFiles=[];$this->removedFiles=[];
    }
    function submissions($request) {
        return $this->rows('SELECT id,parent_item_id config_id,created_by actor_id,'.$this->field('submission','hash').' hash FROM app_entity_'.$this->entity('submission').' WHERE '.$this->field('submission','request').'=? FOR UPDATE',[$request]);
    }
    function source($quote) {
        $rows=$this->rows('SELECT id quote_id,'.$this->field('quote','config').' config_id,'.$this->field('quote','revision').' revision,'.$this->field('quote','snapshot').' payload,'.$this->field('quote','hash').' hash,'.$this->field('quote','created_at').' created_at FROM app_entity_146 WHERE id=? AND '.$this->field('quote','snapshot')."<>''",[$quote]);
        return $rows[0]??null;
    }
    function quotes($id) {
        return $this->rows('SELECT id quote_id,'.$this->field('quote','revision').' revision FROM app_entity_146 WHERE '.$this->field('quote','config').'=? AND '.$this->field('quote','snapshot')."<>'' ORDER BY id DESC",[$id]);
    }
    function saveSource($quote,$config,$revision,$json,$hash,$created) {
        if(!hash_equals($hash,hash('sha256',$json)))throw new RuntimeException('Origem do configurador com integridade inválida.');
        $this->update('quote',$quote,['config'=>$config,'revision'=>$revision,'snapshot'=>$json,'hash'=>$hash,'created_at'=>$created]);
    }
}

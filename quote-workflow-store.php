<?php
require_once __DIR__ . '/quote-workflow-domain.php';
require_once __DIR__ . '/quote-workflow-pdf.php';
require_once __DIR__ . '/quote-approval-attachments.php';
require_once __DIR__ . '/quote-production-order.php';
require_once __DIR__ . '/quote-component-materials.php';
require_once __DIR__ . '/production-schedule.php';
require_once __DIR__ . '/quote-product.php';

final class VDQuoteWorkflow
{
    private $db;
    private $user;
    private $createdFiles = [];
    private $configurationImport = false;

    public function __construct(mysqli $db, array $user)
    {
        $this->db = $db;
        $this->user = $user;
    }

    public function rows($sql, array $values = [])
    {
        $stmt = $this->db->prepare($sql);
        if ($values) $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function write($sql, array $values = [])
    {
        $stmt = $this->db->prepare($sql);
        if ($values) $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        return $stmt->affected_rows;
    }

    public function access($entity, $id, $action = 'view')
    {
        global $current_access_schema;
        if (!users::has_access_to_entity($entity, $action)) {
            throw new DomainException('Sem acesso à entidade ' . $entity . ' (' . $action . ').');
        }
        $visible = items::add_access_query($entity, '') .
            items::add_access_query_for_parent_entities($entity);
        $row = $this->rows("SELECT e.* FROM app_entity_$entity e WHERE e.id=? $visible", [$id]);
        if (!$row) throw new DomainException('Registo inexistente ou sem acesso.');
        $previous = $current_access_schema;
        try {
            $current_access_schema = users::get_entities_access_schema($entity, $this->user['group_id']);
            $rule = new access_rules($entity, $row[0]);
            if (!users::has_access($action, $rule->get_access_schema())) {
                throw new DomainException('A regra do registo não permite esta ação.');
            }
            if ($action !== 'view' && users::has_access('action_with_assigned') &&
                !users::has_access_to_assigned_item($entity, $id)) {
                throw new DomainException('A ação está limitada aos utilizadores atribuídos.');
            }
            if ($action === 'update' && users::has_access('update_creator') &&
                (int) $row[0]['created_by'] !== (int) $this->user['id']) {
                throw new DomainException('A atualização está limitada ao autor.');
            }
        } finally {
            $current_access_schema = $previous;
        }
        return $row[0];
    }

    private function createAccess($entity, $parent)
    {
        if (!users::has_access_to_entity($entity, 'create') ||
            !access_rules::has_add_buttons_access($entity, $parent)) {
            throw new DomainException('Sem autorização para criar linhas na entidade ' . $entity . '.');
        }
    }

    private function insertNative($entity, array $values, array $fixed = [])
    {
        // Native tables have historical NOT NULL fields without defaults.
        $row = [];
        $numeric = [];
        foreach ($this->rows("SHOW COLUMNS FROM app_entity_$entity") as $column) {
            $name = $column['Field'];
            if ($name === 'id') continue;
            $numeric[$name] = (bool) preg_match('/^(int|bigint|decimal|float|double|tinyint|smallint|mediumint)/', $column['Type']);
            $row[$name] = $column['Default'] ?? ($column['Null'] === 'YES' ? null :
                ($numeric[$name] ? 0 : ''));
        }
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, $row)) throw new RuntimeException('Campo nativo desconhecido: ' . $name);
            $row[$name] = $value === '' && $numeric[$name] ? $row[$name] : $value;
        }
        if (isset($values['vd_workflow_version'])) {
            foreach ($this->rows('SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=?', [$entity]) as $field) {
                $row['field_' . $field['field_id']] = ([
                    'version' => $values['vd_workflow_version'], 'quantity' => $values['vd_workflow_quantity'],
                    'production' => '0'] + $fixed)[$field['role']] ?? '';
            }
        }
        $row['date_added'] = time();
        $row['created_by'] = (int) $this->user['id'];
        $this->write("INSERT INTO app_entity_$entity (`" . implode('`,`', array_keys($row)) .
            '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
        return (int) $this->db->insert_id;
    }

    private function audit($quote, $action, array $detail)
    {
        $this->write('INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,?,?,?,?)',
            [$quote, $this->user['id'], $action, json_encode($detail, JSON_THROW_ON_ERROR), time()]);
    }

    private function locked($quote, callable $callback)
    {
        if ($this->configurationImport) {
            $this->write('INSERT IGNORE INTO app_vd_quote_control(quote_id,revision) VALUES(?,0)',[$quote]);
            return $callback();
        }
        if ((int) $this->rows("SELECT GET_LOCK(?,5) acquired", ['vd_workflow_' . $quote])[0]['acquired'] !== 1) {
            throw new DomainException('Orçamento em atualização. Tente novamente.');
        }
        try {
            $this->createdFiles = [];
            $this->db->begin_transaction();
            $this->write('INSERT IGNORE INTO app_vd_quote_control(quote_id,revision) VALUES(?,0)', [$quote]);
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollback();
            foreach ($this->createdFiles as $path) {
                if (is_file($path) && !unlink($path)) error_log('VD quote workflow: failed to remove rolled-back approval attachment.');
            }
            throw $error;
        } finally {
            $this->createdFiles = [];
            $this->write('SET @vd_workflow_production_mutation=NULL');
            $this->rows('SELECT RELEASE_LOCK(?)', ['vd_workflow_' . $quote]);
        }
    }

    public function state($quote)
    {
        $nativeQuote = $this->access(146, $quote);
        $commercial = $this->commercialFields(146, $nativeQuote);
        $components = $this->rows('SELECT id FROM app_entity_262 WHERE parent_item_id=? ORDER BY id', [$quote]);
        foreach ($components as $component) $this->access(262, $component['id']);
        $versions = $this->rows('SELECT id,component_id,quantity_id,quantity,version_no,state,hash,native_id,created_by,created_at FROM app_vd_quote_versions WHERE quote_id=? ORDER BY id DESC', [$quote]);
        foreach ($versions as &$version) {
            $stored = $this->version($quote, $version['id']);
            $version['totals'] = vd_workflow_validate_calculation($stored['snapshot']['calculation'], true);
        }
        unset($version);
        $documents = $this->rows('SELECT id,revision,state,hash,issued_at,expires_at,approved_by,approved_at,sent_at,accepted_quantity,delivery_date,accepted_at FROM app_vd_quote_documents WHERE quote_id=? ORDER BY id DESC', [$quote]);
        foreach ($documents as &$document) {
            $stored = $this->document($quote, $document['id']);
            $snapshot = json_decode($stored['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $document['language'] = $snapshot['quote']['language'] ?? 'pt';
            $document['recipient_email'] = $snapshot['customer']['email'];
            $document['version_ids'] = array_map('intval', $snapshot['version_ids']);
            $document['discount_change'] = $document['sent_at'] === null && in_array($document['state'], ['approved', 'send_failed'], true) ?
                $this->discountChange($quote, $snapshot) : null;
            $files = $this->rows('SELECT name,mime,uploaded_at FROM app_vd_quote_approval_files WHERE document_id=? AND quote_id=?', [$document['id'], $quote]);
            $document['approval_file'] = $files[0] ?? null;
            $document['alternatives'] = [];
            foreach ($snapshot['quantities'] as $quantity) {
                $sale = 0;
                foreach ($snapshot['version_ids'] as $versionId) {
                    $version = $this->version($quote, $versionId);
                    if ((float) $version['quantity'] !== (float) $quantity) continue;
                    $sale += vd_workflow_validate_calculation($version['snapshot']['calculation'])['sale'];
                }
                $prices = vd_workflow_discounted_price($sale, $quantity, $snapshot['customer']['discountPercent'] ?? 0,
                    $snapshot['customer']['extraDiscountPercent'] ?? 0);
                if (!array_key_exists('discountPercent', $snapshot['customer'])) {
                    $prices['grossSale'] = $prices['sale'] = $sale;
                    $prices['unitGrossSale'] = $prices['unitSale'] = $sale / (float) $quantity;
                }
                foreach ($snapshot['commercialAlternatives'] ?? [] as $alternative) {
                    if ((float) $alternative['quantity'] === (float) $quantity && isset($alternative['prices'])) {
                        $prices = $alternative['prices'];
                        break;
                    }
                }
                $document['alternatives'][] = ['quantity' => (float) $quantity] + $prices;
            }
            usort($document['alternatives'], static function ($a, $b) { return $a['quantity'] <=> $b['quantity']; });
            $document['can_accept'] = $document['state'] === 'sent' &&
                (int) $document['expires_at'] >= time();
            $sms = $this->rows('SELECT state FROM app_vd_quote_sms WHERE document_id=?', [$document['id']]);
            $document['sms_state'] = $sms[0]['state'] ?? 'not_started';
            $document['deliveries'] = $this->rows('SELECT id,email,sender_email,sender_name,email_state,phone,state sms_state,created_at FROM app_vd_quote_deliveries WHERE document_id=? ORDER BY id DESC', [$document['id']]);
            if ($document['deliveries']) $document['sms_state'] = $document['deliveries'][0]['sms_state'];
        }
        unset($document);
        $control = $this->rows('SELECT revision,production_document,production_quantity FROM app_vd_quote_control WHERE quote_id=?', [$quote]);
        foreach ($documents as &$document) {
            $document['can_accept'] = $document['can_accept'] &&
                (int) $document['revision'] === (int) ($control[0]['revision'] ?? 0);
        }
        unset($document);
        $legacy = $this->rows('SELECT s.id,s.parent_item_id component_id,s.field_6101 quantity_id,s.field_6102 quantity FROM app_entity_388 s JOIN app_entity_262 c ON c.id=s.parent_item_id LEFT JOIN app_vd_quote_versions v ON v.native_id=s.id WHERE c.parent_item_id=? AND v.id IS NULL ORDER BY s.id DESC', [$quote]);
        foreach ($legacy as $selection) $this->access(388, $selection['id']);
        $audit = $this->rows('SELECT actor_id,event,detail,created_at FROM app_vd_quote_audit WHERE quote_id=? ORDER BY id DESC LIMIT 100', [$quote]);
        $customer = $this->access(26, (int) $nativeQuote['field_3923']);
        return ['quote_id' => (int) $quote, 'versions' => $versions, 'documents' => $documents, 'audit' => $audit,
            'discount_percent' => $this->quoteDiscount($nativeQuote, $customer),
            'extra_discount_percent' => $this->extraDiscount($quote),
            'language' => in_array((string) ($commercial['language'] ?? ''), ['1','true'], true) ? 'en' : 'pt',
            'send_phone' => (string) $nativeQuote['field_3926'],
            'sender_email' => trim((string) ($this->user['email'] ?? '')),
            'sender_name' => trim((string) ($this->user['name'] ?? '')),
            'legacy' => $legacy, 'control' => $control[0] ?? ['revision' => 0],
            'production_order' => users::has_access_to_entity(121, 'view') ? $this->productionOrder($quote) : null,
            'production_order_exists' => (bool) ($this->rows('SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=?', [$quote]) ||
                $this->rows('SELECT id FROM app_entity_121 WHERE field_5972=?', [$quote])),
            'delivery_default' => vd_production_delivery_default(),
            'delivery_min' => (new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d'),
            'approver' => $this->approver(), 'admin' => (int) $this->user['group_id'] === 0,
            'approval_groups' => $this->approvalGroups()];
    }

    private function approvalGroups()
    {
        return array_map('intval', array_column($this->rows('SELECT group_id FROM app_vd_quote_approval_groups ORDER BY group_id'), 'group_id'));
    }

    private function approver()
    {
        return (int) $this->user['group_id'] === 0 ||
            vd_workflow_can_approve($this->user['group_id'], $this->approvalGroups());
    }

    private function version($quote, $id)
    {
        $rows = $this->rows('SELECT * FROM app_vd_quote_versions WHERE id=? AND quote_id=?', [$id, $quote]);
        if (!$rows) throw new DomainException('Versão não encontrada neste orçamento.');
        $version = $rows[0];
        $this->access(262, $version['component_id']);
        $mirror = $this->access(388, $version['native_id']);
        if ($mirror['field_6106'] !== $version['payload']) throw new RuntimeException('A cópia nativa não coincide com o instantâneo imutável.');
        $payload = json_decode($version['payload'], true, 512, JSON_THROW_ON_ERROR);
        $expectedHash = (int) ($payload['version'] ?? 0) >= 4 ? hash('sha256', $version['payload']) :
            vd_workflow_version_hash($payload['calculation']);
        if (!hash_equals($version['hash'], $expectedHash)) {
            throw new RuntimeException('Integridade da versão inválida.');
        }
        $version['snapshot'] = $payload;
        return $version;
    }

    public function snapshot($quote, $id)
    {
        $this->access(146, $quote);
        $version = $this->version($quote, $id);
        return ['id' => (int) $version['id'], 'version_no' => (int) $version['version_no'],
            'component_id' => (int) $version['component_id'], 'quantity' => (float) $version['quantity'],
            'calculation' => $version['snapshot']['calculation']];
    }

    // The configurator owns the transaction; no native rows are committed until the source snapshot is linked.
    public function importProductConfiguration(array $state,array $schema,$draft=false)
    {
        $payload=$state['payload'];
        $customer=$this->access(26,(int)$state['customer']);
        $this->createAccess(146,0);
        $choice=$this->rows('SELECT parent_id FROM app_global_lists_choices WHERE id=? AND lists_id=24 AND is_active=1',[$payload['product_type']]);
        if(!$choice||in_array((int)$payload['product_type'],[3822,3823],true))throw new DomainException('Escolha o tipo/subtipo real antes de gerar o orçamento.');
        $typeValue=((int)$choice[0]['parent_id']>0?$choice[0]['parent_id'].',':'').$payload['product_type'];
        $quoteFields=['parent_item_id'=>0,'field_3923'=>$customer['id'],'field_3925'=>$customer['field_228']??'',
            'field_3927'=>$state['name'],'field_3928'=>$state['notes']??'','field_2030'=>36,'field_3930'=>$typeValue,
            'field_'.$schema['quote.config']=>$state['id']];
        if(!$draft)$quoteFields['field_6271']=$payload['markup'];
        $contact=(int)($payload['request_details']['contact_id']??0);
        if($draft&&$contact) {
            $contactRow=$this->access(90,$contact);
            if((int)$contactRow['parent_item_id']!==(int)$customer['id'])throw new DomainException('O contacto não pertence ao cliente do pedido.');
            $quoteFields['field_3924']=$contact;
        }
        if($draft) {
            $details=$payload['request_details']??[];$technical=[];
            if(trim((string)($state['reference']??''))!=='')$technical[]='Referência do pedido: '.$state['reference'];
            if(!empty($details['delivery']))$technical[]='Data de entrega pedida: '.$details['delivery'];
            $dispatch=['pickup'=>'Levantamento','carrier'=>'Transportadora','delivery'=>'Entrega'];
            foreach($details['dispatch']??[] as $method)if(isset($dispatch[$method]))$technical[]='Expedição: '.$dispatch[$method];
            if(!empty($details['finishing']))$technical[]='Indicações dos acabamentos: '.$details['finishing'];
            if($technical)$quoteFields['field_4417']=nl2br(htmlspecialchars(implode("\n",$technical),ENT_QUOTES,'UTF-8'));
        }
        $quote=$this->insertNative(146,$quoteFields);
        $componentIds=$quantityIds=$colorIds=[];$operationDefaults=[];
        if($draft) {
            foreach($this->rows('SELECT id,configuration FROM app_fields WHERE entities_id=276 AND id IN(6125,6126,6127,6254,6255,6256,6257,6258,6259,6260,6263,6264,6265,6266)') as $field) {
                $configuration=json_decode($field['configuration'],true,512,JSON_THROW_ON_ERROR);
                if(isset($configuration['default_value'])&&$configuration['default_value']!=='')
                    $operationDefaults['field_'.$field['id']]=$configuration['default_value'];
            }
            foreach($this->rows('SELECT fields_id,id FROM app_fields_choices WHERE fields_id IN(6261,6262) AND is_default=1 ORDER BY id') as $choice) {
                $key='field_'.$choice['fields_id'];
                if(isset($operationDefaults[$key]))throw new DomainException('Mais de um valor padrão para '.$key.'. Reveja o campo nativo.');
                $operationDefaults[$key]=$choice['id'];
            }
        }
        foreach($payload['quantities'] as $quantity) {
            $this->createAccess(261,$quote);
            $quantityIds[$quantity]=$this->insertNative(261,['parent_item_id'=>$quote,'field_3938'=>$quantity]);
        }
        foreach($payload['components'] as $component) {
            $this->createAccess(262,$quote);
            $fields=$draft?['parent_item_id'=>$quote,'field_4225'=>$component['name']]:
                ['parent_item_id'=>$quote,'field_4225'=>$component['name'],'field_4220'=>1565,'field_4229'=>1255,'field_6110'=>1544,'field_6169'=>'true'];
            foreach($component['fields']??[] as $field=>$value) {
                if(in_array((int)$field,[4218,4219,4220,4229,4233,6110,6119,6120,6121,6169,6170,6172,6173,6174,6175,6267,6268,6291,4221,4242],true))
                    $fields['field_'.(int)$field]=$value;
            }
            if($draft&&empty($fields['field_4242'])&&!empty($state['reference']))$fields['field_4242']=$state['reference'];
            if(in_array($component['role']??'',['cover','jacket'],true))$fields['field_6110']=1544;
            $id=$componentIds[$component['id']]=$this->insertNative(262,$fields);
            $stageOperations=$component['request']['finishing_operations']??[];
            if(($component['stage']??'')==='assembly')$stageOperations[]=['id'=>9,'name'=>'Corte final','variants'=>[9]];
            if(($component['stage']??'')==='dispatch') {
                $stageOperations[]=['id'=>10,'name'=>'Embalamento do lote','variants'=>[10]];
                if(in_array('delivery',$payload['request_details']['dispatch']??[],true))$stageOperations[]=['id'=>12,'name'=>'Entrega','variants'=>[12]];
            }
            if(isset($component['stage'])||isset($component['technical_operations']))vd_product_stage_write($this->db,262,'quote_part',$id,[
                'stage'=>$component['stage']??'component','requirements'=>[
                    'operations'=>$stageOperations,
                    'details'=>$component['request']??[],'role'=>$component['role']??'',
                    'final_format'=>$payload['request_details']['final_format']??[],
                    'product_details'=>array_intersect_key($payload['request_details']??[],
                        array_flip(['total_pages','box','finishing','document_book','delivery','dispatch','other_product']))
                ]]);
            foreach(($draft?($component['technical_operations']??[]):[]) as $order=>$operation) {
                $this->access(36,(int)$operation['operation_id']);$this->createAccess(276,$id);
                if($operation['equipment']!=='')$this->access(78,(int)$operation['equipment']);
                $settings=array_replace($operationDefaults,['parent_item_id'=>$id,'field_4287'=>(int)$operation['operation_id'],
                    'field_4288'=>$operation['equipment'],'field_6179'=>'false','field_6180'=>($order+1)*10]);
                foreach(['adhesive_per_copy'=>6254,'adhesive_width'=>6255,'adhesive_height'=>6256] as $key=>$field)
                    if(isset($operation[$key]))$settings['field_'.$field]=$operation[$key];
                $this->insertNative(276,$settings);
            }
            foreach($component['papers']??[] as $article) {
                $articleRow=$this->access(99,$article);$this->createAccess(277,$id);
                $this->insertNative(277,['parent_item_id'=>$id,'field_4278'=>$article,'field_4280'=>$articleRow['field_3632']]);
            }
            if($component['kind']!=='printed')continue;
            $pantones=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)($component['request']['printing']['pantone']??''))),
                static function($value){return $value!=='';})));
            $special=array_values(array_intersect($component['colors']??[],[3732,3820]));
            foreach($component['colors']??[] as $index=>$color) {
                $this->createAccess(278,$id);
                $references=$draft&&count($special)===1&&in_array($color,[3732,3820],true)&&$pantones?$pantones:[''];
                foreach($references as $referenceIndex=>$reference) {
                    $colorFields=['parent_item_id'=>$id,'field_4251'=>$color];
                    if($draft&&in_array($color,[3732,3820],true))$colorFields['field_4253']=$reference!==''?$reference:
                        ($component['request']['printing']['pantone']??'');
                    $nativeColor=$this->insertNative(278,$colorFields);
                    if($referenceIndex===0)$colorIds[$component['id']][$index+1]=$nativeColor;
                }
            }
            foreach($component['rubrics']??[] as $rubric) {
                $this->createAccess(280,$id);
                $this->insertNative(280,['parent_item_id'=>$id,'field_4320'=>$rubric['quantity'],'field_4321'=>$rubric['name'],'field_4322'=>$rubric['code']??'']);
            }
        }
        $this->write('INSERT INTO app_vd_quote_products(quote_id,type,revision) VALUES(?,?,1)',[$quote,$payload['type']]);
        foreach($payload['components'] as $component) {
            $inputs=array_map(static function($input)use($componentIds){return $componentIds[$input];},$component['inputs']??[]);
            $this->write('INSERT INTO app_vd_quote_product_components(component_id,quote_id,kind,inputs,units_per_product) VALUES(?,?,?,?,?)',[
                $componentIds[$component['id']],$quote,$component['kind'],json_encode($inputs,JSON_THROW_ON_ERROR),$component['units_per_product']??1]);
        }
        if($draft) {
            $this->audit($quote,'product_request_draft_created',['configuration'=>$state['id'],'revision'=>$state['revision'],'priced'=>false]);
            return $quote;
        }
        $this->configurationImport=true;
        try {
            foreach($payload['components'] as $component)foreach($payload['quantities'] as $quantity) {
                $id=$componentIds[$component['id']];
                $calculation=$payload['selections'][$component['id']][$quantity]['calculation'];
                $metadata=vd_product_component($this->db,$quote,$id);
                $calculation['product']=$metadata;
                if(isset($calculation['component']))$calculation['component']['id']=$id;
                $calculation['quantityId']=$quantityIds[$quantity];
                foreach($calculation['allOperations']['lines'] as &$line) {
                    if(preg_match('/^color:(\d+)$/',$line['key'],$match)) {
                        if(!isset($colorIds[$component['id']][$match[1]]))throw new DomainException('Cor do cálculo sem correspondência.');
                        $line['key']='color:'.$colorIds[$component['id']][$match[1]];
                    }
                }
                unset($line);
                if($component['kind']!=='printed') {
                    // Rebuild manual non-print ledgers with the remapped immutable product metadata.
                    $catalog=array_column($this->rows("SELECT id,field_1982 name,field_1983 machines FROM app_entity_36 WHERE field_6129='true'"),null,'id');
                    $articles=array_column($this->rows('SELECT id,field_1020 name,field_3632 internal_id FROM app_entity_99'),null,'id');
                    $machines=array_column($this->rows('SELECT id FROM app_entity_78'),null,'id');
                    $calculation=vd_product_nonprint_calculation($calculation['definition'],$metadata,$catalog,$articles,$machines);
                }
                $this->save($quote,$id,['request_id'=>'configuration-'.bin2hex(random_bytes(16)),
                    'quantity_id'=>$quantityIds[$quantity],'expected_version'=>0,'calculation'=>$calculation]);
            }
        }finally{$this->configurationImport=false;}
        $this->audit($quote,'product_configuration_imported',['configuration'=>$state['id'],'revision'=>$state['revision'],'source_immutable'=>true]);
        return $quote;
    }

    public function productState($quote)
    {
        $this->access(146,$quote);
        $product=$this->rows('SELECT type,revision FROM app_vd_quote_products WHERE quote_id=?',[$quote]);
        $components=$this->rows('SELECT id,field_4225 name FROM app_entity_262 WHERE parent_item_id=? ORDER BY id',[$quote]);
        foreach($components as &$component) {
            $this->access(262,$component['id']);
            $component['product']=vd_product_component($this->db,$quote,$component['id']);
            $versions=$this->rows('SELECT id,payload FROM app_vd_quote_versions WHERE component_id=? ORDER BY id DESC LIMIT 1',[$component['id']]);
            $component['definition']=$versions ? (json_decode($versions[0]['payload'],true,512,JSON_THROW_ON_ERROR)['calculation']['definition']??null) : null;
        }

        unset($component);
        return ['type'=>$product[0]['type']??'standard','revision'=>(int)($product[0]['revision']??0),
            'types'=>vd_product_types(),'components'=>$components,
            'catalog'=>vd_product_stage_catalog($this->db),
            'articles'=>$this->rows('SELECT id,field_1020 name,field_3632 internal_id FROM app_entity_99 ORDER BY field_1020,id'),
            'machines'=>$this->rows('SELECT id,field_1281 name FROM app_entity_78 ORDER BY id'),
            'quantities'=>$this->rows('SELECT id,field_3938 quantity FROM app_entity_261 WHERE parent_item_id=? ORDER BY id',[$quote]),
            'quote'=>(int)$quote];
    }

    private function assertProductVersion($quote,array $version)
    {
        $current=vd_product_component($this->db,$quote,$version['component_id']);
        if (($version['snapshot']['product']??null) != $current) throw new DomainException('A estrutura do produto mudou. Guarde e confirme uma nova versão do componente #'.$version['component_id']);
        if ($this->rows('SELECT quote_id FROM app_vd_quote_products WHERE quote_id=?',[$quote]) && !$current) throw new DomainException('Configure o tipo de todos os componentes do produto.');
    }

    public function assemblySuggestion($quote, $component, array $input)
    {
        $native = $this->access(262, $component);
        if ((int) $native['parent_item_id'] !== (int) $quote) throw new DomainException('Componente de outro orçamento.');
        $this->access(146, $quote);
        $metadata = vd_product_component($this->db, $quote, $component);
        if (!$metadata || $metadata['kind'] === 'printed') throw new DomainException('Cálculo automático apenas para assemblagem/materiais.');
        $quantities = $this->rows('SELECT id,field_3938 quantity FROM app_entity_261 WHERE parent_item_id=?', [$quote]);
        $chosen = array_values(array_filter($quantities, static function ($row) use ($input) { return (int) $row['id'] === (int) ($input['quantity_id'] ?? 0); }));
        if (!$chosen) throw new DomainException('Escolha a alternativa de quantidade.');
        $quantity = (float) $chosen[0]['quantity'];
        $catalog = array_column(vd_product_stage_catalog($this->db), null, 'id');
        $operations = array_values(array_filter($input['operations'] ?? [], static function ($row) { return (int) ($row['operation_id'] ?? 0) > 0; }));
        if (!$operations) foreach ($metadata['requirements']['operations'] ?? [] as $required)
            $operations[] = ['operation_id' => (int) (($required['variants'] ?? [])[0] ?? $required['id']), 'equipment' => ''];
        if (!$operations) throw new DomainException('Sem operações para calcular: adicione as operações da assemblagem.');
        foreach ($operations as $operation) $this->access(36, (int) $operation['operation_id']);
        $tariffs = $this->rows("SELECT parent_item_id operation_id,field_6135 basis,field_6136 cost,field_6138 version FROM app_entity_167 WHERE field_6164='true' AND (field_6139=0 OR field_6139<=UNIX_TIMESTAMP(CURDATE())) AND (field_6165=0 OR field_6165>=UNIX_TIMESTAMP(CURDATE()))");
        $equipment = $this->rows('SELECT parent_item_id operation_id,field_2403 machine_id,field_2413 speed,field_6143 unit,field_6144 setup FROM app_entity_168 ORDER BY id');
        $notes = [];
        $physical = ['thickness' => 0.0, 'weight' => 0.0, 'signatures' => 0, 'height' => 0.0];
        $visit = function ($componentId, $depth) use (&$visit, $quote, $quantity, &$notes, &$physical) {
            if ($depth > 5) throw new DomainException('Estrutura de assemblagem demasiado profunda.');
            $part = vd_product_component($this->db, $quote, $componentId);
            $name = $this->access(262, $componentId)['field_4225'];
            if ($part && $part['kind'] === 'assembly') {
                foreach ($part['inputs'] as $inputId) $visit($inputId, $depth + 1);
                return;
            }
            if ($part && $part['kind'] === 'material') return;
            $version = null;
            foreach ($this->rows('SELECT id,quantity FROM app_vd_quote_versions WHERE component_id=? ORDER BY id DESC', [$componentId]) as $row) {
                if (abs((float) $row['quantity'] - $quantity) < 1e-6) { $version = $this->version($quote, $row['id']); break; }
            }
            if (!$version) { $notes[] = $name . ': sem versão guardada para ' . $quantity . ' unidades; peso e espessura não incluem este componente.'; return; }
            $calculation = $version['snapshot']['calculation'];
            $paper = $calculation['parts'][0]['paperCalculation']['paper'] ?? [];
            $gsm = (float) ($paper['weight'] ?? 0);
            $pages = (int) ($calculation['component']['pages'] ?? 0);
            $leaves = (int) ($calculation['pagination']['leaves'] ?? ($pages > 4 ? ceil($pages / 2) : 1));
            $width = (float) ($calculation['component']['width'] ?? 0);
            $height = (float) ($calculation['component']['height'] ?? 0);
            if ($gsm <= 0) { $notes[] = $name . ': gramagem do papel desconhecida.'; return; }
            // Caliper estimated at 1 µm per g/m² when the paper thickness is not registered.
            $physical['thickness'] += $leaves * $gsm / 1000;
            $physical['weight'] += $leaves * $width * $height / 1000000 * $gsm;
            $physical['signatures'] += (int) ($calculation['pagination']['signatures'] ?? 0);
            if ($pages > 4) $physical['height'] = max($physical['height'], $height);
        };
        foreach ($metadata['inputs'] as $inputId) $visit($inputId, 0);
        $requested = (float) ($metadata['requirements']['final_format']['thickness_mm'] ?? 0);
        $finalHeight = (float) ($metadata['requirements']['final_format']['height_mm'] ?? 0);
        $price = '(SELECT p.field_1037 FROM app_entity_98 p WHERE p.field_1033=a.id AND p.field_1032 IN(39,3790) AND p.field_1034>0 ORDER BY p.id DESC LIMIT 1)';
        $glue = $this->rows("SELECT a.id,a.field_1020 name,$price price FROM app_entity_99 a WHERE a.id=2828");
        $spirals = $this->rows("SELECT a.id,a.field_1020 name,$price price FROM app_entity_99 a WHERE a.field_1020 LIKE '%spiral%duplo%' OR a.field_1020 LIKE '%spiral%metálico%' OR a.field_1020 LIKE '%spiral%metalico%'");
        $context = ['signatures' => $physical['signatures'], 'binding_height_mm' => $finalHeight > 0 ? $finalHeight : $physical['height'],
            'glue' => $glue[0] ?? null, 'spirals' => $spirals,
            'thickness_mm' => $requested > 0 ? $requested : $physical['thickness'],
            'thickness_source' => $requested > 0 ? 'indicada no pedido' : 'estimada pela gramagem, ~1 µm por g/m²',
            'weight_g' => $physical['weight'], 'notes' => $notes];
        return vd_product_assembly_suggestion($quantity, $context, $operations, $catalog, $tariffs, $equipment) + ['quantity' => $quantity];
    }

    public function nonprintCalculation($quote,$component,array $definition)
    {
        $native=$this->access(262,$component,'update');
        if ((int)$native['parent_item_id']!==(int)$quote) throw new DomainException('Componente de outro orçamento.');
        $this->access(146,$quote,'update');
        $metadata=vd_product_component($this->db,$quote,$component);
        if (!$metadata) throw new DomainException('Configure o produto antes de calcular.');
        $catalog=array_column(vd_product_stage_catalog($this->db),null,'id');
        $articles=array_column($this->rows('SELECT id,field_1020 name,field_3632 internal_id FROM app_entity_99'),null,'id');
        foreach($definition['operations']??[] as $operation) $this->access(36,(int)$operation['operation_id']);
        foreach($definition['materials']??[] as $material) $this->access(99,(int)$material['article_id']);
        $machines=array_column($this->rows('SELECT id FROM app_entity_78'),null,'id');
        return vd_product_nonprint_calculation($definition,$metadata,$catalog,$articles,$machines);
    }

    public function configureProduct($quote, array $input)
    {
        $this->access(146,$quote,'update');
        return $this->locked($quote,function() use($quote,$input) {
            if ($this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND state IN('sending','sent','accepted','send_unknown')",[$quote]) ||
                $this->rows('SELECT id FROM app_entity_121 WHERE field_5972=?',[$quote])) {
                throw new DomainException('Produto bloqueado após envio/adjudicação ou criação da OP. Preserve o histórico e reveja o orçamento antes de reestruturar.');
            }
            $type=(string)($input['type']??'');
            if (!isset(vd_product_types()[$type])) throw new DomainException('Tipo de produto inválido.');
            $previous=$this->rows('SELECT revision FROM app_vd_quote_products WHERE quote_id=?',[$quote]);
            if ((int)($input['revision']??0)!==(int)($previous[0]['revision']??0)) throw new DomainException('O produto mudou. Atualize.');
            $components=$this->rows('SELECT id,field_4225 name FROM app_entity_262 WHERE parent_item_id=? ORDER BY id',[$quote]);
            if (($input['create_template']??false)===true) {
                if ($components) throw new DomainException('O modelo só cria componentes num orçamento vazio. Nos existentes, configure os componentes sem os duplicar.');
                $this->createAccess(262,$quote);
                foreach(vd_product_template($type) as [$name,$kind]) {
                    $id=$this->insertNative(262,['parent_item_id'=>$quote,'field_4225'=>$name,'field_4220'=>1565,
                        'field_4229'=>1255,'field_6110'=>1544,'field_6169'=>'true','field_6170'=>'false']);
                    $components[]=['id'=>$id,'name'=>$name,'kind'=>$kind];
                }
                $sourceIds=array_map('intval',array_column(array_filter($components,static function($c){return $c['kind']!=='assembly';}),'id'));
                $input['components']=array_map(static function($c) use($sourceIds) {
                    return ['id'=>(int)$c['id'],'kind'=>$c['kind'],'inputs'=>$c['kind']==='assembly'?$sourceIds:[],'units_per_product'=>1];
                },$components);
            }
            if (($input['add_assembly']??false)===true) {
                if ($type==='standard' || !$components) throw new DomainException('Selecione um produto estruturado com componentes de entrada.');
                if (array_filter($input['components']??[],static function($c){return ($c['kind']??'')==='assembly';})) throw new DomainException('Já existe uma assemblagem na estrutura indicada.');
                $this->createAccess(262,$quote);
                $sources=array_map('intval',array_column($components,'id'));
                $id=$this->insertNative(262,['parent_item_id'=>$quote,'field_4225'=>'Assemblagem / Encadernação',
                    'field_4220'=>1565,'field_4229'=>1255,'field_6110'=>1544,'field_6169'=>'true']);
                $components[]=['id'=>$id,'name'=>'Assemblagem / Encadernação'];
                $input['components'][]=['id'=>$id,'kind'=>'assembly','inputs'=>$sources,'units_per_product'=>1];
            }
            $definitions=$input['components']??[];
            $staged=false;
            foreach($definitions as &$definition) {
                $metadata=vd_product_stage_read($this->db,(int)$definition['id']);
                if($metadata){$definition['stage']=$metadata['stage'];$staged=true;}
            }
            unset($definition);
            $ids=array_map('intval',array_column($components,'id')); sort($ids);
            $provided=array_map('intval',array_column($definitions,'id')); sort($provided);
            if ($ids!==$provided) throw new DomainException('Configure todos os componentes do orçamento, sem omissões ou repetições.');
            vd_product_graph($definitions);
            if($staged)vd_product_stage_graph($definitions);
            if (!$staged&&$type!=='standard' && count(array_filter($definitions,static function($c){return $c['kind']==='assembly';}))!==1) {
                throw new DomainException('Este produto deve ter exatamente uma assemblagem final.');
            }
            if (!$staged&&$type!=='standard') foreach($definitions as $definition) if ($definition['kind']==='assembly') {
                $expected=array_values(array_diff($ids,[(int)$definition['id']]));
                $actual=array_map('intval',$definition['inputs']??[]);sort($actual);sort($expected);
                if ($actual!==$expected) throw new DomainException('A assemblagem final deve incluir todos os restantes componentes do produto.');
            }
            if (!$staged&&$type==='standard' && array_filter($definitions,static function($c){return $c['kind']!=='printed';})) throw new DomainException('Utilize um tipo de produto estruturado para componentes sem impressão.');
            foreach($definitions as $definition) {
                $id=(int)$definition['id'];
                $this->access(262,$id,'update');
                $units=vd_product_number($definition['units_per_product']??1,'unidades por produto',false);
                if (abs($units-round($units,4))>0.00000001 || $units>99999999) throw new DomainException('Unidades por produto: máximo de quatro casas decimais e 99 999 999 unidades.');
                if ($definition['kind']!=='material' && $units!==1.0) throw new DomainException('Unidades por produto deve ser 1 na impressão e na assemblagem final; represente repetições impressas nas rubricas/imposição.');
                $inputs=json_encode(array_map('intval',$definition['inputs']??[]),JSON_THROW_ON_ERROR);
                $this->write('INSERT INTO app_vd_quote_product_components(component_id,quote_id,kind,inputs,units_per_product) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),inputs=VALUES(inputs),units_per_product=VALUES(units_per_product)',
                    [$id,$quote,$definition['kind'],$inputs,$units]);
                if ($definition['kind']==='printed' && $type!=='standard') {
                    // Final binding/packaging belongs to the assembly, not to each printed part.
                    $native=$this->access(262,$id);
                    $extras=array_filter(explode(',',(string)$native['field_6291']),static function($op){return !in_array((int)$op,[9,10,19,20,23,24,25,26,32,39],true);});
                    $this->write("UPDATE app_entity_262 SET field_6175='false',field_6291=? WHERE id=?",[implode(',',$extras),$id]);
                }
            }
            $this->write('INSERT INTO app_vd_quote_products(quote_id,type,revision) VALUES(?,?,1) ON DUPLICATE KEY UPDATE type=VALUES(type),revision=revision+1',[$quote,$type]);
            $this->write("UPDATE app_vd_quote_documents SET state='superseded' WHERE quote_id=? AND state IN('approved','send_failed')",[$quote]);
            $this->write('UPDATE app_vd_quote_control SET revision=revision+1 WHERE quote_id=?',[$quote]);
            $this->audit($quote,'product_configured',['type'=>$type,'components'=>$definitions,'manual_box_geometry'=>true]);
            return ['message'=>'Produto configurado. Guarde novas versões dos componentes; versões antigas não foram alteradas.'];
        });
    }

    public function addProductComponent($quote,array $input)
    {
        $this->access(146,$quote,'update');
        $this->createAccess(262,$quote);
        return $this->locked($quote,function() use($quote,$input) {
            $product=$this->rows('SELECT type FROM app_vd_quote_products WHERE quote_id=?',[$quote]);
            if (!$product) throw new DomainException('Configure primeiro o tipo de produto.');
            if ($this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND state NOT IN('superseded','approved','send_failed')",[$quote]) ||
                $this->rows('SELECT id FROM app_entity_121 WHERE field_5972=?',[$quote])) throw new DomainException('Não é possível adicionar componentes após envio/OP.');
            $name=trim((string)($input['name']??''));
            $kind=$input['kind']??'';
            if ($name===''||strlen($name)>200||!in_array($kind,['printed','material'],true)) throw new DomainException('Nome ou tipo do componente inválido.');
            $id=$this->insertNative(262,['parent_item_id'=>$quote,'field_4225'=>$name,'field_4220'=>1565,'field_4229'=>1255,'field_6110'=>1544,'field_6169'=>'true']);
            $this->write("INSERT INTO app_vd_quote_product_components(component_id,quote_id,kind,inputs,units_per_product) VALUES(?,?,?,'[]',1)",[$id,$quote,$kind]);
            $this->write('UPDATE app_vd_quote_products SET revision=revision+1 WHERE quote_id=?',[$quote]);
            $this->write("UPDATE app_vd_quote_documents SET state='superseded' WHERE quote_id=? AND state IN('approved','send_failed')",[$quote]);
            $this->write('UPDATE app_vd_quote_control SET revision=revision+1 WHERE quote_id=?',[$quote]);
            $this->audit($quote,'product_component_added',['component'=>$id,'kind'=>$kind,'name'=>$name]);
            return ['message'=>'Componente criado. Associe-o à assemblagem e guarde a estrutura do produto.'];
        });
    }

    public function save($quote, $component, array $input)
    {
        $this->access(146, $quote, 'update');
        $native = $this->access(262, $component, 'update');
        if ((int) $native['parent_item_id'] !== (int) $quote) throw new DomainException('Componente de outro orçamento.');
        $this->createAccess(388, $component);
        $request = (string) ($input['request_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{20,80}$/D', $request)) throw new DomainException('Identificador de gravação inválido.');
        $calculation = $input['calculation'] ?? [];
        $product = vd_product_component($this->db,$quote,$component);
        if(($product['requirements']['role']??'')==='cover'&&
            ((int)$native['field_4233']!==4||(int)$native['field_6110']!==1544))
            throw new DomainException('A capa corresponde a uma peça aberta de 4 páginas. Mantenha a encadernação na etapa de assemblagem, não na imposição da capa.');
        $structured=$product&&($product['type']!=='standard'||$this->rows("SELECT component_id FROM app_vd_quote_product_components WHERE quote_id=? AND kind='assembly' LIMIT 1",[$quote]));
        $nonprint = $product && $product['kind'] !== 'printed';
        if ($product && !$nonprint) $calculation['product'] = $product;
        if ($nonprint !== (($calculation['source']??'')==='nonprint')) throw new DomainException('O tipo de cálculo não corresponde ao componente do produto.');
        if ($product && ($calculation['product']??null) !== $product) throw new DomainException('A estrutura do produto mudou. Atualize e recalcule.');
        $totals = vd_workflow_validate_calculation($calculation, true);
        require_once __DIR__ . '/quote-workflow-discovery.php';
        $discoveryComponent = $native;
        foreach (['lamination_front' => 6267, 'lamination_back' => 6268] as $key => $field) {
            $choice = $this->rows('SELECT value FROM app_fields_choices WHERE fields_id=? AND id=?', [$field, $native['field_' . $field]]);
            $discoveryComponent[$key] = $choice[0]['value'] ?? '';
        }
        $catalog = $this->rows('SELECT id,field_1982 name,field_1983 machines,field_6131 unit,field_6132 setup,field_2523 speed,field_6129 active,field_4440 waste,field_1984 assigned_users,field_2266 operation_type,field_2423 supplier,field_2473 responsible_user,field_2524 speed_min,field_2525 speed_max FROM app_entity_36');
        $stageCatalog=array_column(vd_product_stage_catalog($this->db),null,'id');
        foreach($catalog as &$operation)if(isset($stageCatalog[$operation['id']]))$operation+=$stageCatalog[$operation['id']];
        unset($operation);
        $rules = $this->rows("SELECT parent_item_id operation,field_6149 required,field_6176 `condition`,field_6177 alternatives FROM app_entity_120 WHERE field_6178 IN('1','true') AND field_6176<>'' ORDER BY id");
        $requiredOperations = $nonprint ? [] : vd_workflow_discover_operations($discoveryComponent, [], $catalog, $rules,
            ['plates' => $calculation['results']['plates'] ?? 0]);
        if ($product && $product['type']!=='standard' && $product['kind']==='printed') $requiredOperations=array_values(array_filter($requiredOperations,static function($operation){return !in_array((int)$operation['operation_id'],[9,10,19,20,23,24,25,26,32,39],true);}));
        if(!empty($product['stage'])&&$product['kind']==='printed'&&$structured)
            $requiredOperations=array_values(array_filter($requiredOperations,static function($operation)use($stageCatalog){return in_array(vd_product_stage_code($stageCatalog[$operation['operation_id']]['stage']??''),['component','support'],true);}));
        $included = [];
        $lineKeys = [];
        foreach ($calculation['allOperations']['lines'] as $line) {
            $lineKeys[$line['key']] = true;
            foreach ($line['operationIds'] ?? (isset($line['operationId']) ? [$line['operationId']] : []) as $operation) $included[(int) $operation] = true;
        }
        foreach ($requiredOperations as $operation) {
            $operationId = (int) $operation['operation_id'];
            if ($operation['requirement_type'] === 'plan_alternative') continue;
            // CTP and imposition (plate montages/proofs) only exist when the chosen plan produces offset plates.
            if (in_array($operationId, [3, 43], true) && empty($calculation['results']['plates'])) continue;
            if (!isset($included[$operationId])) throw new DomainException('Operação necessária omitida da versão: ' . $operation['name']);
        }
        if ($product && $product['type']!=='standard' && $product['kind']==='printed' &&
            array_intersect(array_keys($included),[9,10,19,20,23,24,25,26,32,39])) {
            throw new DomainException('Encadernação, corte final e embalamento devem estar apenas na assemblagem do produto.');
        }
        if (!$nonprint && !isset($lineKeys['paper'])) throw new DomainException('Custo do papel em falta na versão.');
        if(!empty($product['stage'])&&$product['kind']==='printed'&&$structured)
            vd_product_stage_validate_operations($product,array_map(static function($id){return ['operation_id'=>$id];},array_keys($included)),$stageCatalog);
        if ($nonprint) {
            $catalogMap=array_column($catalog,null,'id');
            $articleMap=array_column($this->rows('SELECT id,field_1020 name,field_3632 internal_id FROM app_entity_99'),null,'id');
            $machineMap=array_column($this->rows('SELECT id FROM app_entity_78'),null,'id');
            $expected=vd_product_nonprint_calculation($calculation['definition']??[],$product,$catalogMap,$articleMap,$machineMap);
            if ($expected !== $calculation) throw new DomainException('O cálculo de assemblagem não coincide com os parâmetros. Recalcule.');
        }
        if (isset($included[5]) || isset($included[7])) {
            foreach ($this->rows('SELECT id,field_4251 color FROM app_entity_278 WHERE parent_item_id=?', [$component]) as $color) {
                if ((int) $color['color'] !== 3804 && !isset($lineKeys['color:' . $color['id']])) {
                    throw new DomainException('Consumo/custo de uma cor do componente omitido da versão.');
                }
            }
        }
        $preview = vd_workflow_production_rows($calculation, 1, (int) $component,
            (int) ($input['quantity_id'] === 'rubrics' ? 0 : $input['quantity_id']), true);
        foreach ($preview['plans'] as $plan) {
            if (!$this->rows("SELECT id FROM app_entity_78 WHERE id=? AND COALESCE(field_707,'') NOT IN('3647','3655')", [$plan['machine_id']])) {
                throw new DomainException('Equipamento de impressão inexistente ou inativo.');
            }
        }
        foreach (['width' => 4218, 'height' => 4219, 'pages' => 4233, 'binding' => 6110,
            'faces' => 4229, 'die_status' => 4220] as $key => $field) {
            if (isset($calculation['component'][$key]) &&
                (float) $calculation['component'][$key] !== (float) $native['field_' . $field]) {
                throw new DomainException('Os requisitos do componente mudaram. Recalcule.');
            }
        }
        foreach ($calculation['allOperations']['lines'] as $line) {
            foreach ($line['operationIds'] ?? (isset($line['operationId']) ? [$line['operationId']] : []) as $operationId) {
                if (!$this->rows("SELECT id FROM app_entity_36 WHERE id=? AND field_6129='true'", [$operationId])) {
                    throw new DomainException('Operação inexistente ou inativa na versão.');
                }
            }
        }
        $quantityId = $input['quantity_id'] ?? '';
        if ($quantityId === 'rubrics') $quantityId = 0;
        if (!ctype_digit((string) $quantityId)) throw new DomainException('Alternativa inválida.');
        if ((int) $quantityId > 0) {
            $q = $this->rows('SELECT field_3938 quantity FROM app_entity_261 WHERE id=? AND parent_item_id=?', [$quantityId, $quote]);
            if (!$q || (float) $q[0]['quantity'] !== $totals['quantity']) throw new DomainException('A quantidade do orçamento mudou.');
        }
        $hash = vd_workflow_version_hash($calculation, true);
        return $this->locked($quote, function () use ($quote, $component, $input, $calculation, $totals, $quantityId, $hash, $request, $native, $requiredOperations, $catalog, $rules, $product) {
            if (vd_product_component($this->db,$quote,$component) !== $product) throw new DomainException('Estrutura alterada durante a gravação. Recalcule.');
            $existing = $this->rows('SELECT * FROM app_vd_quote_versions WHERE request_id=?', [$request]);
            if ($existing) {
                if ((int) $existing[0]['quote_id'] !== (int) $quote ||
                    (int) $existing[0]['component_id'] !== (int) $component ||
                    (int) $existing[0]['quantity_id'] !== (int) $quantityId ||
                    (int) $existing[0]['created_by'] !== (int) $this->user['id'] ||
                    !hash_equals($existing[0]['request_hash'] ?? $existing[0]['hash'], $hash)) {
                    throw new DomainException('Identificador de gravação reutilizado para outros dados.');
                }
                return $this->selection($existing[0]);
            }
            $latest = $this->rows('SELECT id FROM app_vd_quote_versions WHERE quote_id=? AND component_id=? AND quantity_id=? ORDER BY id DESC LIMIT 1', [$quote, $component, $quantityId]);
            if ((int) ($input['expected_version'] ?? 0) !== (int) ($latest[0]['id'] ?? 0)) {
                throw new DomainException('Já existe uma versão mais recente. Atualize antes de gravar.');
            }
            $payload = ['version' => 5, 'savedAt' => gmdate('c'), 'calculation' => $calculation,
                'nativeComponent' => $native, 'nativeColors' => $this->rows('SELECT * FROM app_entity_278 WHERE parent_item_id=? ORDER BY id', [$component]),
                'nativeRubrics' => $this->rows('SELECT * FROM app_entity_280 WHERE parent_item_id=? ORDER BY id', [$component]),
                'product' => $product,
                'nativeCatalog' => $catalog, 'nativeDependencyRules' => $rules,
                'nativeDiscoveryOrder' => array_column(($calculation['source']??'')==='nonprint' ? $calculation['allOperations']['sourceOperations'] : $requiredOperations, 'operation_id'),
                'nativeDiscovery' => array_map(static function ($operation) {
                    return ['operation_id' => (int) $operation['operation_id'],
                        'type' => $operation['requirement_type'], 'reason' => $operation['requirement_reason'],
                        'requirement_parents' => $operation['requirement_parents'] ?? []];
                }, $requiredOperations)];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            if (strlen($json) > 1000000) throw new DomainException('Instantâneo excede o limite de segurança de 1 MB.');
            $snapshotHash = hash('sha256', $json);
            $number = (int) $this->rows('SELECT COALESCE(MAX(version_no),0)+1 next FROM app_vd_quote_versions WHERE quote_id=?', [$quote])[0]['next'];
            // Legacy field_6105 is NOT NULL; the immutable snapshot retains the actual null total.
            $id = $this->insertNative(388, ['parent_item_id' => $component, 'field_6101' => $quantityId ?: '',
                'field_6102' => $totals['quantity'], 'field_6103' => ($calculation['source'] ?? '') === 'dynamic' ? '' : ($calculation['plan']['id'] ?? ''),
                'field_6104' => $calculation['machineId'] ?? '', 'field_6105' => $totals['sale'] ?? 0,
                'field_6106' => $json]);
            $this->write('INSERT INTO app_vd_quote_versions(quote_id,component_id,quantity_id,quantity,version_no,state,hash,payload,native_id,request_id,request_hash,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$quote, $component, $quantityId, $totals['quantity'], $number, 'draft', $snapshotHash, $json, $id, $request, $hash, $this->user['id'], time()]);
            $versionId = (int) $this->db->insert_id;
            $this->write('UPDATE app_vd_quote_control SET revision=revision+1 WHERE quote_id=?', [$quote]);
            $this->write("UPDATE app_vd_quote_documents SET state='superseded' WHERE quote_id=? AND state IN('approved','send_failed')", [$quote]);
            $this->refreshComponentProgress($quote);
            $this->audit($quote, 'version_saved', ['version' => $versionId, 'hash' => $snapshotHash, 'native' => $id]);
            return $this->selection($this->rows('SELECT * FROM app_vd_quote_versions WHERE id=?', [$versionId])[0]);
        });
    }

    private function selection(array $version)
    {
        $payload = json_decode($version['payload'], true, 512, JSON_THROW_ON_ERROR);
        return ['id' => $version['native_id'], 'workflow_version' => $version['id'],
            'quantity_id' => $version['quantity_id'] ?: '', 'selected_quantity' => $version['quantity'],
            'plan_id' => ($payload['calculation']['source'] ?? '') === 'dynamic' ? '' : ($payload['calculation']['plan']['id'] ?? ''),
            'machine_id' => $payload['calculation']['machineId'] ?? '', 'base' => $payload['calculation']['allOperations']['sale'] ?? null,
            'details' => $version['payload']];
    }

    private function refreshComponentProgress($quote)
    {
        $this->write("UPDATE app_entity_262 c SET field_4241=CASE
            WHEN EXISTS(SELECT 1 FROM app_vd_quote_documents d WHERE d.quote_id=c.parent_item_id AND d.state<>'superseded') THEN 100
            WHEN EXISTS(SELECT 1 FROM app_vd_quote_versions v WHERE v.component_id=c.id AND v.quote_id=c.parent_item_id AND v.state='confirmed') THEN 50
            ELSE 5 END WHERE c.parent_item_id=?", [$quote]);
    }

    public function confirm($quote, $id)
    {
        $this->access(146, $quote, 'update');
        return $this->locked($quote, function () use ($quote, $id) {
            $version = $this->version($quote, $id);
            $this->access(262, $version['component_id'], 'update');
            $this->assertProductVersion($quote,$version);
            if ($version['state'] === 'confirmed') {
                $this->refreshComponentProgress($quote);
                return ['version' => $id, 'already_confirmed' => true];
            }
            $next = vd_workflow_transition($version['state'], 'confirm', false);
            $this->createAccess(279, $version['component_id']);
            $rows = vd_workflow_production_rows($version['snapshot']['calculation'], (int) $id,
                (int) $version['component_id'], (int) $version['quantity_id']);
            $origin = 'vd-workflow:v' . $id . ':q' . $version['quantity_id'];
            foreach ($rows['plans'] as $index => $part) {
                $plan = $part['plan'];
                $nativeId = $this->insertNative(279, ['parent_item_id' => $version['component_id'],
                    'field_4256' => ctype_digit((string) ($plan['id'] ?? '')) ? $plan['id'] : '', 'field_4257' => $plan['component_width'] ?? $version['snapshot']['nativeComponent']['field_4218'],
                    'field_4258' => $plan['component_height'] ?? $version['snapshot']['nativeComponent']['field_4219'],
                    'field_4259' => $plan['columns'] ?? $plan['suggestion']['columns'] ?? '',
                    'field_4260' => $plan['rows'] ?? $plan['suggestion']['rows'] ?? '',
                    'field_4261' => $plan['poses'], 'field_4262' => $plan['width'], 'field_4263' => $plan['height'],
                    'field_4264' => $plan['sheet_width'], 'field_4265' => $plan['sheet_height'],
                    'field_4266' => $plan['per_sheet'], 'field_4271' => $part['mode_id'],
                    'field_4272' => $part['machine_id'], 'field_4274' => $part['production']['forms'] ?? 1,
                    'field_4267' => '<p>' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '</p>',
                    'vd_workflow_version' => $id, 'vd_workflow_quantity' => $version['quantity_id']],
                    ['run_quantity' => $version['quantity']]);
                $this->write('INSERT INTO app_vd_quote_projection(version_id,entity_id,native_id,line_key,production) VALUES(?,?,?,?,0)', [$id, 279, $nativeId, 'plan:' . $index]);
            }
            $this->write('UPDATE app_vd_quote_versions SET state=? WHERE id=? AND state=?', [$next, $id, $version['state']]);
            $this->refreshComponentProgress($quote);
            $this->audit($quote, 'version_confirmed', ['version' => $id, 'plans' => count($rows['plans']), 'operations' => count($rows['operations'])]);
            return ['version' => $id, 'plans' => count($rows['plans']), 'operations' => count($rows['operations'])];
        });
    }

    public function reopen($quote, $id)
    {
        $this->access(146, $quote, 'update');
        if ((int) $this->user['group_id'] !== 0) {
            throw new DomainException('Só o administrador pode reabrir uma versão confirmada.');
        }
        return $this->locked($quote, function () use ($quote, $id) {
            $version = $this->version($quote, $id);
            if ($version['state'] !== 'confirmed') {
                throw new DomainException('Só versões confirmadas podem ser reabertas.');
            }
            foreach ($this->rows('SELECT snapshot FROM app_vd_quote_documents WHERE quote_id=?', [$quote]) as $document) {
                $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                if (in_array((int) $id, array_map('intval', $snapshot['version_ids'] ?? []), true)) {
                    throw new DomainException('Esta versão já pertence a um documento comercial; não pode ser reaberta.');
                }
            }
            $next = vd_workflow_transition($version['state'], 'revise', false);
            if ($this->write('UPDATE app_vd_quote_versions SET state=? WHERE id=? AND quote_id=? AND state=?',
                [$next, $id, $quote, $version['state']]) !== 1) {
                throw new RuntimeException('Estado da versão mudou durante a reabertura.');
            }
            $this->refreshComponentProgress($quote);
            $this->audit($quote, 'version_reopened', ['version' => (int) $id,
                'component' => (int) $version['component_id'], 'quantity_id' => (int) $version['quantity_id'],
                'history_preserved' => true]);
            return ['version' => (int) $id, 'state' => $next, 'history_preserved' => true];
        });
    }

    private function materializeOperations(array $version)
    {
        $id = (int) $version['id'];
        $component = (int) $version['component_id'];
        $this->createAccess(276, $component);
        $rows = vd_workflow_production_rows($version['snapshot']['calculation'], $id, $component,
            (int) $version['quantity_id']);
        $order = array_flip($version['snapshot']['nativeDiscoveryOrder'] ??
            array_column($version['snapshot']['calculation']['allOperations']['sourceOperations'] ?? [], 'operation_id'));
        usort($rows['operations'], static function ($a, $b) use ($order) {
            return ($order[$a['operation_id']] ?? PHP_INT_MAX) <=> ($order[$b['operation_id']] ?? PHP_INT_MAX);
        });
        $existing = [];
        foreach ($this->rows('SELECT p.line_key,p.native_id,r.parent_item_id,r.field_4287 operation_id FROM app_vd_quote_projection p LEFT JOIN app_entity_276 r ON r.id=p.native_id WHERE p.version_id=? AND p.entity_id=276', [$id]) as $projection) {
            $key = $projection['line_key'];
            if (!$projection['parent_item_id'] || (int) $projection['parent_item_id'] !== $component) {
                throw new RuntimeException('Projeção de operações sem linha nativa íntegra.');
            }
            $existing[$key] = (int) $projection['operation_id'];
        }
        $origin = 'vd-workflow:v' . $id . ':q' . $version['quantity_id'];
        foreach ($rows['operations'] as $index => $operation) {
            $lineKey = 'operation:' . $operation['operation_id'];
            if (isset($existing[$lineKey])) {
                if ($existing[$lineKey] !== (int) $operation['operation_id']) {
                    throw new RuntimeException('Projeção de operação não coincide com o instantâneo.');
                }
                unset($existing[$lineKey]);
                continue;
            }
            $sources = $version['snapshot']['calculation']['allOperations']['sourceOperations'] ?? [];
            $source = [];
            foreach ($sources as $candidate) if ((int) $candidate['operation_id'] === $operation['operation_id']) $source = $candidate;
            $fields = ['parent_item_id' => $component,
                'field_4287' => $operation['operation_id'], 'field_4288' => $source['equipment'] ?? '',
                'field_6179' => '1', 'field_6180' => ($index + 1) * 10, 'field_6181' => $origin,
                'vd_workflow_version' => $id, 'vd_workflow_quantity' => $version['quantity_id']];
            foreach ([6254 => 'adhesive_per_copy', 6255 => 'adhesive_width', 6256 => 'adhesive_height',
                6257 => 'adhesive_margin', 6258 => 'adhesive_purchase_price', 6259 => 'cut_pile_size',
                6260 => 'packaging_kg', 6263 => 'packaging_width', 6264 => 'packaging_length',
                6265 => 'packaging_height', 6266 => 'packaging_overlap'] as $field => $alias) {
                if (isset($source[$alias])) $fields['field_' . $field] = $source[$alias];
            }
            $inputs = $version['snapshot']['calculation']['allOperations']['inputs'] ?? [];
            foreach (['adhesive:' => [6254 => 'perCopy', 6255 => 'width', 6256 => 'height', 6257 => 'margin', 6258 => 'purchasePrice'],
                'packaging:' => [6263 => 'width', 6264 => 'length', 6265 => 'height', 6266 => 'overlap'],
                'lamination:' => [6125 => 'entry', 6126 => 'exit', 6127 => 'narrow'],
                'operation:' => [6259 => 'pileSize', 6260 => 'maxKg', 4288 => 'equipment']] as $prefix => $parameters) {
                $manual = $inputs[$prefix . ($source['id'] ?? '')] ?? [];
                foreach ($parameters as $field => $parameter) {
                    if (isset($manual[$parameter]) && $manual[$parameter] !== '') $fields['field_' . $field] = $manual[$parameter];
                }
            }
            foreach ([6125 => 'lamination_entry', 6126 => 'lamination_exit', 6127 => 'lamination_narrow'] as $field => $alias) {
                if (!isset($fields['field_' . $field]) && isset($source[$alias])) $fields['field_' . $field] = $source[$alias];
            }
            if ($operation['operation_id'] === 10) {
                $packaging = $inputs['packaging:' . ($source['id'] ?? '')] ?? [];
                $type = $packaging['type'] ?? $source['packaging_type'] ?? '1';
                $choices = $this->rows('SELECT id FROM app_fields_choices WHERE fields_id=6261 AND value=?', [$type]);
                if (count($choices) !== 1) throw new DomainException('Tipo de embalamento sem escolha nativa válida.');
                $fields['field_6261'] = $choices[0]['id'];
                if ((string) $type === '2') {
                    $box = $packaging['box'] ?? $source['packaging_box'] ?? '';
                    $choices = $this->rows('SELECT id FROM app_fields_choices WHERE fields_id=6262 AND value=?', [$box]);
                    if (count($choices) !== 1) throw new DomainException('Caixa sem escolha nativa válida.');
                    $fields['field_6262'] = $choices[0]['id'];
                }
            }
            $primary = $operation['lines'][0];
            if (!$fields['field_4288'] && isset($primary['parameters']['equipment']['machineId'])) {
                $fields['field_4288'] = $primary['parameters']['equipment']['machineId'];
            }
            $nativeId = $this->insertNative(276, $fields, [
                'run_quantity' => $version['quantity'], 'fixed_cost' => array_sum(array_column($operation['lines'], 'cost')),
                'fixed_sale' => array_sum(array_column($operation['lines'], 'sale')),
                'fixed_hours' => $primary['hours'] ?? '', 'fixed_quantity' => $primary['quantity'] ?? '',
                'calculation' => json_encode($operation['lines'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]);
            $this->write('INSERT INTO app_vd_quote_projection(version_id,entity_id,native_id,line_key,production) VALUES(?,?,?,?,0)',
                [$id, 276, $nativeId, $lineKey]);
        }
        if ($existing) throw new RuntimeException('A projeção contém operações fora do instantâneo.');
        return count($rows['operations']);
    }

    public function discount($quoteId)
    {
        $quote = $this->access(146, $quoteId);
        $customer = $this->access(26, (int) $quote['field_3923']);
        return $this->quoteDiscount($quote, $customer);
    }

    public function extraDiscount($quoteId)
    {
        $quote = $this->access(146, $quoteId);
        $sent = $this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND
            (sent_at IS NOT NULL OR state IN('sending','send_unknown')) ORDER BY id LIMIT 1", [$quoteId]);
        if ($sent) {
            $document = $this->document($quoteId, $sent[0]['id']);
            $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            return vd_workflow_discount_percent($snapshot['customer']['extraDiscountPercent'] ?? 0);
        }
        $fields = $this->commercialFields(146, $quote);
        return vd_workflow_discount_percent($fields['extra_discount'] ?? 0);
    }

    private function discountChange($quoteId, array $snapshot)
    {
        $approved = [vd_workflow_discount_percent($snapshot['customer']['discountPercent'] ?? 0),
            vd_workflow_discount_percent($snapshot['customer']['extraDiscountPercent'] ?? 0)];
        $current = [$this->discount($quoteId), $this->extraDiscount($quoteId)];
        return ['changed' => $approved !== $current, 'approved' => $approved, 'current' => $current];
    }

    private function quoteDiscount(array $quote, array $customer)
    {
        $sent = $this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND
            (sent_at IS NOT NULL OR state IN('sending','send_unknown')) ORDER BY id LIMIT 1", [$quote['id']]);
        if ($sent) {
            $document = $this->document($quote['id'], $sent[0]['id']);
            $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            return vd_workflow_discount_percent($snapshot['customer']['discountPercent'] ?? 0);
        }
        return vd_workflow_discount_percent($customer['field_5124'] ?? null);
    }

    private function customerSnapshot(array $quote)
    {
        $customer = $this->access(26, (int) $quote['field_3923']);
        $payment = $this->rows('SELECT id,name FROM app_fields_choices WHERE fields_id=5121 AND id=?', [$customer['field_5121']]);
        $vat = $this->rows('SELECT id,name FROM app_fields_choices WHERE fields_id=5125 AND id=?', [$customer['field_5125']]);
        if (!$payment || !$vat) throw new DomainException('Condições de pagamento ou IVA por definir no cliente.');
        $email = trim($quote['field_3925'] ?: $customer['field_228']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Email comercial inválido.');
        return ['id' => $customer['id'], 'name' => $customer['field_221'], 'email' => $email,
            'discountPercent' => $this->quoteDiscount($quote, $customer),
            'extraDiscountPercent' => $this->extraDiscount($quote['id']),
            'address' => $customer['field_223'], 'postalCode' => $customer['field_225'], 'city' => $customer['field_226'],
            'paymentTerms' => $payment[0]['name'], 'paymentChoice' => $payment[0]['id'],
            'vat' => $vat[0]['name'], 'vatChoice' => $vat[0]['id'], 'contact' => $quote['field_3924']];
    }

    private function commercialFields($entity, array $row)
    {
        $values = [];
        foreach ($this->rows('SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=?', [$entity]) as $field) {
            $values[$field['role']] = $row['field_' . $field['field_id']] ?? '';
        }
        return $values;
    }

    public function approve($quoteId, array $ids)
    {
        $quote = $this->access(146, $quoteId, 'update');
        if (!$this->approver()) throw new DomainException('Perfil não autorizado para aprovação.');
        return $this->locked($quoteId, function () use ($quoteId, $quote, $ids) {
            if ($this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND state IN('sending','send_unknown') LIMIT 1", [$quoteId])) {
                throw new DomainException('Existe um envio sem resultado confirmado. Reconcile o transporte antes de aprovar outra proposta.');
            }
            if (!$ids || count($ids) !== count(array_unique($ids))) throw new DomainException('Selecione versões únicas para aprovação.');
            $components = $this->rows('SELECT id FROM app_entity_262 WHERE parent_item_id=? ORDER BY id', [$quoteId]);
            if ($this->rows("SELECT quote_id FROM app_vd_quote_products WHERE quote_id=? AND type<>'standard'",[$quoteId])||
                $this->rows("SELECT component_id FROM app_vd_quote_product_components WHERE quote_id=? AND kind='assembly' LIMIT 1",[$quoteId])) {
                $graph=[];
                foreach($components as $component) {
                    $meta=vd_product_component($this->db,$quoteId,$component['id']);
                    if (!$meta) throw new DomainException('Configure todos os componentes do produto.');
                    $graph[]=['id'=>(int)$component['id'],'kind'=>$meta['kind'],'inputs'=>$meta['inputs']]+(isset($meta['stage'])?['stage'=>$meta['stage']]:[]);
                }
                vd_product_graph($graph);
                $assemblies=array_values(array_filter($graph,static function($c){return $c['kind']==='assembly';}));
                $expected=array_map('intval',array_column($components,'id'));
                if(array_filter($graph,static function($part){return isset($part['stage']);}))vd_product_stage_graph($graph);
                elseif (count($assemblies)!==1 || count($assemblies[0]['inputs'])!==count($expected)-1) throw new DomainException('Associe todos os componentes à assemblagem final antes de aprovar.');
            }
            $grouped = [];
            $descriptions = [];
            foreach ($ids as $id) {
                $v = $this->version($quoteId, $id);
                $this->assertProductVersion($quoteId,$v);
                if ($v['state'] !== 'confirmed') throw new DomainException('Só versões confirmadas podem ser aprovadas.');
                $key = (string) $v['quantity_id'];
                if (isset($grouped[$key][$v['component_id']])) throw new DomainException('Mais de uma versão do mesmo componente/quantidade.');
                $grouped[$key][$v['component_id']] = $v;
                $descriptions[$v['component_id']] = vd_workflow_commercial_component($v['snapshot']);
            }
            $alternatives = [];
            foreach ($grouped as $versions) {
                if (count($versions) !== count($components)) throw new DomainException('Cada alternativa deve conter todos os componentes.');
                $lines = [];
                $technical = [];
                $quantity = null;
                foreach ($versions as $v) {
                    $calc = $v['snapshot']['calculation'];
                    $technical[] = vd_workflow_commercial_component($v['snapshot']);
                    vd_workflow_validate_calculation($calc);
                    if ($quantity !== null && $quantity !== (float) $v['quantity']) throw new DomainException('Quantidades diferentes entre componentes.');
                    $quantity = (float) $v['quantity'];
                    foreach ($calc['allOperations']['lines'] as $line) {
                        $line['key'] = $v['component_id'] . ':' . $line['key'];
                        $lines[] = $line;
                    }
                }
                $alternatives[] = ['quantity' => $quantity, 'commercialComponents' => $technical, 'allOperations' => ['complete' => true,
                    'errors' => [], 'lines' => $lines, 'cost' => array_sum(array_column($lines, 'cost')),
                    'sale' => array_sum(array_column($lines, 'sale'))]];
            }
            usort($alternatives, static function ($a, $b) { return $a['quantity'] <=> $b['quantity']; });
            $customer = $this->customerSnapshot($quote);
            $issued = time();
            $commercialFields = $this->commercialFields(146, $quote);
            $languageValue = trim((string) ($commercialFields['language'] ?? ''));
            if (!in_array($languageValue, ['', '0', 'false', '1', 'true'], true)) {
                throw new DomainException('Idioma comercial inválido.');
            }
            $english = in_array($languageValue, ['1', 'true'], true);
            $observations = preg_replace('/<\s*(?:br\b[^>]*|\/(?:p|div|li))\s*>/i', "\n", $quote['field_3928']);
            $commercialQuote = ['id' => $quoteId, 'description' => $quote['field_3927'],
                'discountPercent' => $customer['discountPercent'],
                'extraDiscountPercent' => $customer['extraDiscountPercent'],
                'language' => $english ? 'en' : 'pt',
                'number' => (new DateTimeImmutable('@' . $quote['date_added']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y') . '/' . $quoteId,
                'observations' => trim(html_entity_decode(strip_tags($observations), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            if ($english) {
                if (!in_array((string) ($commercialFields['english_reviewed'] ?? ''), ['1','true'], true)) {
                    throw new DomainException('Reveja os textos em inglês e assinale a confirmação na identificação do orçamento.');
                }
                $description = trim((string) ($commercialFields['english_description'] ?? ''));
                $englishObservations = trim((string) ($commercialFields['english_observations'] ?? ''));
                if ($description === '' || ($commercialQuote['observations'] !== '' && $englishObservations === '')) {
                    throw new DomainException('Descrição ou observações comerciais em inglês por preencher.');
                }
                $commercialQuote['description'] = $description;
                $commercialQuote['observations'] = $englishObservations;
                $commercialQuote['englishReviewed'] = true;
                $paymentTranslations = [1369 => 'Payment on delivery', 1370 => '30 days', 1382 => 'Advance payment'];
                $payment = trim((string) ($commercialFields['english_payment'] ?? ''));
                $customer['paymentTerms'] = $payment !== '' ? $payment : ($paymentTranslations[(int) $customer['paymentChoice']] ?? '');
                if ($customer['paymentTerms'] === '') throw new DomainException('Condições de pagamento em inglês por preencher.');
                if ((int) $customer['vatChoice'] === 1371) $customer['vat'] = 'Exempt';
                foreach ($alternatives as &$alternative) {
                    foreach ($alternative['commercialComponents'] as &$technical) {
                        $native = $this->access(262, $technical['id']);
                        $fields = $this->commercialFields(262, $native);
                        $text = trim((string) ($fields['english_technical'] ?? ''));
                        if ($text === '') throw new DomainException('Descrição técnica em inglês por preencher no componente #' . $technical['id'] . '.');
                        $technical['description'] = $description;
                        $technical['englishTechnical'] = $text;
                    }
                    unset($technical);
                }
                unset($alternative);
                $descriptions = $alternatives[0]['commercialComponents'];
            }
            $html = vd_workflow_commercial_document($commercialQuote,
                $customer, array_values($descriptions), $alternatives, $issued, 15);
            $revision = (int) $this->rows('SELECT revision FROM app_vd_quote_control WHERE quote_id=?', [$quoteId])[0]['revision'];
            $snapshot = json_encode(['quote' => $commercialQuote, 'components' => array_values($descriptions),
                'commercialAlternatives' => array_map(static function ($alternative) use ($customer) {
                    $totals = vd_workflow_validate_calculation($alternative);
                    return ['quantity' => $alternative['quantity'], 'components' => $alternative['commercialComponents'],
                        'prices' => vd_workflow_discounted_price($totals['sale'], $totals['quantity'], $customer['discountPercent'],
                            $customer['extraDiscountPercent'])];
                }, $alternatives),
                'customer' => $customer, 'version_ids' => array_map('intval', $ids),
                'quantities' => array_column($alternatives, 'quantity')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $snapshot . "\n" . $html);
            foreach ($grouped as $versions) {
                foreach ($versions as $version) $this->materializeOperations($version);
            }
            $existing = $this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND revision=? AND snapshot=? AND html=? AND state='approved'", [$quoteId, $revision, $snapshot, $html]);
            if ($existing) {
                $this->refreshComponentProgress($quoteId);
                return ['document' => $existing[0]['id']];
            }
            $this->write("UPDATE app_vd_quote_documents SET state='superseded' WHERE quote_id=? AND state IN('approved','send_failed')", [$quoteId]);
            $this->write('INSERT INTO app_vd_quote_documents(quote_id,revision,state,hash,snapshot,html,issued_at,expires_at,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                [$quoteId, $revision, 'approved', $hash, $snapshot, $html, $issued,
                    (new DateTimeImmutable('@' . $issued))->setTimezone(new DateTimeZone('Europe/Lisbon'))->modify('+15 days')->getTimestamp(),
                    $this->user['id'], $issued]);
            $documentId = (int) $this->db->insert_id;
            $this->refreshComponentProgress($quoteId);
            $this->audit($quoteId, 'approved', ['document' => $documentId, 'versions' => $ids, 'hash' => $hash]);
            return ['document' => $documentId];
        });
    }

    public function document($quote, $id)
    {
        $this->access(146, $quote);
        if ((int) $id === 0) {
            $latest = $this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND state<>'superseded' ORDER BY id DESC LIMIT 1", [$quote]);
            if (!$latest) throw new DomainException('Ainda não existe uma proposta comercial aprovada. Aprove as versões antes de descarregar o PDF.');
            $id = (int) $latest[0]['id'];
        }
        $rows = $this->rows('SELECT * FROM app_vd_quote_documents WHERE quote_id=? AND id=?', [$quote, $id]);
        if (!$rows) throw new DomainException('Documento não encontrado.');
        $document = $rows[0];
        if (!hash_equals($document['hash'], hash('sha256', $document['snapshot'] . "\n" . $document['html']))) {
            throw new RuntimeException('Integridade do documento inválida.');
        }
        $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($snapshot['version_ids'] as $version) $this->version($quote, $version);
        return $document;
    }

    public function send($quote, $id, array $input = [])
    {
        $this->access(146, $quote, 'update');
        if (!$this->approver()) throw new DomainException('Perfil não autorizado para envio.');
        $document = $this->locked($quote, function () use ($quote, $id, $input) {
            $this->assertNotAdjudicated($quote);
            if ($this->rows("SELECT id FROM app_vd_quote_documents WHERE quote_id=? AND state IN('sending','send_unknown') LIMIT 1", [$quote])) {
                throw new DomainException('Um envio anterior está por reconciliar. Novo envio bloqueado para evitar duplicação.');
            }
            if ($this->rows("SELECT id FROM app_vd_quote_deliveries WHERE quote_id=? AND (email_state IN('sending','send_unknown') OR state IN('sending','send_unknown')) LIMIT 1", [$quote]) ||
                $this->rows("SELECT document_id FROM app_vd_quote_sms WHERE quote_id=? AND state IN('sending','send_unknown') LIMIT 1", [$quote])) {
                throw new DomainException('Existe um email ou SMS em curso ou sem resultado confirmado. Consulte a auditoria antes de voltar a enviar.');
            }
            $d = $this->document($quote, $id);
            $id = (int) $d['id'];
            $documentSnapshot = json_decode($d['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if ($d['sent_at'] === null && $this->discountChange($quote, $documentSnapshot)['changed']) {
                throw new DomainException('O desconto mudou após a aprovação. Aprove novamente com o desconto atual antes de enviar.');
            }
            foreach ($documentSnapshot['version_ids'] ?? [] as $versionId) {
                $version = $this->version($quote, (int) $versionId);
                vd_workflow_validate_calculation($version['snapshot']['calculation']);
            }
            $revision = (int) $this->rows('SELECT revision FROM app_vd_quote_control WHERE quote_id=?', [$quote])[0]['revision'];
            if ((int) $d['revision'] !== $revision || (int) $d['expires_at'] < time()) throw new DomainException('Documento desatualizado ou fora de validade.');
            $request = $input['request_id'] ?? '';
            if (!is_string($request) || ($request !== '' && !preg_match('/^[A-Za-z0-9_-]{16,80}$/', $request))) {
                throw new DomainException('Identificador do envio inválido.');
            }
            if ($d['state'] === 'sent') {
                if ($request === '') throw new DomainException('Confirme explicitamente um novo envio e os destinatários.');
                $next = 'sending';
            } else {
                $next = vd_workflow_transition($d['state'], 'begin_send', true);
            }
            if ($request === '') $request = 'delivery-' . bin2hex(random_bytes(16));
            if ($this->rows('SELECT id FROM app_vd_quote_deliveries WHERE request_id=?', [$request])) {
                throw new DomainException('Este pedido de envio já foi processado. Atualize e consulte o histórico; não repita automaticamente.');
            }
            $sender = ['email' => trim((string) ($this->user['email'] ?? '')),
                'name' => trim((string) ($this->user['name'] ?? ''))];
            if (!filter_var($sender['email'], FILTER_VALIDATE_EMAIL) || $sender['name'] === '') {
                throw new DomainException('Configure um email válido e o nome no perfil do utilizador que faz o envio.');
            }
            $nativeQuote = $this->access(146, $quote);
            $email = $input['email'] ?? $documentSnapshot['customer']['email'];
            $phoneInput = $input['phone'] ?? $nativeQuote['field_3926'];
            if (!is_string($email) || !is_string($phoneInput)) throw new DomainException('Destinatários inválidos.');
            $email = trim($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new DomainException('Introduza um email de destino válido.');
            $hasPhone = trim($phoneInput) !== '';
            $phone = preg_replace('/[\s().-]/', '', trim($phoneInput));
            if (strpos($phone, '00') === 0) $phone = '+' . substr($phone, 2);
            if (preg_match('/^9[1236]\d{7}$/', $phone)) $phone = '+351' . $phone;
            if (preg_match('/^[1-9]\d{9,14}$/', $phone)) $phone = '+' . $phone;
            if ($hasPhone && !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
                throw new DomainException('Preencha o campo SMS na identificação do orçamento com um telemóvel válido, incluindo indicativo internacional.');
            }
            $moduleId = 0;
            if ($hasPhone) {
                $field = $this->rows('SELECT configuration FROM app_fields WHERE id=3926 AND entities_id=146');
                if (!$field) throw new DomainException('Campo SMS do orçamento indisponível.');
                $configuration = json_decode($field[0]['configuration'], true, 512, JSON_THROW_ON_ERROR);
                $moduleId = (int) ($configuration['sms_module'] ?? 0);
                $module = $this->rows("SELECT id,type,module,is_active FROM app_ext_modules WHERE id=? AND type='sms' AND is_active=1", [$moduleId]);
                if (!$module || $module[0]['module'] !== 'clicksend') throw new DomainException('Configure o módulo ClickSend ativo no campo SMS do orçamento.');
            }
            $number = $documentSnapshot['quote']['number'] ??
                (new DateTimeImmutable('@' . $d['issued_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y') . '/' . $quote;
            $smsMessage = ($documentSnapshot['quote']['language'] ?? 'pt') === 'en' ?
                'Viana & Dias: quotation ' . $number . ' is complete and has been sent by email. Thank you.' :
                'Viana & Dias: o orçamento ' . $number . ' está concluído e foi enviado por email. Obrigado.';
            $d['pdf'] = vd_workflow_pdf($d);
            $d['pdf_filename'] = vd_workflow_pdf_filename($d);
            $d['email_body'] = vd_workflow_email_body($d, $sender);
            $this->write("INSERT INTO app_vd_quote_deliveries(document_id,quote_id,request_id,email,sender_email,sender_name,email_state,module_id,phone,message,state,created_at) VALUES(?,?,?,?,?,?,'sending',?,?,?,?,?)",
                [$id, $quote, $request, $email, $sender['email'], $sender['name'], $moduleId, $phone, $smsMessage, $hasPhone ? 'pending' : 'skipped_no_phone', time()]);
            $attempt = (int) $this->db->insert_id;
            $this->write('UPDATE app_vd_quote_documents SET state=? WHERE id=? AND state=?', [$next, $id, $d['state']]);
            $this->audit($quote, 'send_started', ['document' => $id, 'delivery' => $attempt, 'sender' => $sender, 'email' => $email, 'phone' => $phone]);
            $d['sender'] = $sender;
            $d['recipient_email'] = $email;
            $d['delivery_id'] = $attempt;
            return $d;
        });
        // The durable "sending" state is committed before transport; ambiguous outcomes never auto-retry.
        $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $id = (int) $document['id'];
        $attempt = $document['delivery_id'];
        $attachment = false;
        try {
            $attachment = tempnam(DIR_FS_TMP, 'vd_quote_');
            if ($attachment === false) throw new RuntimeException('Não foi possível criar o anexo PDF temporário.');
            if (file_put_contents($attachment, $document['pdf']) !== strlen($document['pdf']) || !is_readable($attachment)) {
                throw new RuntimeException('Não foi possível preparar o anexo PDF completo.');
            }
            $accepted = users::send_email(['send_directly' => true, 'force_send_from' => true,
                'to' => $document['recipient_email'], 'to_name' => $snapshot['customer']['name'],
                'from' => $document['sender']['email'], 'from_name' => $document['sender']['name'],
                'subject' => (($snapshot['quote']['language'] ?? 'pt') === 'en' ? 'Quotation ' : 'Proposta de orçamento ') .
                    ($snapshot['quote']['number'] ?? '#' . $quote),
                'body' => $document['email_body'], 'html_layout' => false,
                'attachments' => [$attachment => $document['pdf_filename']]]);
            $outcome = $accepted === true ? 'transport_accepted' : 'transport_unknown';
        } catch (Throwable $error) {
            error_log('VD quote email: ' . get_class($error) . ': ' . $error->getMessage());
            $outcome = 'transport_unknown';
        } finally {
            if ($attachment !== false && is_file($attachment) && !unlink($attachment)) {
                error_log('VD quote email: failed to remove temporary PDF attachment for delivery ' . $attempt);
            }
        }
        $state = vd_workflow_transition('sending', $outcome, true);
        $this->locked($quote, function () use ($quote, $id, $state, $attempt) {
            if ($this->write("UPDATE app_vd_quote_documents SET state=?,sent_at=? WHERE id=? AND state='sending'",
                [$state, $state === 'sent' ? time() : null, $id]) !== 1) throw new RuntimeException('Estado do envio em conflito.');
            if ($this->write("UPDATE app_vd_quote_deliveries SET email_state=? WHERE id=? AND email_state='sending'", [$state, $attempt]) !== 1) throw new RuntimeException('Estado da tentativa de envio em conflito.');
            if ($state === 'sent') {
                $this->write('UPDATE app_entity_146 SET field_2030=39 WHERE id=?', [$quote]);
                $this->audit($quote, 'quote_completed', ['document' => $id, 'status' => 39]);
            }
            $this->audit($quote, $state, ['document' => $id, 'delivery' => $attempt]);
        });
        if ($state !== 'sent') throw new DomainException('Transporte não confirmou o envio. Estado desconhecido bloqueado; não reenvie automaticamente.');
        $notification = $this->rows('SELECT state FROM app_vd_quote_deliveries WHERE id=?', [$attempt])[0];
        if ($notification['state'] === 'skipped_no_phone') {
            $this->locked($quote, function () use ($quote, $id) {
                $this->audit($quote, 'sms_skipped_no_phone', ['document' => $id]);
            });
            return ['document' => $id, 'state' => $state, 'sms_state' => 'skipped_no_phone',
                'message' => 'Proposta enviada apenas por email; não existe telemóvel atribuído ao orçamento.'];
        }
        $sms = $this->locked($quote, function () use ($quote, $id, $attempt) {
            if ($this->write("UPDATE app_vd_quote_deliveries SET state='sending',started_at=? WHERE id=? AND state='pending'", [time(), $attempt]) !== 1) {
                throw new DomainException('Email enviado; SMS já iniciado ou em conflito. Não repita o envio.');
            }
            $this->audit($quote, 'sms_started', ['document' => $id, 'delivery' => $attempt]);
            return $this->rows('SELECT * FROM app_vd_quote_deliveries WHERE id=?', [$attempt])[0];
        });
        try {
            $module = $this->rows("SELECT id,type,module,is_active FROM app_ext_modules WHERE id=? AND type='sms' AND is_active=1", [$sms['module_id']]);
            if (!$module || $module[0]['module'] !== 'clicksend') throw new DomainException('Módulo SMS alterado durante o envio.');
            modules::include_module($module[0], 'sms');
            $provider = new clicksend();
            $smsAccepted = $provider->send($sms['module_id'], [$sms['phone']], $sms['message']) === true;
        } catch (Throwable $error) {
            error_log('VD quote SMS: ' . get_class($error) . ': ' . $error->getMessage());
            $smsAccepted = false;
        }
        $smsState = $smsAccepted ? 'sent' : 'send_unknown';
        $this->locked($quote, function () use ($quote, $id, $smsState, $attempt) {
            if ($this->write("UPDATE app_vd_quote_deliveries SET state=?,completed_at=? WHERE id=? AND state='sending'",
                [$smsState, time(), $attempt]) !== 1) throw new RuntimeException('Email enviado; estado do SMS em conflito.');
            $this->audit($quote, 'sms_' . $smsState, ['document' => $id, 'delivery' => $attempt]);
        });
        return ['document' => $id, 'state' => $state, 'sms_state' => $smsState,
            'message' => $smsAccepted ? 'Proposta enviada por email e SMS aceite pelo operador.' :
                'Proposta enviada por email, mas o operador não confirmou o SMS. Não repita o envio; consulte a auditoria.'];
    }

    public function approvalFile($quote, $id)
    {
        $this->document($quote, $id);
        $files = $this->rows('SELECT * FROM app_vd_quote_approval_files WHERE document_id=? AND quote_id=?', [$id, $quote]);
        if (!$files) throw new DomainException('Documento de aprovação não encontrado.');
        if (!hash_equals($files[0]['hash'], hash('sha256', $files[0]['content']))) {
            throw new RuntimeException('Integridade do documento de aprovação inválida.');
        }
        return $files[0];
    }

    public function accept($quote, $id, $quantity, $evidence, ?array $attachment = null, $deliveryDate = null)
    {
        $this->access(146, $quote, 'update');
        if (!$this->approver()) throw new DomainException('Perfil não autorizado para registar aceitação.');
        if (!is_string($evidence)) throw new DomainException('Referência da aceitação inválida.');
        $evidence = trim($evidence);
        $delivery = vd_production_delivery_date($deliveryDate);
        return $this->locked($quote, function () use ($quote, $id, $quantity, $evidence, $attachment, $delivery) {
            $this->assertNotAdjudicated($quote);
            $d = $this->document($quote, $id);
            $next = vd_workflow_transition($d['state'], 'accept', true);
            $snapshot = json_decode($d['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!in_array((float) $quantity, array_map('floatval', $snapshot['quantities']), true)) throw new DomainException('Quantidade não apresentada ao cliente.');
            if ((int) $d['expires_at'] < time()) throw new DomainException('Proposta fora de validade; emita nova aprovação.');
            $revision = (int) $this->rows('SELECT revision FROM app_vd_quote_control WHERE quote_id=?', [$quote])[0]['revision'];
            if ($revision !== (int) $d['revision']) throw new DomainException('Existe uma nova revisão do orçamento.');
            $fileDetail = null;
            if ($attachment !== null) {
                if (!isset($attachment['name'], $attachment['content']) ||
                    !is_string($attachment['name']) || !is_string($attachment['content'])) {
                    throw new DomainException('Anexo de aprovação inválido.');
                }
                $name = basename(str_replace('\\', '/', $attachment['name']));
                if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw new DomainException('Nome do anexo inválido.');
                }
                $size = strlen($attachment['content']);
                if ($size === 0 || $size > 5 * 1024 * 1024) throw new DomainException('O anexo deve ter até 5 MB e não pode estar vazio.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($attachment['content']);
                $extensions = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
                if (!isset($extensions[$mime]) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions[$mime], true)) {
                    throw new DomainException('Anexe um documento PDF ou uma imagem JPG/PNG válida.');
                }
                $hash = hash('sha256', $attachment['content']);
                $this->write('INSERT INTO app_vd_quote_approval_file_history(document_id,quote_id,name,mime,hash,content,uploaded_by,uploaded_at,native_name) SELECT document_id,quote_id,name,mime,hash,content,uploaded_by,uploaded_at,native_name FROM app_vd_quote_approval_files WHERE document_id=?', [$id]);
                $this->write('DELETE FROM app_vd_quote_approval_files WHERE document_id=?', [$id]);
                $this->write('INSERT INTO app_vd_quote_approval_files(document_id,quote_id,name,mime,hash,content,uploaded_by,uploaded_at) VALUES(?,?,?,?,?,?,?,?)',
                    [$id, $quote, $name, $mime, $hash, $attachment['content'], $this->user['id'], time()]);
                $fileDetail = ['name' => $name, 'hash' => $hash, 'size' => $size];
            }
            $this->write('UPDATE app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id SET p.production=0 WHERE v.quote_id=?', [$quote]);
            $projectionRows = $this->rows('SELECT p.entity_id,p.native_id,p.version_id,v.quantity FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=?', [$quote]);
            $this->write('SET @vd_workflow_production_mutation=1');
            foreach ($projectionRows as $projection) {
                $entity = (int) $projection['entity_id'];
                $this->access($entity, $projection['native_id'], 'update');
                $field = $this->rows("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=? AND role='production'", [$entity])[0]['field_id'];
                $selected = in_array((int) $projection['version_id'], array_map('intval', $snapshot['version_ids']), true) &&
                    (float) $projection['quantity'] === (float) $quantity;
                $this->write("UPDATE app_entity_$entity SET field_$field=? WHERE id=?", [$selected ? '1' : '0', $projection['native_id']]);
            }
            $chosen = [];
            foreach ($snapshot['version_ids'] as $versionId) {
                $v = $this->version($quote, $versionId);
                if ((float) $v['quantity'] !== (float) $quantity) continue;
                $this->access(262, $v['component_id'], 'update');
                $this->write('UPDATE app_vd_quote_projection SET production=1 WHERE version_id=?', [$versionId]);
                $chosen[] = $versionId;
            }
            $this->write('UPDATE app_vd_quote_documents SET state=?,accepted_quantity=?,delivery_date=?,accepted_at=? WHERE id=?',
                [$next, $quantity, $delivery->format('Y-m-d'), time(), $id]);
            $this->write('UPDATE app_vd_quote_control SET production_document=?,production_quantity=? WHERE quote_id=?', [$id, $quantity, $quote]);
            $this->audit($quote, 'customer_accepted', ['document' => $id, 'quantity' => $quantity, 'versions' => $chosen, 'evidence' => $evidence,
                'delivery_date' => $delivery->format('Y-m-d'), 'approval_file' => $fileDetail]);
            $order = $this->materializeProductionOrder($quote, (int) $id);
            return ['document' => $id, 'production_versions' => $chosen, 'production_order' => $order,
                'message' => 'Adjudicação registada e OP ' . $order['number'] . ' criada em estado Aberto.'];
        });
    }

    public function productionOrder($quote)
    {
        $stored = $this->rows('SELECT * FROM app_vd_quote_production_orders WHERE quote_id=?', [$quote]);
        if (!$stored) return null;
        $stored = $stored[0];
        if (!hash_equals($stored['hash'], hash('sha256', $stored['snapshot']))) {
            throw new RuntimeException('Integridade da origem da OP inválida.');
        }
        $order = $this->access(121, (int) $stored['order_id']);
        if ((int) $order['field_5972'] !== (int) $quote) throw new RuntimeException('OP sem vínculo íntegro ao orçamento.');
        return ['id' => (int) $order['id'], 'number' => $order['field_2000'],
            'document' => (int) $stored['document_id'], 'quantity' => (float) $stored['quantity'],
            'unassigned_materials' => (int) $stored['unassigned_materials'],
            'delivery_date' => (int) $order['field_1992'] > 0 ?
                (new DateTimeImmutable('@' . $order['field_1992']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y-m-d') : null,
            'url' => 'index.php?module=items/info&path=121-' . (int) $order['id']];
    }

    public function createProductionOrder($quote)
    {
        $this->access(146, $quote, 'update');
        if (!$this->approver()) throw new DomainException('Perfil não autorizado para gerar a OP.');
        return $this->locked($quote, function () use ($quote) {
            $control = $this->rows('SELECT production_document FROM app_vd_quote_control WHERE quote_id=?', [$quote])[0];
            if (!$control['production_document']) throw new DomainException('Adjudique o orçamento antes de gerar a OP.');
            return $this->materializeProductionOrder($quote, (int) $control['production_document']);
        });
    }

    public function setProductionDeliveryDate($quote, $date)
    {
        $this->access(146, $quote, 'update');
        if (!$this->approver()) throw new DomainException('Perfil não autorizado para definir a entrega.');
        $delivery = vd_production_delivery_date($date);
        return $this->locked($quote, function () use ($quote, $delivery) {
            $order = $this->productionOrder($quote);
            if (!$order) throw new DomainException('Não existe OP para definir a entrega.');
            $native = $this->access(121, $order['id'], 'update');
            if ((int) $native['field_1992'] > 0) throw new DomainException('A OP já tem data de entrega. Altere-a na ficha da OP.');
            $this->write('UPDATE app_entity_121 SET field_1992=? WHERE id=?', [$delivery->getTimestamp(), $order['id']]);
            $this->write('UPDATE app_vd_quote_documents SET delivery_date=? WHERE id=? AND quote_id=?',
                [$delivery->format('Y-m-d'), $order['document'], $quote]);
            vd_production_schedule_order($this->db, $order['id'], true, $this->user['id']);
            $this->audit($quote, 'production_delivery_date_set', ['order' => $order['id'], 'document' => $order['document'],
                'delivery_date' => $delivery->format('Y-m-d'), 'previous_date' => null]);
            return ['message' => 'Data prevista de entrega guardada na OP.', 'production_order' => $this->productionOrder($quote)];
        });
    }

    public function productionPrintSummary($id)
    {
        $order = $this->access(121, $id);
        foreach ([159,145,158,170,161,172] as $entity) {
            if (!users::has_access_to_entity($entity, 'view')) throw new DomainException('Sem acesso aos dados necessários para imprimir a OP.');
        }
        $materialSchema = vd_component_material_schema($this->db);
        if (!users::has_access_to_entity($materialSchema['entity'], 'view')) throw new DomainException('Sem acesso aos materiais dos componentes.');
        $origin = $this->rows('SELECT document_id,quantity FROM app_vd_quote_production_orders WHERE order_id=?', [$id]);
        if (!$origin) throw new DomainException('Esta OP não tem uma origem adjudicada neste fluxo.');
        $escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
        $number = static function ($value, $precision = 4) {
            return rtrim(rtrim(number_format((float) $value, $precision, ',', ' '), '0'), ',');
        };
        $delivery = (int) $order['field_1992'] > 0 ?
            (new DateTimeImmutable('@' . $order['field_1992']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y') : 'POR DEFINIR';
        $plannedStart = $this->rows('SELECT ' . vd_production_planned_start_sql('e.id', 'e.field_2471') .
            ' AS planned_start FROM app_entity_121 e WHERE e.id=?', [$id])[0]['planned_start'];
        $start = (int) $plannedStart > 0 ?
            (new DateTimeImmutable('@' . $plannedStart))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i') : 'POR DEFINIR';
        $customer = $this->access(26, (int) $order['field_1979']);
        $html = '<article class="vd-production-print"><h1>ORDEM DE PRODUÇÃO · ' . $escape($order['field_2000']) .
            '</h1><h2>' . $escape($order['field_1994']) . '</h2><table><tbody><tr><th>Cliente</th><td>' .
            $escape($customer['field_221']) . '</td><th>Entrega prevista</th><td><strong>' . $delivery .
            '</strong></td></tr><tr><th>Início previsto da produção</th><td colspan="3"><strong>' . $start .
            '</strong></td></tr><tr><th>Quantidade</th><td>' . $number($order['field_1993']) .
            ' un.</td><th>Orçamento / proposta</th><td>' . $escape($order['field_2022']) . ' / #' .
            (int) $origin[0]['document_id'] . '</td></tr><tr><th>Referência</th><td>' . $escape($order['field_1999']) .
            '</td><th>Contacto</th><td>' . $escape($order['field_4442']) . '</td></tr></tbody></table>';
        $observations = trim(html_entity_decode(strip_tags(preg_replace('/<\s*(br\b[^>]*|\/p|\/div)>/i', "\n", $order['field_1991'])), ENT_QUOTES, 'UTF-8'));
        if ($observations !== '') $html .= '<h3>Observações do cliente</h3><p>' . nl2br($escape($observations)) . '</p>';
        foreach ($this->rows('SELECT id FROM app_entity_159 WHERE parent_item_id=? ORDER BY id', [$id]) as $componentRow) {
            $component = $this->access(159, $componentRow['id']);
            $componentId = (int) $component['id'];
            $source=$this->rows("SELECT snapshot FROM app_vd_quote_production_rows WHERE order_id=? AND entity_id=159 AND native_id=?",[$id,$componentId]);
            $product=$source ? (json_decode($source[0]['snapshot'],true,512,JSON_THROW_ON_ERROR)['product']??null) : null;
            $nonprint=$product && $product['kind']!=='printed';
            $html .= '<section><h2>Componente · ' . $escape($component['field_2243']) . '</h2><p><strong>' .
                $number($component['field_2242']) . ' un.</strong> · ' .
                (($product['kind']??'')==='assembly' ? $escape(vd_product_stage_names()[$product['stage']??'assembly']) : $number($component['field_2236']) . ' × ' .
                $number($component['field_2237']) . ' mm') . ' · referência ' . $escape($component['field_2580']) . '</p>';
            if(isset($product['requirements'])) {
                $html.='<p><strong>Requisitos fixados:</strong> '.$escape(implode('; ',array_column($product['requirements']['operations']??[],'name'))).'</p>';
                $details=$product['requirements']['product_details']??[];
                if(!empty($details['finishing']))$html.='<p><strong>Indicações dos acabamentos:</strong> '.nl2br($escape($details['finishing'])).'</p>';
                if(isset($details['total_pages']))$html.='<p><strong>Páginas totais do produto (incluindo capa):</strong> '.$escape($details['total_pages']).'</p>';
                if(isset($details['box'])) {
                    $box=$details['box'];
                    $html.='<p><strong>Modelo da caixa:</strong> '.$escape(vd_config_box_models()[$box['model']]).' · '.
                        $escape($box['length_mm'].' × '.$box['width_mm'].' × '.$box['height_mm']).' mm ('.
                        ($box['measurement']==='internal'?'interiores':'exteriores').'). '.$escape($box['model_description']??'').
                        ' Confirmar desenho/cortante; vista 3D apenas ilustrativa.</p>';
                }
                if(($product['stage']??'')==='packaging')$html.='<p>Aplicar após o produto pronto; confirmar espessura/capacidade da embalagem individual.</p>';
            }
            if (($product['kind']??'')==='assembly') {
                $names=[];
                foreach($this->rows('SELECT c.id,c.field_2243 name FROM app_vd_production_component_inputs i JOIN app_entity_159 c ON c.id=i.input_component_id WHERE i.component_id=? ORDER BY c.id',[$componentId]) as $input) {
                    $this->access(159,$input['id']);
                    $names[]=$escape($input['name']);
                }
                $html.='<p><strong>Entradas:</strong> '.implode(' + ',$names).'. Início bloqueado até à conclusão das operações destes componentes.</p>';
            }
            $rubrics = $this->rows('SELECT id FROM app_entity_145 WHERE parent_item_id=? ORDER BY id', [$componentId]);
            if ($rubrics) $html .= '<h3>Rubricas</h3><table><thead><tr><th>Código</th><th>Descrição</th><th>Quantidade</th></tr></thead><tbody>';
            foreach ($rubrics as $rubricRow) {
                $rubric = $this->access(145, $rubricRow['id']);
                $html .= '<tr><td>' . $escape($rubric['field_2021']) . '</td><td>' . $escape($rubric['field_2020']) .
                    '</td><td>' . $number($rubric['field_2019']) . '</td></tr>';
            }
            if ($rubrics) $html .= '</tbody></table>';
            $html .= '<div class="vd-production-columns"><div class="vd-production-operations">' . ($nonprint?'<p>Sem impressão/imposição.':'<h3>Cores</h3><p>');
            $colors = [];
            $pantoneField = $this->rows("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=172 AND role='op_pantone'");
            foreach ($this->rows('SELECT id FROM app_entity_172 WHERE parent_item_id=? ORDER BY id', [$componentId]) as $colorRow) {
                $color = $this->access(172, $colorRow['id']);
                $name = $this->rows('SELECT name FROM app_global_lists_choices WHERE id=?', [$color['field_2466']]);
                $colors[] = $escape(($name[0]['name'] ?? $color['field_2466']) .
                    ($pantoneField ? ' ' . ($color['field_' . $pantoneField[0]['field_id']] ?? '') : ''));
            }
            $html .= implode(' · ', $colors) . '</p><h3>Sequência de operações</h3><table><thead><tr><th>Pos.</th><th>Operação</th></tr></thead><tbody>';
            foreach ($this->rows('SELECT id FROM app_entity_170 WHERE parent_item_id=? ORDER BY field_2430+0,id', [$componentId]) as $operationRow) {
                $operation = $this->access(170, $operationRow['id']);
                $html .= '<tr><td>' . $escape($operation['field_2430']) . '</td><td>' . $escape($operation['field_2450']) .
                    '</td></tr>';
            }
            $html .= '</tbody></table></div><div class="vd-production-plan">' .
                $this->productionPresentation(159, $componentId, true, 'plans') . '</div></div>' .
                $this->productionPresentation(159, $componentId, true, 'materials') . '</section>';
        }
        $html .= '<h2>Controlo de produção e entrega</h2><table class="vd-signoff"><thead><tr><th>Controlo final / quantidade conforme</th><th>Embalamento / volumes</th><th>Responsável / data</th></tr></thead><tbody><tr><td></td><td></td><td></td></tr></tbody></table>' .
            '<p class="vd-print-meta">Emitido em ' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('d/m/Y H:i') .
            ' · Ficha de acompanhamento. Materiais e imposição previstos a partir da adjudicação; não confirma reservas nem movimentos de stock. Sem valores comerciais.</p></article>';
        return $html;
    }

    public function productionPresentation($entity, $id, $forPrint = false, $section = 'all')
    {
        if (!in_array($section, ['all','plans','materials'], true)) throw new DomainException('Secção de produção desconhecida.');
        if (!in_array($entity, [121,159,158], true)) throw new DomainException('Entidade de produção desconhecida.');
        $native = $this->access($entity, $id);
        if ($entity === 121) {
            $orderId = (int) $id;
            $componentId = null;
        } else {
            $componentId = $entity === 159 ? (int) $id : (int) $native['parent_item_id'];
            $component = $this->access(159, $componentId);
            $orderId = (int) $component['parent_item_id'];
            $this->access(121, $orderId);
        }
        $orderSource = $this->rows('SELECT snapshot,hash FROM app_vd_quote_production_orders WHERE order_id=?', [$orderId]);
        if (!$orderSource) return '';
        if (!hash_equals($orderSource[0]['hash'], hash('sha256', $orderSource[0]['snapshot']))) throw new RuntimeException('Origem da OP inválida.');
        $origin = json_decode($orderSource[0]['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $versions = array_column($origin['versions'], null, 'id');
        $html = '<div class="vd-production-presentation">';
        if ($section !== 'plans' && $entity !== 158 && users::has_access_to_entity(161, 'view')) {
            $materialSchema = vd_component_material_schema($this->db);
            if (!users::has_access_to_entity($materialSchema['entity'], 'view')) throw new DomainException('Sem acesso aos materiais dos componentes.');
            $html .= '<h3>Materiais da OP</h3><table class="table table-bordered vd-production-materials"><thead><tr><th>Operação</th><th>Material / referência</th><th>Quantidade prevista</th></tr></thead><tbody>';
            $materials = $this->rows('SELECT r.*,o.parent_item_id component_id FROM app_vd_quote_production_rows r JOIN app_entity_170 o ON o.id=r.parent_id WHERE r.order_id=? AND r.entity_id=161 ORDER BY o.parent_item_id,o.field_2430+0,r.id', [$orderId]);
            $materialRows = [];
            $inkGroups = [];
            foreach ($materials as $row) {
                if ($componentId !== null && (int) $row['component_id'] !== $componentId) continue;
                $this->access(159, $row['component_id']);
                $operation = $this->access(170, $row['parent_id']);
                $this->access(161, $row['native_id']);
                $projection = $this->rows('SELECT native_id FROM app_vd_component_material_rows WHERE source_id=?', [$row['native_id']]);
                if (!$projection) throw new RuntimeException('Material em falta na entidade do componente.');
                $componentMaterial = $this->access($materialSchema['entity'], $projection[0]['native_id']);
                if ((int) $componentMaterial['parent_item_id'] !== (int) $row['component_id']) throw new RuntimeException('Material associado a outro componente.');
                if (!hash_equals($row['hash'], hash('sha256', $row['snapshot']))) throw new RuntimeException('Origem do material inválida.');
                $material = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                $link = 'index.php?module=items/info&path=121-' . $orderId . '/159-' . (int) $row['component_id'] .
                    '/' . $materialSchema['entity'] . '-' . (int) $projection[0]['native_id'];
                $escape = static function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
                if (strpos($row['line_key'], 'color:') === 0) {
                    $group = 'ink:' . (int) $row['parent_id'];
                    $quantity = rtrim(rtrim(number_format((float) ($material['quantity'] ?? 0), 6, ',', ' '), '0'), ',');
                    $name = preg_replace('/^Tinta\s*[—-]\s*/u', '', $material['name'] ?? $row['line_key']);
                    $inkGroups[$group][] = '<a href="' . $link . '">' . $escape($name) . '</a> = ' .
                        $escape($quantity) . ' ' . $escape($material['unit'] ?? '');
                    if (!isset($materialRows[$group])) $materialRows[$group] = $escape($operation['field_2450']);
                    continue;
                }
                $materialRows['material:' . $row['native_id']] = '<tr><td>' . $escape($operation['field_2450']) .
                    '</td><td><a href="' . $link . '">' . $escape($material['article']['name'] ?? $material['name'] ?? $row['line_key']) .
                    '</a>' . (!empty($material['article']['internal_id']) ? ' · ref. ' . $escape($material['article']['internal_id']) : '') .
                    '</td><td>' . $escape(rtrim(rtrim(number_format((float) ($material['quantity'] ?? $material['sheets'] ?? 0), 6, ',', ' '), '0'), ',')) .
                    ' ' . $escape($material['unit'] ?? 'folhas') . '</td></tr>';
            }
            foreach ($materialRows as $key => $content) {
                $html .= isset($inkGroups[$key]) ? '<tr><td>' . $content .
                    '</td><td colspan="2">Tinta — ' . implode('; ', $inkGroups[$key]) . '</td></tr>' : $content;
            }
            $html .= '</tbody></table><p>Materiais existentes em Componentes → Materiais, com ligação à respetiva operação. Consumos previstos, sem reservas ou saídas de stock.</p>';
        }
        if ($section !== 'materials' && users::has_access_to_entity(158, 'view')) {
            foreach ($this->rows('SELECT * FROM app_vd_quote_production_rows WHERE order_id=? AND entity_id=158 ORDER BY id', [$orderId]) as $row) {
                if ($entity === 158 && (int) $row['native_id'] !== (int) $id) continue;
                if ($componentId !== null && (int) $row['parent_id'] !== $componentId) continue;
                $this->access(158, $row['native_id']);
                $this->access(159, $row['parent_id']);
                if (!hash_equals($row['hash'], hash('sha256', $row['snapshot']))) throw new RuntimeException('Origem do plano inválida.');
                $detail = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
                $source = $versions[$row['version_id']]['snapshot'];
                $partIndex = (int) substr($row['line_key'], strlen('plan:'));
                $sourcePart = $source['calculation']['parts'][$partIndex] ?? $source['calculation'];
                $detail['mode_id'] = $detail['mode_id'] ?? $sourcePart['modeId'] ?? $source['calculation']['modeId'] ?? '';
                $detail['plan'] += ['component_width' => $source['nativeComponent']['field_4218'],
                    'component_height' => $source['nativeComponent']['field_4219']];
                $graphic = vd_production_plan_graphic($detail);
                if ($entity === 158) $graphic = str_replace('height:330px', 'height:65vh', $graphic);
                $html .= '<h3>Plano de impressão · imposição aprovada</h3>' . $graphic;
                if ($entity !== 158 && !$forPrint) {
                    $html .= '<p><a class="btn btn-default btn-sm" href="index.php?module=items/info&path=121-' .
                        $orderId . '/159-' . (int) $row['parent_id'] . '/158-' . (int) $row['native_id'] . '">Abrir / ampliar plano</a></p>';
                }
            }
        }
        return $html . '</div>';
    }

    private function insertProductionNative($quote, $order, $version, $entity, $parent, $key, array $fields, array $snapshot, array $extra = [])
    {
        $this->createAccess($entity, $parent);
        $fields['parent_item_id'] = $parent;
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        foreach ($this->rows("SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=? AND role LIKE 'op_%'", [$entity]) as $field) {
            $values = ['op_origin' => 'Proposta ' . $order['document'] . ' · versão ' . $version,
                'op_snapshot' => $json] + $extra;
            if (array_key_exists($field['role'], $values)) $fields['field_' . $field['field_id']] = $values[$field['role']];
        }
        $nativeId = $this->insertNative($entity, $fields);
        $this->write('INSERT INTO app_vd_quote_production_rows(quote_id,order_id,version_id,entity_id,native_id,parent_id,line_key,snapshot,hash) VALUES(?,?,?,?,?,?,?,?,?)',
            [$quote, $order['id'], $version, $entity, $nativeId, $parent, $key, $json, hash('sha256', $json)]);
        return $nativeId;
    }

    private function materializeProductionOrder($quote, $documentId)
    {
        $document = $this->document($quote, $documentId);
        if ($document['state'] !== 'accepted') throw new DomainException('A proposta não está adjudicada.');
        $commercial = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $control = $this->rows('SELECT production_document,production_quantity FROM app_vd_quote_control WHERE quote_id=?', [$quote])[0];
        if ((int) $control['production_document'] !== $documentId ||
            (float) $control['production_quantity'] !== (float) $document['accepted_quantity']) {
            throw new RuntimeException('A seleção de produção não coincide com a adjudicação.');
        }
        $existing = $this->productionOrder($quote);
        if ($existing) {
            if ($existing['document'] !== $documentId || $existing['quantity'] !== (float) $document['accepted_quantity']) {
                throw new DomainException('Já existe OP para outra adjudicação; trate a OP primeiro.');
            }
            $origin = json_decode($this->rows('SELECT snapshot FROM app_vd_quote_production_orders WHERE quote_id=?', [$quote])[0]['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            $actualDependencies=$this->rows('SELECT component_id,input_component_id FROM app_vd_production_component_inputs WHERE order_id=? ORDER BY component_id,input_component_id',[$existing['id']]);
            $expectedDependencies=$origin['component_dependencies']??[];
            usort($expectedDependencies,static function($a,$b){return [$a['component_id'],$a['input_component_id']]<=>[$b['component_id'],$b['input_component_id']];});
            if ($actualDependencies != $expectedDependencies) throw new RuntimeException('Dependências da assemblagem não coincidem com a criação original.');
            $manifest = $this->rows('SELECT entity_id,native_id,parent_id,hash FROM app_vd_quote_production_rows WHERE order_id=? ORDER BY id', [$existing['id']]);
            if ($manifest !== $origin['native_rows']) throw new RuntimeException('A estrutura da OP não coincide com a criação original.');
            foreach ($this->rows('SELECT * FROM app_vd_quote_production_rows WHERE order_id=?', [$existing['id']]) as $row) {
                $entity = (int) $row['entity_id'];
                if (!in_array($entity, [159,145,158,170,161,172], true)) throw new RuntimeException('Entidade de produção inválida.');
                if (vd_production_rubric_archived($this->db, $row)) continue;
                if ($entity === 170 && $row['line_key'] === 'operation:44') {
                    $archived = $this->rows('SELECT * FROM app_vd_production_excluded_operations WHERE native_id=? AND order_id=? AND operation_id=44', [$row['native_id'], $existing['id']]);
                    if ($archived) {
                        if (!hash_equals($archived[0]['hash'], hash('sha256', $archived[0]['native_snapshot'])) ||
                            $this->rows('SELECT id FROM app_entity_170 WHERE id=?', [$row['native_id']])) throw new RuntimeException('Arquivo administrativo em conflito.');
                        continue;
                    }
                }
                $native = $this->access($entity, (int) $row['native_id']);
                if ((int) $native['parent_item_id'] !== (int) $row['parent_id'] ||
                    !hash_equals($row['hash'], hash('sha256', $row['snapshot']))) {
                    throw new RuntimeException('Estrutura ou origem da OP em conflito; não será criada outra OP.');
                }
                if ($entity === 161) {
                    $schema = vd_component_material_schema($this->db);
                    $operation = $this->access(170, $row['parent_id']);
                    $this->createAccess($schema['entity'], $operation['parent_item_id']);
                    vd_component_material_project($this->db, $row['native_id'], $this->user['id']);
                }
            }
            return $existing + ['already_created' => true];
        }
        if ($this->rows('SELECT id FROM app_entity_121 WHERE field_5972=?', [$quote])) {
            throw new DomainException('Já existe uma OP nativa ligada a este orçamento. Verifique-a antes de gerar outra.');
        }
        $dieChoices = ['1248' => 504, '1249' => 505, '1565' => 1473];
        $vinco = $this->rows("SELECT id FROM app_fields_choices WHERE fields_id=2238 AND name='Vinco'");
        if (count($vinco) === 1) $dieChoices['1608'] = (int) $vinco[0]['id'];
        $selected = [];
        $components = [];
        foreach ($commercial['version_ids'] as $id) {
            $version = $this->version($quote, $id);
            if ((float) $version['quantity'] !== (float) $document['accepted_quantity']) continue;
            if (isset($components[$version['component_id']])) throw new DomainException('Componentes repetidos na alternativa adjudicada.');
            $components[$version['component_id']] = vd_production_component($version, $dieChoices);
            $selected[$version['component_id']] = $version;
        }
        if (!$components) throw new DomainException('A alternativa adjudicada não tem componentes.');
        $nativeQuote = $this->access(146, $quote);
        $this->createAccess(121, 0);
        $orderId = $this->insertNative(121, ['parent_item_id' => 0, 'field_5972' => $quote,
            'field_1979' => $commercial['customer']['id'], 'field_2010' => $commercial['customer']['contact'],
            'field_1993' => $document['accepted_quantity'], 'field_1994' => $commercial['quote']['description'],
            'field_1991' => nl2br(htmlspecialchars($commercial['quote']['observations'], ENT_QUOTES, 'UTF-8')),
            'field_2022' => $commercial['quote']['number'], 'field_2280' => $nativeQuote['field_3929'],
            'field_4442' => $commercial['customer']['email'], 'field_2437' => 897,
            'field_4443' => 'false', 'field_4457' => 'false', 'field_4460' => 'false',
            'field_2550' => 0, 'field_1992' => !empty($document['delivery_date']) ?
                (new DateTimeImmutable($document['delivery_date'], new DateTimeZone('Europe/Lisbon')))->getTimestamp() : 0,
            'field_2471' => 0]);
        $configurationSource=null;
        $number = 'OP' . date('y') . str_pad((string) $orderId, 5, '0', STR_PAD_LEFT);
        $this->write('UPDATE app_entity_121 SET field_2000=? WHERE id=?', [$number, $orderId]);
        $configFields=array_map('intval',array_column($this->rows("SELECT role,native_id FROM app_vd_config_schema WHERE role IN('quote.config','quote.revision','quote.snapshot','quote.hash','order.config')"),'native_id','role'));
        if(isset($configFields['quote.snapshot'])) {
            $source=$this->rows('SELECT field_'.$configFields['quote.config'].' config_id,field_'.$configFields['quote.revision'].' revision,field_'.$configFields['quote.hash'].' hash FROM app_entity_146 WHERE id=? AND field_'.$configFields['quote.snapshot']."<>''",[$quote]);
            if($source) {
                $configurationSource=$source[0];
                $field=$this->rows("SELECT native_id FROM app_vd_config_schema WHERE role='order.config'");
                if(!$field)throw new RuntimeException('Campo de origem do configurador inexistente.');
                $this->write('UPDATE app_entity_121 SET field_'.(int)$field[0]['native_id'].'=? WHERE id=?',[$source[0]['config_id'],$orderId]);
            }
        }
        $order = ['id' => $orderId, 'document' => $documentId];
        $unassigned = 0;
        $productionComponents = [];
        foreach ($components as $sourceId => $component) {
            $versionId = (int) $selected[$sourceId]['id'];
            $stageMetadata=$selected[$sourceId]['snapshot']['product']??[];
            if(isset($stageMetadata['stage'])) {
                $stageSchema=vd_product_stage_schema($this->db);
                $component['fields']['field_'.$stageSchema['production_part.stage']]=$stageMetadata['stage'];
                $component['fields']['field_'.$stageSchema['production_part.requirements']]=json_encode($stageMetadata['requirements'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
            $componentId = $this->insertProductionNative($quote, $order, $versionId, 159, $orderId, 'component',
                $component['fields'], $component['snapshot']);
            $productionComponents[$sourceId]=$componentId;
            foreach ($component['rubrics'] as $i => $rubric) {
                $this->insertProductionNative($quote, $order, $versionId, 145, $componentId, 'rubric:' . $i, $rubric, $rubric);
            }
            foreach ($component['plans'] as $i => $plan) {
                $this->insertProductionNative($quote, $order, $versionId, 158, $componentId, 'plan:' . $i, $plan['fields'], $plan['snapshot']);
            }
            foreach ($component['colors'] as $i => $color) {
                $this->insertProductionNative($quote, $order, $versionId, 172, $componentId, 'color:' . $i,
                    ['field_2466' => $color['field_4251'], 'field_2463' => $color['field_4249'] ?? '',
                        'field_3949' => ''], $color, ['op_pantone' => $color['field_4253'] ?? '']);
            }
            foreach ($component['operations'] as $operationId => $operation) {
                $this->access(36, $operationId);
                $staff = vd_production_operation_staff($this->db, $operationId);
                $operation['fields'] = array_replace($operation['fields'], $staff);
                $operation['snapshot']['production_staff'] = ['source' => 'operation_catalog_at_op_creation',
                    'assigned_users' => $staff['field_2428'], 'responsible_user' => $staff['field_2472']];
                foreach (array_filter(explode(',', (string) $operation['fields']['field_2427'])) as $machineId) $this->access(78, (int) $machineId);
                $operationNativeId = $this->insertProductionNative($quote, $order, $versionId, 170, $componentId,
                    'operation:' . $operationId, $operation['fields'], $operation['snapshot'],
                    ['op_quantity' => $operation['fields']['field_2578'],
                        'op_unit' => $operation['snapshot']['lines'][0]['unit'] ?? '']);
                vd_production_staff_index($this->db, $operationNativeId, $staff);
                foreach ($operation['materials'] as $material) {
                    if ($material['article_id']) $this->access(99, (int) $material['article_id']);
                    if ($material['internal_id']) $this->access(281, (int) $material['internal_id']);
                    if (!$material['article_id']) $unassigned++;
                    $materialId = $this->insertProductionNative($quote, $order, $versionId, 161, $operationNativeId,
                        $material['key'], ['field_2275' => 36, 'field_2276' => $material['quantity'], 'field_2277' => '',
                            'field_2543' => $material['name'], 'field_2545' => 0], $material['snapshot'],
                        ['op_article' => $material['article_id'] ?? '', 'op_internal' => $material['internal_id'] ?? '',
                            'op_quantity' => $material['quantity'], 'op_unit' => $material['unit']]);
                    $schema = vd_component_material_schema($this->db);
                    $this->createAccess($schema['entity'], $componentId);
                    vd_component_material_project($this->db, $materialId, $this->user['id']);
                }
            }
        }
        $componentGraph=[];
        foreach($selected as $sourceId=>$version) {
            $metadata=$version['snapshot']['product']??null;
            if (!$metadata) continue;
            $componentGraph[]=['id'=>(int)$sourceId,'kind'=>$metadata['kind'],'inputs'=>$metadata['inputs']];
        }
        if ($componentGraph) vd_product_graph($componentGraph);
        $dependencies=[];
        foreach($componentGraph as $component) foreach($component['inputs'] as $inputId) {
            if (!isset($productionComponents[$inputId])) throw new DomainException('Componente de entrada fora da alternativa adjudicada.');
            $dependency=['component_id'=>$productionComponents[$component['id']],'input_component_id'=>$productionComponents[$inputId]];
            $dependencies[]=$dependency;
            $this->write('INSERT INTO app_vd_production_component_inputs(order_id,component_id,input_component_id) VALUES(?,?,?)',[$orderId,$dependency['component_id'],$dependency['input_component_id']]);
        }
        if ($this->rows("SELECT document_id FROM app_vd_quote_approval_files WHERE document_id=? AND native_name=''", [$documentId])) {
            vd_workflow_sync_approval_attachment($this->db, $documentId, $this->createdFiles);
            $nativeQuote = $this->access(146, $quote);
            $this->write('UPDATE app_entity_121 SET field_2280=? WHERE id=?', [$nativeQuote['field_3929'], $orderId]);
        }
        $json = json_encode(['document_id' => $documentId, 'document_hash' => $document['hash'],
            'quantity' => (float) $document['accepted_quantity'], 'delivery_date' => $document['delivery_date'] ?? null, 'versions' => $selected,
            'header_source' => $commercial, 'attachments' => $nativeQuote['field_3929'], 'component_dependencies'=>$dependencies,
            'configuration_source'=>$configurationSource,
            'native_rows' => $this->rows('SELECT entity_id,native_id,parent_id,hash FROM app_vd_quote_production_rows WHERE order_id=? ORDER BY id', [$orderId])],
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->write('INSERT INTO app_vd_quote_production_orders(quote_id,document_id,order_id,quantity,snapshot,hash,unassigned_materials,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)',
            [$quote, $documentId, $orderId, $document['accepted_quantity'], $json, hash('sha256', $json), $unassigned, $this->user['id'], time()]);
        if (!empty($document['delivery_date'])) vd_production_schedule_order($this->db, $orderId, false, $this->user['id']);
        $this->audit($quote, 'production_order_created', ['document' => $documentId, 'order' => $orderId,
            'number' => $number, 'quantity' => (float) $document['accepted_quantity'],
            'versions' => array_column($selected, 'id'), 'unassigned_materials' => $unassigned,
            'stock_movement' => false]);
        return $this->productionOrder($quote);
    }

    private function assertNotAdjudicated($quote)
    {
        $control = $this->rows('SELECT production_document FROM app_vd_quote_control WHERE quote_id=?', [$quote]);
        if (!empty($control[0]['production_document'])) {
            throw new DomainException('Orçamento adjudicado. Só o administrador pode restabelecer o envio e a adjudicação.');
        }
    }

    public function restoreAdjudication($quote)
    {
        $this->access(146, $quote, 'update');
        if ((int) $this->user['group_id'] !== 0) throw new DomainException('Só o administrador pode restabelecer a adjudicação.');
        return $this->locked($quote, function () use ($quote) {
            if ($this->rows('SELECT order_id FROM app_vd_quote_production_orders WHERE quote_id=?', [$quote]) ||
                $this->rows('SELECT id FROM app_entity_121 WHERE field_5972=?', [$quote])) {
                throw new DomainException('Existe uma OP ligada a este orçamento. Trate a OP primeiro; o envio e a adjudicação não podem ser restabelecidos.');
            }
            $control = $this->rows('SELECT production_document,production_quantity FROM app_vd_quote_control WHERE quote_id=?', [$quote])[0];
            if (!$control['production_document']) throw new DomainException('Não existe adjudicação para restabelecer.');
            $document = $this->document($quote, $control['production_document']);
            if ($document['state'] !== 'accepted') throw new DomainException('Estado da adjudicação em conflito.');
            $this->write('SET @vd_workflow_production_mutation=1');
            foreach ($this->rows('SELECT p.entity_id,p.native_id FROM app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id WHERE v.quote_id=? AND p.production=1', [$quote]) as $projection) {
                $entity = (int) $projection['entity_id'];
                $this->access($entity, $projection['native_id'], 'update');
                $field = (int) $this->rows("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=? AND role='production'", [$entity])[0]['field_id'];
                $this->write("UPDATE app_entity_$entity SET field_$field='0' WHERE id=?", [$projection['native_id']]);
            }
            $this->write('UPDATE app_vd_quote_projection p JOIN app_vd_quote_versions v ON v.id=p.version_id SET p.production=0 WHERE v.quote_id=?', [$quote]);
            $this->write("UPDATE app_vd_quote_documents SET state='sent',accepted_quantity=NULL,delivery_date=NULL,accepted_at=NULL WHERE id=?", [$document['id']]);
            $this->write('UPDATE app_vd_quote_control SET production_document=NULL,production_quantity=NULL WHERE quote_id=?', [$quote]);
            $this->audit($quote, 'adjudication_restored', ['document' => (int) $document['id'],
                'previous_quantity' => $control['production_quantity'], 'previous_delivery_date' => $document['delivery_date'],
                'previous_accepted_at' => $document['accepted_at'], 'history_preserved' => true]);
            return ['message' => 'Envio e adjudicação restabelecidos. Histórico e anexos preservados; seleção para produção retirada.'];
        });
    }

    public function configureGroups($quote, array $groups)
    {
        $this->access(146, $quote, 'update');
        if ((int) $this->user['group_id'] !== 0) throw new DomainException('Só o administrador pode configurar os perfis.');
        $groups = array_values(array_unique(array_map('intval', $groups)));
        if (!$groups) throw new DomainException('Defina pelo menos um perfil aprovador.');
        foreach ($groups as $id) if (!$this->rows('SELECT id FROM app_access_groups WHERE id=?', [$id])) throw new DomainException('Perfil inexistente.');
        return $this->locked($quote, function () use ($quote, $groups) {
            $this->write('DELETE FROM app_vd_quote_approval_groups');
            foreach ($groups as $group) $this->write('INSERT INTO app_vd_quote_approval_groups(group_id) VALUES(?)', [$group]);
            $this->audit($quote, 'approval_groups_changed', ['groups' => $groups]);
            return ['groups' => $groups];
        });
    }
}

<?php
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$base = __DIR__;
$manifest = json_decode(file_get_contents($base . '/workflow-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $target => $entry) {
    if (hash_file('sha256', $base . '/' . $entry['source']) !== $entry['hash']) throw new RuntimeException('Source changed: ' . $target);
    if (file_exists($target) && hash_file('sha256', $target) !== $entry['hash'] &&
        (!isset($entry['baseline']) || hash_file('sha256', $target) !== $entry['baseline'])) {
        throw new RuntimeException('Concurrent file change: ' . $target);
    }
    foreach ($manifest['custom_php'] ?? [] as $id => $helperEntry) {
        $code = preg_replace('/^<\?php\n/', '', file_get_contents($base . '/' . $helperEntry['source']));
        if (hash('sha256', $code) !== $helperEntry['hash']) throw new RuntimeException('Native helper source mismatch');
        $old = $db->query('SELECT code FROM app_custom_php WHERE id=' . (int) $id)->fetch_row()[0];
        if (!in_array(hash('sha256', $old), [$helperEntry['baseline'], $helperEntry['hash']], true)) throw new RuntimeException('Native helper changed: ' . $id);
        token_get_all('<?php ' . $code, TOKEN_PARSE);
    }
    if (substr($target, -4) === '.php') token_get_all(file_get_contents($base . '/' . $entry['source']), TOKEN_PARSE);
}
if ((int) $db->query("SELECT GET_LOCK('vd_workflow_schema',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Schema lock unavailable');
try {
    require_once __DIR__.'/production-component-guards.php';
    $tables = [
        'app_vd_quote_products' => "quote_id INT UNSIGNED PRIMARY KEY,type VARCHAR(32) NOT NULL,revision INT UNSIGNED NOT NULL DEFAULT 1",
        'app_vd_quote_product_components' => "component_id INT UNSIGNED PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,kind VARCHAR(20) NOT NULL,inputs TEXT NOT NULL,units_per_product DECIMAL(12,4) NOT NULL DEFAULT 1,KEY lookup(quote_id)",
        'app_vd_production_component_inputs' => 'order_id INT UNSIGNED NOT NULL,component_id INT UNSIGNED NOT NULL,input_component_id INT UNSIGNED NOT NULL,PRIMARY KEY(component_id,input_component_id),KEY lookup(order_id)',
        'app_vd_quote_control' => 'quote_id INT UNSIGNED PRIMARY KEY,revision INT UNSIGNED NOT NULL,production_document INT UNSIGNED NULL,production_quantity DECIMAL(18,4) NULL',
        'app_vd_quote_versions' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,component_id INT UNSIGNED NOT NULL,quantity_id INT UNSIGNED NOT NULL,quantity DECIMAL(18,4) NOT NULL,version_no INT UNSIGNED NOT NULL,state VARCHAR(32) NOT NULL,hash CHAR(64) NOT NULL,payload MEDIUMTEXT NOT NULL,native_id INT UNSIGNED NOT NULL,request_id VARCHAR(80) NOT NULL,created_by INT UNSIGNED NOT NULL,created_at INT UNSIGNED NOT NULL,UNIQUE KEY uq_request(request_id),UNIQUE KEY uq_native(native_id),UNIQUE KEY uq_number(quote_id,version_no),KEY lookup(quote_id,component_id,quantity_id)",
        'app_vd_quote_documents' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL,state VARCHAR(32) NOT NULL,hash CHAR(64) NOT NULL,snapshot MEDIUMTEXT NOT NULL,html MEDIUMTEXT NOT NULL,issued_at INT UNSIGNED NOT NULL,expires_at INT UNSIGNED NOT NULL,approved_by INT UNSIGNED NOT NULL,approved_at INT UNSIGNED NOT NULL,sent_at INT UNSIGNED NULL,accepted_quantity DECIMAL(18,4) NULL,KEY lookup(quote_id,revision)',
        'app_vd_quote_projection' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version_id INT UNSIGNED NOT NULL,entity_id INT UNSIGNED NOT NULL,native_id INT UNSIGNED NOT NULL,line_key VARCHAR(100) NOT NULL,production TINYINT NOT NULL,UNIQUE KEY uq_line(version_id,entity_id,line_key),UNIQUE KEY uq_native(entity_id,native_id)',
        'app_vd_quote_audit' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,actor_id INT UNSIGNED NOT NULL,event VARCHAR(64) NOT NULL,detail MEDIUMTEXT NOT NULL,created_at INT UNSIGNED NOT NULL,KEY lookup(quote_id,id)',
        'app_vd_quote_approval_groups' => 'group_id INT UNSIGNED PRIMARY KEY',
        'app_vd_quote_sms' => 'document_id INT UNSIGNED PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,module_id INT UNSIGNED NOT NULL,phone VARCHAR(32) NOT NULL,message TEXT NOT NULL,state VARCHAR(32) NOT NULL,started_at INT UNSIGNED NULL,completed_at INT UNSIGNED NULL,KEY lookup(quote_id)',
        'app_vd_quote_approval_files' => 'document_id INT UNSIGNED PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,name VARCHAR(255) NOT NULL,mime VARCHAR(80) NOT NULL,hash CHAR(64) NOT NULL,content MEDIUMBLOB NOT NULL,uploaded_by INT UNSIGNED NOT NULL,uploaded_at INT UNSIGNED NOT NULL,KEY lookup(quote_id)',
        'app_vd_quote_approval_file_history' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_id INT UNSIGNED NOT NULL,quote_id INT UNSIGNED NOT NULL,name VARCHAR(255) NOT NULL,mime VARCHAR(80) NOT NULL,hash CHAR(64) NOT NULL,content MEDIUMBLOB NOT NULL,uploaded_by INT UNSIGNED NOT NULL,uploaded_at INT UNSIGNED NOT NULL,native_name VARCHAR(400) NOT NULL,KEY lookup(quote_id,document_id)',
        'app_vd_quote_deliveries' => 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_id INT UNSIGNED NOT NULL,quote_id INT UNSIGNED NOT NULL,request_id VARCHAR(80) NOT NULL,email VARCHAR(255) NOT NULL,sender_email VARCHAR(255) NOT NULL,sender_name VARCHAR(255) NOT NULL,email_state VARCHAR(32) NOT NULL,module_id INT UNSIGNED NOT NULL,phone VARCHAR(32) NOT NULL,message TEXT NOT NULL,state VARCHAR(32) NOT NULL,created_at INT UNSIGNED NOT NULL,started_at INT UNSIGNED NULL,completed_at INT UNSIGNED NULL,UNIQUE KEY uq_request(request_id),KEY lookup(quote_id,document_id,id)',
        'app_vd_quote_ink_pdfs' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,component_id INT UNSIGNED NOT NULL,name VARCHAR(255) NOT NULL,content MEDIUMBLOB NOT NULL,hash CHAR(64) NOT NULL,result MEDIUMTEXT NOT NULL,created_by INT UNSIGNED NOT NULL,created_at INT UNSIGNED NOT NULL,KEY lookup(quote_id,component_id,id)',
        'app_vd_quote_ink_estimates' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,component_id INT UNSIGNED NOT NULL,pdf_id BIGINT UNSIGNED NOT NULL,version_id INT UNSIGNED NOT NULL,version_hash CHAR(64) NOT NULL,pdf_hash CHAR(64) NOT NULL,result MEDIUMTEXT NOT NULL,created_by INT UNSIGNED NOT NULL,created_at INT UNSIGNED NOT NULL,KEY lookup(quote_id,component_id,id)',
        'app_vd_quote_native_fields' => 'entity_id INT UNSIGNED NOT NULL,role VARCHAR(32) NOT NULL,field_id INT UNSIGNED NOT NULL,PRIMARY KEY(entity_id,role),UNIQUE KEY uq_field(field_id)',
        'app_vd_quote_production_orders' => 'quote_id INT UNSIGNED PRIMARY KEY,document_id INT UNSIGNED NOT NULL,order_id INT UNSIGNED NOT NULL,quantity DECIMAL(18,4) NOT NULL,snapshot MEDIUMTEXT NOT NULL,hash CHAR(64) NOT NULL,unassigned_materials INT UNSIGNED NOT NULL,created_by INT UNSIGNED NOT NULL,created_at INT UNSIGNED NOT NULL,UNIQUE KEY uq_document(document_id),UNIQUE KEY uq_order(order_id)',
        'app_vd_quote_production_rows' => 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,order_id INT UNSIGNED NOT NULL,version_id INT UNSIGNED NOT NULL,entity_id INT UNSIGNED NOT NULL,native_id INT UNSIGNED NOT NULL,parent_id INT UNSIGNED NOT NULL,line_key VARCHAR(100) NOT NULL,snapshot MEDIUMTEXT NOT NULL,hash CHAR(64) NOT NULL,UNIQUE KEY uq_line(order_id,version_id,entity_id,line_key),UNIQUE KEY uq_native(entity_id,native_id),KEY lookup(quote_id,order_id)',
        'app_vd_production_removed_rubrics' => 'native_id INT UNSIGNED PRIMARY KEY,order_id INT UNSIGNED NOT NULL,parent_id INT UNSIGNED NOT NULL,native_snapshot MEDIUMTEXT NOT NULL,hash CHAR(64) NOT NULL,archived_at INT UNSIGNED NOT NULL',
        'app_vd_production_excluded_operations' => 'native_id INT UNSIGNED PRIMARY KEY,order_id INT UNSIGNED NOT NULL,operation_id INT UNSIGNED NOT NULL,native_snapshot MEDIUMTEXT NOT NULL,hash CHAR(64) NOT NULL,reason VARCHAR(255) NOT NULL,archived_at INT UNSIGNED NOT NULL',
        'app_vd_quote_legacy' => 'native_id INT UNSIGNED PRIMARY KEY,quote_id INT UNSIGNED NOT NULL,component_id INT UNSIGNED NOT NULL,snapshot MEDIUMTEXT NOT NULL,hash CHAR(64) NOT NULL,archived_at INT UNSIGNED NOT NULL'
    ];
    foreach ($tables as $name => $schema) {
        $db->query("CREATE TABLE IF NOT EXISTS $name ($schema) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($db->query("SHOW TABLE STATUS LIKE '$name'")->fetch_assoc()['Engine'] !== 'InnoDB') throw new RuntimeException('Unsafe engine: ' . $name);
    }
    vd_production_install_component_guards($db);
    if (!$db->query("SHOW COLUMNS FROM app_vd_quote_versions LIKE 'request_hash'")->num_rows) $db->query('ALTER TABLE app_vd_quote_versions ADD request_hash CHAR(64) NULL');
    foreach (['delivery_date' => 'VARCHAR(10) NULL', 'accepted_at' => 'INT UNSIGNED NULL'] as $column => $definition) {
        if (!$db->query("SHOW COLUMNS FROM app_vd_quote_documents LIKE '$column'")->num_rows) {
            $db->query("ALTER TABLE app_vd_quote_documents ADD $column $definition");
        }
    }
    if (!$db->query("SHOW COLUMNS FROM app_vd_quote_approval_files LIKE 'native_name'")->num_rows) $db->query("ALTER TABLE app_vd_quote_approval_files ADD native_name VARCHAR(400) NOT NULL DEFAULT ''");
    require_once 'config/server.php';
    require_once $base . '/quote-approval-attachments.php';
    foreach ($db->query("SELECT document_id FROM app_vd_quote_approval_files WHERE native_name=''")->fetch_all(MYSQLI_ASSOC) as $approval) {
        $db->begin_transaction();
        try {
            vd_workflow_sync_approval_attachment($db, (int) $approval['document_id']);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        echo 'Added approval document ', $approval['document_id'], ' to native quote attachments without replacing existing files.', PHP_EOL;
    }
    $progressField = $db->query("SELECT type FROM app_fields WHERE id=4241 AND entities_id=262")->fetch_assoc();
    if (!$progressField || $progressField['type'] !== 'fieldtype_progress') throw new RuntimeException('Unexpected component progress field.');
    $progressTrigger = $db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='vd_quote_component_initial_progress'")->fetch_assoc();
    $progressBody = 'SET NEW.field_4241 = 5';
    if ($progressTrigger && trim($progressTrigger['ACTION_STATEMENT']) !== $progressBody) throw new RuntimeException('Component initial progress trigger changed.');
    if (!$progressTrigger) $db->query("CREATE TRIGGER vd_quote_component_initial_progress BEFORE INSERT ON app_entity_262 FOR EACH ROW $progressBody");
    $db->query("UPDATE app_entity_262 c SET field_4241=CASE
        WHEN EXISTS(SELECT 1 FROM app_vd_quote_documents d WHERE d.quote_id=c.parent_item_id AND d.state<>'superseded') THEN 100
        WHEN EXISTS(SELECT 1 FROM app_vd_quote_versions v WHERE v.component_id=c.id AND v.quote_id=c.parent_item_id AND v.state='confirmed') THEN 50
        ELSE 5 END WHERE EXISTS(SELECT 1 FROM app_vd_quote_control q WHERE q.quote_id=c.parent_item_id)");
    $details = $db->query("SHOW COLUMNS FROM app_entity_388 LIKE 'field_6106'")->fetch_assoc();
    if (!in_array(strtolower($details['Type']), ['text','mediumtext'], true)) throw new RuntimeException('Unexpected native snapshot column type.');
    if (strtolower($details['Type']) === 'text') $db->query('ALTER TABLE app_entity_388 MODIFY field_6106 MEDIUMTEXT NOT NULL');
    $legacyRows = $db->query('SELECT s.*,c.parent_item_id quote_id FROM app_entity_388 s JOIN app_entity_262 c ON c.id=s.parent_item_id LEFT JOIN app_vd_quote_versions v ON v.native_id=s.id LEFT JOIN app_vd_quote_legacy l ON l.native_id=s.id WHERE v.id IS NULL AND l.native_id IS NULL')->fetch_all(MYSQLI_ASSOC);
    foreach ($legacyRows as $legacy) {
        $snapshot = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $snapshot);
        $now = time();
        $stmt = $db->prepare('INSERT INTO app_vd_quote_legacy(native_id,quote_id,component_id,snapshot,hash,archived_at) VALUES(?,?,?,?,?,?)');
        $stmt->bind_param('iiissi', $legacy['id'], $legacy['quote_id'], $legacy['parent_item_id'], $snapshot, $hash, $now);
        $stmt->execute();
        echo 'Archived previous selection ', $legacy['id'], ' without changing its data.', PHP_EOL;
    }
    if (!$db->query('SELECT group_id FROM app_vd_quote_approval_groups')->num_rows) $db->query('INSERT INTO app_vd_quote_approval_groups(group_id) VALUES(4)');
    foreach ([
        [146, 'extra_discount', 'Desconto extra (%)', 6271, 'fieldtype_input_numeric'],
        [146, 'language', 'Enviar proposta em inglês', 3927, 'fieldtype_boolean_checkbox'],
        [146, 'english_description', 'Descrição comercial em inglês', 3927, 'fieldtype_input'],
        [146, 'english_observations', 'Observações comerciais em inglês', 3927, 'fieldtype_textarea'],
        [146, 'english_payment', 'Condições de pagamento em inglês (se não normalizadas)', 3927, 'fieldtype_input'],
        [146, 'english_reviewed', 'Textos da proposta em inglês revistos', 3927, 'fieldtype_boolean_checkbox'],
        [262, 'english_technical', 'Descrição técnica para proposta em inglês', 4225, 'fieldtype_textarea']
    ] as $definition) {
        list($entity, $role, $label, $templateId, $type) = $definition;
        $existing = $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=$entity AND role='$role'")->fetch_assoc();
        if ($existing) {
            $fieldId = (int) $existing['field_id'];
            $db->query("UPDATE app_fields SET is_required=0,is_heading=0,listing_status=0,forms_rows_position='' WHERE id=$fieldId AND entities_id=$entity");
            if ($role === 'extra_discount') {
                $clientTab = (int) $db->query("SELECT forms_tabs_id FROM app_fields WHERE id=3923 AND entities_id=146")->fetch_row()[0];
                $db->query("UPDATE app_fields SET forms_tabs_id=$clientTab WHERE id=$fieldId AND entities_id=146");
            }
            continue;
        }
        $template = $db->query("SELECT * FROM app_fields WHERE id=$templateId")->fetch_assoc();
        unset($template['id']);
        $template['name'] = $label;
        $template['type'] = $type;
        if ($role === 'extra_discount')
            $template['forms_tabs_id'] = $db->query("SELECT forms_tabs_id FROM app_fields WHERE id=3923 AND entities_id=146")->fetch_row()[0];
        $template['is_required'] = 0;
        $template['is_heading'] = 0;
        $template['listing_status'] = 0;
        $template['forms_rows_position'] = '';
        $template['tooltip'] = $role === 'extra_discount' ?
            'Soma ao desconto do cliente. Ex.: 25% + 5% = 30%. Fica bloqueado após o primeiro envio.' :
            'Propostas anteriores mantêm o idioma e textos aprovados. Inglês exige revisão antes de aprovar.';
        $template['sort_order'] = 110 + array_search($role, ['language','english_description','english_observations','english_payment','english_reviewed','english_technical']);
        $template['configuration'] = $role === 'extra_discount' ?
            '{"width":"input-small","default_value":"0","min_value":"0","max_value":"100","suffix":" %"}' :
            ($type === 'fieldtype_boolean_checkbox' ? '{"default_value":"false"}' :
            ($type === 'fieldtype_textarea' ? '{"width":"input-xlarge","height":"150"}' : '{"width":"input-xlarge"}'));
        $stmt = $db->prepare('INSERT INTO app_fields (`' . implode('`,`', array_keys($template)) . '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
        $values = array_values($template);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $fieldId = (int) $db->insert_id;
        $db->query("ALTER TABLE app_entity_$entity ADD field_$fieldId TEXT NULL");
        $db->query("INSERT INTO app_vd_quote_native_fields(entity_id,role,field_id) VALUES($entity,'$role',$fieldId)");
        echo "Native commercial field $entity/$role: $fieldId\n";
    }
    $extraDiscountField = (int) $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=146 AND role='extra_discount'")->fetch_row()[0];
    $extraDiscountTrigger = "BEGIN IF
        CAST(REPLACE(COALESCE(NULLIF(TRIM(NEW.field_$extraDiscountField),''),'0'),',','.') AS DECIMAL(12,6)) <>
        CAST(REPLACE(COALESCE(NULLIF(TRIM(OLD.field_$extraDiscountField),''),'0'),',','.') AS DECIMAL(12,6)) AND
        EXISTS(SELECT 1 FROM app_vd_quote_documents WHERE quote_id=OLD.id AND
        (sent_at IS NOT NULL OR state IN('sending','send_unknown'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Desconto extra bloqueado: proposta ja enviada'; END IF; END";
    $storedExtraTrigger = $db->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='vd_quote_extra_discount_locked'")->fetch_assoc();
    if ($storedExtraTrigger && trim($storedExtraTrigger['ACTION_STATEMENT']) !== $extraDiscountTrigger)
        throw new RuntimeException('Extra discount trigger changed');
    if (!$storedExtraTrigger) $db->query("CREATE TRIGGER vd_quote_extra_discount_locked BEFORE UPDATE ON app_entity_146 FOR EACH ROW $extraDiscountTrigger");
    foreach ([276,279] as $entity) {
        foreach (['vd_workflow_version','vd_workflow_quantity'] as $column) {
            if (!$db->query("SHOW COLUMNS FROM app_entity_$entity LIKE '$column'")->num_rows) {
                $db->query("ALTER TABLE app_entity_$entity ADD `$column` INT UNSIGNED NULL DEFAULT NULL");
            }
        }
        $roles = ['version' => 'Versão do orçamento (imutável)', 'quantity' => 'Alternativa de quantidade (ID)',
            'production' => 'Alternativa aceite para produção', 'run_quantity' => 'Tiragem fixada na versão'];
        if ($entity === 276) $roles += ['fixed_cost' => 'Custo fixado na versão (€)',
            'fixed_sale' => 'Venda fixada na versão (€)', 'fixed_hours' => 'Tempo fixado na versão (h)',
            'fixed_quantity' => 'Quantidade de trabalho fixada', 'calculation' => 'Linhas de cálculo fixadas (JSON)'];
        foreach ($roles as $role => $label) {
            if ($db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=$entity AND role='$role'")->num_rows) continue;
            $template = $db->query('SELECT * FROM app_fields WHERE id=6181')->fetch_assoc();
            unset($template['id']);
            $template['entities_id'] = $entity;
            $template['forms_tabs_id'] = $db->query("SELECT forms_tabs_id FROM app_fields WHERE entities_id=$entity AND forms_tabs_id>0 LIMIT 1")->fetch_row()[0];
            $template['name'] = $label;
            $template['tooltip'] = 'Gerido pelo fluxo de versões; alterações exigem nova versão.';
            $template['sort_order'] = 100 + array_search($role, array_keys($roles));
            $template['listing_sort_order'] = $template['sort_order'];
            $template['type'] = $role === 'production' ? 'fieldtype_boolean_checkbox' :
                ($role === 'calculation' ? 'fieldtype_textarea' : 'fieldtype_input_numeric');
            $template['configuration'] = $role === 'production' ? '{"default_value":"0"}' :
                ($role === 'calculation' ? '{"width":"input-xlarge","height":"150"}' : '{"number_format":"4","width":"input-small"}');
            $stmt = $db->prepare('INSERT INTO app_fields (`' . implode('`,`', array_keys($template)) . '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
            $values = array_values($template);
            $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            $stmt->execute();
            $fieldId = (int) $db->insert_id;
            $columnType = $role === 'calculation' ? 'MEDIUMTEXT NULL' : "VARCHAR(64) NOT NULL DEFAULT ''";
            $db->query("ALTER TABLE app_entity_$entity ADD field_$fieldId $columnType");
            $db->query("INSERT INTO app_vd_quote_native_fields(entity_id,role,field_id) VALUES($entity,'$role',$fieldId)");
            echo "Native provenance field $entity/$role: $fieldId\n";
        }
    }
    foreach ([121 => 0, 159 => 121, 145 => 159, 158 => 159, 170 => 159, 161 => 170, 172 => 159] as $entity => $parent) {
        $native = $db->query("SELECT parent_id FROM app_entities WHERE id=$entity")->fetch_assoc();
        if (!$native || (int) $native['parent_id'] !== $parent ||
            $db->query("SHOW TABLE STATUS LIKE 'app_entity_$entity'")->fetch_assoc()['Engine'] !== 'InnoDB') {
            throw new RuntimeException('Unexpected native production structure: ' . $entity);
        }
    }
    if (!$db->query("SELECT id FROM app_fields_choices WHERE fields_id=2238 AND name='Vinco'")->num_rows) {
        $choice = $db->query('SELECT * FROM app_fields_choices WHERE id=504 AND fields_id=2238')->fetch_assoc();
        if (!$choice) throw new RuntimeException('Missing native die choice template.');
        unset($choice['id']);
        $choice['name'] = 'Vinco';
        $choice['sort_order'] = 40;
        $stmt = $db->prepare('INSERT INTO app_fields_choices (`' . implode('`,`', array_keys($choice)) .
            '`) VALUES(' . implode(',', array_fill(0, count($choice), '?')) . ')');
        $values = array_values($choice);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
    }
    foreach ([159,170,161,172] as $entity) {
        $roles = ['op_origin' => ['Origem da OP (proposta e versão)', 'fieldtype_input', ['width' => 'input-large']],
            'op_snapshot' => ['Dados aprovados de origem (JSON)', 'fieldtype_textarea', ['width' => 'input-xlarge', 'height' => '150']]];
        if ($entity === 170) {
            $roles += [
                'op_quantity' => ['Quantidade de trabalho prevista (precisão)', 'fieldtype_input_numeric', ['width' => 'input-small', 'number_format' => '6/./ ']],
                'op_unit' => ['Unidade do trabalho previsto', 'fieldtype_input', ['width' => 'input-medium']]
            ];
        }
        if ($entity === 161) {
            $roles += [
                'op_article' => ['Artigo previsto (catálogo de materiais)', 'fieldtype_entity_ajax',
                    ['entity_id' => '99', 'display_as' => 'dropdown', 'width' => 'input-large', 'hide_plus_button' => '1', 'copy_values' => '']],
                'op_internal' => ['Referência interna prevista', 'fieldtype_entity_ajax',
                    ['entity_id' => '281', 'display_as' => 'dropdown', 'width' => 'input-large', 'hide_plus_button' => '1', 'copy_values' => '']],
                'op_quantity' => ['Quantidade prevista (precisão)', 'fieldtype_input_numeric', ['width' => 'input-small', 'number_format' => '6/./ ']],
                'op_unit' => ['Unidade do consumo previsto', 'fieldtype_input', ['width' => 'input-medium']]
            ];
        }
        if ($entity === 172) $roles['op_pantone'] = ['Pantone fixado no orçamento', 'fieldtype_input', ['width' => 'input-medium']];
        foreach ($roles as $role => $definition) {
            $existing = $db->query("SELECT field_id FROM app_vd_quote_native_fields WHERE entity_id=$entity AND role='$role'")->fetch_assoc();
            $visible = in_array($role, ['op_article','op_internal','op_quantity','op_unit'], true);
            if ($existing) {
                if ($visible) $db->query('UPDATE app_fields SET listing_status=1 WHERE id=' . (int) $existing['field_id'] . " AND entities_id=$entity");
                continue;
            }
            $template = $db->query('SELECT * FROM app_fields WHERE id=6181')->fetch_assoc();
            unset($template['id']);
            $template['entities_id'] = $entity;
            $template['forms_tabs_id'] = $db->query("SELECT forms_tabs_id FROM app_fields WHERE entities_id=$entity AND forms_tabs_id>0 ORDER BY sort_order,id LIMIT 1")->fetch_row()[0];
            $template['name'] = $definition[0];
            $template['type'] = $definition[1];
            $template['configuration'] = json_encode($definition[2], JSON_THROW_ON_ERROR);
            $template['tooltip'] = 'Criado a partir da alternativa adjudicada. O instantâneo histórico é preservado. Não reserva nem consome stock.';
            $template['is_required'] = 0;
            $template['is_heading'] = 0;
            $template['forms_rows_position'] = '';
            $template['listing_status'] = $visible ? 1 : 0;
            $template['sort_order'] = 150 + array_search($role, array_keys($roles));
            $template['listing_sort_order'] = $template['sort_order'];
            $stmt = $db->prepare('INSERT INTO app_fields (`' . implode('`,`', array_keys($template)) .
                '`) VALUES(' . implode(',', array_fill(0, count($template), '?')) . ')');
            $values = array_values($template);
            $stmt->bind_param(str_repeat('s', count($values)), ...$values);
            $stmt->execute();
            $fieldId = (int) $db->insert_id;
            $db->query("ALTER TABLE app_entity_$entity ADD field_$fieldId MEDIUMTEXT NULL");
            $db->query("INSERT INTO app_vd_quote_native_fields(entity_id,role,field_id) VALUES($entity,'$role',$fieldId)");
            echo "Native production field $entity/$role: $fieldId\n";
        }
    }
    foreach ($db->query("SELECT entity_id,field_id FROM app_vd_quote_native_fields WHERE role='op_snapshot'")->fetch_all(MYSQLI_ASSOC) as $technical) {
        $entity = (int) $technical['entity_id'];
        $fieldId = (int) $technical['field_id'];
        $configuration = $db->query("SELECT id,configuration_value FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='item_page_hidden_fields'")->fetch_assoc();
        $hidden = array_filter(explode(',', $configuration['configuration_value'] ?? ''));
        $hidden[] = (string) $fieldId;
        $value = implode(',', array_unique($hidden));
        if ($configuration) {
            $stmt = $db->prepare('UPDATE app_entities_configuration SET configuration_value=? WHERE id=?');
            $stmt->bind_param('si', $value, $configuration['id']);
        } else {
            $stmt = $db->prepare("INSERT INTO app_entities_configuration(entities_id,configuration_name,configuration_value) VALUES(?,'item_page_hidden_fields',?)");
            $stmt->bind_param('is', $entity, $value);
        }
        $stmt->execute();
    }
    foreach ([388,276,279] as $entity) {
        foreach (['UPDATE','DELETE'] as $event) {
            $condition = $entity === 388 ?
                '(EXISTS(SELECT 1 FROM app_vd_quote_versions WHERE native_id=OLD.id) OR EXISTS(SELECT 1 FROM app_vd_quote_legacy WHERE native_id=OLD.id))' :
                "(EXISTS(SELECT 1 FROM app_vd_quote_projection WHERE entity_id=$entity AND native_id=OLD.id))";
            if ($event === 'UPDATE' && $entity !== 388) $condition .= ' AND COALESCE(@vd_workflow_production_mutation,0)<>1';
            $body = "BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable quotation snapshot: create a new version'; END IF; END";
            $name = 'vd_quote_' . $entity . '_immutable_' . strtolower($event);
            $stmt = $db->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stored = $stmt->get_result()->fetch_assoc();
            if ($stored && trim($stored['ACTION_STATEMENT']) !== $body) throw new RuntimeException('Existing immutable trigger changed: ' . $name);
            if (!$stored) $db->query("CREATE TRIGGER $name BEFORE $event ON app_entity_$entity FOR EACH ROW $body");
        }
    }
    // Add only the missing native read/create grants; existing grants remain untouched.
    $access = $db->query('SELECT id,access_schema FROM app_entities_access WHERE entities_id=388 AND access_groups_id=4')->fetch_assoc();
    if (!$access) {
        $db->query("INSERT INTO app_entities_access(entities_id,access_groups_id,access_schema) VALUES(388,4,'view,create')");
    } else {
        $schema = array_unique(array_merge(explode(',', $access['access_schema']), ['view','create']));
        $stmt = $db->prepare('UPDATE app_entities_access SET access_schema=? WHERE id=? AND access_schema=?');
        $new = implode(',', $schema);
        $stmt->bind_param('sis', $new, $access['id'], $access['access_schema']);
        $stmt->execute();
    }
    if (in_array('--schema-only', $argv ?? [], true)) {
        echo "Workflow schema prepared; live files were not changed.\n";
        return;
    }
    foreach ($manifest['files'] as $target => $entry) {
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true)) throw new RuntimeException('Cannot create deployment directory.');
        if (file_exists($target) && hash_file('sha256', $target) === $entry['hash']) continue;
        if (isset($entry['baseline']) && file_exists($target)) {
            $backup = $base . '/backup-' . str_replace('/', '-', $target);
            if (!file_exists($backup) && !copy($target, $backup)) throw new RuntimeException('Backup failed.');
        }
        if (!copy($base . '/' . $entry['source'], $target) || !chmod($target, 0644) ||
            hash_file('sha256', $target) !== $entry['hash']) throw new RuntimeException('Persistence mismatch: ' . $target);
        echo 'Persisted ', $target, ' ', $entry['hash'], PHP_EOL;
    }
    foreach ($manifest['custom_php'] ?? [] as $id => $entry) {
        $code = preg_replace('/^<\?php\n/', '', file_get_contents($base . '/' . $entry['source']));
        $old = $db->query('SELECT code FROM app_custom_php WHERE id=' . (int) $id)->fetch_row()[0];
        if ($old === $code) continue;
        $stmt = $db->prepare('UPDATE app_custom_php SET code=? WHERE id=? AND code=?');
        $stmt->bind_param('sis', $code, $id, $old);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Native helper compare-and-swap conflict');
        echo 'Preserved native helper ', $id, ' with projection exclusion: ', $entry['hash'], PHP_EOL;
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_workflow_schema')");
}
echo "Workflow schema and files verified.\n";

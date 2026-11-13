<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__ . '/quote-component-materials.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$db->set_charset('utf8mb4');
if ((int) $db->query("SELECT GET_LOCK('vd_component_material_schema',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Materials schema lock unavailable.');
function vdMaterialInsert(mysqli $db, $table, array $row)
{
    unset($row['id']);
    $stmt = $db->prepare("INSERT INTO $table (`" . implode('`,`', array_keys($row)) . '`) VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')');
    $values = array_values($row);
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $stmt->execute();
    return (int) $db->insert_id;
}
try {
    $db->query('CREATE TABLE IF NOT EXISTS app_vd_component_material_fields(role VARCHAR(32) PRIMARY KEY,entity_id INT UNSIGNED NOT NULL,field_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE IF NOT EXISTS app_vd_component_material_rows(source_id INT UNSIGNED PRIMARY KEY,native_id INT UNSIGNED NOT NULL UNIQUE,order_id INT UNSIGNED NOT NULL,hash CHAR(64) NOT NULL,KEY(order_id)) ENGINE=InnoDB');
    $registered = $db->query("SELECT entity_id FROM app_vd_component_material_fields WHERE role='entity'")->fetch_assoc();
    if ($registered) {
        $entity = (int) $registered['entity_id'];
        $native = $db->query("SELECT parent_id FROM app_entities WHERE id=$entity")->fetch_assoc();
        if (!$native || (int) $native['parent_id'] !== 159) throw new RuntimeException('Component materials entity missing or moved.');
    } else {
        $template = $db->query('SELECT * FROM app_entities WHERE id=172')->fetch_assoc();
        $template['parent_id'] = 159;
        $template['name'] = 'Materiais';
        $template['notes'] = 'Consumos previstos da alternativa adjudicada, por componente. Ligação à operação e à linha original; não são movimentos de stock.';
        $template['sort_order'] = 1;
        $db->begin_transaction();
        try {
            $entity = vdMaterialInsert($db, 'app_entities', $template);
            $db->query("INSERT INTO app_vd_component_material_fields VALUES('entity',$entity,0)");
            $db->commit();
        } catch (Throwable $error) { $db->rollback(); throw $error; }
    }
    $db->query("CREATE TABLE IF NOT EXISTS app_entity_$entity (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_id INT UNSIGNED DEFAULT 0,parent_item_id INT UNSIGNED DEFAULT 0,linked_id INT UNSIGNED DEFAULT 0,date_added BIGINT DEFAULT 0,date_updated BIGINT DEFAULT 0,created_by INT UNSIGNED DEFAULT NULL,sort_order INT DEFAULT 0,KEY idx_parent_id(parent_id),KEY idx_parent_item_id(parent_item_id),KEY idx_created_by(created_by)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS app_entity_{$entity}_values LIKE app_entity_172_values");
    $tab = $db->query("SELECT id FROM app_forms_tabs WHERE entities_id=$entity ORDER BY id LIMIT 1")->fetch_assoc();
    $tabId = $tab ? (int) $tab['id'] : vdMaterialInsert($db, 'app_forms_tabs', array_replace($db->query('SELECT * FROM app_forms_tabs WHERE id=198')->fetch_assoc(), ['entities_id' => $entity]));
    foreach ($db->query("SELECT * FROM app_fields WHERE entities_id=172 AND type IN('fieldtype_action','fieldtype_id','fieldtype_date_added','fieldtype_date_updated','fieldtype_created_by','fieldtype_parent_item_id')")->fetch_all(MYSQLI_ASSOC) as $reserved) {
        if ($db->query("SELECT id FROM app_fields WHERE entities_id=$entity AND type='" . $db->real_escape_string($reserved['type']) . "'")->num_rows) continue;
        $reserved['entities_id'] = $entity;
        $reserved['forms_tabs_id'] = $tabId;
        $reserved['forms_rows_position'] = '';
        vdMaterialInsert($db, 'app_fields', $reserved);
    }
    $definitions = [
        'name' => ['Material / descrição', 'fieldtype_input', ['width' => 'input-xlarge'], true],
        'article' => ['Artigo', 'fieldtype_entity_ajax', ['entity_id' => '99','display_as' => 'dropdown','width' => 'input-large','hide_plus_button' => '1'], true],
        'internal' => ['Referência interna', 'fieldtype_entity_ajax', ['entity_id' => '281','display_as' => 'dropdown','width' => 'input-large','hide_plus_button' => '1'], true],
        'quantity' => ['Quantidade prevista', 'fieldtype_input_numeric', ['width' => 'input-small','number_format' => '6/./ '], true],
        'unit' => ['Unidade', 'fieldtype_input', ['width' => 'input-small'], true],
        'process' => ['Operação', 'fieldtype_entity_ajax', ['entity_id' => '36','display_as' => 'dropdown','width' => 'input-large','hide_plus_button' => '1','display_as_link' => '1'], true],
        'operation' => ['Operação da OP', 'fieldtype_entity_ajax', ['entity_id' => '170','display_as' => 'dropdown','width' => 'input-large','hide_plus_button' => '1','heading_template' => '[2430] · [2426]'], true],
        'source' => ['Linha original na operação', 'fieldtype_entity_ajax', ['entity_id' => '161','display_as' => 'dropdown','width' => 'input-large','hide_plus_button' => '1'], false],
        'origin' => ['Origem adjudicada', 'fieldtype_input', ['width' => 'input-large'], false],
        'snapshot' => ['Dados aprovados de origem (JSON)', 'fieldtype_textarea', ['width' => 'input-xlarge','height' => '150'], false],
    ];
    foreach ($definitions as $role => $definition) {
        $field = $db->query("SELECT field_id FROM app_vd_component_material_fields WHERE role='$role'")->fetch_assoc();
        if ($field) {
            $fieldId = (int) $field['field_id'];
            if ($role === 'operation') {
                $previous = $definition[2];
                unset($previous['heading_template']);
                $oldConfiguration = json_encode($previous, JSON_THROW_ON_ERROR);
                $newConfiguration = json_encode($definition[2], JSON_THROW_ON_ERROR);
                $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=? AND entities_id=? AND configuration=?');
                $stmt->bind_param('siis', $newConfiguration, $fieldId, $entity, $oldConfiguration);
                $stmt->execute();
            }
            if (in_array($role, ['article','internal','operation','source'], true)) {
                $current = $db->query("SELECT configuration FROM app_fields WHERE id=$fieldId AND entities_id=$entity")->fetch_assoc();
                $configuration = json_decode($current['configuration'], true, 512, JSON_THROW_ON_ERROR);
                if (!array_key_exists('display_as_link', $configuration)) {
                    $configuration['display_as_link'] = '1';
                    $next = json_encode($configuration, JSON_THROW_ON_ERROR);
                    $stmt = $db->prepare('UPDATE app_fields SET configuration=? WHERE id=? AND configuration=?');
                    $stmt->bind_param('sis', $next, $fieldId, $current['configuration']);
                    $stmt->execute();
                }
            }
        }
        else {
            $template = $db->query('SELECT * FROM app_fields WHERE id=2543')->fetch_assoc();
            $template = array_replace($template, ['entities_id' => $entity, 'forms_tabs_id' => $tabId,
                'name' => $definition[0], 'type' => $definition[1], 'is_heading' => $role === 'name' ? 1 : 0,
                'listing_status' => $definition[3] ? 1 : 0, 'is_required' => 0,
                'sort_order' => 10 + array_search($role, array_keys($definitions)),
                'listing_sort_order' => 10 + array_search($role, array_keys($definitions)),
                'configuration' => json_encode($definition[2] + ($definition[1] === 'fieldtype_entity_ajax' ? ['display_as_link' => '1'] : []), JSON_THROW_ON_ERROR),
                'tooltip' => 'Previsto no orçamento adjudicado; não reserva nem consome stock.']);
            $db->begin_transaction();
            try {
                $fieldId = vdMaterialInsert($db, 'app_fields', $template);
                $db->query("INSERT INTO app_vd_component_material_fields VALUES('$role',$entity,$fieldId)");
                $db->commit();
            } catch (Throwable $error) { $db->rollback(); throw $error; }
        }
        if (!$db->query("SHOW COLUMNS FROM app_entity_$entity LIKE 'field_$fieldId'")->num_rows) $db->query("ALTER TABLE app_entity_$entity ADD field_$fieldId MEDIUMTEXT NULL");
    }
    foreach (['menu_title' => 'Materiais','listing_heading' => 'Materiais previstos do componente','disable_notification' => '1','disable_internal_notification' => '1','use_comments' => '0','redirect_after_adding' => 'subentity'] as $name => $value) {
        if (!$db->query("SELECT id FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='$name'")->num_rows) vdMaterialInsert($db, 'app_entities_configuration', ['entities_id' => $entity,'configuration_name' => $name,'configuration_value' => $value]);
    }
    foreach ($db->query('SELECT * FROM app_entities_access WHERE entities_id=161')->fetch_all(MYSQLI_ASSOC) as $access) {
        $group = (int) $access['access_groups_id'];
        if ($db->query("SELECT id FROM app_entities_access WHERE entities_id=$entity AND access_groups_id=$group")->num_rows) continue;
        $access['entities_id'] = $entity;
        vdMaterialInsert($db, 'app_entities_access', $access);
    }
    $schema = vd_component_material_schema($db);
    $snapshotField = substr($schema['fields']['snapshot'], strlen('field_'));
    $configuration = $db->query("SELECT id,configuration_value FROM app_entities_configuration WHERE entities_id=$entity AND configuration_name='item_page_hidden_fields'")->fetch_assoc();
    $hidden = array_filter(explode(',', $configuration['configuration_value'] ?? ''));
    $hidden[] = $snapshotField;
    $value = implode(',', array_unique($hidden));
    if ($configuration) {
        $stmt = $db->prepare('UPDATE app_entities_configuration SET configuration_value=? WHERE id=?');
        $stmt->bind_param('si', $value, $configuration['id']);
        $stmt->execute();
    } else {
        vdMaterialInsert($db, 'app_entities_configuration', ['entities_id' => $entity,
            'configuration_name' => 'item_page_hidden_fields', 'configuration_value' => $value]);
    }
    echo "Component materials entity ready: $entity, parent 159.\n";
    if (in_array('--backfill', $argv, true)) {
        foreach ($db->query('SELECT quote_id,order_id,created_by FROM app_vd_quote_production_orders')->fetch_all(MYSQLI_ASSOC) as $order) {
            $lock = 'vd_workflow_' . (int) $order['quote_id'];
            if ((int) $db->query("SELECT GET_LOCK('$lock',10)")->fetch_row()[0] !== 1) throw new RuntimeException('Quotation busy.');
            try {
                $db->begin_transaction();
                $before = (int) $db->query('SELECT COUNT(*) FROM app_vd_component_material_rows WHERE order_id=' . (int) $order['order_id'])->fetch_row()[0];
                $count = 0;
                foreach ($db->query('SELECT native_id FROM app_vd_quote_production_rows WHERE entity_id=161 AND order_id=' . (int) $order['order_id'])->fetch_all(MYSQLI_ASSOC) as $material) {
                    vd_component_material_project($db, $material['native_id'], $order['created_by']);
                    $count++;
                }
                if ($count > $before) {
                    $detail = json_encode(['order' => (int) $order['order_id'], 'entity' => $entity,
                        'created' => $count - $before, 'source' => 'approved_production_materials',
                        'stock_movement' => false], JSON_THROW_ON_ERROR);
                    $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,0,'component_materials_backfilled',?,?)");
                    $now = time();
                    $stmt->bind_param('isi', $order['quote_id'], $detail, $now);
                    $stmt->execute();
                }
                $db->commit();
                echo "OP {$order['order_id']}: $count component materials verified.\n";
            } catch (Throwable $error) { $db->rollback(); throw $error; }
            finally { $db->query("SELECT RELEASE_LOCK('$lock')"); }
        }
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('vd_component_material_schema')");
}

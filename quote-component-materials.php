<?php
function vd_component_material_schema(mysqli $db)
{
    $rows = $db->query('SELECT role,entity_id,field_id FROM app_vd_component_material_fields')->fetch_all(MYSQLI_ASSOC);
    if (!$rows) throw new RuntimeException('Entidade Materiais do componente não instalada.');
    $schema = ['entity' => (int) $rows[0]['entity_id'], 'fields' => []];
    foreach ($rows as $row) {
        if ((int) $row['entity_id'] !== $schema['entity']) throw new RuntimeException('Configuração de materiais inconsistente.');
        $schema['fields'][$row['role']] = 'field_' . (int) $row['field_id'];
    }
    foreach (['name','article','internal','quantity','unit','process','operation','source','origin','snapshot'] as $role) {
        if (!isset($schema['fields'][$role])) throw new RuntimeException('Campo de materiais em falta: ' . $role);
    }
    return $schema;
}

// Called inside the quotation transaction, or the explicit CLI backfill transaction.
function vd_component_material_project(mysqli $db, $sourceId, $actor)
{
    $schema = vd_component_material_schema($db);
    $entity = $schema['entity'];
    $sourceId = (int) $sourceId;
    $source = $db->query("SELECT r.*,o.parent_item_id component_id,o.field_2426 operation_type FROM app_vd_quote_production_rows r JOIN app_entity_170 o ON o.id=r.parent_id WHERE r.entity_id=161 AND r.native_id=$sourceId FOR UPDATE")->fetch_assoc();
    if (!$source || !hash_equals($source['hash'], hash('sha256', $source['snapshot']))) throw new RuntimeException('Origem do material inválida.');
    $existing = $db->query("SELECT * FROM app_vd_component_material_rows WHERE source_id=$sourceId")->fetch_assoc();
    if ($existing) {
        $native = $db->query("SELECT * FROM app_entity_$entity WHERE id=" . (int) $existing['native_id'])->fetch_assoc();
        if (!$native || (int) $native['parent_item_id'] !== (int) $source['component_id'] ||
            (int) $native[$schema['fields']['source']] !== $sourceId ||
            (int) $native[$schema['fields']['operation']] !== (int) $source['parent_id'] ||
            !hash_equals($source['hash'], hash('sha256', $native[$schema['fields']['snapshot']])) ||
            !hash_equals($existing['hash'], $source['hash'])) throw new RuntimeException('Material do componente alterado ou removido; rever antes de regenerar.');
        if ($native[$schema['fields']['process']] === null) {
            $stmt = $db->prepare("UPDATE app_entity_$entity SET " . $schema['fields']['process'] . '=? WHERE id=? AND ' . $schema['fields']['process'] . ' IS NULL');
            $stmt->bind_param('ii', $source['operation_type'], $existing['native_id']);
            $stmt->execute();
        }
        return (int) $existing['native_id'];
    }
    $roles = [];
    foreach ($db->query("SELECT role,field_id FROM app_vd_quote_native_fields WHERE entity_id=161 AND role LIKE 'op_%'") as $field) $roles[$field['role']] = 'field_' . (int) $field['field_id'];
    $nativeSource = $db->query("SELECT * FROM app_entity_161 WHERE id=$sourceId")->fetch_assoc();
    if (!$nativeSource || (int) $nativeSource['parent_item_id'] !== (int) $source['parent_id']) throw new RuntimeException('Material original inexistente ou movido.');
    $values = ['parent_item_id' => $source['component_id'], 'created_by' => (int) $actor, 'date_added' => time()];
    $content = ['name' => $nativeSource['field_2543'], 'article' => $nativeSource[$roles['op_article']],
        'internal' => $nativeSource[$roles['op_internal']], 'quantity' => $nativeSource[$roles['op_quantity']],
        'unit' => $nativeSource[$roles['op_unit']], 'process' => $source['operation_type'],
        'operation' => $source['parent_id'], 'source' => $sourceId,
        'origin' => $nativeSource[$roles['op_origin']], 'snapshot' => $source['snapshot']];
    foreach ($content as $role => $value) $values[$schema['fields'][$role]] = $value;
    $stmt = $db->prepare("INSERT INTO app_entity_$entity (`" . implode('`,`', array_keys($values)) . '`) VALUES(' . implode(',', array_fill(0, count($values), '?')) . ')');
    $parameters = array_values($values);
    $stmt->bind_param(str_repeat('s', count($parameters)), ...$parameters);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt = $db->prepare('INSERT INTO app_vd_component_material_rows(source_id,native_id,order_id,hash) VALUES(?,?,?,?)');
    $stmt->bind_param('iiis', $sourceId, $id, $source['order_id'], $source['hash']);
    $stmt->execute();
    return $id;
}

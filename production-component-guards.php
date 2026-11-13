<?php
function vd_production_component_guard_sql()
{
    $blocked = static function($component) {
        return "EXISTS(SELECT 1 FROM app_vd_production_component_inputs i WHERE i.component_id=$component AND
            (NOT EXISTS(SELECT 1 FROM app_entity_170 source_op WHERE source_op.parent_item_id=i.input_component_id) OR
            EXISTS(SELECT 1 FROM app_entity_170 source_op WHERE source_op.parent_item_id=i.input_component_id AND source_op.field_2425 NOT IN(39,3790))))";
    };
    $signal="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Assemblagem bloqueada: conclua todas as operacoes dos componentes de entrada';";
    return [
        'vd_product_operation_start'=>"BEFORE UPDATE ON app_entity_170 FOR EACH ROW BEGIN
            IF ((NOT (NEW.field_2425 <=> OLD.field_2425) AND NEW.field_2425 NOT IN(36,3718))
                OR NEW.field_2549+0>OLD.field_2549+0 OR NEW.parent_item_id<>OLD.parent_item_id) AND ".$blocked('NEW.parent_item_id')." THEN $signal END IF; END",
        'vd_product_operation_insert'=>"BEFORE INSERT ON app_entity_170 FOR EACH ROW BEGIN
            IF (NEW.field_2425 NOT IN(36,3718) OR NEW.field_2549+0>0) AND ".$blocked('NEW.parent_item_id')." THEN $signal END IF; END",
        'vd_product_time_insert'=>"BEFORE INSERT ON app_entity_171 FOR EACH ROW BEGIN
            IF (NEW.field_2446>0 OR NEW.field_2447>0 OR NEW.field_2445+0>0) AND ".
            $blocked('(SELECT parent_item_id FROM app_entity_170 WHERE id=NEW.parent_item_id)')." THEN $signal END IF; END",
        'vd_product_time_update'=>"BEFORE UPDATE ON app_entity_171 FOR EACH ROW BEGIN
            IF (NEW.parent_item_id<>OLD.parent_item_id OR NEW.field_2446<>OLD.field_2446 OR NEW.field_2445+0>OLD.field_2445+0) AND ".
            $blocked('(SELECT parent_item_id FROM app_entity_170 WHERE id=NEW.parent_item_id)')." THEN $signal END IF; END"
    ];
}

function vd_production_install_component_guards(mysqli $db, $createMissing = true)
{
    foreach(vd_production_component_guard_sql() as $name=>$body) {
        $existing=$db->query("SHOW TRIGGERS WHERE `Trigger`='$name'")->fetch_assoc();
        $statement=preg_replace('/^BEFORE (?:INSERT|UPDATE) ON \w+ FOR EACH ROW /','',$body);
        if ($existing) {
            if (preg_replace('/\s+/','', $existing['Statement'])!==preg_replace('/\s+/','',$statement)) {
                throw new RuntimeException('Production component guard changed: '.$name);
            }
        } elseif ($createMissing) $db->query("CREATE TRIGGER $name $body");
        else throw new RuntimeException('Missing production component guard: '.$name);
    }
}

<?php
require_once __DIR__ . '/quote-workflow-store.php';

function vd_production_kanban_time(mysqli $db, array $user, $operationId, $status, $now = null)
{
    $operationId = (int) $operationId;
    $status = (int) $status;
    $actor = (int) $user['id'];
    $now = $now ?? time();
    if ($operationId < 1 || $actor < 1) throw new DomainException('Operação ou utilizador inválido.');
    $workflow = new VDQuoteWorkflow($db, $user);
    $workflow->access(170, $operationId, 'update');
    $valid = $workflow->rows('SELECT id FROM app_global_lists_choices WHERE id=? AND lists_id=10 AND is_active=1', [$status]);
    if (!$valid) throw new DomainException('Estado inválido para a operação.');
    $running = in_array($status, [3717,3725], true);
    $lock = 'vd_operation_time_' . $operationId;
    if ((int) $workflow->rows('SELECT GET_LOCK(?,5) acquired', [$lock])[0]['acquired'] !== 1) throw new DomainException('Operação em atualização. Tente novamente.');
    try {
        $db->begin_transaction();
        $operation = $workflow->rows('SELECT * FROM app_entity_170 WHERE id=? FOR UPDATE', [$operationId])[0];
        $workflow->access(170, $operationId, 'update');
        $open = $workflow->rows('SELECT * FROM app_entity_171 WHERE parent_item_id=? AND created_by=? AND field_2446>0 AND field_2447=0 ORDER BY id FOR UPDATE', [$operationId, $actor]);
        if (count($open) > 1) throw new DomainException('Existem vários tempos abertos para este utilizador. Corrija-os antes de mudar o estado.');
        $timeId = null;
        $event = 'status';
        if ($running && !$open) {
            if (!users::has_access_to_entity(171, 'create') || !access_rules::has_add_buttons_access(171, $operationId)) throw new DomainException('Sem autorização para iniciar o registo de tempo.');
            $type = $status === 3725 ? 3721 : 3719;
            $stmt = $db->prepare("INSERT INTO app_entity_171(parent_item_id,date_added,created_by,field_2444,field_2445,field_2446,field_2447,field_2507,field_2552) VALUES(?,?,?,?,'',?,0,36,'')");
            $stmt->bind_param('iiiii', $operationId, $now, $actor, $type, $now);
            $stmt->execute();
            $timeId = (int) $db->insert_id;
            $event = 'start';
            foreach ([2444 => $type, 2507 => 36] as $field => $value) $db->query("INSERT INTO app_entity_171_values(items_id,fields_id,value) VALUES($timeId,$field,$value)");
        } elseif (!$running && $open) {
            $timeId = (int) $open[0]['id'];
            $workflow->access(171, $timeId, 'update');
            if ($now < (int) $open[0]['field_2446']) throw new DomainException('A hora de fim é anterior ao início do tempo.');
            $type = $status === 39 ? 3722 : 3720;
            $stmt = $db->prepare('UPDATE app_entity_171 SET field_2447=?,field_2444=?,field_2507=39,date_updated=? WHERE id=? AND field_2447=0');
            $stmt->bind_param('iiii', $now, $type, $now, $timeId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('O registo de tempo mudou durante a atualização.');
            $event = 'stop';
            $db->query("DELETE FROM app_entity_171_values WHERE items_id=$timeId AND fields_id IN(2444,2507)");
            foreach ([2444 => $type, 2507 => 39] as $field => $value) $db->query("INSERT INTO app_entity_171_values(items_id,fields_id,value) VALUES($timeId,$field,$value)");
        } elseif ($open) {
            $timeId = (int) $open[0]['id'];
        }
        $stmt = $db->prepare('UPDATE app_entity_170 SET field_2425=?,date_updated=? WHERE id=?');
        $stmt->bind_param('iii', $status, $now, $operationId);
        $stmt->execute();
        $db->query("DELETE FROM app_entity_170_values WHERE items_id=$operationId AND fields_id=2425");
        $db->query("INSERT INTO app_entity_170_values(items_id,fields_id,value) VALUES($operationId,2425,$status)");
        if ((int) $operation['field_2425'] !== $status || in_array($event, ['start','stop'], true)) {
            $previous = (int) $operation['field_2425'];
            $stmt = $db->prepare('INSERT INTO app_vd_production_time_audit(operation_id,actor_id,previous_status,status,time_id,event,created_at) VALUES(?,?,?,?,?,?,?)');
            $stmt->bind_param('iiiiisi', $operationId, $actor, $previous, $status, $timeId, $event, $now);
            $stmt->execute();
        }
        $db->commit();
        return ['event' => $event, 'time_id' => $timeId, 'previous_status' => (int) $operation['field_2425']];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    } finally {
        $workflow->rows('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

<?php
require_once __DIR__ . '/quote-production-order.php';
require_once __DIR__ . '/quote-product.php';

function vd_production_planned_start_sql($orderExpression, $actualStartExpression)
{
    return "COALESCE((SELECT MIN(o.field_2536) FROM app_entity_170 o JOIN app_entity_159 c ON c.id=o.parent_item_id WHERE c.parent_item_id=$orderExpression AND o.field_2536>0 AND o.field_2531>=o.field_2536),NULLIF($actualStartExpression,0),0)";
}

function vd_production_schedule_slot(DateTimeImmutable $cursor)
{
    $cursor = $cursor->setTimezone(new DateTimeZone('Europe/Lisbon'));
    while (true) {
        if (vd_production_workday($cursor)) {
            foreach ([[14,0,17,0], [8,0,12,30]] as $period) {
                $start = $cursor->setTime($period[0], $period[1]);
                $end = $cursor->setTime($period[2], $period[3]);
                if ($cursor > $start) return [$start, $cursor < $end ? $cursor : $end];
            }
        }
        $cursor = $cursor->modify('-1 day')->setTime(17, 0);
    }
}

function vd_production_schedule_backwards(DateTimeImmutable $delivery, array $operations, DateTimeImmutable $deadline = null)
{
    $cursor = $deadline ? $deadline->setTimezone(new DateTimeZone('Europe/Lisbon')) :
        $delivery->setTimezone(new DateTimeZone('Europe/Lisbon'))->modify('-1 day')->setTime(17, 0);
    while (!vd_production_workday($cursor)) $cursor = $cursor->modify('-1 day')->setTime(17, 0);
    $result = [];
    foreach (array_reverse($operations, true) as $key => $minutes) {
        if (!is_numeric($minutes) || !is_finite((float) $minutes) || $minutes < 0 ||
            $minutes > 450 * 260 * 10) throw new DomainException('Duração inválida para o planeamento.');
        $seconds = (int) ceil((float) $minutes * 60);
        [$slotStart, $cursor] = vd_production_schedule_slot($cursor);
        $end = $cursor;
        while ($seconds > 0) {
            [$slotStart, $cursor] = vd_production_schedule_slot($cursor);
            $available = $cursor->getTimestamp() - $slotStart->getTimestamp();
            $consume = min($available, $seconds);
            $cursor = $cursor->modify('-' . $consume . ' seconds');
            $seconds -= $consume;
        }
        $result[$key] = ['start' => $cursor->getTimestamp(), 'end' => $end->getTimestamp()];
    }
    return array_replace(array_fill_keys(array_keys($operations), null), $result);
}

// The caller owns the quotation lock and database transaction.
function vd_production_schedule_order(mysqli $db, $orderId, $preserveStarted, $actor)
{
    $orderId = (int) $orderId;
    $order = $db->query("SELECT field_1992,field_5972 FROM app_entity_121 WHERE id=$orderId FOR UPDATE")->fetch_assoc();
    if (!$order || (int) $order['field_1992'] <= 0) throw new DomainException('Defina a data de entrega antes de planear as operações.');
    $delivery = (new DateTimeImmutable('@' . $order['field_1992']))->setTimezone(new DateTimeZone('Europe/Lisbon'));
    $rows = $db->query("SELECT o.*,(SELECT COUNT(*) FROM app_entity_171 t WHERE t.parent_item_id=o.id) time_rows FROM app_entity_170 o JOIN app_vd_quote_production_rows r ON r.entity_id=170 AND r.native_id=o.id WHERE r.order_id=$orderId ORDER BY o.parent_item_id,o.field_2430+0,o.id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $components = [];
    foreach ($rows as $row) $components[$row['parent_item_id']][] = $row;
    $changes = [];
    $skipped = [];
    $dependencies=$db->query("SELECT component_id,input_component_id FROM app_vd_production_component_inputs WHERE order_id=$orderId ORDER BY component_id,input_component_id")->fetch_all(MYSQLI_ASSOC);
    $inputs=[];
    foreach($dependencies as $dependency) {
        $component=(int)$dependency['component_id'];$input=(int)$dependency['input_component_id'];
        if (!isset($components[$component],$components[$input])) throw new DomainException('Dependência de produção sem operações num dos componentes.');
        $inputs[$component][]=$input;
    }
    $graph=[];
    foreach(array_keys($components) as $component) $graph[]=['id'=>(int)$component,'kind'=>isset($inputs[$component])?'assembly':'material','inputs'=>$inputs[$component]??[]];
    $orderOfComponents=array_reverse(vd_product_graph($graph));
    $deadlines=[];
    foreach ($orderOfComponents as $componentId) {
        $operations=$components[$componentId];
        $durations = [];
        foreach ($operations as $operation) $durations[$operation['id']] = $operation['field_2431'];
        $deadline=isset($deadlines[$componentId]) ? new DateTimeImmutable('@'.$deadlines[$componentId]) : null;
        $schedule = vd_production_schedule_backwards($delivery, $durations, $deadline);
        $firstStart=null;
        foreach ($operations as $operation) {
            $id = (int) $operation['id'];
            if ($preserveStarted && ((int) $operation['time_rows'] > 0 ||
                (float) $operation['field_2549'] > 0 ||
                !in_array((int) $operation['field_2425'], [36,3718], true))) {
                $skipped[] = $id;
                if ((int)$operation['field_2536']>0) $firstStart=$firstStart===null?(int)$operation['field_2536']:min($firstStart,(int)$operation['field_2536']);
                continue;
            }
            $start = $schedule[$id]['start'];
            $end = $schedule[$id]['end'];
            $firstStart=$firstStart===null?$start:min($firstStart,$start);
            if ((int) $operation['field_2536'] === $start && (int) $operation['field_2531'] === $end) continue;
            $stmt = $db->prepare('UPDATE app_entity_170 SET field_2536=?,field_2531=? WHERE id=?');
            $stmt->bind_param('iii', $start, $end, $id);
            $stmt->execute();
            $changes[] = ['operation' => $id, 'previous_start' => $operation['field_2536'],
                'previous_deadline' => $operation['field_2531'], 'start' => $start, 'deadline' => $end];
        }
        foreach($inputs[$componentId]??[] as $inputId) {
            if ($firstStart===null) throw new DomainException('Assemblagem iniciada sem data de início válida.');
            $deadlines[$inputId]=isset($deadlines[$inputId])?min($deadlines[$inputId],$firstStart):$firstStart;
        }
    }
    if ($changes) {
        $json = json_encode(['order' => $orderId, 'delivery_date' => $delivery->format('Y-m-d'),
            'tolerance_workdays' => 1, 'working_hours' => '08:00-12:30,14:00-17:00',
            'timezone' => 'Europe/Lisbon', 'changes' => $changes, 'preserved_started_operations' => $skipped,
            'resource_capacity_scheduling' => false], JSON_THROW_ON_ERROR);
        $now = time();
        $quote = (int) $order['field_5972'];
        $actor = (int) $actor;
        $stmt = $db->prepare("INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,?,'production_operations_scheduled',?,?)");
        $stmt->bind_param('iisi', $quote, $actor, $json, $now);
        $stmt->execute();
    }
    return ['planned' => count($changes), 'preserved' => $skipped];
}

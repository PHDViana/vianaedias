<?php
if ((int) $reports['entities_id'] === 170 && (int) $reports['group_by_field'] === 2425) {
    try {
        require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/production-kanban-time.php';
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $vdTimeDb = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
        $vdTimeDb->set_charset('utf8mb4');
        if (!$is_kanban_sotrtable || !array_key_exists($choices_id, $kanban_choices) ||
            in_array((string) $choices_id, explode(',', $reports['exclude_choices']), true)) throw new DomainException('Não é permitido mover a operação para este estado.');
        $vdTimeResult = vd_production_kanban_time($vdTimeDb, $app_user, $item_id, $choices_id);
        $previous_choices_id = $vdTimeResult['previous_status'];
        $vdTimeDb->close();
    } catch (Throwable $vdTimeError) {
        error_log('VD Kanban production time: ' . $vdTimeError->getMessage());
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $vdTimeError instanceof DomainException ? $vdTimeError->getMessage() :
            'Não foi possível guardar o estado e o tempo. Atualize o quadro antes de tentar novamente.'], JSON_UNESCAPED_UNICODE);
        exit();
    }
} else {
    db_query("update app_entity_" . $reports['entities_id'] . " set field_" . $reports['group_by_field'] . " = " . $choices_id . " where id='" . $item_id . "'");
}

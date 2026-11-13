<?php
namespace VDAttendance;

function managesAttendance(array $user): bool
{
    return isset($user['id'], $user['group_id']) && (int) $user['id'] > 0 &&
        in_array((string) $user['group_id'], ['0', '4'], true);
}

function employeeScope(array $user): array
{
    if (!isset($user['id']) || (int) $user['id'] <= 0) {
        return ['manager' => false, 'employee' => 0, 'error' => 'É necessário iniciar sessão para consultar o resumo de ponto.'];
    }
    if (managesAttendance($user)) {
        return ['manager' => true, 'employee' => 0, 'error' => ''];
    }
    $userId = (int) $user['id'];
    $employees = rows("SELECT id FROM app_entity_141 WHERE field_1900=$userId");
    if (count($employees) !== 1) {
        return ['manager' => false, 'employee' => 0, 'error' => 'Não é possível identificar o seu recurso humano de forma única. Contacte a direção para verificar a ligação ao utilizador.'];
    }
    return ['manager' => false, 'employee' => (int) $employees[0]['id'], 'error' => ''];
}

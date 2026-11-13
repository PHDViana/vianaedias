<?php

function vd_equipment_cost(array $values): array
{
    $labels = [
        'replacement' => 'valor de substituição',
        'reserve' => 'montante reservado',
        'years' => 'prazo de renovação',
        'weekly_hours' => 'horas semanais',
        'weeks' => 'semanas anuais',
        'maintenance' => 'manutenção anual',
        'other' => 'outros custos anuais',
    ];
    $numbers = [];
    foreach ($labels as $key => $label) {
        $value = $values[$key] ?? null;
        if ($value === null || trim((string) $value) === '') {
            $numbers[$key] = null;
        } elseif (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            return ['error' => 'Valor inválido: ' . $label . '.'];
        } else {
            $numbers[$key] = (float) $value;
        }
    }
    foreach (['years', 'weekly_hours', 'weeks'] as $key) {
        if ($numbers[$key] !== null && $numbers[$key] <= 0) {
            return ['error' => 'O valor de ' . $labels[$key] . ' deve ser superior a zero.'];
        }
    }
    if ($numbers['weeks'] !== null && $numbers['weeks'] > 52) {
        return ['error' => 'As semanas anuais não podem ultrapassar 52.'];
    }
    if ($numbers['replacement'] !== null && $numbers['reserve'] !== null &&
        $numbers['reserve'] > $numbers['replacement']) {
        return ['error' => 'O montante reservado não pode exceder o valor de substituição.'];
    }
    $missing = [];
    foreach ($numbers as $key => $number) {
        if ($number === null) $missing[] = $labels[$key];
    }
    $hours = $numbers['weekly_hours'] !== null && $numbers['weeks'] !== null
        ? $numbers['weekly_hours'] * $numbers['weeks'] : null;
    $renewal = $numbers['replacement'] !== null && $numbers['reserve'] !== null && $numbers['years'] !== null
        ? ($numbers['replacement'] - $numbers['reserve']) / $numbers['years'] : null;
    $partial = $hours !== null && $renewal !== null && $numbers['maintenance'] !== null
        ? ($renewal + $numbers['maintenance']) / $hours : null;
    $total = !$missing && $partial !== null ? $partial + $numbers['other'] / $hours : null;
    return compact('numbers', 'missing', 'hours', 'renewal', 'partial', 'total');
}

function vd_equipment_cost_html(array $values): string
{
    $result = vd_equipment_cost($values);
    if (isset($result['error'])) return htmlspecialchars($result['error'], ENT_QUOTES, 'UTF-8');
    $format = static fn($value, $decimals = 2) => number_format($value, $decimals, ',', ' ');
    $lines = [];
    if ($result['hours'] !== null) $lines[] = 'Utilização estimada: ' . $format($result['hours']) . ' h/ano';
    if ($result['renewal'] !== null) $lines[] = 'Reserva para renovação: ' . $format($result['renewal']) . ' €/ano';
    if ($result['partial'] !== null) {
        $lines[] = 'Renovação + manutenção: ' . $format($result['partial']) . ' €/h';
    }
    if ($result['total'] !== null) {
        $lines[] = '<strong>Custo do equipamento: ' . $format($result['total']) . ' €/h</strong>';
    } else {
        $lines[] = '<strong>Custo total por completar.</strong> Falta: ' .
            htmlspecialchars(implode(', ', $result['missing']), ENT_QUOTES, 'UTF-8') . '.';
    }
    $lines[] = 'Energia e pessoas não incluídas. A reserva deve ser constituída efetivamente.';
    return implode('<br>', $lines);
}

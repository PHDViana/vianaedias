<?php
require __DIR__ . '/equipment-hourly-cost.php';

function check($condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$base = ['replacement' => 50000, 'reserve' => 0, 'years' => 5, 'weekly_hours' => 7,
    'weeks' => 47, 'maintenance' => 500, 'other' => null];
$result = vd_equipment_cost($base);
check($result['hours'] === 329.0, 'Annual hours');
check(abs($result['partial'] - 10500 / 329) < 0.000001, 'Partial cost');
check($result['total'] === null && $result['missing'] === ['outros custos anuais'], 'Unknown costs');
check(strpos(vd_equipment_cost_html($base), '31,91 €/h') !== false, 'Displayed partial amount');
$base['other'] = 329;
check(abs(vd_equipment_cost($base)['total'] - (10500 / 329 + 1)) < 0.000001, 'Complete cost');
$base['weekly_hours'] = 0;
check(isset(vd_equipment_cost($base)['error']), 'Zero hours');
$base['weekly_hours'] = 7;
$base['reserve'] = 50001;
check(isset(vd_equipment_cost($base)['error']), 'Excess reserve');
$base['reserve'] = 0;
$base['weeks'] = 53;
check(isset(vd_equipment_cost($base)['error']), 'Invalid weeks');
echo "Equipment cost tests passed.\n";

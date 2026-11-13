<?php
require dirname(__DIR__) . '/vd_quote_workflow/attendance-calculation.php';

$tests = 0;
function same($actual, $expected, string $message): void
{
    global $tests;
    $tests++;
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode([$actual, $expected]));
    }
}
$zone = new DateTimeZone('Europe/Lisbon');
function stamp(string $value): int
{
    global $zone;
    return (new DateTimeImmutable($value, $zone))->getTimestamp();
}
function punch(string $from, ?string $to): array
{
    return ['start' => stamp($from), 'end' => $to ? stamp($to) : 0];
}
function calculate(array $punches, array $absences = [], bool $working = true, string $date = '2026-01-05', ?int $now = null): array
{
    global $zone;
    return VDAttendance\dayCalculation($date, [['08:00', '12:30'], ['13:30', '17:00']], $punches, $absences, $working, $zone, $now ?? stamp('2026-12-01'));
}
$employee = ['field_6589' => 1631, 'field_6590' => '00:00', 'field_6591' => '08:00', 'field_6592' => '', 'field_6593' => ''];
same(VDAttendance\schedule($employee), [['00:00', '08:00']], 'One period preserves midnight');
$employee['field_6589'] = 1632;
$employee['field_6592'] = '09:00';
$employee['field_6593'] = '12:00';
same(count(VDAttendance\schedule($employee)), 2, 'Two periods');
$employee['field_6592'] = '07:59';
try {
    VDAttendance\schedule($employee);
    throw new RuntimeException('Overlapping schedule accepted');
} catch (InvalidArgumentException $expected) {
    $tests++;
}
$normal = [punch('2026-01-05 08:00', '2026-01-05 12:30'), punch('2026-01-05 13:30', '2026-01-05 17:00')];
$day = calculate($normal);
foreach (['planned' => 480, 'worked' => 480, 'extra' => 0, 'missing' => 0, 'balance' => 0] as $key => $expected) {
    same($day[$key], $expected, 'Normal day ' . $key);
}
$day = calculate([punch('2026-01-05 08:03:59', '2026-01-05 12:31:59'), punch('2026-01-05 13:30', '2026-01-05 17:02')]);
same($day['missing'], 3, 'No tolerance');
same($day['extra'], 3, 'Outside schedule includes lunch');
same($day['worked'], 480, 'Minute precision without rounding');
same($day['balance'], 0, 'Net zero does not hide missing minutes');
$day = calculate([]);
same($day['missing'], 480, 'No punches');
same($day['balance'], -480, 'Absent balance');
$day = calculate($normal, [], false);
same($day['planned'], 0, 'Weekend or holiday schedule');
same($day['extra'], 480, 'Non-working day extra');
$day = calculate([punch('2026-01-05 08:00', null)]);
same($day['worked'], null, 'Incomplete work unknown');
same($day['missing'], null, 'Incomplete work not a fabricated absence');
same($day['warnings'], ['Marcação sem saída'], 'Explicit incomplete warning');
$day = calculate([punch('2026-01-05 08:00', '2026-01-05 07:59')]);
same($day['balance'], null, 'Invalid exit');
$day = calculate(array_merge($normal, [$normal[0]]));
same($day['worked'], 480, 'Duplicates not double counted');
same(count($day['warnings']), 1, 'Overlaps reported');
$day = calculate([punch('2026-01-04 23:00', '2026-01-05 02:00')]);
same($day['worked'], 120, 'Overnight clipped to calendar date');
$day = calculate([], [['start' => stamp('2026-01-05 08:00'), 'end' => stamp('2026-01-05 17:00'), 'label' => 'Férias']]);
same($day['breakdown'], ['Férias' => 480], 'Vacation excludes lunch');
same($day['worked'], 0, 'Vacation does not invent attendance');
same($day['balance'], -480, 'Vacation remains absence in raw balance');
$day = calculate([], [
    ['start' => stamp('2026-01-05 08:00'), 'end' => stamp('2026-01-05 17:00'), 'label' => 'Férias'],
    ['start' => stamp('2026-01-05 08:00'), 'end' => stamp('2026-01-05 12:30'), 'label' => 'Outra ausência'],
]);
same(array_sum($day['breakdown']), 480, 'Overlapping approvals counted once');
same($day['unclassified'], 0, 'Approved absence coverage');
$day = calculate([], [], true, '2026-01-05', stamp('2026-01-05 10:00'));
same($day['missing'], null, 'Today not marked as absent');
same($day['provisional'], true, 'Today provisional');
$day = calculate([], [], true, '2026-01-05', stamp('2026-01-04 10:00'));
same($day['missing'], null, 'Future not marked as absent');
same($day['planned'], 480, 'Future planned hours retained');
$day = calculate([punch('2026-03-29 00:00', '2026-03-29 04:00')], [], false, '2026-03-29');
same($day['worked'], 180, 'DST spring elapsed hours');
$day = calculate([punch('2026-10-25 00:00', '2026-10-25 04:00')], [], false, '2026-10-25');
same($day['worked'], 300, 'DST autumn elapsed hours');
same(VDAttendance\formatMinutes(10080), '168:00', 'Monthly hours never wrap');
same(VDAttendance\formatMinutes(-2400), '-40:00', 'Negative balance');
same(VDAttendance\formatMinutes(null), '—', 'Unknown not zero');
same(VDAttendance\formatMinutes(0), '00:00', 'Zero duration');
$monthly = 0;
$expected = 0;
for ($day = 1; $day <= 31; $day++) {
    $date = sprintf('2026-01-%02d', $day);
    $business = (int) (new DateTimeImmutable($date))->format('N') <= 5 && $day !== 1;
    $result = calculate([], [], $business, $date);
    $expected += $result['planned'];
    $monthly += $result['missing'];
}
same($expected, 168 * 60, 'January 2026 with January 1 holiday: exact 168h');
same($monthly, $expected, 'Monthly daily sum');
echo "PASS $tests attendance assertions\n";

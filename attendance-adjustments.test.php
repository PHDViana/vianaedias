<?php
require dirname(__DIR__) . '/vd_quote_workflow/attendance-calculation.php';
require dirname(__DIR__) . '/vd_quote_workflow/attendance-adjustments.php';
$checks = 0;
function verify($actual, $expected, string $message): void
{
    global $checks;
    $checks++;
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode([$actual,$expected]));
}
$holidays = VDAttendance\statutoryHolidays(2026);
verify(count($holidays), 14, '13 mandatory national holidays plus Braga');
foreach (['2026-01-01','2026-04-03','2026-04-05','2026-04-25','2026-05-01','2026-06-04','2026-06-10','2026-06-24','2026-08-15','2026-10-05','2026-11-01','2026-12-01','2026-12-08','2026-12-25'] as $date) {
    verify(isset($holidays[$date]), true, 'Mandatory holiday ' . $date);
}
verify(isset($holidays['2026-02-17']), false, 'Carnival not a mandatory national holiday');
verify(isset(VDAttendance\statutoryHolidays(2027)['2027-03-26']), true, '2027 Good Friday');
verify(isset(VDAttendance\statutoryHolidays(2027)['2027-05-27']), true, '2027 Corpus Christi');
verify(isset(VDAttendance\statutoryHolidays(2024)['2024-05-30']), true, '2024 Corpus Christi');
verify(count(VDAttendance\statutoryHolidays(2014)), 10, 'Suspended holidays 2013-2015');
$zone = new DateTimeZone('Europe/Lisbon');
function ts(string $date): int {
    global $zone;
    return (new DateTimeImmutable($date, $zone))->getTimestamp();
}
$originals = [
    ['id'=>10,'start'=>ts('2026-06-23 08:00'),'end'=>0],
    ['id'=>11,'start'=>ts('2026-06-23 13:30'),'end'=>ts('2026-06-23 17:00')],
];
$correction = ['id'=>1,'action'=>'replace','original'=>10,'start'=>ts('2026-06-23 08:00'),'end'=>ts('2026-06-23 12:30'),'reason'=>'Saída não marcada','active'=>true];
$result = VDAttendance\adjustedPunches($originals, [$correction]);
verify($result['errors'], [], 'Valid replacement');
verify($originals[0]['end'], 0, 'Raw originals unchanged');
verify(count($result['punches']), 2, 'Replacement does not double count');
$worked = VDAttendance\dayCalculation('2026-06-23',[['08:00','12:30'],['13:30','17:00']],$result['punches'],[],true,$zone,ts('2026-07-01'));
verify($worked['worked'], 480, 'Missing exit correction exact 8h');
verify($worked['missing'], 0, 'Corrected absence cleared');
$add = array_merge($correction,['id'=>2,'action'=>'add','original'=>0,'start'=>ts('2026-06-24 08:00'),'end'=>ts('2026-06-24 12:00')]);
$result = VDAttendance\adjustedPunches($originals, [$correction,$add]);
verify(count($result['punches']), 3, 'Added missing pair');
$worked = VDAttendance\dayCalculation('2026-06-24',[['08:00','12:30'],['13:30','17:00']],$result['punches'],[],!isset($holidays['2026-06-24']),$zone,ts('2026-07-01'));
verify($worked['extra'], 240, 'Braga holiday work all extra');
verify($worked['planned'], 0, 'Braga holiday no planned hours');
$cancel = array_merge($correction,['action'=>'cancel','start'=>0,'end'=>0]);
$result = VDAttendance\adjustedPunches($originals, [$cancel]);
verify(count($result['punches']), 1, 'Canceled original excluded');
verify($result['errors'], [], 'Cancellation needs no invented times');
verify(count($result['events']), 1, 'Cancellation trace retained');
$inactive = array_merge($correction,['active'=>false]);
$result = VDAttendance\adjustedPunches($originals, [$inactive]);
verify($result['punches'], $originals, 'Inactive correction restores original');
verify($result['events'], [], 'Inactive corrections not shown as applied');
$result = VDAttendance\adjustedPunches($originals, [$correction,array_merge($correction,['id'=>3])]);
verify(count($result['errors']), 2, 'Conflicting active corrections explicitly rejected');
verify($result['punches'], $originals, 'Conflicting corrections do not alter original');
$result = VDAttendance\adjustedPunches($originals,[array_merge($correction,['original'=>999])]);
verify(count($result['errors']), 1, 'Foreign or missing original rejected');
foreach ([
    ['action'=>'invalid'],['reason'=>''],['start'=>0],['end'=>0],
    ['end'=>ts('2026-06-23 07:00')],['end'=>ts('2026-06-25 12:30')],
    ['action'=>'add','original'=>10], ['original'=>'10.5'],
] as $invalid) {
    $result = VDAttendance\adjustedPunches($originals,[array_merge($correction,$invalid)]);
    verify(count($result['errors']), 1, 'Invalid correction ' . json_encode($invalid));
}
$moved = array_merge($correction,['start'=>ts('2026-07-01 08:00'),'end'=>ts('2026-07-01 12:30')]);
$result = VDAttendance\adjustedPunches($originals,[$moved]);
verify($result['errors'], [], 'Cross-month correction');
verify(count($result['events']), 2, 'Old and new date both annotated');
$minutes = 0;
for ($day = 1; $day <= 30; $day++) {
    $date = sprintf('2026-06-%02d',$day);
    $working = (int)(new DateTimeImmutable($date))->format('N')<=5 && !isset($holidays[$date]);
    $data = VDAttendance\dayCalculation($date,[['08:00','12:30'],['13:30','17:00']],[],[],$working,$zone,ts('2026-07-01'));
    $minutes += $data['planned'];
}
verify($minutes, 152*60, 'June 2026 exactly 152h excludes Corpus, Portugal and Braga');
echo "PASS $checks holiday/correction assertions\n";

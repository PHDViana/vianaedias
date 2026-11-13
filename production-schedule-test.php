<?php
require_once __DIR__ . '/production-schedule.php';
$checks = 0;
function scheduleCheck($ok) {
    global $checks;
    if (!$ok) throw new RuntimeException('Schedule check failed #' . ($checks + 1));
    $checks++;
}
function scheduleDates($delivery, array $minutes) {
    $tz = new DateTimeZone('Europe/Lisbon');
    $rows = vd_production_schedule_backwards(new DateTimeImmutable($delivery, $tz), $minutes);
    return array_map(static function ($row) use ($tz) {
        return array_map(static function ($stamp) use ($tz) { return (new DateTimeImmutable('@' . $stamp))->setTimezone($tz)->format('Y-m-d H:i'); }, $row);
    }, $rows);
}
$rows = scheduleDates('2026-11-12', [6,11,35,23,30,12,7]);
scheduleCheck($rows[6] === ['start' => '2026-11-11 16:53', 'end' => '2026-11-11 17:00']);
scheduleCheck($rows[0]['start'] === '2026-11-11 14:56');
scheduleCheck(scheduleDates('2026-11-16', [60])[0]['end'] === '2026-11-13 17:00');
scheduleCheck(scheduleDates('2026-12-02', [60])[0]['end'] === '2026-11-30 17:00');
scheduleCheck(scheduleDates('2026-04-06', [60])[0]['end'] === '2026-04-02 17:00');
scheduleCheck(scheduleDates('2026-06-05', [60])[0]['end'] === '2026-06-03 17:00');
scheduleCheck(scheduleDates('2026-11-12', [240])[0]['start'] === '2026-11-11 11:30');
scheduleCheck(scheduleDates('2026-11-12', [450])[0]['start'] === '2026-11-11 08:00');
scheduleCheck(scheduleDates('2026-11-12', [451])[0]['start'] === '2026-11-10 16:59');
scheduleCheck(scheduleDates('2026-11-12', [270,180])[0] === ['start' => '2026-11-11 08:00', 'end' => '2026-11-11 12:30']);
scheduleCheck(scheduleDates('2026-11-12', [0])[0] === ['start' => '2026-11-11 17:00', 'end' => '2026-11-11 17:00']);
scheduleCheck(scheduleDates('2026-10-26', [451])[0]['start'] === '2026-10-22 16:59');
scheduleCheck(scheduleDates('2027-01-04', [60])[0]['end'] === '2026-12-31 17:00');
scheduleCheck(vd_production_schedule_backwards(new DateTimeImmutable('2026-11-12'), []) === []);
foreach ([-1, INF, 'invalid', 450 * 260 * 11] as $invalid) {
    try { scheduleDates('2026-11-12', [$invalid]); throw new RuntimeException('Invalid duration accepted.'); }
    catch (DomainException $error) { scheduleCheck(true); }
}
echo "$checks production schedule checks passed.\n";

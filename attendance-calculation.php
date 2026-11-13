<?php
namespace VDAttendance;

function mergeIntervals(array $intervals): array
{
    usort($intervals, function ($a, $b) { return $a[0] <=> $b[0]; });
    $result = [];
    foreach ($intervals as $interval) {
        if ($interval[1] <= $interval[0]) {
            continue;
        }
        $last = count($result) - 1;
        if ($last >= 0 && $interval[0] <= $result[$last][1]) {
            $result[$last][1] = max($result[$last][1], $interval[1]);
        } else {
            $result[] = $interval;
        }
    }
    return $result;
}

function intersection(array $left, array $right): array
{
    $result = [];
    foreach ($left as $a) {
        foreach ($right as $b) {
            $from = max($a[0], $b[0]);
            $to = min($a[1], $b[1]);
            if ($to > $from) {
                $result[] = [$from, $to];
            }
        }
    }
    return mergeIntervals($result);
}

function duration(array $intervals): int
{
    return array_sum(array_map(function ($range) { return $range[1] - $range[0]; }, mergeIntervals($intervals)));
}

function subtractIntervals(array $left, array $right): array
{
    foreach (mergeIntervals($right) as $cut) {
        $next = [];
        foreach ($left as $range) {
            if ($cut[1] <= $range[0] || $cut[0] >= $range[1]) {
                $next[] = $range;
                continue;
            }
            if ($range[0] < $cut[0]) {
                $next[] = [$range[0], $cut[0]];
            }
            if ($range[1] > $cut[1]) {
                $next[] = [$cut[1], $range[1]];
            }
        }
        $left = $next;
    }
    return $left;
}

function formatMinutes(?int $minutes): string
{
    if ($minutes === null) {
        return '—';
    }
    return ($minutes < 0 ? '-' : '') . sprintf('%02d:%02d', intdiv(abs($minutes), 60), abs($minutes) % 60);
}

function schedule(array $employee): array
{
    $periods = [1631 => 1, 1632 => 2][(int) $employee['field_6589']] ?? 0;
    if (!$periods) {
        throw new \InvalidArgumentException('Horário de trabalho por preencher.');
    }
    $result = [];
    for ($i = 0; $i < $periods; $i++) {
        $pair = [];
        foreach ([6590 + 2 * $i, 6591 + 2 * $i] as $field) {
            $value = trim((string) $employee['field_' . $field]);
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) {
                throw new \InvalidArgumentException('Horário incompleto ou inválido no ' . ($i + 1) . '.º período.');
            }
            $pair[] = $value;
        }
        if ($pair[1] <= $pair[0] || ($i && $pair[0] < $result[$i - 1][1])) {
            throw new \InvalidArgumentException('Horário inválido: os períodos devem ser sequenciais, no mesmo dia e sem sobreposição.');
        }
        $result[] = $pair;
    }
    return $result;
}

function dayCalculation(string $date, array $schedule, array $punches, array $absences, bool $workingDay, \DateTimeZone $zone, int $now): array
{
    $start = new \DateTimeImmutable($date . ' 00:00:00', $zone);
    $end = $start->modify('+1 day');
    $bounds = [[intdiv($start->getTimestamp(), 60), intdiv($end->getTimestamp(), 60)]];
    $planned = [];
    if ($workingDay) {
        foreach ($schedule as $pair) {
            $planned[] = [
                intdiv((new \DateTimeImmutable($date . ' ' . $pair[0], $zone))->getTimestamp(), 60),
                intdiv((new \DateTimeImmutable($date . ' ' . $pair[1], $zone))->getTimestamp(), 60),
            ];
        }
    }
    $valid = [];
    $marks = [];
    $warnings = [];
    foreach ($punches as $punch) {
        $entry = (int) $punch['start'];
        $exit = (int) $punch['end'];
        $entryDay = (new \DateTimeImmutable('@' . $entry))->setTimezone($zone)->format('Y-m-d');
        if ($exit <= 0 || $exit < $entry || $exit - $entry > 86400) {
            if ($entryDay === $date) {
                $marks[] = [$entry, $exit > 0 ? $exit : null];
                $warnings[] = $exit <= 0 ? 'Marcação sem saída' : 'Marcação com duração inválida';
            }
            continue;
        }
        $clipped = intersection([[intdiv($entry, 60), intdiv($exit, 60)]], $bounds);
        if ($clipped || $entryDay === $date) {
            $marks[] = [$entry, $exit];
            $valid = array_merge($valid, $clipped);
        }
    }
    usort($marks, function ($a, $b) { return $a[0] <=> $b[0]; });
    if (array_sum(array_map(function ($range) { return $range[1] - $range[0]; }, $valid)) > duration($valid)) {
        $warnings[] = 'Marcações sobrepostas (não duplicadas no cálculo)';
    }
    $valid = mergeIntervals($valid);
    $missing = subtractIntervals($planned, $valid);
    $labels = [];
    $breakdown = [];
    $unclassified = $missing;
    foreach ($absences as $absence) {
        $range = [[intdiv((int) $absence['start'], 60), intdiv((int) $absence['end'], 60)]];
        if (!intersection($range, $bounds)) {
            continue;
        }
        $label = $absence['label'];
        $labels[] = $label;
        $covered = intersection($unclassified, $range);
        $breakdown[$label] = ($breakdown[$label] ?? 0) + duration($covered);
        $unclassified = subtractIntervals($unclassified, $range);
    }
    $future = $start->getTimestamp() > $now;
    $provisional = !$future && $end->getTimestamp() > $now;
    $incomplete = count(array_filter($warnings, function ($warning) {
        return $warning === 'Marcação sem saída' || $warning === 'Marcação com duração inválida';
    })) > 0;
    $calculable = !$future && !$provisional && !$incomplete;
    return [
        'planned' => duration($planned),
        'worked' => $future || $incomplete ? null : duration($valid),
        'extra' => $calculable ? duration(subtractIntervals($valid, $planned)) : null,
        'missing' => $calculable ? duration($missing) : null,
        'balance' => $calculable ? duration($valid) - duration($planned) : null,
        'breakdown' => $calculable ? $breakdown : [],
        'unclassified' => $calculable ? duration($unclassified) : null,
        'marks' => $marks,
        'warnings' => array_values(array_unique($warnings)),
        'labels' => array_values(array_unique($labels)),
        'future' => $future,
        'provisional' => $provisional,
    ];
}

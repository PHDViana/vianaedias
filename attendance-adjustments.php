<?php
namespace VDAttendance;

function statutoryHolidays(int $year): array
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = ($h + $l - 7 * $m + 114) % 31 + 1;
    $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('Europe/Lisbon'));
    $dates = [
        '01-01' => 'Ano Novo', '04-25' => 'Dia da Liberdade', '05-01' => 'Dia do Trabalhador',
        '06-10' => 'Dia de Portugal', '06-24' => 'São João — feriado municipal de Braga',
        '08-15' => 'Assunção de Nossa Senhora', '12-01' => 'Restauração da Independência',
        '12-08' => 'Imaculada Conceição', '12-25' => 'Natal',
    ];
    if ($year < 2013 || $year > 2015) {
        $dates += ['10-05' => 'Implantação da República', '11-01' => 'Todos os Santos'];
    } else {
        unset($dates['12-01']);
    }
    $result = [];
    foreach ($dates as $date => $name) {
        $date = $year . '-' . $date;
        $result[$date] = ['name' => $name, 'start_date' => $date, 'end_date' => $date];
    }
    $movable = [-2 => 'Sexta-feira Santa', 0 => 'Domingo de Páscoa'];
    if ($year < 2013 || $year > 2015) {
        $movable[60] = 'Corpo de Deus';
    }
    foreach ($movable as $offset => $name) {
        $date = $easter->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
        $result[$date] = ['name' => $name, 'start_date' => $date, 'end_date' => $date];
    }
    ksort($result);
    return $result;
}

function adjustedPunches(array $originals, array $corrections): array
{
    $punches = [];
    foreach ($originals as $original) {
        $punches[(int) $original['id']] = $original;
    }
    $errors = [];
    $targets = [];
    foreach ($corrections as $correction) {
        if (!$correction['active']) {
            continue;
        }
        $target = (int) $correction['original'];
        if ($target && in_array($correction['action'], ['replace', 'cancel'], true)) {
            $targets[$target] = ($targets[$target] ?? 0) + 1;
        }
    }
    $events = [];
    foreach ($corrections as $correction) {
        if (!$correction['active']) {
            continue;
        }
        $id = (int) $correction['id'];
        $target = (int) $correction['original'];
        $action = $correction['action'];
        $entry = (int) $correction['start'];
        $exit = (int) $correction['end'];
        $problem = '';
        if (!preg_match('/^[0-9]*$/D', (string) $correction['original'])) {
            $problem = 'O ID do registo original deve ser um número inteiro';
        } elseif (!in_array($action, ['add', 'replace', 'cancel'], true)) {
            $problem = 'Tipo de correção inválido';
        } elseif (trim($correction['reason']) === '') {
            $problem = 'Motivo da correção por preencher';
        } elseif ($action === 'add' && $target) {
            $problem = 'Uma adição não pode indicar um registo original';
        } elseif ($action !== 'add' && (!$target || !isset($punches[$target]))) {
            $problem = 'Registo original inexistente ou de outro colaborador';
        } elseif ($action !== 'add' && ($targets[$target] ?? 0) > 1) {
            $problem = 'Existem várias correções ativas para o mesmo registo original';
        } elseif ($action !== 'cancel' && ($entry <= 0 || $exit <= $entry || $exit - $entry > 86400)) {
            $problem = 'A correção exige entrada e saída válidas, com duração até 24 horas';
        }
        if ($problem) {
            $errors[] = 'Correção #' . $id . ': ' . $problem . '.';
            continue;
        }
        $label = ['add' => 'Marcação acrescentada', 'replace' => 'Marcação corrigida', 'cancel' => 'Marcação anulada'][$action] . ' (#' . $id . ')';
        if ($action !== 'add') {
            $events[] = ['start' => (int) $punches[$target]['start'], 'label' => $label];
            unset($punches[$target]);
        }
        if ($action !== 'cancel') {
            $punches['correction-' . $id] = ['id' => 'correction-' . $id, 'start' => $entry, 'end' => $exit];
            $events[] = ['start' => $entry, 'label' => $label];
        }
    }
    return ['punches' => array_values($punches), 'events' => $events, 'errors' => $errors];
}

<?php
require_once __DIR__ . '/quote-workflow-domain.php';

function vd_production_easter($year)
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
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new DateTimeZone('Europe/Lisbon'));
}

function vd_production_delivery_default(?DateTimeImmutable $adjudicatedAt = null)
{
    $date = ($adjudicatedAt ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))
        ->setTimezone(new DateTimeZone('Europe/Lisbon'))->setTime(0, 0);
    $remaining = 3;
    while ($remaining > 0) {
        $date = $date->modify('+1 day');
        if (vd_production_workday($date)) $remaining--;
    }
    return $date->format('Y-m-d');
}

function vd_production_workday(DateTimeImmutable $date)
{
    $date = $date->setTimezone(new DateTimeZone('Europe/Lisbon'));
    $easter = vd_production_easter((int) $date->format('Y'));
    $holidays = ['01-01','04-25','05-01','06-10','08-15','10-05','11-01','12-01','12-08','12-25',
        $easter->modify('-2 days')->format('m-d'), $easter->format('m-d'),
        $easter->modify('+60 days')->format('m-d')];
    return (int) $date->format('N') <= 5 && !in_array($date->format('m-d'), $holidays, true);
}

function vd_production_delivery_date($value)
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        throw new DomainException('Introduza a data prevista de entrega.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Lisbon'));
    if (!$date || $date->format('Y-m-d') !== $value || $value < (new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d')) {
        throw new DomainException('A data prevista de entrega deve ser válida e não anterior à adjudicação.');
    }
    return $date;
}

function vd_production_plan_graphic(array $detail)
{
    $plan = $detail['plan'];
    $s = $plan['suggestion'] ?? [];
    $width = vd_production_number($plan['width'], 'largura do plano');
    $height = vd_production_number($plan['height'], 'altura do plano');
    $columns = (int) ($plan['columns'] ?? $s['columns'] ?? 0);
    $rows = (int) ($plan['rows'] ?? $s['rows'] ?? 0);
    if ($columns < 1 || $rows < 1 || $columns * $rows > 4096) throw new DomainException('Plano sem grelha gráfica válida.');
    $rotated = !empty($s['rotated']);
    $pieceWidth = vd_production_number($rotated ? $plan['component_height'] : $plan['component_width'], 'largura do trabalho');
    $pieceHeight = vd_production_number($rotated ? $plan['component_width'] : $plan['component_height'], 'altura do trabalho');
    $gap = vd_production_number($s['gap'] ?? 0, 'intervalo');
    $bleed = vd_production_number($s['bleed'] ?? 0, 'sangria');
    $gripper = vd_production_number($s['gripper'] ?? 0, 'pinça');
    $reserved = (int) ($s['reservedGrippers'] ?? 1);
    $usableWidth = $s['usableWidth'] ?? $width;
    $usableHeight = $s['usableHeight'] ?? $height - $reserved * $gripper;
    $occupiedWidth = $s['occupiedWidth'] ?? $columns * $pieceWidth + ($columns - 1) * $gap + 2 * $bleed;
    $occupiedHeight = $s['occupiedHeight'] ?? $rows * $pieceHeight + ($rows - 1) * $gap + 2 * $bleed;
    $areaY = $gripper + ($height - $reserved * $gripper - $usableHeight) / 2;
    $startX = ($width - $occupiedWidth) / 2 + $bleed;
    $startY = $areaY + ($usableHeight - $occupiedHeight) / 2 + $bleed;
    $slots = [];
    $mode = (string) ($detail['mode_id'] ?? $plan['mode_id'] ?? '');
    $turn = in_array($mode, ['3749','3750'], true);
    for ($i = 0; $i < $columns * $rows; $i++) {
        $pair = $mode === '3749' ? intdiv($i, $columns) * $columns + $columns - 1 - $i % $columns :
            ($rows - 1 - intdiv($i, $columns)) * $columns + $i % $columns;
        if (!$turn || $i < $pair) $slots[] = $turn ? [$i, $pair] : [$i];
    }

    $labels = [];
    $colors = ['#b8d9f5','#ffdc9e','#bce3c1','#e1c4ef','#f5bfc4','#b9e8e4','#e5dfa2','#d4c8bd'];
    $cursor = 0;
    foreach ($detail['rubrics'] as $i => $rubric) {
        $allocation = (int) ($detail['allocation'][$i] ?? (count($detail['rubrics']) === 1 ? $plan['poses'] : 0));
        if ($turn && $allocation % 2) throw new DomainException('Distribuição de viragem sem posições pares.');
        for ($n = 0; $n < $allocation; $n += $turn ? 2 : 1) {
            if (!isset($slots[$cursor])) throw new DomainException('Distribuição gráfica excede as poses do plano.');
            foreach ($slots[$cursor++] as $slot) $labels[$slot] = ['label' => trim($rubric['code'] ?? '') ?: '#' . ($rubric['id'] ?? $i + 1),
                'color' => $colors[$i % count($colors)]];
        }
    }
    $rect = static function ($x, $y, $w, $h, $fill, $kind) {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h .
            '" fill="' . $fill . '" stroke="#2865a2" stroke-width="0.8" data-kind="' . $kind . '"/>';
    };
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-2 -2 ' . ($width + 4) . ' ' . ($height + 4) .
        '" role="img" aria-label="Imposição aprovada: ' . $columns . ' colunas e ' . $rows . ' filas" style="display:block;width:100%;height:330px">';
    $svg .= $rect(0, 0, $width, $height, '#fff', 'plan') . $rect(0, 0, $width, $gripper, '#f9ce87', 'gripper');
    if ($reserved === 2) $svg .= $rect(0, $height - $gripper, $width, $gripper, '#f9ce87', 'gripper');
    $svg .= $rect(($width - $usableWidth) / 2, $areaY, $usableWidth, $usableHeight, '#edf5f5', 'print-area');
    for ($i = 0; $i < (int) $plan['poses']; $i++) {
        $x = $startX + $i % $columns * ($pieceWidth + $gap);
        $y = $startY + intdiv($i, $columns) * ($pieceHeight + $gap);
        $svg .= $rect($x, $y, $pieceWidth, $pieceHeight, $labels[$i]['color'] ?? '#eee', 'piece');
        $label = htmlspecialchars($labels[$i]['label'] ?? 'Livre', ENT_QUOTES, 'UTF-8');
        $svg .= '<text x="' . ($x + $pieceWidth / 2) . '" y="' . ($y + $pieceHeight / 2) .
            '" text-anchor="middle" dominant-baseline="central" font-size="14" data-kind="rubric-code">' . $label . '</text>';
    }
    if ($turn) {
        $svg .= '<line x1="' . ($mode === '3749' ? $width / 2 : 0) . '" x2="' . ($mode === '3749' ? $width / 2 : $width) .
            '" y1="' . ($mode === '3749' ? 0 : $height / 2) . '" y2="' . ($mode === '3749' ? $height : $height / 2) .
            '" stroke="#b42318" stroke-dasharray="5 3" data-kind="turn"/>';
    }
    return $svg . '</svg><p>Plano ' . $width . ' × ' . $height . ' mm · ' . (int) $plan['poses'] .
        ' poses · intervalo ' . $gap . ' mm · sangria ' . $bleed . ' mm · pinça ' . $gripper .
        ' mm · rotação: ' . ($rotated ? 'sim' : 'não') .
        '.</p>';
}

function vd_production_plan_display(array $detail)
{
    return '<div class="vd-production-plan-preview" style="min-width:320px;max-width:760px">' .
        vd_production_plan_graphic($detail) . '</div>';
}

function vd_production_number($value, $label)
{
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
        throw new DomainException('Valor de produção inválido: ' . $label);
    }
    return (float) $value;
}

function vd_production_rubric_archived(mysqli $db, array $row)
{
    if ((int) $row['entity_id'] !== 145) return false;
    $id = (int) $row['native_id'];
    $order = (int) $row['order_id'];
    $archive = $db->query("SELECT * FROM app_vd_production_removed_rubrics WHERE native_id=$id AND order_id=$order")->fetch_assoc();
    if (!$archive) return false;
    $native = json_decode($archive['native_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals($archive['hash'], hash('sha256', $archive['native_snapshot'])) ||
        !hash_equals($row['hash'], hash('sha256', $row['snapshot'])) ||
        (int) $archive['parent_id'] !== (int) $row['parent_id'] ||
        (int) $native['id'] !== $id || (int) $native['parent_item_id'] !== (int) $row['parent_id'] ||
        $db->query("SELECT id FROM app_entity_145 WHERE id=$id")->num_rows) {
        throw new RuntimeException('Arquivo da rubrica removida em conflito.');
    }
    return true;
}

function vd_production_operation_sequence(array $ids)
{
    $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) { return $id !== 44; }));
    foreach ([[43,2], [13,9]] as $precedence) {
        $before = array_search($precedence[0], $ids, true);
        $after = array_search($precedence[1], $ids, true);
        if ($before !== false && $after !== false && $before > $after) {
            array_splice($ids, $before, 1);
            array_splice($ids, $after, 0, [$precedence[0]]);
        }
    }
    return $ids;
}

function vd_production_operation_staff(mysqli $db, $operationId)
{
    $operationId = (int) $operationId;
    $catalog = $db->query("SELECT field_1984,field_2473 FROM app_entity_36 WHERE id=$operationId")->fetch_assoc();
    if (!$catalog) throw new DomainException('Operação inexistente ao associar utilizadores.');
    $fields = [];
    foreach ([2428 => 'field_1984', 2472 => 'field_2473'] as $field => $source) {
        $ids = [];
        foreach (array_filter(explode(',', trim((string) $catalog[$source])), 'strlen') as $id) {
            if (!ctype_digit($id) || (int) $id < 1) throw new DomainException('Utilizador inválido na configuração da operação.');
            $id = (int) $id;
            if (!$db->query("SELECT id FROM app_entity_1 WHERE id=$id")->num_rows) throw new DomainException('Utilizador da operação já não existe: ' . $id);
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        if ($field === 2472 && count($ids) > 1) throw new DomainException('A operação tem mais de um responsável configurado.');
        $fields['field_' . $field] = implode(',', $ids);
    }
    return $fields;
}

function vd_production_staff_index(mysqli $db, $id, array $fields)
{
    $id = (int) $id;
    foreach ([2428,2472] as $field) {
        if (!array_key_exists('field_' . $field, $fields)) continue;
        $db->query("DELETE FROM app_entity_170_values WHERE items_id=$id AND fields_id=$field");
        foreach (array_filter(explode(',', $fields['field_' . $field]), 'strlen') as $user) {
            if (!ctype_digit($user) || (int) $user < 1) throw new DomainException('Índice de utilizadores inválido.');
            $db->query("INSERT INTO app_entity_170_values(items_id,fields_id,value) VALUES($id,$field," . (int) $user . ')');
        }
    }
}

function vd_production_component(array $version, array $dieChoices)
{
    $snapshot = $version['snapshot'];
    $calculation = $snapshot['calculation'];
    $nonprint = ($calculation['source'] ?? '') === 'nonprint';
    $native = $snapshot['nativeComponent'];
    $rows = vd_workflow_production_rows($calculation, (int) $version['id'],
        (int) $version['component_id'], (int) $version['quantity_id']);
    $die = (string) ($native['field_4220'] ?? '');
    $faces = (string) ($native['field_4229'] ?? '');
    if (!$nonprint && $die !== '' && !isset($dieChoices[$die])) throw new DomainException('Cortante sem correspondência na OP.');
    $faceChoices = ['1255' => 888, '1256' => 889, '1257' => 890];
    if (!$nonprint && $faces !== '' && !isset($faceChoices[$faces])) throw new DomainException('Impressão sem correspondência na OP.');
    $componentQuantity=isset($snapshot['product']['units_per_product']) ?
        (float)$version['quantity']*(float)$snapshot['product']['units_per_product'] : $version['quantity'];
    $fields = ['field_2242' => $componentQuantity, 'field_2424' => 1286, 'field_2579' => 0,
        'field_2238' => $dieChoices[$die] ?? '', 'field_2453' => $nonprint ? '' : ($faceChoices[$faces] ?? ''),
        'field_2576' => in_array((string) ($native['field_4239'] ?? ''), ['1258', '1', 'true'], true) ? 898 : '',
        'field_2262' => $rows['plans'][0]['paper']['internal_id'] ?? ''];
    foreach ([4218 => 2236, 4219 => 2237, 4221 => 2239, 4225 => 2243, 4233 => 2537,
        4238 => 2573, 4240 => 2577, 4242 => 2580] as $from => $to) {
        $fields['field_' . $to] = $native['field_' . $from] ?? '';
    }
    $rubrics = $calculation['rubrics'] ?? null;
    if ($rubrics === null) {
        if (!isset($snapshot['nativeRubrics'])) throw new DomainException('Versão histórica sem rubricas fixadas; confirme uma nova versão.');
        $rubrics = array_map(static function ($row) {
            return ['id' => $row['id'], 'quantity' => $row['field_4320'],
                'name' => $row['field_4321'], 'code' => $row['field_4322']];
        }, $snapshot['nativeRubrics']);
    }
    $hasRubrics = isset($snapshot['nativeRubrics']) ? count($snapshot['nativeRubrics']) > 0 :
        count(array_filter($rubrics, static function ($rubric) { return (int) ($rubric['id'] ?? 0) > 0; })) > 0;
    if (!$hasRubrics) {
        $rubrics = [['id' => 0, 'quantity' => $version['quantity'],
            'name' => $native['field_4225'] ?? '', 'code' => $native['field_4242'] ?? '']];
    }
    if ($hasRubrics && !$rubrics) throw new DomainException('Rubricas de origem sem distribuição fixada na versão.');
    $rubricRows = [];
    foreach ($rubrics as $rubric) {
        $rubricRows[] = ['field_2019' => vd_production_number($rubric['quantity'] ?? null, 'quantidade da rubrica'),
            'field_2020' => $rubric['name'] ?? '', 'field_2021' => $rubric['code'] ?? ''];
    }
    if (abs(array_sum(array_column($rubricRows, 'field_2019')) - (float) $version['quantity']) > 0.0001) {
        throw new DomainException('As rubricas fixadas não correspondem à quantidade adjudicada.');
    }
    $parts = $calculation['parts'] ?? $calculation['pagination']['parts'] ?? [$calculation];
    $plans = [];
    foreach ($rows['plans'] as $index => $row) {
        $plan = $row['plan'];
        $plan += ['component_width' => $native['field_4218'], 'component_height' => $native['field_4219']];
        $part = $parts[$index];
        $detail = ['plan' => $plan, 'production' => $row['production'],
            'mode_id' => $row['mode_id'],
            'rubrics' => $rubrics, 'allocation' => $part['allocation'] ?? [],
            'produced' => $part['produced'] ?? [], 'runs' => $part['runs'] ?? null];
        if (count($rubrics) > 1 &&
            (count($detail['allocation']) !== count($rubrics) || count($detail['produced']) !== count($rubrics))) {
            throw new DomainException('Plano sem distribuição completa das rubricas.');
        }
        $planFields = ['field_2196' => 36, 'field_2198' => '',
            'field_2199' => $plan['component_width'] ?? $native['field_4218'],
            'field_2200' => $plan['component_height'] ?? $native['field_4219'],
            'field_2201' => $plan['columns'] ?? $plan['suggestion']['columns'] ?? '',
            'field_2202' => $plan['rows'] ?? $plan['suggestion']['rows'] ?? '',
            'field_2203' => $plan['poses'], 'field_2204' => $plan['width'], 'field_2205' => $plan['height'],
            'field_2206' => $plan['sheet_width'], 'field_2207' => $plan['sheet_height'],
            'field_2208' => $plan['per_sheet'], 'field_2213' => $row['mode_id'],
            'field_2214' => $row['machine_id'], 'field_2539' => $row['production']['forms'] ?? 1,
            'field_2569' => $plan['perimeter'] ?? 2 * ($plan['component_width'] ?? $native['field_4218']) +
                2 * ($plan['component_height'] ?? $native['field_4219'])];
        $html = '<p>Montagem ' . ($index + 1) . ' · Versão ' . (int) $version['id'] . '</p>';
        if ($hasRubrics) $html .= '<table><tr><th>Rubrica</th><th>Pedida</th><th>Poses</th><th>Produzida</th></tr>';
        foreach ($hasRubrics ? $rubrics : [] as $i => $rubric) {
            $html .= '<tr><td>' . htmlspecialchars(($rubric['code'] ?? '') . ' ' . ($rubric['name'] ?? ''), ENT_QUOTES, 'UTF-8') .
                '</td><td>' . $rubricRows[$i]['field_2019'] . '</td><td>' . ($detail['allocation'][$i] ?? $plan['poses']) .
                '</td><td>' . ($detail['produced'][$i] ?? $row['production']['goodPlans'] ?? $version['quantity']) . '</td></tr>';
        }
        $planFields['field_2209'] = $html . ($hasRubrics ? '</table>' : '') . vd_production_plan_display($detail);
        $plans[] = ['fields' => $planFields, 'snapshot' => $detail];
    }
    $catalog = array_column($snapshot['nativeCatalog'] ?? [], null, 'id');
    $sources = $calculation['allOperations']['sourceOperations'] ?? [];
    $order = array_flip($snapshot['nativeDiscoveryOrder'] ?? array_column($sources, 'operation_id'));
    usort($rows['operations'], static function ($a, $b) use ($order) {
        return ($order[$a['operation_id']] ?? PHP_INT_MAX) <=> ($order[$b['operation_id']] ?? PHP_INT_MAX);
    });
    $operationRows = array_column($rows['operations'], null, 'operation_id');
    $rows['operations'] = array_map(static function ($id) use ($operationRows) { return $operationRows[$id]; },
        vd_production_operation_sequence(array_column($rows['operations'], 'operation_id')));
    $operations = [];
    $parentOperations = [];
    foreach ($rows['operations'] as $index => $operation) {
        $operationId = $operation['operation_id'];
        $source = [];
        foreach ($sources as $candidate) {
            if ((int) $candidate['operation_id'] === $operationId) $source = $candidate;
            if (isset($candidate['id'])) $parentOperations[(string) $candidate['id']] = (int) $candidate['operation_id'];
        }
        $mainLines = array_values(array_filter($operation['lines'], static function ($line) {
            return !isset($line['parentOperationRow']);
        }));
        if (!$mainLines) throw new DomainException('Operação sem linha principal fixada.');
        $primary = $mainLines[0];
        $hours = 0;
        foreach ($operation['lines'] as $line) {
            if (isset($line['hours'])) $hours += vd_production_number($line['hours'], 'tempo da operação');
            if (isset($line['operationRow'])) $parentOperations[(string) $line['operationRow']] = $operationId;
        }
        $equipment = $source['equipment'] ?? '';
        $inputs = $calculation['allOperations']['inputs'] ?? [];
        foreach ($mainLines as $line) {
            $inputKey = 'operation:' . ($line['operationRow'] ?? $source['id'] ?? '');
            if (isset($inputs[$inputKey]['equipment'])) $equipment = $inputs[$inputKey]['equipment'];
            if (!$equipment) $equipment = $line['parameters']['equipment']['machineId'] ?? '';
        }
        if (!$equipment && in_array($operationId, [4,5,7], true)) {
            $equipment = implode(',', array_unique(array_column($rows['plans'], 'machine_id')));
        }
        if (!$equipment && $operationId === 3) {
            $equipment = implode(',', array_column($calculation['plateProduction']['operation']['ctpResources']['equipment'] ?? [], 'machines'));
        }
        if (!$equipment && $operationId === 43) {
            $equipment = implode(',', array_column($primary['imposition']['resources']['equipment'] ?? [], 'machine_id'));
        }
        $catalogRow = $catalog[$operationId] ?? [];
        $operationFields = ['field_2425' => 36, 'field_2426' => $operationId, 'field_2427' => $equipment,
            'field_2430' => ($index + 1) * 10, 'field_2431' => (int) ceil($hours * 60 - 0.00000001),
            'field_2450' => $catalogRow['name'] ?? $primary['name'], 'field_2549' => 0,
            'field_2578' => isset($primary['quantity']) ? vd_production_number($primary['quantity'], 'trabalho da operação') : '',
            'field_2527' => $catalogRow['speed'] ?? ''];
        foreach (['assigned_users' => 2428, 'operation_type' => 2429, 'supplier' => 2433,
            'responsible_user' => 2472, 'speed_min' => 2526, 'speed_max' => 2528, 'waste' => 4439] as $key => $field) {
            if (isset($catalogRow[$key])) $operationFields['field_' . $field] = $catalogRow[$key];
        }
        $operations[$operationId] = ['fields' => $operationFields,
            'snapshot' => ['lines' => $operation['lines'], 'source' => $source, 'catalog' => $catalogRow,
                'inputs' => $inputs, 'hours' => $hours], 'materials' => []];
    }
    $printing = array_values(array_intersect([4,5,7], array_keys($operations)));
    $addMaterial = static function ($key, $operationId, $quantity, array $article, $unit, array $detail) use (&$operations) {
        $quantity = vd_production_number($quantity, 'consumo de ' . $key);
        if ($quantity === 0.0) return;
        if (!isset($operations[$operationId])) throw new DomainException('Material sem operação de destino: ' . $key);
        $operations[$operationId]['materials'][] = ['key' => $key, 'quantity' => $quantity,
            'article_id' => $article['id'] ?? null, 'internal_id' => $article['internal_id'] ?? null,
            'name' => $article['name'] ?? $key, 'unit' => $unit,
            'snapshot' => $detail + ['article' => $article, 'quantity' => $quantity, 'unit' => $unit]];
    };
    $parent = static function (array $line, $default) use ($parentOperations) {
        if (!isset($line['parentOperationRow'])) return $default;
        if (!isset($parentOperations[(string) $line['parentOperationRow']])) {
            throw new DomainException('Origem do material sem operação fixada.');
        }
        return $parentOperations[(string) $line['parentOperationRow']];
    };
    foreach ($calculation['allOperations']['lines'] as $line) {
        $key = $line['key'];
        if ($key === 'paper') {
            $papers = $line['paperCalculations'] ?? array_column($parts, 'paperCalculation');
            if (!$papers) throw new DomainException('Consumo de papel sem folhas e artigo fixados.');
            foreach ($papers as $i => $paper) {
                $addMaterial('paper:' . $i, isset($operations[2]) ? 2 : ($printing[0] ?? 0),
                    $paper['sheets'] ?? null, $paper['paper'] ?? [], 'folhas', $paper);
            }
        } elseif ($key === 'plates') {
            foreach ($parts as $i => $part) {
                $addMaterial('plates:' . $i, 3, $part['result']['plates'] ?? $part['results']['plates'] ?? 0,
                    ['internal_id' => $part['plate']['materialId'] ?? null, 'name' => 'Chapas de impressão'],
                    'chapas', ['plate' => $part['plate'] ?? []]);
            }
        } elseif (strpos($key, 'imposition:') === 0 && substr($key, -6) === ':paper') {
            $addMaterial($key, $parent($line, 43), $line['quantity'] ?? null, $line['imposition']['paper'] ?? [],
                $line['unit'] ?? 'folhas', $line);
        } elseif (preg_match('/^(color|packaging|lamination|adhesive|spiral-material|material):/', $key)) {
            $article = $line['article'] ?? $line['material']['article'] ?? [];
            if (strpos($key, 'material:') === 0) {
                $article = ['id' => $line['reference']['articleId'] ?? null,
                    'internal_id' => $line['reference']['descriptionId'] ?? null, 'name' => $line['name']];
            }
            if (strpos($key, 'color:') === 0 && empty($article['id'])) {
                $priceSource = $line['color']['calibration']['priceSource'] ?? [];
                $article = ['id' => $priceSource['articleId'] ?? $line['color']['article_id'] ?? null,
                    'name' => $priceSource['articleName'] ?? $line['color']['name'] ?? $line['name']];
            }
            $default = strpos($key, 'packaging:') === 0 ? 10 : (strpos($key, 'lamination:') === 0 ? 13 : ($printing[0] ?? 0));
            if (strpos($key, 'color:') === 0) {
                $offset = array_values(array_intersect([5,7], $printing));
                $default = $offset[0] ?? 0;
            }
            $addMaterial($key, $parent($line, $default), $line['quantity'] ?? null, $article, $line['unit'] ?? '', $line);
        }
    }
    return ['fields' => $fields, 'rubrics' => !$nonprint && $hasRubrics ? $rubricRows : [], 'plans' => $plans, 'operations' => $operations,
        'colors' => $nonprint ? [] : ($snapshot['nativeColors'] ?? []), 'snapshot' => ['nativeComponent' => $native, 'rubrics' => $rubrics, 'product' => $snapshot['product'] ?? null]];
}

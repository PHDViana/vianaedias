<?php
function vd_ink_render($pdf)
{
    if (!is_string($pdf) || strlen($pdf) > 5 * 1024 * 1024 || substr($pdf, 0, 5) !== '%PDF-') {
        throw new DomainException('Selecione um PDF válido até 5 MB.');
    }
    // The privileged command accepts no arguments; the service receives only stdin.
    $process = proc_open(['/usr/bin/timeout', '55', '/usr/bin/sudo', '-n', '/usr/local/sbin/vd-ink-analyze'],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Processador PDF indisponível.');
    try {
        $offset = 0;
        while ($offset < strlen($pdf)) {
            $written = fwrite($pipes[0], substr($pdf, $offset, 65536));
            if ($written === false || $written === 0) throw new RuntimeException('Falha ao enviar o PDF ao processador.');
            $offset += $written;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], 1024 * 1024);
        fclose($pipes[1]);
        $diagnostic = stream_get_contents($pipes[2], 8192);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $process = null;
        $result = json_decode($output, true);
        if ($exit !== 0 || !is_array($result) || isset($result['error'])) {
            error_log('VD ink: isolated processor failed, exit=' . $exit . ' diagnostic=' . $diagnostic . ' output=' . substr($output, 0, 1000));
            throw new DomainException($result['error'] ?? 'Análise PDF indisponível ou excedeu os limites de processamento. Tente um PDF mais simples (máximo 20 páginas).');
        }
        if (!isset($result['pages'], $result['spots'], $result['engine']) || !$result['pages']) {
            throw new RuntimeException('Resultado PDF inválido.');
        }
        return $result;
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
}

function vd_ink_number($value, $name, $max, $positive = false)
{
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 ||
        ($positive && (float) $value <= 0) || (float) $value > $max) {
        throw new DomainException('Parâmetro inválido: ' . $name . '.');
    }
    return (float) $value;
}

function vd_ink_estimate(array $analysis, array $calculation, array $parameters)
{
    if (!empty($analysis['spots'])) throw new DomainException('O PDF contém cores diretas. Converta e valide um PDF CMYK para estimar os gramas; a cobertura CMYK equivalente não representa o consumo das cores diretas.');
    $rubrics = $calculation['rubrics'] ?? [];
    $parts = $calculation['parts'] ?? [];
    if (!$parts || !$rubrics) throw new DomainException('Guarde uma versão com imposição e rubricas antes de calcular tinta.');
    $rates = [];
    foreach (['C','M','Y','K'] as $ink) $rates[$ink] = vd_ink_number($parameters['rates'][$ink] ?? null, 'g/m² ' . $ink, 20, true);
    $loss = vd_ink_number($parameters['loss_percent'] ?? null, 'perdas adicionais (%)', 200);
    $startup = vd_ink_number($parameters['startup_g'] ?? null, 'perda fixa por tinta e plano (g)', 5000);
    $copies = array_fill(0, count($rubrics), 0);
    $faces = null;
    $width = vd_ink_number($calculation['component']['width'] ?? $parts[0]['plan']['component_width'] ?? null, 'largura', 2000, true);
    $height = vd_ink_number($calculation['component']['height'] ?? $parts[0]['plan']['component_height'] ?? null, 'altura', 2000, true);
    foreach ($parts as $part) {
        if ((int) ($part['operation']['id'] ?? $part['tariff']['operationId'] ?? 0) !== 7 ||
            (int) ($part['machine']['id'] ?? $calculation['machineId'] ?? 0) !== 6) {
            throw new DomainException('Esta análise estima apenas offset SpeedMaster, não impressão digital ou outras máquinas.');
        }
        $mode = (int) ($part['modeId'] ?? $part['plan']['suggestion']['modeId'] ?? 0);
        if (!in_array($mode, [3747,3748], true)) throw new DomainException('Imposição com viragem ou modo não suportado nesta análise. Não será calculado um consumo potencialmente incorreto.');
        $partFaces = $mode === 3747 ? 1 : 2;
        if ($faces !== null && $faces !== $partFaces) throw new DomainException('Os planos têm números de faces diferentes. Analise-os em componentes separados.');
        $faces = $partFaces;
        $plans = vd_ink_number($part['result']['goodPlans'] ?? null, 'planos úteis', 1000000000) +
            vd_ink_number($part['result']['wastePlans'] ?? null, 'planos de desperdício', 1000000000);
        if ($plans <= 0) throw new DomainException('A versão não tem tiragem prevista válida.');
        foreach ($rubrics as $i => $rubric) {
            $allocation = vd_ink_number($part['allocation'][$i] ?? null, 'poses da rubrica', 4096);
            if (floor($allocation) !== $allocation) throw new DomainException('As poses devem ser inteiras.');
            $copies[$i] += $plans * $allocation;
        }
    }
    if (count($analysis['pages']) !== count($rubrics) * $faces) {
        throw new DomainException('O PDF deve conter ' . (count($rubrics) * $faces) . ' página(s): pela ordem das rubricas, frente e depois verso de cada rubrica. Não utilize um PDF já imposto.');
    }
    $totals = array_fill_keys(['C','M','Y','K'], 0.0);
    $pageResults = [];
    $activePlans = array_fill_keys(['C','M','Y','K'], []);
    foreach ($analysis['pages'] as $index => $page) {
        $rubric = intdiv($index, $faces);
        $pw = vd_ink_number($page['width_mm'], 'largura PDF', 2000, true);
        $ph = vd_ink_number($page['height_mm'], 'altura PDF', 2000, true);
        if (min(max(abs($pw-$width),abs($ph-$height)), max(abs($pw-$height),abs($ph-$width))) > 15) {
            throw new DomainException('O formato do PDF não coincide com o trabalho (tolerância de 15 mm para sangria). Envie páginas individuais ao tamanho final, não o plano imposto.');
        }
        $area = $pw * $ph / 1000000;
        $pageGrams = [];
        foreach ($totals as $ink => $unused) {
            $coverage = vd_ink_number($page['coverage_percent'][$ink] ?? null, 'cobertura ' . $ink, 100);
            $pageGrams[$ink] = $area * $coverage / 100 * $copies[$rubric] * $rates[$ink];
            $totals[$ink] += $pageGrams[$ink];
            if ($coverage > 0) {
                foreach ($parts as $partIndex => $part) {
                    if ((float) $part['allocation'][$rubric] > 0) $activePlans[$ink][$partIndex] = true;
                }
            }
        }
        $pageResults[] = $page + ['rubric' => $rubrics[$rubric]['name'] ?? '#' . ($rubric + 1),
            'face' => $index % $faces === 0 ? 'Frente' : 'Verso', 'printed_copies_including_waste' => $copies[$rubric],
            'area_m2' => $area, 'grams_without_extra_losses' => $pageGrams];
    }
    $inkResults = [];
    foreach ($totals as $ink => $grams) {
        $fixed = count($activePlans[$ink]) * $startup;
        $inkResults[$ink] = ['printing_g' => $grams, 'extra_loss_g' => $grams * $loss / 100,
            'fixed_loss_g' => $fixed, 'total_g' => $grams * (1 + $loss / 100) + $fixed];
    }
    return ['pages' => $pageResults, 'inks' => $inkResults, 'parameters' => ['rates' => $rates, 'loss_percent' => $loss, 'startup_g' => $startup],
        'calibrated' => false, 'method' => 'Área CropBox × cobertura tonal CMYK × exemplares (tiragem + desperdício) × g/m²; perdas adicionais e perda fixa por tinta ativa/plano.',
        'warnings' => ['Os valores são sempre indicativos; não é uma medição de consumo real nem uma separação RIP certificada.',
            'Conversão CMYK do Ghostscript: pode diferir do perfil, trapping e curvas do RIP de produção. Marcas de controlo fora do PDF não são contabilizadas.',
            'PDF pela ordem das rubricas: frente e verso consecutivos de cada rubrica. Confirme a correspondência antes de usar os resultados.']];
}

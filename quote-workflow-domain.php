<?php

function vd_workflow_discount_percent($value)
{
    if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value))
        throw new DomainException('Desconto do cliente inválido.');
    if ($value === null || trim((string) $value) === '') return 0.0;
    $normal = str_replace(',', '.', trim((string) $value));
    if (!preg_match('/^\d+(?:\.\d+)?$/D', $normal) || !is_finite((float) $normal) ||
        (float) $normal < 0 || (float) $normal > 100) {
        throw new DomainException('Desconto do cliente inválido; indique uma percentagem entre 0 e 100.');
    }
    return (float) $normal;
}

function vd_workflow_discounted_price($gross, $quantity, $discount, $extraDiscount = 0)
{
    $percent = vd_workflow_discount_percent($discount);
    $extra = vd_workflow_discount_percent($extraDiscount);
    if ($percent + $extra > 100) throw new DomainException('A soma do desconto do cliente e extra não pode exceder 100%.');
    if (!is_numeric($gross) || !is_finite((float) $gross) || (float) $gross < 0 ||
        !is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
        throw new DomainException('Valor ou quantidade inválida para aplicar o desconto.');
    }
    $grossSale = round((float) $gross, 2);
    $sale = round($grossSale * (1 - ($percent + $extra) / 100), 2);
    return ['grossSale' => $grossSale, 'unitGrossSale' => (float) $gross / (float) $quantity,
        'discountPercent' => $percent, 'extraDiscountPercent' => $extra, 'totalDiscountPercent' => $percent + $extra,
        'discountAmount' => round($grossSale - $sale, 2),
        'sale' => $sale, 'unitSale' => $sale / (float) $quantity];
}

function vd_workflow_can_approve($group, array $allowedGroups)
{
    return in_array((int) $group, array_map('intval', $allowedGroups), true);
}

function vd_workflow_validate_calculation(array $calculation, $allowIncomplete = false)
{
    $quantity = $calculation['quantity'] ?? null;
    $ledger = $calculation['allOperations'] ?? null;
    if (!empty($calculation['errors']) || !empty($calculation['results']['errors'])) {
        throw new DomainException('Existem erros técnicos por resolver na alternativa.');
    }
    if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
        throw new DomainException('Quantidade da alternativa invalida.');
    }
    if (!is_array($ledger) || empty($ledger['lines']) || !is_array($ledger['lines'])) {
        throw new DomainException('A simulacao nao tem linhas de calculo.');
    }
    if (isset($ledger['errors']) && !is_array($ledger['errors'])) {
        throw new DomainException('A simulacao tem erros num formato invalido.');
    }
    if (!$allowIncomplete && (empty($ledger['complete']) || !empty($ledger['errors']))) {
        throw new DomainException('A simulacao tem dados em falta; nao pode ser confirmada.');
    }
    if (isset($ledger['quantity']) && (float) $ledger['quantity'] !== (float) $quantity) {
        throw new DomainException('Quantidade do cálculo incoerente com a alternativa.');
    }
    $cost = 0.0;
    $sale = 0.0;
    $partialCost = 0.0;
    $partialSale = 0.0;
    $reportedComplete = !empty($ledger['complete']);
    $complete = $reportedComplete && empty($ledger['errors']);
    $keys = [];
    foreach ($ledger['lines'] as $line) {
        $key = (string) ($line['key'] ?? '');
        if ($key === '' || isset($keys[$key])) {
            throw new DomainException('Linha de calculo sem identificacao unica.');
        }
        $keys[$key] = true;
        foreach (['quantity', 'hours'] as $field) {
            if (array_key_exists($field, $line) && $line[$field] !== null &&
                (!is_numeric($line[$field]) || !is_finite((float) $line[$field]) || (float) $line[$field] < 0)) {
                throw new DomainException('Linha com quantidade ou tempo invalido.');
            }
        }
        foreach (['cost', 'sale'] as $field) {
            if (!array_key_exists($field, $line) || $line[$field] === null) {
                if (!$allowIncomplete) {
                    throw new DomainException('Linha com valor de custo ou venda invalido.');
                }
                $complete = false;
                continue;
            }
            if (!is_numeric($line[$field]) || !is_finite((float) $line[$field]) || (float) $line[$field] < 0) {
                throw new DomainException('Linha com valor de custo ou venda invalido.');
            }
            if ($field === 'cost') $partialCost += (float) $line[$field];
            else $partialSale += (float) $line[$field];
        }
        if (array_key_exists('errors', $line) && !is_array($line['errors'])) {
            throw new DomainException('Linha com erros num formato invalido.');
        }
        if (!empty($line['errors'])) {
            if (!$allowIncomplete) {
                throw new DomainException('Linha com erros por resolver.');
            }
            foreach ($line['errors'] as $error) {
                if (!is_string($error)) {
                    throw new DomainException('Linha com erros num formato invalido.');
                }
            }
            $complete = false;
        }
        if (isset($line['cost']) && is_numeric($line['cost'])) $cost += (float) $line['cost'];
        if (isset($line['sale']) && is_numeric($line['sale'])) $sale += (float) $line['sale'];
    }
    if (!is_finite($cost) || !is_finite($sale) ||
        !is_finite($partialCost) || !is_finite($partialSale)) {
        throw new DomainException('Os totais da simulacao excedem os limites numericos.');
    }
    if ($reportedComplete && !$complete) {
        throw new DomainException('A simulacao marca como completa uma linha com dados em falta.');
    }
    foreach (['cost' => $cost, 'sale' => $sale] as $field => $sum) {
        if (!array_key_exists($field, $ledger) || $ledger[$field] === null) {
            if (!$allowIncomplete || $complete) {
                throw new DomainException('Os totais nao coincidem com as linhas da simulacao.');
            }
            continue;
        }
        if (!is_numeric($ledger[$field]) || !is_finite($sum) || !is_finite((float) $ledger[$field])) {
            throw new DomainException('Os totais nao coincidem com as linhas da simulacao.');
        }
        if ($complete && abs($sum - (float) $ledger[$field]) > 0.0001) {
            throw new DomainException('Os totais nao coincidem com as linhas da simulacao.');
        }
    }
    if ($complete && (!is_finite($cost) || !is_finite($sale) ||
        abs($cost - (float) $ledger['cost']) > 0.0001 ||
        abs($sale - (float) $ledger['sale']) > 0.0001)) {
        throw new DomainException('Os totais nao coincidem com as linhas da simulacao.');
    }
    foreach (['partialCost' => $partialCost, 'partialSale' => $partialSale] as $field => $sum) {
        if (array_key_exists($field, $ledger) && $ledger[$field] !== null &&
            (!is_numeric($ledger[$field]) || !is_finite((float) $ledger[$field]) ||
                abs($sum - (float) $ledger[$field]) > 0.0001)) {
            throw new DomainException('Os totais parciais nao coincidem com as linhas da simulacao.');
        }
    }
    if (!$complete && !$allowIncomplete) {
        throw new DomainException('A simulacao tem dados em falta; nao pode ser confirmada.');
    }
    if (!$complete && empty($ledger['errors']) &&
        !array_filter($ledger['lines'], static function ($line) {
            return !isset($line['cost'], $line['sale']) || !empty($line['errors']);
        })) {
        throw new DomainException('A simulacao incompleta nao identifica os dados em falta.');
    }
    return ['quantity' => (float) $quantity, 'cost' => $complete ? $cost : null,
        'sale' => $complete ? $sale : null, 'partialCost' => $partialCost,
        'partialSale' => $partialSale, 'unitSale' => $complete ? $sale / (float) $quantity : null,
        'complete' => $complete];
}

function vd_workflow_version_hash(array $calculation, $allowIncomplete = false)
{
    vd_workflow_validate_calculation($calculation, $allowIncomplete);
    return hash('sha256', json_encode($calculation, JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
}

function vd_workflow_transition($state, $action, $approver)
{
    $transitions = [
        'draft' => ['confirm' => 'confirmed'],
        'confirmed' => ['approve' => 'approved', 'revise' => 'superseded'],
        'approved' => ['begin_send' => 'sending', 'revise' => 'superseded'],
        'sending' => ['transport_accepted' => 'sent', 'transport_failed' => 'send_failed',
            'transport_unknown' => 'send_unknown'],
        'send_failed' => ['retry' => 'approved', 'revise' => 'superseded'],
        'sent' => ['accept' => 'accepted', 'reject' => 'rejected', 'revise' => 'superseded'],
        'accepted' => [],
        'rejected' => ['revise' => 'superseded'],
        'send_unknown' => [],
        'superseded' => []
    ];
    if (!isset($transitions[$state][$action])) {
        throw new DomainException('Transicao de estado nao permitida.');
    }

    if (in_array($action, ['approve', 'begin_send', 'retry'], true) && !$approver) {
        throw new DomainException('Sem autorizacao de aprovacao/envio.');
    }
    return $transitions[$state][$action];
}

function vd_workflow_production_rows(array $calculation, $versionId, $componentId, $quantityId, $allowIncomplete = false)
    {
        $totals = vd_workflow_validate_calculation($calculation, $allowIncomplete);
        foreach ([$versionId, $componentId] as $id) {
            if (!is_int($id) || $id < 1) throw new DomainException('Origem da versao invalida.');
        }
        if (!is_int($quantityId) || $quantityId < 0) {
            throw new DomainException('Alternativa de quantidade invalida.');
        }
        $origin = ['version_id' => $versionId, 'component_id' => $componentId,
            'quantity_id' => $quantityId, 'quantity' => $totals['quantity']];
        $snapshotParts = $calculation['parts'] ?? $calculation['pagination']['parts'] ?? [];
        if (!$snapshotParts && isset($calculation['plan'])) $snapshotParts = [$calculation];
        $nonprint = ($calculation['source'] ?? '') === 'nonprint' &&
            in_array($calculation['product']['kind'] ?? '', ['material','assembly'], true);
        if (!$snapshotParts && !$nonprint) throw new DomainException('A versao nao tem planos de producao.');
        if ($nonprint && $snapshotParts) throw new DomainException('Componente sem impressão não pode ter planos.');
        $plans = [];
        foreach ($snapshotParts as $index => $part) {
            $plan = $part['plan'] ?? null;
            if (!is_array($plan)) throw new DomainException('Montagem sem plano de producao.');
            foreach (['width', 'height', 'poses', 'per_sheet', 'sheet_width', 'sheet_height'] as $field) {
                if (!isset($plan[$field]) || !is_numeric($plan[$field]) ||
                    !is_finite((float) $plan[$field]) || (float) $plan[$field] <= 0) {
                    throw new DomainException('Plano com geometria incompleta: ' . $field);
                }
            }
            $machineId = $part['machine']['id'] ?? $part['machineId'] ?? $plan['machine_id'] ??
                $calculation['machineId'] ?? null;
            $modeId = $part['modeId'] ?? $calculation['modeId'] ?? null;
            if (!ctype_digit((string) $machineId) || (int) $machineId < 1 ||
                !in_array((int) $modeId, [3747, 3748, 3749, 3750], true)) {
                throw new DomainException('Plano sem equipamento ou modo de impressao valido.');
            }
            $plans[] = $origin + ['mounting' => $index + 1, 'machine_id' => (int) $machineId,
                'mode_id' => (int) $modeId, 'plan' => $plan,
                'paper' => $part['paperCalculation']['paper'] ?? $calculation['paperCalculation']['paper'] ?? null,
                'production' => $part['result'] ?? $part['results'] ?? $calculation['results'] ?? []];
        }
        $operations = [];
        foreach ($calculation['allOperations']['lines'] as $line) {
            $operationIds = $line['operationIds'] ?? (isset($line['operationId']) ? [$line['operationId']] : []);
            foreach ($operationIds as $operationId) {
                if (!ctype_digit((string) $operationId) || (int) $operationId < 1) {
                    throw new DomainException('Linha com operacao de catalogo invalida.');
                }
                $key = (string) $operationId;
                if (!isset($operations[$key])) {
                    $operations[$key] = $origin + ['operation_id' => (int) $operationId, 'lines' => []];
                }
                $operations[$key]['lines'][] = $line;
            }
        }
        return ['origin' => $origin, 'plans' => $plans, 'operations' => array_values($operations),
            'materials_and_other_lines' => array_values(array_filter($calculation['allOperations']['lines'],
                static function ($line) { return empty($line['operationIds']) && empty($line['operationId']); })),
            'colors' => $calculation['colors'] ?? []];
    }
function vd_workflow_commercial_component(array $snapshot)
{
    $native = $snapshot['nativeComponent'];
    $calculation = $snapshot['calculation'];
    $papers = [];
    $printing = [];
    $finishing = [];
    $colors = [];
    $processColors = [];
    foreach ($calculation['allOperations']['lines'] as $line) {
        if (($line['key'] ?? '') === 'paper') {
            foreach ($line['paperCalculations'] ?? [] as $paper) {
                if (!empty($paper['paper']['name'])) $papers[] = $paper['paper']['name'];
            }
            $references = $line['reference'] ?? [];
            if (isset($references['name'])) $references = [$references];
            foreach ($references as $paper) {
                if (!empty($paper['name'])) $papers[] = $paper['name'];
            }
        }
        if (strpos($line['key'], 'printing') === 0) {
            foreach ($line['operationIds'] ?? [($line['operationId'] ?? 0)] as $operationId) {
                if (in_array((int) $operationId, [5,7], true)) $printing[] = 'Impressão Offset';
                if ((int) $operationId === 4) $printing[] = 'Impressão Digital';
            }
        }
        if (strpos($line['key'], 'color:') === 0) {
            $color = $line['color'] ?? [];
            $name = $color['color_name'] ?? $color['classification'] ?? '';
            $initial = ['Cyan' => 'C', 'Magenta' => 'M', 'Yellow' => 'Y', 'Black' => 'K'][$color['classification'] ?? $name] ?? null;
            if ($initial !== null) $processColors[$initial] = true;
            elseif ($name !== '') $colors[] = $name . (!empty($color['pantone']) ? ' ' . $color['pantone'] : '');
        }
        if (isset($line['operationRow'], $line['operationId']) &&
            !in_array((int) $line['operationId'], [1,2,3,4,5,7,9,10,43,44], true)) {
            $finishing[] = $line['name'];
        }
    }
    $initials = implode('', array_filter(['C', 'M', 'Y', 'K'], static function ($initial) use ($processColors) {
        return isset($processColors[$initial]);
    }));
    if ($initials !== '') array_unshift($colors, $initials);
    return ['id' => (int) $native['id'], 'description' => $native['field_4225'] ?? '',
        'reference' => $native['field_4242'] ?? '',
        'dimensions' => ($snapshot['product']['kind'] ?? '') === 'assembly' ? '' :
            $native['field_4218'] . ' × ' . $native['field_4219'] . ' mm',
        'pages' => isset($native['field_4233']) && is_numeric($native['field_4233']) &&
            (float) $native['field_4233'] > 0 ? $native['field_4233'] : '',
        'printing' => implode(' + ', array_unique($printing)),
        'colors' => implode(', ', array_unique($colors)),
        'paper' => implode('; ', array_unique($papers)),
        'finishing' => implode('; ', array_unique($finishing))];
}

function vd_workflow_commercial_document(array $quote, array $customer, array $components,
    array $alternatives, $issuedAt, $validDays = 15)
{
    if (!$alternatives || !$components || !is_int($validDays) || $validDays < 1 ||
        !is_int($issuedAt) || $issuedAt <= 0) {
        throw new DomainException('Documento sem alternativas, componentes ou validade valida.');
    }
    if (trim((string) ($customer['paymentTerms'] ?? '')) === '') {
        throw new DomainException('Condicoes de pagamento em falta na ficha do cliente.');
    }
    if (trim((string) ($customer['name'] ?? '')) === '' ||
        trim((string) ($quote['description'] ?? '')) === '') {
        throw new DomainException('Cliente ou descricao comercial em falta.');
    }
    $language = $quote['language'] ?? 'pt';
    if (!in_array($language, ['pt', 'en'], true)) throw new DomainException('Idioma comercial inválido.');
    $english = $language === 'en';
    $translations = [
        'Orçamento' => 'Quotation', 'ORÇAMENTO' => 'QUOTATION', 'Ex.mo(s) Sr(s).' => 'Dear Sir / Madam,',
        'Descrição' => 'Description', 'Quantidade' => 'Quantity', 'P. unit.' => 'Unit Price', 'Desc.' => 'Disc.',
        'Total s/ IVA' => 'Total excl. VAT',
        'Valor s/ IVA' => 'Amount excl. VAT', 'Observações' => 'Notes', 'Validade' => 'Validity',
        ' DIAS' => ' DAYS', 'Entrega' => 'Delivery', 'A COMBINAR' => 'TO BE AGREED',
        'Cond. Pag.' => 'Payment terms', 'I. V. A.' => 'VAT', 'Alternativa: ' => 'Alternative: ',
        ' unidades' => ' units', 'DIMENSÕES' => 'DIMENSIONS', 'Nº PÁG. / FLS. / Nº VIAS' => 'PAGES / SHEETS / COPIES',
        'IMPRESSÃO' => 'PRINTING', 'PAPEL' => 'PAPER', 'ACABAMENTO' => 'FINISHING', 'V/ Ref.ª' => 'Your ref.',
        'De acordo com o vosso pedido de orçamento, que agradecemos, submetemos à vossa apreciação o nosso melhor preço para o eventual fornecimento de:' =>
            'Thank you for your enquiry. We are pleased to submit our quotation for the supply of:',
        'Operação isenta de IVA (regime indicado pelo cliente).' => 'VAT-exempt transaction under the customer’s applicable tax regime.',
        'Acresce IVA à taxa legal aplicável' => 'VAT will be added at the applicable statutory rate',
        'taxa legal aplicável' => 'applicable statutory rate', 'Emitido em ' => 'Issued on ', '. Válido até ' => '. Valid until ',
        ' dias).' => ' days).',
        'Trabalho finalizado em suporte digital a fornecer por V. Exas, contemplando (quando aplicável):' =>
            'Print-ready digital artwork must be supplied by the customer, including where applicable:',
        '- Ficheiro em formato PDF Press Quality ou PDF X1a; imagens convertidas em Quadricromia, com uma resolução mínima de 300 pontos por polegada; fontes embebidas; linhas e massas de corte (3 mm); cores directas ou desdobramentos em quadricromia de cores constantes em catálogo normalizado (Pantone®), de acordo com cor comprovável em suporte físico.' =>
            '- Press Quality PDF or PDF X1a; images converted to CMYK at a minimum resolution of 300 dpi; embedded fonts; crop marks and 3 mm bleed; spot colours or CMYK conversions from a standardised colour catalogue (Pantone®), with a physical colour reference.',
        '- Caso não seja identificada a marca do papel, o papel proposto poderá ser diferente do solicitado.' =>
            '- If the paper brand is not specified, the proposed paper may differ from the paper requested.',
        '- Caso seja detetado, posteriormente, algum erro no orçamento o mesmo será retificado.' =>
            '- Any error subsequently identified in this quotation will be corrected.',
        '- Entregas gratuitas mediante disponibilidade e limitadas a uma distância máxima de 70 km da n/ sede.' =>
            '- Free delivery is subject to availability and limited to a maximum distance of 70 km from our premises.',
        'Na expectativa de que esta proposta seja digna de aprovação, subscrevemo-nos com elevada consideração.' =>
            'We look forward to your approval of this quotation.',
        'Atenciosamente,' => 'Yours faithfully,'
    ];
    $text = static function ($value) use ($english, $translations) {
        return $english ? ($translations[$value] ?? $value) : $value;
    };
    $escape = static function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $money = static function ($value) use ($english) {
        return number_format($value, 2, $english ? '.' : ',', $english ? ',' : ' ') . ' €';
    };
    $brand = [];
    foreach (['logo', 'footer'] as $name) {
        $path = __DIR__ . '/quote-brand-' . $name . '.png';
        if (!is_readable($path)) throw new RuntimeException('Imagem do modelo comercial indisponível: ' . $name);
        $image = file_get_contents($path);
        if ($image === false) throw new RuntimeException('Falha ao ler imagem comercial: ' . $name);
        $brand[$name] = 'data:image/png;base64,' . base64_encode($image);
    }
    $issued = (new DateTimeImmutable('@' . $issuedAt))->setTimezone(new DateTimeZone('Europe/Lisbon'));
    $expires = $issued->modify('+' . $validDays . ' days');
    $number = $quote['number'] ?? $issued->format('Y') . '/' . $quote['id'];
    $html = '<!doctype html><html lang="' . $language . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
        '<title>' . $text('Orçamento') . ' ' . $escape($number) . '</title><style>' .
        '@page{size:A4;margin:14mm}*{box-sizing:border-box}body{font:11px Arial,sans-serif;color:#111;' .
        'max-width:182mm;min-height:269mm;position:relative;padding-bottom:25mm;margin:14mm auto;background:white}.header{display:flex;justify-content:space-between;min-height:180px}' .
        '.logo{width:139px;height:130px}.number{text-align:right;font-size:19px}.date{font-size:10px;margin-top:10px}' .
        '.customer{margin-top:40px;font-size:11px;text-align:left;min-width:240px}.intro{margin:15px 0}' .
        'table{border-collapse:collapse;width:100%}th{background:#888;color:white;font-size:10px;text-align:left;padding:4px 6px}' .
        'td{padding:9px 6px;border-bottom:1px solid #aaa}.price{text-align:right;white-space:nowrap}' .
        '.product{font-size:14px;font-weight:bold}.specifications{min-height:245px;padding:15px 0}' .
        '.component{break-inside:avoid;margin-bottom:15px}.spec{margin:0 0 16px}.spec dt{font-size:9px;margin-bottom:4px}' .
        '.spec dd{font-weight:bold;margin-left:5px}.section-title{background:#888;color:white;padding:3px 6px}' .
        '.observations{min-height:42px;border-top:1px solid #888;padding-top:6px}.conditions{font-size:10px;margin:12px 0}' .
        '.conditions td{padding:4px}.terms{font-size:8px;line-height:1.3}.terms p{margin:2px 0}' .
        '.footer{position:absolute;bottom:0;width:100%}.footer img{display:block;width:100%;height:auto}.closing{margin:8px 0}.no-print{margin-bottom:12px}' .
        '.page-layout>tbody>tr>td,.page-layout>tfoot>tr>td{padding:0;border:0}.footer-space{display:none}' .
        '@media print{body{margin:0;max-width:none;min-height:0;padding-bottom:0;position:static}.no-print{display:none}.header{min-height:150px}' .
        '.specifications{padding:10px 0;min-height:220px}.spec{margin-bottom:10px}.component h3{margin:8px 0 12px}' .
        '.intro{margin:10px 0}.footer{position:fixed;left:0;bottom:0;width:100%;height:20mm;margin:0}' .
        '.footer-space{display:table-footer-group}.footer-space td{height:24mm}' .
        '.footer img{height:20mm;object-fit:contain}' .
        'th,.section-title{-webkit-print-color-adjust:exact;print-color-adjust:exact}}' .
        '</style><body><footer class="footer"><img alt="Viana &amp; Dias, L.da — contactos e identificação comercial" src="' .
        $brand['footer'] . '"></footer><table class="page-layout" role="presentation"><tfoot class="footer-space"><tr><td></td></tr></tfoot><tbody><tr><td><header class="header"><img class="logo" alt="Viana &amp; Dias — imprimimos inovação" src="' .
        $brand['logo'] . '"><div><div class="number"><strong>' . $text('ORÇAMENTO') . '</strong> &nbsp; ' .
        $escape($number) . '</div><div class="date" style="text-align:right">' .
        $issued->format('d.m.Y') . '</div><div class="customer">' . $text('Ex.mo(s) Sr(s).') . '<br><br><strong>' .
        $escape($customer['name']) . '</strong><br>' . $escape($customer['address'] ?? '') . '<br>' .
        $escape(trim(($customer['postalCode'] ?? '') . ' ' . ($customer['city'] ?? ''))) .
        (!empty($customer['contactName']) ? '<br>A/C: ' . $escape($customer['contactName']) : '') .
        '</div></div></header><p class="intro">' . $text('De acordo com o vosso pedido de orçamento, que agradecemos, submetemos à vossa apreciação o nosso melhor preço para o eventual fornecimento de:') . '</p>' .
        '<table><thead><tr><th>' . $text('Descrição') . '</th><th class="price">' . $text('Quantidade') .
        '</th><th class="price">' . $text('P. unit.') .
        '</th><th class="price">' . $text('Total s/ IVA') . '</th></tr></thead><tbody>';
    $quantities = [];
    foreach ($alternatives as $alternative) {
        $values = vd_workflow_validate_calculation($alternative);
        $prices = vd_workflow_discounted_price($values['sale'], $values['quantity'], $customer['discountPercent'] ?? 0,
            $customer['extraDiscountPercent'] ?? 0);
        $quantityKey = (string) $values['quantity'];
        if (isset($quantities[$quantityKey])) {
            throw new DomainException('Alternativas comerciais repetidas para a mesma quantidade.');
        }
        $quantities[$quantityKey] = true;
        $html .= '<tr><td class="product">' . $escape($quote['description']) . '</td><td class="price">' .
            $escape($values['quantity']) . '</td><td class="price">' .
            $escape(number_format($prices['unitSale'], 4, $english ? '.' : ',', $english ? ',' : ' ') . ' €') .
            '</td><td class="price">' . $escape($money($prices['sale'])) . '</td></tr>';
    }
    $vatText = (int) ($customer['vatChoice'] ?? 0) === 1371 ? $text('Operação isenta de IVA (regime indicado pelo cliente).') :
        $text('Acresce IVA à taxa legal aplicável') . (isset($customer['vat']) ? ' (' . $customer['vat'] . ')' : '') . '.';
    $html .= '</tbody></table><section class="specifications">';
    $technicalGroups = [];
    foreach ($alternatives as $alternative) {
        $groupComponents = $alternative['commercialComponents'] ?? $components;
        $key = json_encode($groupComponents, JSON_THROW_ON_ERROR);
        if (!isset($technicalGroups[$key])) $technicalGroups[$key] = ['components' => $groupComponents, 'quantities' => []];
        $technicalGroups[$key]['quantities'][] = $alternative['quantity'];
    }
    foreach ($technicalGroups as $technicalGroup) {
        if (count($technicalGroups) > 1) {
            $html .= '<h3>' . $text('Alternativa: ') . $escape(implode(' / ', $technicalGroup['quantities'])) . $text(' unidades') . '</h3>';
        }
        foreach ($technicalGroup['components'] as $component) {
            // The single-product description is already in the price table; only distinct component names get a heading.
            $heading = trim((string) ($component['description'] ?? ''));
            $html .= '<div class="component">' . ($heading !== '' && $heading !== trim((string) $quote['description']) ?
                '<h3>' . $escape($heading) . '</h3>' : '');
            if ($english) {
                if (trim((string) ($component['englishTechnical'] ?? '')) === '') {
                    throw new DomainException('Descrição técnica em inglês por preencher.');
                }
                $html .= '<p>' . nl2br($escape($component['englishTechnical'])) . '</p></div>';
                continue;
            }
            foreach (['reference' => 'V/ Ref.ª', 'dimensions' => 'DIMENSÕES', 'pages' => 'Nº PÁG. / FLS. / Nº VIAS',
                'printing' => 'IMPRESSÃO', 'paper' => 'PAPEL', 'finishing' => 'ACABAMENTO'] as $key => $label) {
                if (!isset($component[$key]) || trim((string) $component[$key]) === '') continue;
                $value = $component[$key];
                if ($key === 'printing' && !empty($component['colors'])) $value .= ' (' . $component['colors'] . ')';
                $html .= '<dl class="spec"><dt>' . $escape($text($label)) . '</dt><dd>' .
                    nl2br($escape($value)) . '</dd></dl>';
            }
            $html .= '</div>';
        }
    }
    $html .= '</section><span class="section-title">' . $text('Observações') . '</span><div class="observations">' .
        nl2br($escape($quote['observations'] ?? '')) . '</div><table class="conditions"><tr><th>' . $text('Validade') . '</th><td>' .
        $validDays . $text(' DIAS') . '</td><th>' . $text('Entrega') . '</th><td>' . $escape($quote['delivery'] ?? $text('A COMBINAR')) .
        '</td><th>' . $text('Cond. Pag.') . '</th><td>' . $escape($customer['paymentTerms']) . '</td><th>' . $text('I. V. A.') . '</th><td>' .
        $escape($customer['vat'] ?? $text('taxa legal aplicável')) . '</td></tr></table><p>' . $escape($vatText) .
        '</p><p>' . $text('Emitido em ') . $issued->format('d/m/Y') . $text('. Válido até ') . $expires->format('d/m/Y') .
        ' (' . $validDays . $text(' dias).') . '</p><div class="terms"><p>' .
        $text('Trabalho finalizado em suporte digital a fornecer por V. Exas, contemplando (quando aplicável):') . '</p>' .
        '<p>' . $text('- Ficheiro em formato PDF Press Quality ou PDF X1a; imagens convertidas em Quadricromia, com uma resolução mínima de 300 pontos por polegada; fontes embebidas; linhas e massas de corte (3 mm); cores directas ou desdobramentos em quadricromia de cores constantes em catálogo normalizado (Pantone®), de acordo com cor comprovável em suporte físico.') . '</p>' .
        '<p>' . $text('- Caso não seja identificada a marca do papel, o papel proposto poderá ser diferente do solicitado.') . '</p>' .
        '<p>' . $text('- Caso seja detetado, posteriormente, algum erro no orçamento o mesmo será retificado.') . '</p>' .
        '<p>' . $text('- Entregas gratuitas mediante disponibilidade e limitadas a uma distância máxima de 70 km da n/ sede.') . '</p></div>' .
        '<p class="closing">' . $text('Na expectativa de que esta proposta seja digna de aprovação, subscrevemo-nos com elevada consideração.') . '</p>' .
        '<p>' . $text('Atenciosamente,') . '<br>Viana &amp; Dias, L.da</p></td></tr></tbody></table></body></html>';
    return $html;
}

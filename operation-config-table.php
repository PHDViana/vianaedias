<?php
namespace VDOperationTable;

if (!function_exists('db_query')) {
    http_response_code(403);
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rows(string $sql): array
{
    $result = [];
    $query = \db_query($sql);
    while ($row = \db_fetch_array($query)) {
        $result[] = $row;
    }
    return $result;
}

function num($value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '—';
    }
    $number = (float) str_replace(',', '.', (string) $value);
    $text = rtrim(rtrim(number_format($number, 4, ',', ' '), '0'), ',');
    return $text === '' || $text === '-' ? '0' : $text;
}

function dt($timestamp): string
{
    return (int) $timestamp > 0 ? date('d/m/Y', (int) $timestamp) : '—';
}

function dialog(string $url, string $label, string $class = 'vd-optable-edit'): string
{
    return '<a class="' . $class . '" onclick="open_dialog(this.href); return false;" href="' . esc($url) . '">' . esc($label) . '</a>';
}

function render(int $reportId): string
{
    global $report_page_filters;
    $activeOnly = true;
    if (PHP_SAPI !== 'cli') {
        if (isset($_POST['vd_optable_active'])) {
            if (!is_string($_POST['vd_optable_active']) || !in_array($_POST['vd_optable_active'], ['0', '1'], true)) {
                return '<div class="alert alert-danger" role="alert">Filtro inválido.</div>';
            }
            $report_page_filters[$reportId]['active_only'] = $_POST['vd_optable_active'] === '1';
        }
        $activeOnly = $report_page_filters[$reportId]['active_only'] ?? true;
    }
    $operations = rows("SELECT id,field_1982 name,field_6128 base_op,field_6129 active,field_6131 unit,field_6130 basis,field_6132 setup,field_2523 speed,field_4440 waste,field_6133 lead_h,field_6109 cost_h,field_4357 sale_h,field_6107 click,field_6275 proof_click,field_1983 machines,field_6134 notes FROM app_entity_36 ORDER BY field_6128,field_1982,id");
    $machineNames = [];
    foreach (rows('SELECT id,field_1281 name FROM app_entity_78') as $row) {
        $machineNames[(int) $row['id']] = $row['name'];
    }
    $peopleNames = [];
    foreach (rows('SELECT id,field_1876 name FROM app_entity_141') as $row) {
        $peopleNames[(int) $row['id']] = $row['name'];
    }
    $articleNames = [];
    foreach (rows('SELECT id,field_1020 name FROM app_entity_99 WHERE EXISTS(SELECT 1 FROM app_entity_163 m WHERE m.field_2300=app_entity_99.id)') as $row) {
        $articleNames[(int) $row['id']] = $row['name'];
    }
    $group = function (string $sql): array {
        $map = [];
        foreach (rows($sql) as $row) {
            $map[(int) $row['parent_item_id']][] = $row;
        }
        return $map;
    };
    $tariffs = $group('SELECT id,parent_item_id,field_4352 name,field_6135 basis,field_6136 cost,field_6624 annual_ops,field_6164 current,field_6138 version,field_6139 starts_at,field_6165 ends_at FROM app_entity_167 ORDER BY parent_item_id,id');
    $equipment = $group('SELECT id,parent_item_id,field_2403 machines,field_2413 speed,field_6143 unit,field_6144 setup,field_6142 cost,field_6024 click FROM app_entity_168 ORDER BY parent_item_id,id');
    $labor = $group('SELECT id,parent_item_id,field_2411 people,field_6159 role,field_6160 headcount,field_6272 setup,field_6273 per_unit,field_6274 per_hour FROM app_entity_169 ORDER BY parent_item_id,id');
    $materials = $group('SELECT id,parent_item_id,field_2300 article,field_6167 reference,field_6154 unit,field_6155 quantity,field_6156 rule FROM app_entity_163 ORDER BY parent_item_id,id');
    $stageCounts = [];
    foreach (rows('SELECT parent_item_id,COUNT(*) n FROM app_entity_120 GROUP BY parent_item_id') as $row) {
        $stageCounts[(int) $row['parent_item_id']] = (int) $row['n'];
    }
    $names = function (array $ids, string $csv): string {
        $labels = [];
        foreach (explode(',', $csv) as $id) {
            if (isset($ids[(int) $id])) {
                $labels[] = $ids[(int) $id];
            }
        }
        return implode(', ', $labels);
    };
    $html = '<link rel="stylesheet" href="includes/vd_quote_workflow/operation-config-table.css?v=1">';
    $html .= '<div class="vd-optable">';
    $html .= '<form class="from-report-page-filters form-inline noprint vd-optable-filters">' .
        '<label class="vd-optable-flag"><input type="hidden" name="vd_optable_active" value="0">' .
        '<input type="checkbox" name="vd_optable_active" value="1"' . ($activeOnly ? ' checked' : '') . ' onchange="load_report_page()"> Apenas ativas no catálogo</label> ' .
        '<label class="vd-optable-search">Procurar: <input type="search" class="form-control" id="vd-optable-search" placeholder="Nome da operação…"></label> ' .
        '<button type="button" class="btn btn-default" onclick="load_report_page()"><i class="fa fa-refresh" aria-hidden="true"></i> Atualizar tabela</button>' .
        '</form>';
    $html .= '<div class="vd-optable-guide noprint"><strong>Configuração de Operações — tabela</strong>' .
        '<p>Clique em <b>Editar</b> para abrir o formulário nativo em popup (operação ou linha de Custos/Equipamentos/RH/Materiais) e em <b>＋</b> para acrescentar. ' .
        'Depois de guardar no popup, use <b>Atualizar tabela</b> para ver os novos valores. As permissões e validações são as nativas.</p></div>';
    $html .= '<table class="vd-optable-table"><thead><tr>' .
        '<th class="vd-optable-name">Operação</th><th>Ativa</th><th>Unidade</th><th>Base de cálculo</th>' .
        '<th>Acerto (min)</th><th>Prod. méd./h</th><th>Planos acerto</th><th>Prazo ext. (h)</th>' .
        '<th>Custo/h impressão (€)</th><th>Venda (€/h)</th><th>Clique (€/face)</th><th>Clique prova (€)</th>' .
        '<th>Equipamentos associados</th><th class="vd-optable-actions">Ações</th></tr></thead><tbody>';
    $shown = 0;
    foreach ($operations as $operation) {
        $id = (int) $operation['id'];
        if ($activeOnly && $operation['active'] !== 'true') {
            continue;
        }
        $shown++;
        $opTariffs = $tariffs[$id] ?? [];
        $opEquipment = $equipment[$id] ?? [];
        $opLabor = $labor[$id] ?? [];
        $opMaterials = $materials[$id] ?? [];
        $currentTariffs = array_filter($opTariffs, function ($row) { return $row['current'] === 'true'; });
        $editOperation = 'index.php?module=items/form&path=36&id=' . $id;
        $html .= '<tr class="vd-optable-op' . ($operation['active'] === 'true' ? '' : ' is-inactive') . '" data-name="' . esc(mb_strtolower($operation['name'] . ' ' . $operation['base_op'])) . '">' .
            '<td class="vd-optable-name"><strong>' . esc($operation['name']) . '</strong> <span class="vd-optable-id">#' . $id . '</span>' .
            ($operation['base_op'] !== '' ? '<span class="vd-optable-base">' . esc($operation['base_op']) . '</span>' : '') . '</td>' .
            '<td class="num">' . ($operation['active'] === 'true' ? '✓' : '—') . '</td>' .
            '<td>' . esc($operation['unit'] ?: '—') . '</td>' .
            '<td>' . esc($operation['basis'] ?: '—') . '</td>' .
            '<td class="num">' . num($operation['setup']) . '</td>' .
            '<td class="num">' . num($operation['speed']) . '</td>' .
            '<td class="num">' . num($operation['waste']) . '</td>' .
            '<td class="num">' . num($operation['lead_h']) . '</td>' .
            '<td class="num">' . num($operation['cost_h']) . '</td>' .
            '<td class="num">' . num($operation['sale_h']) . '</td>' .
            '<td class="num">' . num($operation['click']) . '</td>' .
            '<td class="num">' . num($operation['proof_click']) . '</td>' .
            '<td>' . esc($names($machineNames, (string) $operation['machines']) ?: '—') . '</td>' .
            '<td class="vd-optable-actions noprint">' . dialog($editOperation, 'Editar', 'vd-optable-edit btn btn-xs btn-primary') .
            ' <a class="btn btn-xs btn-default" href="index.php?module=items/info&path=36-' . $id . '">Ficha</a></td></tr>';
        $html .= '<tr class="vd-optable-sub" data-name="' . esc(mb_strtolower($operation['name'] . ' ' . $operation['base_op'])) . '"><td colspan="14"><details><summary>' .
            'Custos (' . count($opTariffs) . ($currentTariffs ? '' : (count($opTariffs) ? ' · <b>sem tarifa atual</b>' : '')) . ') · Equipamentos (' . count($opEquipment) . ') · Recursos Humanos (' . count($opLabor) . ') · Materiais (' . count($opMaterials) . ') · Etapas (' . ($stageCounts[$id] ?? 0) . ')</summary><div class="vd-optable-panels">';
        $html .= '<div class="vd-optable-panel"><h4>Custos ' . dialog('index.php?module=items/form&path=36-' . $id . '/167', '＋ Adicionar', 'vd-optable-add') . '</h4>';
        if ($opTariffs) {
            $html .= '<table><thead><tr><th>Descrição</th><th>Base</th><th>Custo unit. (€)</th><th>#OP\'s Anuais</th><th>Atual</th><th>Versão</th><th>Vigência</th><th class="noprint"></th></tr></thead><tbody>';
            foreach ($opTariffs as $row) {
                $html .= '<tr' . ($row['current'] === 'true' ? '' : ' class="is-muted"') . '><td>' . esc($row['name'] ?: '—') . '</td><td>' . esc($row['basis'] ?: '—') . '</td>' .
                    '<td class="num">' . num($row['cost']) . '</td><td class="num">' . num($row['annual_ops']) . '</td>' .
                    '<td class="num">' . ($row['current'] === 'true' ? '✓' : '—') . '</td><td>' . esc($row['version'] ?: '—') . '</td>' .
                    '<td>' . dt($row['starts_at']) . ' → ' . dt($row['ends_at']) . '</td>' .
                    '<td class="noprint">' . dialog('index.php?module=items/form&path=36-' . $id . '/167&id=' . (int) $row['id'], 'Editar') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p class="vd-optable-empty">Sem linhas de custo.</p>';
        }
        $html .= '</div>';
        $html .= '<div class="vd-optable-panel"><h4>Equipamentos ' . dialog('index.php?module=items/form&path=36-' . $id . '/168', '＋ Adicionar', 'vd-optable-add') . '</h4>';
        if ($opEquipment) {
            $html .= '<table><thead><tr><th>Equipamento</th><th>Prod. méd./h</th><th>Unidade</th><th>Acerto (min)</th><th>Custo (€/h)</th><th>Clique (€/face)</th><th class="noprint"></th></tr></thead><tbody>';
            foreach ($opEquipment as $row) {
                $html .= '<tr><td>' . esc($names($machineNames, (string) $row['machines']) ?: '—') . '</td>' .
                    '<td class="num">' . num($row['speed']) . '</td><td>' . esc($row['unit'] ?: '—') . '</td>' .
                    '<td class="num">' . num($row['setup']) . '</td><td class="num">' . num($row['cost']) . '</td><td class="num">' . num($row['click']) . '</td>' .
                    '<td class="noprint">' . dialog('index.php?module=items/form&path=36-' . $id . '/168&id=' . (int) $row['id'], 'Editar') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p class="vd-optable-empty">Sem equipamentos configurados.</p>';
        }
        $html .= '</div>';
        $html .= '<div class="vd-optable-panel"><h4>Recursos Humanos ' . dialog('index.php?module=items/form&path=36-' . $id . '/169', '＋ Adicionar', 'vd-optable-add') . '</h4>';
        if ($opLabor) {
            $html .= '<table><thead><tr><th>Recursos</th><th>Função</th><th>Pessoas</th><th>Preparação (min)</th><th>Por unidade (min)</th><th>Por hora equip. (min)</th><th class="noprint"></th></tr></thead><tbody>';
            foreach ($opLabor as $row) {
                $html .= '<tr><td>' . esc($names($peopleNames, (string) $row['people']) ?: '—') . '</td><td>' . esc($row['role'] ?: '—') . '</td>' .
                    '<td class="num">' . num($row['headcount']) . '</td><td class="num">' . num($row['setup']) . '</td>' .
                    '<td class="num">' . num($row['per_unit']) . '</td><td class="num">' . num($row['per_hour']) . '</td>' .
                    '<td class="noprint">' . dialog('index.php?module=items/form&path=36-' . $id . '/169&id=' . (int) $row['id'], 'Editar') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p class="vd-optable-empty">Sem recursos humanos configurados.</p>';
        }
        $html .= '</div>';
        $html .= '<div class="vd-optable-panel"><h4>Materiais ' . dialog('index.php?module=items/form&path=36-' . $id . '/163', '＋ Adicionar', 'vd-optable-add') . '</h4>';
        if ($opMaterials) {
            $html .= '<table><thead><tr><th>Matéria-prima</th><th>Designação ref.</th><th>Unidade consumo</th><th>Qtd. ref.</th><th>Regra</th><th class="noprint"></th></tr></thead><tbody>';
            foreach ($opMaterials as $row) {
                $html .= '<tr><td>' . esc($articleNames[(int) $row['article']] ?? ($row['article'] ? '#' . (int) $row['article'] : '—')) . '</td>' .
                    '<td>' . esc($row['reference'] ?: '—') . '</td><td>' . esc($row['unit'] ?: '—') . '</td>' .
                    '<td class="num">' . num($row['quantity']) . '</td><td>' . esc($row['rule'] ?: '—') . '</td>' .
                    '<td class="noprint">' . dialog('index.php?module=items/form&path=36-' . $id . '/163&id=' . (int) $row['id'], 'Editar') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p class="vd-optable-empty">Sem materiais configurados.</p>';
        }
        $html .= '</div>';
        $html .= '<div class="vd-optable-panel vd-optable-stages"><a href="index.php?module=items/items&path=36-' . $id . '/120">Gerir etapas e dependências (' . ($stageCounts[$id] ?? 0) . ')</a></div>';
        $html .= '</div></details></td></tr>';
    }
    $html .= '</tbody></table>';
    if (!$shown) {
        $html .= '<div class="alert alert-warning" role="alert">Sem operações para o filtro atual.</div>';
    }
    $html .= '<script>(function(){var input=document.getElementById("vd-optable-search");if(!input)return;input.addEventListener("input",function(){var term=input.value.trim().toLowerCase();document.querySelectorAll(".vd-optable-table tbody tr").forEach(function(row){row.hidden=term!==""&&(row.getAttribute("data-name")||"").indexOf(term)<0;});});})();</script>';
    return $html . '</div>';
}

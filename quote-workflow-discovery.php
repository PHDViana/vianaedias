<?php
if (!function_exists('vd_quote_ops_plan')) require_once __DIR__ . '/quote-operation-dependencies.php';

function vd_workflow_discover_operations(array $component, array $explicit, array $catalog, array $rules, array $context = [])
{
    $flags = [];
    foreach (['files' => 6169, 'paper' => 6170, 'gathered' => 6172, 'folded' => 6173, 'signatures' => 6174, 'gather' => 6175] as $key => $field) {
        $flags[$key] = vd_quote_ops_enabled($component['field_' . $field] ?? '');
    }
    if (array_key_exists('plates', $context)) $flags['plates'] = (float) $context['plates'] > 0;
    $roots = [4, 5, 7, 9, 10, 44];
    $inferred = [];
    if (!$flags['files']) $roots[] = 1;
    if (!$flags['files']) $inferred[1] = 'Ficheiros não prontos';
    if (!$flags['paper']) {
        $roots[] = 2;
        $inferred[2] = 'Papel ainda não cortado';
    }
    if (in_array((int) ($component['field_4220'] ?? 0), [1248, 1249], true)) {
        $operation = min((float) $component['field_4218'], (float) $component['field_4219']) <= 250 &&
            max((float) $component['field_4218'], (float) $component['field_4219']) <= 350 ? 11 : 8;
        $roots[] = $operation;
        $inferred[$operation] = 'Corte/vinco e dimensões do componente';
    }
    if ((int) ($component['field_4220'] ?? 0) === 1608) {
        $roots[] = 18;
        $inferred[18] = 'Corte/vinco configurado no componente';
    }
    if (array_intersect([$component['lamination_front'] ?? '', $component['lamination_back'] ?? ''], ['gloss', 'matt', 'anti-scuff'])) {
        $roots[] = 13;
        $inferred[13] = 'Plastificação selecionada no componente';
    }
    $binding = (int) ($component['field_6110'] ?? 0);
    if ($binding === 1545 && !$flags['folded']) {
        $roots[] = 21;
        $inferred[21] = 'Encadernação selecionada; cadernos ainda não dobrados';
    }
    if ($binding === 1546) {
        $roots[] = 23;
        $inferred[23] = 'Encadernação selecionada no componente';
    }
    if ($binding === 1547) {
        $roots[] = 25;
        $inferred[25] = 'Encadernação selecionada no componente';
    }
    if ($binding === 1548) {
        $roots[] = 26;
        $inferred[26] = 'Encadernação selecionada no componente';
    }
    if ($flags['gather'] && !$flags['gathered']) {
        $roots[] = 19;
        $inferred[19] = 'Alceamento necessário e ainda não realizado';
    }
    $selectedExtras = [];
    $rawExtras = $component['field_6291'] ?? '';
    $extraValues = is_array($rawExtras) ? $rawExtras :
        (trim((string) $rawExtras) === '' ? [] : preg_split('/\s*,\s*/', trim((string) $rawExtras)));
    foreach ($extraValues as $extra) {
        $extra = trim((string) $extra);
        if (!preg_match('/^[1-9][0-9]*$/D', $extra)) {
            throw new DomainException('A seleção Operações extra contém um identificador inválido.');
        }
        $id = (int) $extra;
        $selectedExtras[$id] = true;
        $roots[] = $id;
    }
    $manual = [];
    foreach ($explicit as $row) {
        $id = (int) $row['operation_id'];
        if ($id < 1) throw new DomainException('Operação explícita inválida.');
        if ($id === 3 && isset($flags['plates']) && !$flags['plates']) continue;
        if ($id === 1 && $flags['files']) continue;
        if (isset($manual[$id])) throw new DomainException('Operação explícita repetida; reveja os parâmetros.');
        $manual[$id] = $row;
        $roots[] = $id;
    }
    $ordered = vd_quote_ops_plan(array_values(array_unique($roots)), $rules, $flags);
    $catalogById = [];
    foreach ($catalog as $row) $catalogById[(int) $row['id']] = $row;
    if (($component['product_kind'] ?? '') === 'printed') {
        $ordered['order'] = array_values(array_filter($ordered['order'], static function($id)use($component,$catalogById) {
            if(!empty($component['product_stage']))return in_array(vd_product_stage_code($catalogById[$id]['stage']??''),['component','support'],true);
            return !in_array((int)$id,[9,10,19,20,23,24,25,26,32,39],true);
        }));
    }
    $requiredBy = [];
    $parentIds = [];
    foreach ($ordered['edges'] as $source => $requirements) {
        foreach (array_keys($requirements) as $required) {
            $requiredBy[$required][] = $catalogById[(int) $source]['name'] ?? ('Operação #' . $source);
            $parentIds[$required][] = (int) $source;
        }
    }
    $rows = [];
    foreach ($ordered['order'] as $position => $id) {
        if (!isset($catalogById[$id])) throw new DomainException('Operação necessária inexistente: ' . $id);
        $catalogRow = $catalogById[$id];
        if (!vd_quote_ops_enabled($catalogRow['active'])) throw new DomainException('Operação necessária inativa: ' . $id);
        if (in_array($id, [4, 5, 7], true)) {
            $type = 'plan_alternative';
            $reason = 'A máquina é escolhida pelo plano de impressão';
        } elseif (in_array($id, [9, 10, 44], true)) {
            $type = 'mandatory';
            $reason = $id === 44 ? 'Obrigatória em todos os componentes' : 'Etapa base do fluxo de produção';
        } elseif (isset($inferred[$id])) {
            $type = 'inferred';
            $reason = $inferred[$id];
        } elseif (isset($manual[$id]) || isset($selectedExtras[$id])) {
            $type = 'selected';
            $reason = isset($selectedExtras[$id]) ?
                'Selecionada no campo Operações extra do componente' :
                'Selecionada explicitamente no componente; acabamentos opcionais devem ser indicados aqui';
        } else {
            $type = 'dependency';
            $reason = 'Dependência de ' . implode(', ', $requiredBy[$id] ?? []);
        }
        $row = ($manual[$id] ?? []) + ['id' => -((int) $component['id'] * 1000 + $id), 'operation_id' => $id, 'equipment' => '',
            'operation_order' => ($position + 1) * 10, 'automatic' => 'true', 'discovered' => !isset($manual[$id]),
            'packaging_kg' => 10, 'packaging_type' => '1', 'packaging_overlap' => 100,
            'lamination_entry' => 10, 'lamination_exit' => 10, 'lamination_narrow' => 'false'] + $catalogRow;
        if ($id === 13) {
            foreach (['lamination_entry', 'lamination_exit'] as $margin) {
                if ($row[$margin] === null || $row[$margin] === '') $row[$margin] = 10;
            }
        }
        $row['automatic'] = isset($manual[$id]) ? 'false' : 'true';
        $row['discovered'] = !isset($manual[$id]);
        $row['requirement_type'] = $type;
        $row['requirement_reason'] = $reason;
        $row['requirement_parents'] = $parentIds[$id] ?? [];
        $rows[] = $row;
    }
    return $rows;
}

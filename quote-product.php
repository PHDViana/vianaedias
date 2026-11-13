<?php
require_once __DIR__.'/product-stages.php';
function vd_product_types()
{
    return ['standard'=>'Produto habitual','magazine'=>'Revista','folding_box'=>'Caixa dobrável em cartolina',
        'rigid_box'=>'Caixa rígida','lid_base_box'=>'Caixa com tampa e fundo','accessory_box'=>'Caixa com acessórios'];
}

function vd_product_template($type)
{
    $templates = [
        'magazine'=>[['Capa','printed'],['Miolo','printed'],['Assemblagem / Encadernação','assembly']],
        'folding_box'=>[['Corpo da caixa','printed'],['Assemblagem / Colagem','assembly']],
        'rigid_box'=>[['Estrutura de cartão','material'],['Revestimento','printed'],['Assemblagem da caixa','assembly']],
        'lid_base_box'=>[['Tampa','printed'],['Fundo','printed'],['Assemblagem da caixa','assembly']],
        'accessory_box'=>[['Corpo da caixa','printed'],['Berço / divisórias','material'],['Assemblagem da caixa','assembly']]
    ];
    if (!isset($templates[$type])) throw new DomainException('Tipo de produto sem modelo.');
    return $templates[$type];
}

function vd_product_graph(array $components)
{
    $byId = [];
    foreach ($components as $component) {
        $id = (int) $component['id'];
        if ($id < 1 || isset($byId[$id]) || !in_array($component['kind'], ['printed','material','assembly'], true)) {
            throw new DomainException('Tipo ou identificador do componente inválido.');
        }
        $byId[$id] = $component;
    }
    $visiting = $visited = $ordered = [];
    $visit = static function ($id) use (&$visit, &$visiting, &$visited, &$ordered, $byId) {
        if (isset($visiting[$id])) throw new DomainException('As dependências dos componentes formam um ciclo.');
        if (isset($visited[$id])) return;
        if (!isset($byId[$id])) throw new DomainException('Componente de entrada inexistente neste produto.');
        $visiting[$id] = true;
        $component = $byId[$id];
        $inputs = array_map('intval', $component['inputs'] ?? []);
        if (count($inputs) !== count(array_unique($inputs))) throw new DomainException('Componente de entrada repetido.');
        if ($component['kind'] !== 'assembly' && $inputs) throw new DomainException('Só a assemblagem pode depender de outros componentes.');
        if ($component['kind'] === 'assembly' && !$inputs) throw new DomainException('Selecione os componentes de entrada da assemblagem.');
        foreach ($inputs as $input) $visit($input);
        unset($visiting[$id]);
        $visited[$id] = true;
        $ordered[] = $id;
    };
    foreach (array_keys($byId) as $id) $visit($id);
    return $ordered;
}

function vd_product_component(mysqli $db, $quote, $component)
{
    $quote = (int) $quote;
    $component = (int) $component;
    $row = $db->query("SELECT p.type,c.kind,c.inputs,c.units_per_product FROM app_vd_quote_products p JOIN app_vd_quote_product_components c ON c.quote_id=p.quote_id WHERE p.quote_id=$quote AND c.component_id=$component")->fetch_assoc();
    if (!$row) return null;
    return ['type'=>$row['type'],'kind'=>$row['kind'],'inputs'=>array_map('intval',json_decode($row['inputs'],true,512,JSON_THROW_ON_ERROR)),
        'units_per_product'=>(float)$row['units_per_product']]+(vd_product_stage_read($db,$component)??[]);
}

function vd_product_number($value, $label, $allowZero = true)
{
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 ||
        (!$allowZero && (float)$value <= 0) || (float)$value > 1000000000) throw new DomainException('Valor inválido: '.$label);
    return (float)$value;
}

function vd_product_nonprint_calculation(array $definition, array $metadata, array $catalog, array $articles, array $machines)
{
    if (!in_array($metadata['kind'],['material','assembly'],true)) throw new DomainException('Utilize o configurador de impressão neste componente.');
    $quantity = vd_product_number($definition['quantity'] ?? null,'quantidade do produto',false);
    $markup = vd_product_number($definition['markup'] ?? null,'acréscimo');
    $units = vd_product_number($metadata['units_per_product'],'unidades por produto',false);
    $lines = $sources = $operationMap = [];
    vd_product_stage_validate_operations($metadata,$definition['operations']??[],$catalog);
    foreach ($definition['operations'] ?? [] as $index=>$input) {
        $id = (int)($input['operation_id']??0);
        if (!isset($catalog[$id]) || in_array($id,[1,3,4,5,7,13,14,43,44],true) || isset($operationMap[$id])) {
            throw new DomainException('Operação inválida, repetida ou de impressão num componente sem impressão.');
        }
        $operationMap[$id] = $index+1;
        $minutes = vd_product_number($input['minutes']??null,'minutos totais de '.$catalog[$id]['name']);
        $hourly = vd_product_number($input['hourly']??null,'custo/h de '.$catalog[$id]['name']);
        $work = vd_product_number($input['work_quantity']??null,'quantidade de trabalho');
        $equipment = (int)($input['equipment']??0);
        if(!empty($metadata['stage'])&&trim((string)$catalog[$id]['machines'])!==''&&!$equipment)
            throw new DomainException('Escolha explicitamente o equipamento de '.$catalog[$id]['name'].'.');
        if ($equipment && (!isset($machines[$equipment]) ||
            !in_array((string)$equipment,explode(',',(string)$catalog[$id]['machines']),true))) {
            throw new DomainException('Equipamento não associado à operação selecionada.');
        }
        $service = ($input['basis'] ?? 'h') === 'serviço';
        // Subcontracted services are priced per service (work × price), without machine time.
        $cost = $service ? $work*$hourly : $minutes/60*$hourly;
        $lines[] = ['key'=>'operation:'.$id,'operationId'=>$id,'operationRow'=>$index+1,
            'name'=>$catalog[$id]['name'],'quantity'=>$work,'unit'=>$input['unit']??'un.',
            'hours'=>$minutes/60,'cost'=>$cost,'calculatedCost'=>$cost,'sale'=>$cost*(1+$markup/100),
            'parameters'=>['minutes'=>$minutes,'hourly'=>$hourly,'basis'=>$service?'serviço':'h'],'errors'=>[]];
        $sources[] = ['id'=>$index+1,'operation_id'=>$id,'equipment'=>$equipment?:''];
    }
    if (!$lines) throw new DomainException('Adicione pelo menos uma operação ao componente.');
    foreach ($definition['materials']??[] as $index=>$input) {
        $articleId = (int)($input['article_id']??0);
        $operationId = (int)($input['operation_id']??0);
        if (!isset($articles[$articleId],$operationMap[$operationId])) throw new DomainException('Material sem artigo ou operação válida.');
        $amount = vd_product_number($input['quantity']??null,'consumo total de material',false);
        $price = vd_product_number($input['unit_cost']??null,'custo unitário do material');
        $unit = trim((string)($input['unit']??''));
        if ($unit === '' || strlen($unit)>40) throw new DomainException('Indique a unidade de consumo do material.');
        $cost = $amount*$price;
        $article = $articles[$articleId];
        $lines[] = ['key'=>'material:'.$index,'parentOperationRow'=>$operationMap[$operationId],
            'name'=>$article['name'],'quantity'=>$amount,'unit'=>$unit,'cost'=>$cost,'calculatedCost'=>$cost,
            'sale'=>$cost*(1+$markup/100),'reference'=>['articleId'=>$articleId,'descriptionId'=>$article['internal_id']?:null],
            'price_source'=>'manual_explicit','unit_cost'=>$price,'errors'=>[]];
    }
    $cost = array_sum(array_column($lines,'cost'));
    $sale = array_sum(array_column($lines,'sale'));
    if (!is_finite($cost)||!is_finite($sale)) throw new DomainException('Custos excedem os limites.');
    return ['source'=>'nonprint','quantity'=>$quantity,'parts'=>[],'rubrics'=>[],'component'=>[],
        'product'=>$metadata,'units'=>$quantity*$units,'definition'=>$definition,
        'allOperations'=>['quantity'=>$quantity,'complete'=>true,'errors'=>[],'lines'=>$lines,
            'sourceOperations'=>$sources,'inputs'=>[],'cost'=>$cost,'sale'=>$sale]];
}

/**
 * Suggests assembly operation inputs from the catalog (consolidated tariff, productivity, setup) and the
 * physical inputs of the product. Values remain editable; the saved definition stays explicit.
 */
function vd_product_assembly_suggestion($quantity, array $context, array $operations, array $catalog, array $tariffs, array $equipmentRows)
{
    $quantity = vd_product_number($quantity, 'quantidade do produto', false);
    $notes = [];
    $thickness = (float) ($context['thickness_mm'] ?? 0);
    $weight = (float) ($context['weight_g'] ?? 0);
    $pileHeight = 100.0;
    $piles = $thickness > 0 ? (int) ceil($quantity * $thickness / $pileHeight) : null;
    $result = [];
    foreach ($operations as $input) {
        $id = (int) ($input['operation_id'] ?? 0);
        if (!isset($catalog[$id])) throw new DomainException('Operação inexistente no catálogo: #' . $id);
        $operation = $catalog[$id];
        $rows = array_values(array_filter($tariffs, static function ($row) use ($id) { return (int) $row['operation_id'] === $id; }));
        $tariff = count($rows) === 1 ? $rows[0] : null;
        $selected = (string) ($input['equipment'] ?? '');
        $candidates = array_values(array_filter($equipmentRows, static function ($row) use ($id, $selected) {
            return (int) $row['operation_id'] === $id && ($selected === '' || $selected === '0' ||
                in_array($selected, explode(',', (string) $row['machine_id']), true));
        }));
        $equipment = $candidates[0] ?? null;
        $machines = array_values(array_filter(explode(',', (string) $operation['machines'])));
        $machine = $selected !== '' && $selected !== '0' ? (int) $selected :
            ($equipment ? (int) explode(',', (string) $equipment['machine_id'])[0] : (int) ($machines[0] ?? 0));
        $unit = trim((string) ($equipment['unit'] ?? '') !== '' ? $equipment['unit'] : ($operation['unit'] ?? ''));
        $base = strtolower(trim(preg_replace('#/h$#', '', $unit)));
        $speed = (float) (($equipment['speed'] ?? '') !== '' ? $equipment['speed'] : ($operation['speed'] ?? 0));
        $setup = (float) (($equipment['setup'] ?? '') !== '' ? $equipment['setup'] : ($operation['setup'] ?? 0));
        $line = ['operation_id' => $id, 'equipment' => $machine, 'unit' => $base ?: 'un.', 'notes' => []];
        if (!$tariff) {
            $line['notes'][] = 'Sem tarifa vigente única: indique o custo/h.';
            $line = array_replace($line, ['minutes' => null, 'hourly' => null, 'work_quantity' => null, 'basis' => 'h']);
            $result[] = $line;
            continue;
        }
        if ($tariff['basis'] === 'serviço') {
            if ($id === 26) {
                // Subcontracted sewing is priced per signature: copies × folded signatures of the sewn inputs.
                $signatures = (int) ($context['signatures'] ?? 0);
                if ($signatures <= 0) $line['notes'][] = 'Cadernos desconhecidos: guarde a versão do miolo cosido para esta quantidade.';
                $result[] = array_replace($line, ['basis' => 'serviço', 'minutes' => 0, 'hourly' => (float) $tariff['cost'],
                    'work_quantity' => $signatures > 0 ? $quantity * $signatures : null, 'unit' => 'cadernos',
                    'source' => 'Subcontratado · ' . $signatures . ' cadernos/exemplar · tarifa ' . $tariff['version'] . ' por caderno']);
                continue;
            }
            $result[] = array_replace($line, ['basis' => 'serviço', 'minutes' => 0, 'hourly' => (float) $tariff['cost'], 'work_quantity' => 1,
                'unit' => 'serviço', 'source' => 'Serviço subcontratado · tarifa ' . $tariff['version'] . ' por serviço']);
            continue;
        }
        $work = null;
        if (in_array($base, ['cortes'], true)) {
            $work = $piles === null ? null : $piles * 3;
            if ($work === null) $line['notes'][] = 'Indique a espessura do produto para calcular pilhas e cortes.';
        } elseif (in_array($base, ['lotes'], true)) {
            $work = $piles;
            if ($work === null) $line['notes'][] = 'Indique a espessura do produto para calcular os lotes.';
        } elseif ($base === 'volumes') {
            $work = $weight > 0 ? (int) ceil($quantity * $weight / 1000 / 10) : null;
            if ($work === null) $line['notes'][] = 'Peso por exemplar desconhecido: guarde as versões dos componentes impressos.';
        } elseif ($base === 'entregas') {
            $work = 1;
        } else {
            $work = $quantity;
        }
        $minutes = $work !== null && $speed > 0 ? $setup + $work / $speed * 60 : null;
        if ($speed <= 0) $line['notes'][] = 'Produtividade em falta no catálogo/equipamento.';
        $result[] = array_replace($line, ['basis' => 'h', 'work_quantity' => $work, 'minutes' => $minutes === null ? null : round($minutes, 2),
            'hourly' => (float) $tariff['cost'],
            'source' => 'Tarifa ' . $tariff['version'] . ' · ' . $speed . ' ' . $unit . ' · acerto ' . $setup . ' min']);
    }
    $materials = [];
    $ids = array_column($result, 'operation_id');
    $height = (float) ($context['binding_height_mm'] ?? 0);
    if (in_array(25, $ids, true)) {
        $glue = $context['glue'] ?? null;
        $rate = 0.06;
        if (!$glue || !($glue['price'] > 0)) $notes[] = 'Cola de brochar sem preço de compra.';
        elseif ($thickness <= 0 || $height <= 0) $notes[] = 'Cola: indique lombada e altura do produto.';
        else $materials[] = ['article_id' => (int) $glue['id'], 'operation_id' => 25, 'unit' => 'kg', 'unit_cost' => (float) $glue['price'],
            'quantity' => round($quantity * ($thickness / 10) * ($height / 10) * $rate / 1000, 4),
            'source' => 'Cola ' . $rate . ' g/cm² × lombada ' . round($thickness, 2) . ' mm × altura ' . $height . ' mm'];
    }
    if (in_array(32, $ids, true)) {
        $turns = $height > 0 ? (int) floor(($height - 6) / 8.467) : 0;
        $best = null;
        foreach ($context['spirals'] ?? [] as $article) {
            if (!preg_match('/N[ºo°]\s*(\d+)/iu', $article['name'], $size) || !preg_match('/(\d+)\s*v(?:oltas)?\b/iu', $article['name'], $loops)) continue;
            $capacity = (int) $size[1] * 25.4 / 16 - 1.5;
            if (!($article['price'] > 0) || $capacity < $thickness || (int) $loops[1] < $turns) continue;
            $key = [(int) $size[1], (int) $loops[1], (float) $article['price']];
            if (!$best || $key < $best[0]) $best = [$key, $article];
        }
        if ($thickness <= 0 || $turns <= 0) $notes[] = 'Espiral: indique lombada e altura do produto.';
        elseif (!$best) $notes[] = 'Espiral: sem artigo com diâmetro ≥ ' . round($thickness, 1) . ' mm e ' . $turns . ' voltas com preço.';
        else $materials[] = ['article_id' => (int) $best[1]['id'], 'operation_id' => 32, 'unit' => 'Un.', 'unit_cost' => (float) $best[1]['price'],
            'quantity' => $quantity, 'source' => 'Espiral Nº ' . $best[0][0] . ' (~' . round($best[0][0] * 25.4 / 16, 1) . ' mm) · ' . $best[0][1] .
                ' voltas para ' . $turns . ' necessárias (passo 3:1)'];
    }
    if ($piles !== null) $notes[] = 'Lombada ' . round($thickness, 2) . ' mm (' . ($context['thickness_source'] ?? '') . ') · pilhas de ' .
        $pileHeight . ' mm: ' . $piles . '.';
    if ($weight > 0) $notes[] = 'Peso estimado por exemplar: ' . round($weight, 1) . ' g · volumes até 10 kg.';
    return ['operations' => $result, 'materials' => $materials, 'notes' => array_merge($notes, $context['notes'] ?? [])];
}

function vd_production_check_inputs(mysqli $db, $componentId)
{
    $componentId=(int)$componentId;
    $missing=$db->query("SELECT i.input_component_id FROM app_vd_production_component_inputs i WHERE i.component_id=$componentId AND (NOT EXISTS(SELECT 1 FROM app_entity_170 o WHERE o.parent_item_id=i.input_component_id) OR EXISTS(SELECT 1 FROM app_entity_170 o WHERE o.parent_item_id=i.input_component_id AND o.field_2425 NOT IN(39,3790)))")->fetch_all(MYSQLI_ASSOC);
    if ($missing) throw new DomainException('Assemblagem bloqueada: conclua todas as operações dos componentes de entrada #'.implode(', #',array_column($missing,'input_component_id')).'.');
}

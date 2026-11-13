<?php
function vd_quote_ops_enabled($value)
{
    return in_array((string) $value, ['1', 'true'], true);
}

function vd_quote_ops_plan(array $roots, array $rules, array $flags)
{
    $nodes = array_fill_keys(array_map('intval', $roots), true);
    $edges = [];
    $conditions = [
        'always' => true,
        'files' => !$flags['files'],
        'paper' => !$flags['paper'],
        'plates' => $flags['plates'] ?? true,
        'gather' => $flags['gather'] && !$flags['gathered'],
        'fold' => !$flags['folded'],
        'signatures' => $flags['signatures'] && !$flags['folded'],
        'selected' => false,
    ];
    foreach ($rules as &$rule) {
        if (!array_key_exists($rule['condition'], $conditions)) {
            throw new DomainException('Regra automatica desconhecida: ' . $rule['condition']);
        }
        $text = str_replace(' ', '', trim($rule['alternatives']));
        if ($text !== '' && !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $text)) {
            throw new DomainException('IDs de alternativas invalidos.');
        }
        $rule['choices'] = $text === '' ? [(int) $rule['required']] : array_map('intval', explode(',', $text));
        if ((int) $rule['required'] < 1 || !in_array((int) $rule['required'], $rule['choices'], true)) {
            throw new DomainException('A operacao preferida deve pertencer as alternativas.');
        }
    }
    unset($rule);
    do {
        $changed = false;
        foreach ($rules as $rule) {
            $op = (int) $rule['operation'];
            if (!isset($nodes[$op]) || !$conditions[$rule['condition']]) {
                continue;
            }
            $chosen = array_values(array_filter($rule['choices'], static function ($id) use ($nodes) {
                return isset($nodes[$id]);
            }));
            if (!$chosen) {
                $chosen = [(int) $rule['required']];
            }
            foreach ($chosen as $required) {
                $edges[$op][$required] = true;
                if (!isset($nodes[$required])) {
                    $nodes[$required] = true;
                    $changed = true;
                }
            }
        }
    } while ($changed);
    foreach ($rules as $rule) {
        $op = (int) $rule['operation'];
        if (!isset($nodes[$op]) || $rule['condition'] !== 'selected') {
            continue;
        }
        foreach ($rule['choices'] as $required) {
            if (isset($nodes[$required])) {
                $edges[$op][$required] = true;
            }
        }
    }
    $state = [];
    $order = [];
    $visit = static function ($id) use (&$visit, &$state, &$order, $edges) {
        if (($state[$id] ?? 0) === 1) {
            throw new DomainException('Ciclo nas dependencias da operacao ' . $id . '.');
        }
        if (($state[$id] ?? 0) === 2) {
            return;
        }
        $state[$id] = 1;
        foreach (array_keys($edges[$id] ?? []) as $required) {
            $visit($required);
        }
        $state[$id] = 2;
        $order[] = $id;
    };
    foreach (array_keys($nodes) as $id) {
        $visit($id);
    }
    return ['order' => $order, 'edges' => $edges];
}

function vd_quote_ops_rows($sql)
{
    $query = db_query($sql);
    if ($query === false) {
        throw new RuntimeException('Falha ao consultar as dependencias das operacoes.');
    }
    $rows = [];
    while ($row = db_fetch_array($query)) {
        $rows[] = $row;
    }
    return $rows;
}

function vd_quote_ops_sync($componentId, $ignoreItemId = 0)
{
    static $running = [];
    global $alerts, $app_user;
    $componentId = (int) $componentId;
    $ignoreItemId = (int) $ignoreItemId;
    if ($componentId < 1) {
        throw new DomainException('Componente de orcamento invalido.');
    }
    if (isset($running[$componentId])) {
        return true;
    }
    $running[$componentId] = true;
    $locked = false;
    try {
        $lock = vd_quote_ops_rows("SELECT GET_LOCK('vd_quote_ops_{$componentId}', 5) AS acquired");
        if ((int) ($lock[0]['acquired'] ?? 0) !== 1) {
            throw new DomainException('O componente esta a ser atualizado. Tente novamente.');
        }
        $locked = true;
        $components = vd_quote_ops_rows("SELECT c.*, q.field_2030 AS quote_state FROM app_entity_262 c JOIN app_entity_146 q ON q.id=c.parent_item_id WHERE c.id={$componentId}");
        if (!$components) {
            throw new DomainException('Componente ou orcamento nao encontrado.');
        }
        $component = $components[0];
        if (!in_array((string) $component['quote_state'], ['', '36', '3653', '3646', '3681'], true)) {
            if (is_object($alerts)) {
                $alerts->add('As dependencias nao foram alteradas: o orcamento nao esta em preparacao.', 'warning');
            }
            return true;
        }
        $flags = [];
        foreach (['files' => 6169, 'paper' => 6170, 'gathered' => 6172, 'folded' => 6173, 'signatures' => 6174, 'gather' => 6175] as $key => $field) {
            $flags[$key] = vd_quote_ops_enabled($component['field_' . $field] ?? '');
        }
        $records = vd_quote_ops_rows("SELECT * FROM app_entity_276 WHERE parent_item_id={$componentId} AND vd_workflow_version IS NULL ORDER BY id");
        $manual = [];
        $byOperation = [];
        foreach ($records as $record) {
            if ((int) $record['id'] === $ignoreItemId) {
                continue;
            }
            $operation = (int) $record['field_4287'];
            if ($operation < 1) {
                throw new DomainException('Existe uma linha sem operacao definida.');
            }
            $byOperation[$operation][] = $record;
            if (!vd_quote_ops_enabled($record['field_6179'])) {
                $manual[$operation] = true;
            }
        }
        $rawRules = vd_quote_ops_rows("SELECT parent_item_id AS operation,field_6149 AS required_op,field_6176 AS condition_code,field_6177 AS alternatives FROM app_entity_120 WHERE field_6178 IN ('1','true') AND field_6176<>'' ORDER BY id");
        $rules = [];
        foreach ($rawRules as $row) {
            $rules[] = ['operation' => (int) $row['operation'], 'required' => (int) $row['required_op'], 'condition' => $row['condition_code'], 'alternatives' => $row['alternatives']];
        }
        $plan = vd_quote_ops_plan(array_keys($manual), $rules, $flags);
        $catalog = [];
        foreach (vd_quote_ops_rows('SELECT id,field_1982,field_6129,field_1983 FROM app_entity_36') as $row) {
            $catalog[(int) $row['id']] = $row;
        }
        foreach ($plan['order'] as $operation) {
            if (!isset($catalog[$operation])) {
                throw new DomainException('Operacao inexistente nas dependencias: ' . $operation);
            }
            if (!isset($byOperation[$operation]) && !vd_quote_ops_enabled($catalog[$operation]['field_6129'])) {
                throw new DomainException('Operacao necessaria inativa: ' . $operation);
            }
        }
        $position = 10;
        foreach ($plan['order'] as $operation) {
            $sources = [];
            foreach ($plan['edges'] as $source => $requirements) {
                if (isset($requirements[$operation])) {
                    $sources[] = $catalog[$source]['field_1982'] . ' (#' . $source . ')';
                }
            }
            $origin = 'Necessaria para: ' . implode(', ', $sources);
            if (!isset($byOperation[$operation])) {
                $equipment = array_values(array_filter(explode(',', (string) $catalog[$operation]['field_1983'])));
                $data = ['parent_item_id' => $componentId, 'created_by' => (int) $app_user['id'], 'field_4287' => $operation, 'field_6179' => 1, 'field_6180' => $position, 'field_6181' => $origin];
                if (count($equipment) === 1) {
                    $data['field_4288'] = $equipment[0];
                }
                items::insert(276, $data);
                $inserted = vd_quote_ops_rows("SELECT id FROM app_entity_276 WHERE parent_item_id={$componentId} AND field_4287={$operation} AND vd_workflow_version IS NULL AND field_6179 IN ('1','true') AND id<>{$ignoreItemId}");
                if (!$inserted) {
                    throw new RuntimeException('Nao foi possivel incluir a operacao ' . $operation . '.');
                }
            } else {
                foreach ($byOperation[$operation] as $record) {
                    $updates = [];
                    if ((int) $record['field_6180'] !== $position) {
                        $updates['field_6180'] = $position;
                    }
                    if (vd_quote_ops_enabled($record['field_6179']) && $record['field_6181'] !== $origin) {
                        $updates['field_6181'] = $origin;
                    }
                    if ($updates) {
                        items::update_by_id(276, (int) $record['id'], $updates);
                    }
                }
            }
            $position += 10;
        }
        // Only generated rows can disappear when conditions or manual choices change.
        foreach ($records as $record) {
            if ((int) $record['id'] === $ignoreItemId || !vd_quote_ops_enabled($record['field_6179'])) {
                continue;
            }
            $operation = (int) $record['field_4287'];
            if (!in_array($operation, $plan['order'], true) || isset($manual[$operation])) {
                items::delete(276, (int) $record['id']);
                $deletedId = (int) $record['id'];
                if (vd_quote_ops_rows("SELECT id FROM app_entity_276 WHERE id={$deletedId}")) {
                    throw new RuntimeException('Nao foi possivel remover a dependencia automatica ' . $deletedId . '.');
                }
            }
        }
        return true;
    } catch (DomainException $error) {
        error_log('VD quote operations: ' . $error->getMessage());
        if (is_object($alerts)) {
            $alerts->add('Nao foi possivel completar as dependencias: ' . htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }
        return false;
    } finally {
        if ($locked) {
            db_query("SELECT RELEASE_LOCK('vd_quote_ops_{$componentId}')");
        }
        unset($running[$componentId]);
    }
}
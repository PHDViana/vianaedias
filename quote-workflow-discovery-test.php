<?php
require __DIR__ . '/quote-workflow-discovery.php';
$catalog = [];
foreach ([1,2,3,4,5,7,8,9,10,11,13,18,19,21,22,23,25,26,32,36,43,44] as $id) {
    $catalog[] = ['id' => $id, 'name' => 'Operation #' . $id, 'active' => 'true'];
}
$component = ['id' => 90, 'field_4218' => 210, 'field_4219' => 297, 'field_6110' => 1544];
$rules = [['operation' => 5, 'required' => 3, 'condition' => 'plates', 'alternatives' => ''],
    ['operation' => 7, 'required' => 3, 'condition' => 'plates', 'alternatives' => ''],
    ['operation' => 3, 'required' => 43, 'condition' => 'always', 'alternatives' => ''],
    ['operation' => 32, 'required' => 22, 'condition' => 'always', 'alternatives' => '']];
$checks = 0;
function discoveryCheck($value) { global $checks; if (!$value) throw new RuntimeException('Discovery assertion failed.'); $checks++; }
$operations = vd_workflow_discover_operations($component, [], $catalog, $rules);
$ids = array_column($operations, 'operation_id');
foreach ([1,2,3,4,5,7,9,10,43,44] as $id) discoveryCheck(in_array($id, $ids, true));
discoveryCheck(array_search(43, $ids, true) < array_search(3, $ids, true));
discoveryCheck(array_search(3, $ids, true) < array_search(5, $ids, true));
$byId = [];
foreach ($operations as $operation) $byId[$operation['operation_id']] = $operation;
discoveryCheck($byId[44]['requirement_type'] === 'mandatory');
discoveryCheck($byId[4]['requirement_type'] === 'plan_alternative');
discoveryCheck($byId[3]['requirement_type'] === 'dependency');
discoveryCheck($byId[3]['requirement_parents'] === [5, 7]);
discoveryCheck($byId[43]['requirement_parents'] === [3]);
discoveryCheck($byId[44]['requirement_parents'] === []);
discoveryCheck($byId[1]['requirement_type'] === 'inferred');
$manual = ['id' => 771, 'operation_id' => 18, 'equipment' => 23, 'custom' => 'Preserved'];
$operations = vd_workflow_discover_operations($component, [$manual], $catalog, $rules);
$crease = array_values(array_filter($operations, static function ($row) { return $row['operation_id'] === 18; }))[0];
discoveryCheck($crease['id'] === 771 && $crease['custom'] === 'Preserved' && empty($crease['discovered']));
discoveryCheck($crease['requirement_type'] === 'selected' && $crease['automatic'] === 'false');
$lamination = ['id' => 773, 'operation_id' => 13, 'equipment' => '28',
    'lamination_entry' => '10', 'lamination_exit' => '12', 'lamination_narrow' => '1'];
$component['lamination_front'] = 'matt';
$operations = vd_workflow_discover_operations($component, [$lamination], $catalog, $rules);
$laminationRows = array_values(array_filter($operations, static function ($row) { return $row['operation_id'] === 13; }));
discoveryCheck(count($laminationRows) === 1 && $laminationRows[0]['id'] === 773 &&
    $laminationRows[0]['equipment'] === '28' && $laminationRows[0]['lamination_entry'] === '10' &&
    $laminationRows[0]['lamination_exit'] === '12' && $laminationRows[0]['lamination_narrow'] === '1' &&
    $laminationRows[0]['automatic'] === 'false');
$defaultLamination = array_values(array_filter(
    vd_workflow_discover_operations($component, [], $catalog, $rules),
    static function ($row) { return $row['operation_id'] === 13; }
))[0];
discoveryCheck($defaultLamination['lamination_entry'] === 10 && $defaultLamination['lamination_exit'] === 10);
$lamination['lamination_entry'] = '';
$lamination['lamination_exit'] = '0';
$blankLamination = array_values(array_filter(
    vd_workflow_discover_operations($component, [$lamination], $catalog, $rules),
    static function ($row) { return $row['operation_id'] === 13; }
))[0];
discoveryCheck($blankLamination['lamination_entry'] === 10 && $blankLamination['lamination_exit'] === '0');
$component['lamination_front'] = '';
$other = vd_workflow_discover_operations($component + [], [], $catalog, $rules)[0]['id'];
$component['id'] = 91;
discoveryCheck($other !== vd_workflow_discover_operations($component, [], $catalog, $rules)[0]['id']);
$component['lamination_front'] = 'matt';
$component['field_6110'] = 1546;
$ids = array_column(vd_workflow_discover_operations($component, [], $catalog, $rules), 'operation_id');
discoveryCheck(in_array(13, $ids, true) && in_array(23, $ids, true));
$component['field_6169'] = '1';
$component['field_6170'] = '1';
$ids = array_column(vd_workflow_discover_operations($component, [], $catalog, $rules), 'operation_id');
discoveryCheck(!in_array(1, $ids, true) && !in_array(2, $ids, true));
$legacyArt = ['id' => 772, 'operation_id' => 1, 'equipment' => '11,16'];
$ids = array_column(vd_workflow_discover_operations($component, [$legacyArt], $catalog, $rules), 'operation_id');
discoveryCheck(!in_array(1, $ids, true));
$component['field_4220'] = 1608;
$ids = array_column(vd_workflow_discover_operations($component, [], $catalog, $rules), 'operation_id');
discoveryCheck(in_array(18, $ids, true) && !in_array(11, $ids, true) && !in_array(8, $ids, true));
$component['field_6169'] = '1';
$component['field_6170'] = '1';
$operations = vd_workflow_discover_operations($component, [], $catalog, $rules, ['plates' => 0]);
$ids = array_column($operations, 'operation_id');
discoveryCheck(!in_array(3, $ids, true) && !in_array(43, $ids, true));
$component['field_6291'] = '22,32,36';
$operations = vd_workflow_discover_operations($component, [], $catalog, $rules);
$ids = array_column($operations, 'operation_id');
$byId = [];
foreach ($operations as $operation) $byId[$operation['operation_id']] = $operation;
discoveryCheck(in_array(22, $ids, true) && in_array(32, $ids, true) && in_array(36, $ids, true));
discoveryCheck(array_search(22, $ids, true) < array_search(32, $ids, true));
discoveryCheck($byId[22]['requirement_type'] === 'selected' &&
    $byId[22]['requirement_reason'] === 'Selecionada no campo Operações extra do componente');
discoveryCheck($byId[36]['requirement_type'] === 'selected');
$component['field_6291'] = ['22', '36'];
$ids = array_column(vd_workflow_discover_operations($component, [], $catalog, $rules), 'operation_id');
discoveryCheck(in_array(22, $ids, true) && in_array(36, $ids, true));
$component['field_6291'] = '22,invalid';
try {
    vd_workflow_discover_operations($component, [], $catalog, $rules);
    throw new RuntimeException('Invalid extra operation selection was accepted.');
} catch (DomainException $error) {
    discoveryCheck(strpos($error->getMessage(), 'Operações extra') !== false);
}
echo "$checks requirements discovery checks passed.\n";

<?php
if (PHP_SAPI !== 'cli') exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$connection = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$connection->set_charset('utf8mb4');
function db_query($sql) {
    global $connection;
    return $connection->query($sql);
}
function db_fetch_array($query) { return $query->fetch_assoc(); }
require 'includes/functions/app.php';
require 'includes/classes/fields_types_cfg.php';
foreach (['TEXT_FIELDTYPE_INPUT_DATETIME_TITLE'=>'Data e hora','TEXT_FIELDTYPE_DROPDOWN_TITLE'=>'Lista','TEXT_FIELDTYPE_BOOLEAN_CHECKBOX_TITLE'=>'Sim/Não','TEXT_FIELDTYPE_TEXTAREA_TITLE'=>'Texto'] as $name=>$value) define($name,$value);
foreach (['fieldtype_input_datetime','fieldtype_dropdown','fieldtype_boolean_checkbox','fieldtype_textarea'] as $type) require "includes/classes/fieldstypes/$type.php";
$setting = db_fetch_array(db_query("SELECT configuration_value FROM app_configuration WHERE configuration_name='CFG_APP_TIMEZONE'"));
define('CFG_APP_TIMEZONE',$setting['configuration_value']);
date_default_timezone_set(CFG_APP_TIMEZONE);
require '_CustomDEVs/quote-workflow/vd_quote_workflow/attendance-report.php';
$app_user = ['id'=>1,'group_id'=>0];
$checks=0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function reportMinutes(string $html, int $column): int {
    preg_match_all('/<td class="vd-attendance-number">([^<]+)<\/td>/',$html,$matches);
    $minutes=0;
    foreach($matches[1] as $i=>$value) {
        if($i%4!==$column) continue;
        if(!preg_match('/^(-?)([0-9]+):([0-9]{2})$/D',$value,$time)) throw new RuntimeException('Unexpected unknown daily hours');
        $minutes+=((int)$time[2]*60+(int)$time[3])*($time[1]==='-'?-1:1);
    }
    return $minutes;
}
$rawBefore=db_fetch_array(db_query("SELECT COUNT(*) n,SUM(CRC32(CONCAT_WS('|',id,created_by,date_added,field_5792))) checksum FROM app_entity_135"));
$realCorrections=db_fetch_array(db_query('SELECT COUNT(*) n FROM app_entity_409'));
$permissions=db_fetch_array(db_query("SELECT COUNT(*) n FROM app_entities_access WHERE entities_id=409 AND access_groups_id<>4 AND access_schema<>''"));
check((int)$permissions['n']===0,'Unexpected correction access');
$permissions=db_fetch_array(db_query('SELECT access_schema FROM app_entities_access WHERE entities_id=409 AND access_groups_id=4'));
check($permissions['access_schema']==='view,create,update,export,reports','Correction permissions');
$filters=['filter_by_date'=>'2026-06','filter_by_entity1'=>15];
$baseline=VDAttendance\render($filters);
check(strpos($baseline,'São João — feriado municipal de Braga')!==false,'Missing Braga holiday');
check(strpos($baseline,'Feriado: Corpo de Deus')!==false,'Missing Corpus Christi');
check(strpos($baseline,'Sem feriados configurados')===false,'Obsolete calendar warning');
check(substr_count($baseline,'<td class="vd-attendance-date">')===30,'June row count');
check(strpos($baseline,'<dt>Horas previstas</dt><dd>152:00</dd>')!==false,'June expected hours must exclude all 3 working-day holidays');
$workedBefore=reportMinutes($baseline,2);
$target=db_fetch_array(db_query("SELECT p.id,p.date_added,p.field_5792 FROM app_entity_135 p JOIN app_entity_141 h ON h.field_1900=p.created_by WHERE h.id=15 AND FROM_UNIXTIME(p.date_added,'%Y-%m-%d')='2026-06-01' ORDER BY p.id LIMIT 1"));
check($target!==null,'Fixture original missing');
$id=(int)$target['id'];
$entry=(int)$target['date_added'];
$exit=(int)$target['field_5792'];
$duration=intdiv($exit,60)-intdiv($entry,60);
$newExit=date('Y-m-d H:i',$exit+3600);
$newEntry=date('Y-m-d H:i',$entry);
$fields=[];
foreach(db_query('SELECT * FROM app_fields WHERE entities_id=409 AND id BETWEEN 6606 AND 6611') as $field) $fields[(int)$field['id']]=$field;
function nativeProcess(int $fieldId,$value) {
    global $fields;
    $field=$fields[$fieldId];
    $processor=new $field['type'];
    return $processor->process(['field'=>$field,'value'=>$value,'is_new_item'=>true,'current_field_value'=>'']);
}
$savedEntry=nativeProcess(6608,$newEntry);
$savedExit=nativeProcess(6609,$newExit);
check(date('Y-m-d H:i',$savedEntry)===$newEntry,'Native entry processor');
check(date('Y-m-d H:i',$savedExit)===$newExit,'Native exit processor');
check(nativeProcess(6611,'1')==='true','Native active checkbox processor');
check(nativeProcess(6606,'1634')==1634,'Native dropdown processor');
$reason=nativeProcess(6610,'Teste isolado de persistência');
// A connection-scoped temporary table shadows only this connection's corrections table.
$create=$connection->query('SHOW CREATE TABLE app_entity_409')->fetch_row()[1];
db_query(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$create));
$stmt=$connection->prepare('INSERT INTO app_entity_409 (id,parent_item_id,created_by,date_added,field_6606,field_6607,field_6608,field_6609,field_6610,field_6611) VALUES(1,15,1,?,1634,?,?,?,?,"true")');
$now=time();$stmt->bind_param('iiiis',$now,$id,$savedEntry,$savedExit,$reason);$stmt->execute();
$saved=db_fetch_array(db_query('SELECT field_6608,field_6609,field_6610 FROM app_entity_409 WHERE id=1'));
check((int)$saved['field_6608']===$savedEntry && (int)$saved['field_6609']===$savedExit && $saved['field_6610']===$reason,'Native values persist and read back');
$updated=VDAttendance\render($filters);
check(reportMinutes($updated,2)===$workedBefore+60,'Replacement changes report by exactly 60 minutes');
check(strpos($updated,'Marcação corrigida (#1)')!==false,'Correction trace in daily row');
check(strpos($updated,'Editar correção ativa #1')!==false,'Existing correction edits itself instead of creating a conflicting duplicate');
$addEntry=nativeProcess(6608,'2026-06-24 08:00');
$addExit=nativeProcess(6609,'2026-06-24 12:00');
db_query("INSERT INTO app_entity_409 (id,parent_item_id,created_by,date_added,field_6606,field_6607,field_6608,field_6609,field_6610,field_6611) VALUES(2,15,1,$now,1633,'',$addEntry,$addExit,'Teste de adição','true')");
$updated=VDAttendance\render($filters);
check(reportMinutes($updated,2)===$workedBefore+300,'Addition and replacement combined exactly once');
check(strpos($updated,'Marcação acrescentada (#2)')!==false,'Addition trace in daily row');
db_query('UPDATE app_entity_409 SET field_6606=1635,field_6608=0,field_6609=0 WHERE id=1');
$updated=VDAttendance\render($filters);
check(reportMinutes($updated,2)===$workedBefore-$duration+240,'Cancellation removes original duration without mutating it');
check(strpos($updated,'Marcação anulada (#1)')!==false,'Cancellation trace');
db_query("UPDATE app_entity_409 SET field_6611='false' WHERE id=1");
$updated=VDAttendance\render($filters);
check(reportMinutes($updated,2)===$workedBefore+240,'Deactivating correction restores original');
db_query("UPDATE app_entity_409 SET field_6606=1634,field_6607=999999,field_6611='true' WHERE id=1");
$invalid=VDAttendance\render($filters);
check(strpos($invalid,'Correções por resolver:')!==false,'Invalid correction explicit error');
check(strpos($invalid,'<dt>Horas trabalhadas</dt><dd>—</dd>')!==false,'Invalid correction must not show false totals');
db_query('DROP TEMPORARY TABLE app_entity_409');
$after=db_fetch_array(db_query('SELECT COUNT(*) n FROM app_entity_409'));
check($after===$realCorrections,'No real correction records modified by tests');
$rawAfter=db_fetch_array(db_query("SELECT COUNT(*) n,SUM(CRC32(CONCAT_WS('|',id,created_by,date_added,field_5792))) checksum FROM app_entity_135"));
check($rawAfter===$rawBefore,'Original time-clock records unchanged');
$all=VDAttendance\render(['filter_by_date'=>'2026-06','filter_by_entity1'=>0]);
$employeeCount=(int)db_fetch_array(db_query('SELECT COUNT(*) n FROM app_entity_141'))['n'];
check(substr_count($all,'<section class="vd-attendance-sheet">')===$employeeCount,'All employee pages');
echo json_encode(['status'=>'PASS','assertions'=>$checks,'native_processor_persistence'=>true,'corrections_applied_end_to_end'=>true,'originals_unchanged'=>true,'production_corrections_unchanged'=>true,'all_employee_pages'=>$employeeCount])."\n";

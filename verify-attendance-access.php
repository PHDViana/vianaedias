<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$connection=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$connection->set_charset('utf8mb4');
function db_query($sql){global $connection;return $connection->query($sql);}
function db_fetch_array($query){return $query->fetch_assoc();}
$timezone=db_fetch_array(db_query("SELECT configuration_value FROM app_configuration WHERE configuration_name='CFG_APP_TIMEZONE'"));
define('CFG_APP_TIMEZONE',$timezone['configuration_value']);
date_default_timezone_set(CFG_APP_TIMEZONE);
require '_CustomDEVs/quote-workflow/vd_quote_workflow/attendance-report.php';
$checks=0;
function check($condition,string $message):void{
 global $checks;$checks++;
 if(!$condition)throw new RuntimeException($message);
}
$report=db_fetch_array(db_query('SELECT * FROM app_ext_report_page WHERE id=29'));
$settings=json_decode($report['settings'],true);
check($settings['filter_by_entity1']==='0','Native all-HR selector must be disabled');
check($report['users_groups']==='0,4','Only managerial groups allowed globally');
$menu=db_fetch_array(db_query('SELECT reports_list FROM app_entities_menu WHERE id=17'));
check(in_array('report_page29',explode(',',$menu['reports_list']),true),'Report absent from HR menu');
check((int)db_fetch_array(db_query("SELECT COUNT(*) n FROM app_entities_access WHERE entities_id=409 AND access_groups_id<>4 AND access_schema<>''"))['n']===0,'Non-manager corrections permissions found');
$employees=VDAttendance\rows('SELECT h.id,h.field_1900,u.field_6 FROM app_entity_141 h JOIN app_entity_1 u ON u.id=h.field_1900 ORDER BY h.id');
$assigned=explode(',',$report['assigned_to']);
$employeeCount=(int)db_fetch_array(db_query('SELECT COUNT(*) n FROM app_entity_141'))['n'];
$tested=0;
foreach($employees as $employee){
 $app_user=['id'=>(int)$employee['field_1900'],'group_id'=>(int)$employee['field_6']];
 check(in_array((string)$app_user['id'],$assigned,true),'Linked account not assigned native report access');
 if(VDAttendance\managesAttendance($app_user))continue;
 $tested++;
 $own=VDAttendance\render(['filter_by_date'=>'2026-10','filter_by_entity1'=>0]);
 check(substr_count($own,'<section class="vd-attendance-sheet">')===1,'Employee sees multiple/zero pages');
 check(strpos($own,'O meu resumo de ponto')!==false,'Missing read-only employee heading');
 check(strpos($own,'items/form')===false,'Employee receives write/form URL');
 check(strpos($own,'/409')===false,'Employee receives correction management URL');
 check(strpos($own,'vd-attendance-tools')===false,'Employee receives write controls');
 check(strpos($own,'vd-attendance-records')===false,'Employee receives original-edit details');
 check(strpos($own,'name="filter_by_entity')===false,'Employee receives colleague selector');
 foreach([999999,(int)$employees[0]['id'],(int)$employees[count($employees)-1]['id'],['15']] as $attack){
  $attempt=VDAttendance\render(['filter_by_date'=>'2026-10','filter_by_entity1'=>$attack]);
  check($attempt===$own,'Untrusted employee filter changes scoped content');
 }
 check(VDAttendance\employeeScope($app_user)['employee']===(int)$employee['id'],'Scope not bound to logged-in HR');
}
foreach([0,4] as $group){
 $app_user=['id'=>1,'group_id'=>$group];
 $all=VDAttendance\render(['filter_by_date'=>'2026-10','filter_by_entity1'=>0]);
 check(substr_count($all,'<section class="vd-attendance-sheet">')===$employeeCount,'Manager cannot see all');
 check(strpos($all,'id="vd-attendance-employee"')!==false,'Manager selector missing');
 check(strpos($all,'items/form')!==false,'Manager edit controls missing');
 $single=VDAttendance\render(['filter_by_date'=>'2026-10','filter_by_entity1'=>$employees[0]['id']]);
 check(substr_count($single,'<section class="vd-attendance-sheet">')===1,'Manager employee filter failed');
}
$app_user=['id'=>0,'group_id'=>0];
check(strpos(VDAttendance\render([]),'vd-attendance-sheet')===false,'Unauthenticated admin-shaped user gets data');
$app_user=['id'=>2147483647,'group_id'=>12];
check(strpos(VDAttendance\render([]),'vd-attendance-sheet')===false,'Unmapped user gets data');
db_query('CREATE TEMPORARY TABLE app_entity_141 (id INT NOT NULL,field_1900 INT NOT NULL)');
$app_user=['id'=>123456,'group_id'=>12];
db_query("INSERT INTO app_entity_141 (id,field_1900) SELECT 900001,123456");
db_query("INSERT INTO app_entity_141 (id,field_1900) SELECT 900002,123456");
check(strpos(VDAttendance\render([]),'de forma única')!==false,'Ambiguous HR mapping not denied');
db_query('DROP TEMPORARY TABLE app_entity_141');
echo json_encode(['status'=>'PASS','assertions'=>$checks,'read_only_hr_accounts_tested'=>$tested,'manager_roles_tested'=>2,'tampered_filters_isolated'=>true,'menu_link'=>true,'native_corrections_denied_to_non_managers'=>true])."\n";

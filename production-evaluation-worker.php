<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
require_once __DIR__.'/production-evaluation.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$failed=false;
foreach($db->query('SELECT order_id FROM app_vd_variance_queue ORDER BY queued_at,order_id LIMIT 20')->fetch_all(MYSQLI_ASSOC) as $row) {
    $id=(int)$row['order_id'];
    try{vd_evaluation_process($db,$id);echo "Evaluated OP $id\n";}
    catch(Throwable $error){
        $message=$error->getMessage();$s=$db->prepare('UPDATE app_vd_variance_queue SET error=? WHERE order_id=?');$s->bind_param('si',$message,$id);$s->execute();
        error_log('VD production evaluation OP '.$id.': '.$message);$failed=true;
    }
}
exit($failed?1:0);

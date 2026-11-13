<?php
$base=__DIR__;
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
$old=$db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=364')->fetch_row()[0];
$settings=json_decode($old,true,512,JSON_THROW_ON_ERROR);
$source=preg_replace('/^<\?php\s*/','',file_get_contents($base.'/costing-configurator.php'));
token_get_all('<?php '.$source,TOKEN_PARSE);
if ($settings['php_code']!==$source && hash('sha256',$old)!=='f81ab2e92a3c8bb0fc5af47dc5c1c0f89bc22c208ae9cea36d2d27e71ca4a034') {
    throw new RuntimeException('Costing report changed concurrently.');
}
$start=strpos($source,'$simulationQueries = [');
$end=strpos($source,'];',$start);
if ($start===false||$end===false) throw new RuntimeException('Missing costing queries.');
$componentId=30;
eval(substr($source,$start,$end+2-$start));
foreach($simulationQueries as $name=>$sql) {
    if (!preg_match('/^SELECT\b/i',$sql)) throw new RuntimeException('Non-read-only costing query.');
    $db->query('EXPLAIN '.$sql);
}
$settings['php_code']=$source;
$new=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$db->begin_transaction();
try {
    $current=$db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=364 FOR UPDATE')->fetch_row()[0];
    if ($old!==$current) throw new RuntimeException('Costing report changed during deployment.');
    $statement=$db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=364');
    $statement->bind_param('s',$new);$statement->execute();
    $db->commit();
    echo "Product-aware costing report published; all SELECT queries verified.\n";
} catch(Throwable $error) {$db->rollback();throw $error;}

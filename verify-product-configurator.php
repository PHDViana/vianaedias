<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
require __DIR__.'/product-configurator.php';
$schema=vd_config_schema($db);
$report=$db->query('SELECT entities_id,is_active FROM app_ext_report_page WHERE id='.$schema['config.report'])->fetch_assoc();
if((int)$report['entities_id']!==0||(int)$report['is_active']!==1)throw new RuntimeException('Configurator is not an active standalone report.');
$block=json_decode($db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id='.$schema['config.block'])->fetch_row()[0],true,512,JSON_THROW_ON_ERROR);
if(($block['php_code']??'')!=="require_once 'includes/vd_quote_workflow/product-configurator-view.php'; vd_product_configurator_report('config', 0);")throw new RuntimeException('Report still requires an entity item.');
$menu=$db->query('SELECT * FROM app_entities_menu WHERE id='.$schema['config.menu'])->fetch_assoc();
if(!in_array($menu['type'],['url','url_self'],true)||$menu['url']!=='index.php?module=report_page/view&id='.$schema['config.report']||$menu['entities_list']!==(string)$schema['config.entity'])throw new RuntimeException('Direct report menu mismatch.');
$manifest=json_decode(file_get_contents(__DIR__.'/workflow-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($manifest['files'] as $target=>$entry)if(hash_file('sha256',$target)!==$entry['hash'])throw new RuntimeException('Runtime file mismatch: '.$target);
$view=file_get_contents('includes/vd_quote_workflow/product-configurator-view.php');
if(strpos($view,'src="js/vd-product-configurator-print.js"')!==false)throw new RuntimeException('Standalone collection report still loads a simulator.');
$action=file_get_contents('modules/items/actions/vd_product_configurator.php');
foreach(["case 'simulation':","case 'select':","case 'nonprint':","case 'generate':"] as $forbidden)if(strpos($action,$forbidden)!==false)throw new RuntimeException('Collection report exposes costing/quote generation.');
foreach(['config.technical','config.revision','part.entity','quantity.entity','finish.entity','document.entity','submission.entity','piece.entity','quote.snapshot'] as $role)
    if(empty($schema[$role]))throw new RuntimeException('Native configurator field/entity missing: '.$role);
foreach(['product-configurator.php','product-configurator-native.php','production-evaluation.php','quote-workflow-store.php'] as $file) {
    $runtime=file_get_contents('includes/vd_quote_workflow/'.$file);
    if(preg_match('/app_vd_product_(drafts|attachments|submissions|quote_sources)/',$runtime))throw new RuntimeException('Runtime still uses retired configurator tables: '.$file);
}
echo "Verified standalone report #".$schema['config.report'].", direct menu #".$schema['config.menu'].", support entity #".$schema['config.entity']." and managed runtime hashes.\n";
echo "Native entity/subentity storage verified; retired configurator tables are not used by runtime.\n";
echo "No simulation, price or automatic quotation-generation actions in the collection interface.\n";

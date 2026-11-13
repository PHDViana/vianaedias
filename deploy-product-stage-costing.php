<?php
if(PHP_SAPI!=='cli')exit(1);
chdir('/home/vianaedias/public_html');
require 'config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);$db->set_charset('utf8mb4');
$anchor="require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-workflow-discovery.php';";
$addition=<<<'PHP'

require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-product.php';
$vdStageDb=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$vdStageDb->set_charset('utf8mb4');
vd_product_stage_simulation($vdStageDb,$simulation,$componentId);
$vdStageDb->close();
PHP;
require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-product.php';
if(!function_exists('vd_product_stage_simulation'))throw new RuntimeException('Publique primeiro o runtime das etapas.');
$db->begin_transaction();
try {
    $old=$db->query('SELECT settings FROM app_ext_report_page_blocks WHERE id=364 FOR UPDATE')->fetch_row()[0];
    $settings=json_decode($old,true,512,JSON_THROW_ON_ERROR);
    $source=$settings['php_code'];
    if(strpos($source,$anchor.$addition)===false) {
        if(substr_count($source,$anchor)!==1||strpos($source,'vd_product_stage_simulation(')!==false)throw new RuntimeException('Integração de etapas alterada; reveja o bloco 364.');
        $settings['php_code']=str_replace($anchor,$anchor.$addition,$source);
    }
    token_get_all('<?php '.$settings['php_code'],TOKEN_PARSE);
    $new=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(strlen($new)>65535)throw new RuntimeException('Bloco de orçamentação excede a capacidade nativa.');
    $stmt=$db->prepare('UPDATE app_ext_report_page_blocks SET settings=? WHERE id=364 AND settings=?');
    $stmt->bind_param('ss',$new,$old);$stmt->execute();
    $db->commit();
    echo "Stage-aware native costing published; unrelated report settings preserved.\n";
}catch(Throwable $error){$db->rollback();throw $error;}

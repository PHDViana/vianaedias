<?php
require_once __DIR__.'/product-configurator.php';
function vd_product_configurator_report($role,$id) {
    global $app_user,$app_session_token;
    $db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
    $db->set_charset('utf8mb4');
    try {
        $service=new VDProductConfigurator($db,$app_user);
        if($role==='evaluation') {
            require_once __DIR__.'/production-evaluation.php';
            echo vd_evaluation_html($db,$app_user,$id);
            return;
        }
        $service->access();
        $data=['catalog'=>$service->catalog(),'schema'=>$service->schema,
            'token'=>$app_session_token,'url'=>url_for('items/vd_product_configurator','path='.$service->schema['config.entity'])];
        echo '<link rel="stylesheet" href="js/vd-product-request.css?v='.hash_file('sha256','js/vd-product-request.css').'">';
        echo '<div id="vd-product-configurator"></div><script type="application/json" id="vd-product-configurator-data">'.
            json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
        echo '<script src="js/vd-product-configurator.js?v='.hash_file('sha256','js/vd-product-configurator.js').'"></script>';
    }catch(Throwable $error) {
        error_log('VD product configurator report: '.$error->getMessage());
        echo '<div class="alert alert-danger">Não foi possível carregar o configurador. Verifique o acesso ou consulte o registo técnico.</div>';
    }finally{$db->close();}
}

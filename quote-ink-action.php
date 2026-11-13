<?php
require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-workflow-store.php';
require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-ink-store.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$vdDb=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$vdDb->set_charset('utf8mb4');
$vdWorkflow=new VDQuoteWorkflow($vdDb,$app_user);
$vdInk=new VDQuoteInk($vdDb,$vdWorkflow,$app_user['id']);
try {
    if (!in_array((int)$current_entity_id,[146,262],true)) throw new DomainException('Abra a análise no orçamento ou num componente.');
    $vdQuote=(int)$current_entity_id===146 ? (int)$current_item_id : (int)$vdWorkflow->access(262,(int)$current_item_id)['parent_item_id'];
    $vdWorkflow->access(146,$vdQuote);
    $vdComponents=$vdWorkflow->rows('SELECT id,field_4225 name FROM app_entity_262 WHERE parent_item_id=? ORDER BY id',[$vdQuote]);
    foreach($vdComponents as $vdComponent) $vdWorkflow->access(262,$vdComponent['id']);
    $vdComponentId=(int)($_GET['component']??$_POST['component']??((int)$current_entity_id===262?$current_item_id:($vdComponents[0]['id']??0)));
    if (isset($_GET['pdf'])) {
        $vdPdf=$vdInk->pdf($vdQuote,$vdComponentId,(int)$_GET['pdf']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="trabalho.pdf"; filename*=UTF-8\'\''.rawurlencode($vdPdf['name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $vdPdf['content']; exit;
    }
    if (isset($_GET['api'])||isset($_POST['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['api']??'')==='state') {
            $vdResult=$vdInk->state($vdQuote,$vdComponentId);
        } else {
            if ($_SERVER['REQUEST_METHOD']!=='POST' || !isset($_POST['form_session_token']) ||
                !hash_equals((string)$app_session_token,(string)$_POST['form_session_token'])) {
                http_response_code(403); throw new DomainException('Token de sessão inválido.');
            }
            $vdInput=json_decode($_POST['payload']??'{}',true,512,JSON_THROW_ON_ERROR);
            switch($_POST['api']??'') {
                case 'upload':
                    $vdUpload=$_FILES['ink_pdf']??null;
                    if (!$vdUpload || $vdUpload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($vdUpload['tmp_name'])) throw new DomainException('Falha ao carregar o PDF. Limite de 5 MB.');
                    if (filesize($vdUpload['tmp_name'])>5*1024*1024) throw new DomainException('O PDF deve ter até 5 MB.');
                    $vdBytes=file_get_contents($vdUpload['tmp_name']);
                    if ($vdBytes===false) throw new RuntimeException('Falha ao ler o PDF.');
                    $vdResult=$vdInk->upload($vdQuote,$vdComponentId,$vdUpload['name'],$vdBytes);
                    break;
                case 'estimate':
                    if (($vdInput['mapping_confirmed']??false)!==true) throw new DomainException('Confirme a ordem das páginas e a correspondência com as rubricas.');
                    $vdResult=$vdInk->estimate($vdQuote,$vdComponentId,(int)($vdInput['pdf_id']??0),(int)($vdInput['version_id']??0),$vdInput['parameters']??[]);
                    break;
                default: throw new DomainException('Ação de tinta desconhecida.');
            }
        }
        echo json_encode(['ok'=>true,'data'=>$vdResult],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        exit;
    }
    $vdInkState=$vdComponentId ? $vdInk->state($vdQuote,$vdComponentId) : null;
    include '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-ink-view.php';
} catch (Throwable $vdError) {
    if (!($vdError instanceof DomainException)) error_log('VD ink: '.get_class($vdError).': '.$vdError->getMessage());
    if (http_response_code()===200) http_response_code($vdError instanceof DomainException?409:500);
    $vdMessage=$vdError instanceof DomainException?$vdError->getMessage():'Falha interna na análise de tinta. Consulte o registo técnico.';
    if (isset($_GET['api'])||isset($_POST['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$vdMessage],JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8'); echo $vdMessage;
    }
}
exit;

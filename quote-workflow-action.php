<?php
require_once 'includes/vd_quote_workflow/quote-workflow-store.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$vdDb = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$vdDb->set_charset('utf8mb4');
$vdWorkflow = new VDQuoteWorkflow($vdDb, $app_user);
$vdQuote = $current_entity_id === 146 ? (int) $current_item_id :
    (int) $vdWorkflow->access(262, (int) $current_item_id)['parent_item_id'];
if (isset($_GET['api']) || isset($_POST['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            switch ($_GET['api']) {
                case 'state': $vdResult = $vdWorkflow->state($vdQuote); break;
                case 'product_state': $vdResult = $vdWorkflow->productState($vdQuote); break;
                case 'snapshot': $vdResult = $vdWorkflow->snapshot($vdQuote, _get::int('version')); break;
                default: throw new DomainException('Leitura desconhecida.');
            }
        } else {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['form_session_token']) ||
                !hash_equals((string) $app_session_token, (string) $_POST['form_session_token'])) {
                http_response_code(403);
                throw new DomainException('Token de sessão inválido.');
            }
            $vdInput = json_decode($_POST['payload'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            switch ($_POST['api']) {
                case 'product_configure': $vdResult=$vdWorkflow->configureProduct($vdQuote,$vdInput); break;
                case 'product_add': $vdResult=$vdWorkflow->addProductComponent($vdQuote,$vdInput); break;
                case 'assembly_suggest': $vdResult=$vdWorkflow->assemblySuggestion($vdQuote,(int)($vdInput['component']??0),$vdInput); break;
                case 'nonprint_preview':
                case 'nonprint_save':
                    $vdComponent=(int)($vdInput['component']??0);
                    $vdCalculation=$vdWorkflow->nonprintCalculation($vdQuote,$vdComponent,$vdInput['definition']??[]);
                    $vdResult=$_POST['api']==='nonprint_preview' ? $vdCalculation : $vdWorkflow->save($vdQuote,$vdComponent,[
                        'calculation'=>$vdCalculation,'quantity_id'=>$vdInput['quantity_id']??'',
                        'request_id'=>$vdInput['request_id']??'','expected_version'=>$vdInput['expected_version']??0]);
                    break;
                case 'save': $vdResult = $vdWorkflow->save($vdQuote, (int) $current_item_id, $vdInput); break;
                case 'confirm': $vdResult = $vdWorkflow->confirm($vdQuote, (int) $vdInput['version']); break;
                case 'reopen': $vdResult = $vdWorkflow->reopen($vdQuote, (int) $vdInput['version']); break;
                case 'approve': $vdResult = $vdWorkflow->approve($vdQuote, $vdInput['versions'] ?? []); break;
                case 'send': $vdResult = $vdWorkflow->send($vdQuote, (int) $vdInput['document'], $vdInput); break;
                case 'restore_adjudication': $vdResult = $vdWorkflow->restoreAdjudication($vdQuote); break;
                case 'create_production_order': $vdResult = $vdWorkflow->createProductionOrder($vdQuote); break;
                case 'set_delivery_date': $vdResult = $vdWorkflow->setProductionDeliveryDate($vdQuote, $vdInput['delivery_date'] ?? null); break;
                case 'accept':
                    $vdAttachment = null;
                    if (isset($_FILES['approval_file']) && $_FILES['approval_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                        $vdUpload = $_FILES['approval_file'];
                        if (!is_int($vdUpload['error']) || $vdUpload['error'] !== UPLOAD_ERR_OK ||
                            !is_string($vdUpload['tmp_name']) || !is_uploaded_file($vdUpload['tmp_name'])) {
                            throw new DomainException('Falha no carregamento do documento de aprovação. Volte a selecionar o ficheiro (máximo 5 MB).');
                        }
                        if (filesize($vdUpload['tmp_name']) > 5 * 1024 * 1024) throw new DomainException('O anexo deve ter até 5 MB.');
                        $vdContent = file_get_contents($vdUpload['tmp_name']);
                        if ($vdContent === false) throw new RuntimeException('Falha ao ler o documento de aprovação.');
                        $vdAttachment = ['name' => $vdUpload['name'], 'content' => $vdContent];
                    }
                    $vdResult = $vdWorkflow->accept($vdQuote, (int) $vdInput['document'], $vdInput['quantity'], $vdInput['evidence'] ?? '', $vdAttachment, $vdInput['delivery_date'] ?? null);
                    break;
                case 'groups': $vdResult = $vdWorkflow->configureGroups($vdQuote, $vdInput['groups'] ?? []); break;
                default: throw new DomainException('Ação desconhecida.');
            }
        }
        echo json_encode(['ok' => true, 'data' => $vdResult], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (DomainException $vdError) {
        if (http_response_code() === 200) http_response_code(409);
        echo json_encode(['ok' => false, 'error' => $vdError->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $vdError) {
        error_log('VD quote workflow: ' . get_class($vdError) . ': ' . $vdError->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Falha interna; nenhuma confirmação de sucesso. Consulte o registo técnico.']);
    }
    exit;
}
if (($_GET['product']??'')==='1') {
    try {
        $vdProductState=$vdWorkflow->productState($vdQuote);
        $vdProductState['token']=$app_session_token;
        $vdProductState['url']=url_for('items/vd_quote_workflow','path='.urlencode($current_path));
        echo '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Produto, componentes e etapas</h4></div>';
        echo '<div class="modal-body" style="max-height:80vh;overflow:auto"><div id="vd-product"></div><script type="application/json" id="vd-product-data">' .
            json_encode($vdProductState,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) .
            '</script><script src="js/vd-quote-product.js?v='.hash_file('sha256','js/vd-quote-product.js').'"></script></div>';
    } catch (Throwable $vdError) {
        error_log('VD product view: '.$vdError->getMessage());
        http_response_code(403);
        echo '<div class="alert alert-danger">Não foi possível consultar a estrutura do produto.</div>';
    }
    exit;
}
if (isset($_GET['approval_file'])) {
    try {
        $vdFile = $vdWorkflow->approvalFile($vdQuote, _get::int('approval_file'));
        header('Content-Type: ' . $vdFile['mime']);
        header("Content-Disposition: attachment; filename=\"approval-" . _get::int('approval_file') .
            '.' . pathinfo($vdFile['name'], PATHINFO_EXTENSION) . "\"; filename*=UTF-8''" . rawurlencode($vdFile['name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $vdFile['content'];
    } catch (DomainException $vdError) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $vdError->getMessage();
    } catch (Throwable $vdError) {
        error_log('VD approval file: ' . get_class($vdError) . ': ' . $vdError->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Não foi possível consultar o documento de aprovação.';
    }
    exit;
}
if (isset($_GET['pdf'])) {
    try {
        $vdDocument = $vdWorkflow->document($vdQuote, isset($_GET['document']) ? _get::int('document') : 0);
        require_once 'includes/vd_quote_workflow/quote-workflow-pdf.php';
        $vdPdf = vd_workflow_pdf($vdDocument);
        $vdFilename = vd_workflow_pdf_filename($vdDocument);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $vdFilename . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $vdPdf;
    } catch (DomainException $vdError) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        echo $vdError->getMessage();
    } catch (Throwable $vdError) {
        error_log('VD quote PDF: ' . get_class($vdError) . ': ' . $vdError->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Não foi possível gerar o PDF. Consulte o registo técnico.';
    }
    exit;
}
if (isset($_GET['document'])) {
    try {
        $vdDocument = $vdWorkflow->document($vdQuote, (int) $_GET['document']);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
        echo $vdDocument['html'];
    } catch (Throwable $vdError) {
        http_response_code(403);
        echo 'Documento indisponível ou sem acesso.';
    }
    exit;
}
$vdState = $vdWorkflow->state($vdQuote);
if (($_GET['modal'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'self'");
    echo '<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>';
    echo '<h4 class="modal-title">' . (($_GET['accept_only'] ?? '') === '1' ? 'Adjudicar orçamento' :
        (($_GET['send_only'] ?? '') === '1' ? 'Enviar proposta ao cliente' : 'Versões, confirmação, aprovação e proposta')) . '</h4></div>';
    echo '<div class="modal-body" style="max-height:calc(100vh - 120px);overflow-y:auto">';
    echo '<style>#vd-quote-workflow{color:#333;font:14px/1.45 Arial,sans-serif}#vd-quote-workflow h1{display:none}';
    echo '#vd-quote-workflow h2{font-size:20px;margin:20px 0 10px}#vd-quote-workflow h3{font-size:17px}';
    echo '#vd-quote-workflow p{margin:0 0 10px}#vd-quote-workflow table{width:100%;border-collapse:collapse;margin:10px 0 16px}';
    echo '#vd-quote-workflow th,#vd-quote-workflow td{padding:7px;border:1px solid #ddd;text-align:left;vertical-align:top}';
    echo '#vd-quote-workflow th{background:#f5f5f5}#vd-quote-workflow button{padding:6px 12px;border:1px solid #ccc;border-radius:4px;background:#fff;color:#333;cursor:pointer}';
    echo '#vd-quote-workflow button:disabled{opacity:.6}#vd-quote-workflow input{max-width:100%;padding:6px;border:1px solid #ccc;border-radius:4px}';
    echo '#vd-quote-workflow a{color:#337ab7}#vd-quote-workflow #vd-workflow-message{margin:8px 0}</style>';
    $vdView = 'modules/items/views/vd_quote_workflow.php';
    if (!is_file($vdView)) throw new RuntimeException('Workflow modal view is unavailable.');
    require $vdView;
    echo '</div>';
    exit;
}

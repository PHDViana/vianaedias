<?php
require_once 'includes/vd_quote_workflow/product-configurator.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$db=new mysqli(DB_SERVER,DB_SERVER_USERNAME,DB_SERVER_PASSWORD,DB_DATABASE,(int)DB_SERVER_PORT);
$db->set_charset('utf8mb4');
try {
    $service=new VDProductConfigurator($db,$app_user);
    $service->access();
    $id=(int)($_GET['config_id']??0);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        switch($_GET['api']??'state') {
            case 'state':$result=$service->state($id);break;
            case 'recent':$result=$service->recent();break;
            case 'customers':$result=$service->customers($_GET['term']??'');break;
            case 'internal_customer':$result=$service->internalCustomer();break;
            case 'contacts':$result=$service->contacts((int)($_GET['customer_id']??0),$_GET['term']??'');break;
            case 'quote_preparation':
                require_once 'includes/vd_quote_workflow/product-quote-preparation.php';
                vd_config_quote_preparation_access($service,$id);
                $result=vd_config_quote_preparation_state($service,$id);break;
            case 'download':
                $file=$service->document($id,(int)($_GET['attachment_id']??0));
                header('Content-Type: application/octet-stream');
                header('X-Content-Type-Options: nosniff');
                header("Content-Disposition: attachment; filename=\"documento\"; filename*=UTF-8''".rawurlencode($file['name']));
                header('Content-Length: '.strlen($file['content']));
                echo $file['content'];$db->close();exit;
            default:throw new DomainException('Leitura desconhecida.');
        }
    }else{
        if(!empty($service->schema['config.storage_locked']))throw new DomainException('Configurador em manutenção para migração dos dados. Aguarde e tente novamente; o formulário não foi gravado.');
        if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['form_session_token'])||!hash_equals((string)$app_session_token,(string)$_POST['form_session_token'])) {
            http_response_code(403);throw new DomainException('Token de sessão inválido.');
        }
        $input=json_decode($_POST['payload']??'{}',true,512,JSON_THROW_ON_ERROR);
        $id=(int)($input['config_id']??0);
        switch($_POST['api']??'') {
            case 'extract_request':
                require_once DIR_FS_CATALOG . '_CustomDEVs/product-request-import/product-request-import.php';
                $sourceText=$input['source_text']??'';
                if(!is_string($sourceText))throw new DomainException('Texto de importação inválido.');
                $result=vd_request_import_extract($sourceText,vd_config_uploads($_FILES['documents']??[]));
                $suggestions=vd_request_import_suggestions($result['text'],$service->catalog()['product_types']);
                $result['suggestions']=$suggestions['suggestions'];
                $result['warnings']=array_values(array_unique(array_merge($result['warnings'],$suggestions['warnings'])));
                break;
            case 'submit':$result=$service->submit($id,$input['revision']??0,$input['identity']??[],$input['payload']??[],$input['request_id']??'',vd_config_uploads($_FILES['documents']??[]),$input['removed_attachments']??[]);break;
            case 'template':$result=$service->template($id,(int)($input['template']??0));break;
            case 'finishing_review':$result=$service->finishingReview($input['payload']??[],$id);break;
            case 'quote_preparation':
            case 'create_quote':
                require_once 'includes/vd_quote_workflow/product-quote-preparation.php';
                vd_config_quote_preparation_access($service,$id);
                if(!isset($input['revision'])||!is_int($input['revision'])||!is_array($input['answers']??null))throw new DomainException('Preparação inválida. Atualize o formulário.');
                if($_POST['api']==='quote_preparation')$result=vd_config_quote_preparation_state($service,$id,$input['answers'],$input['revision']);
                else {
                    require_once 'includes/vd_quote_workflow/product-request-conversion.php';
                    $result=vd_config_request_to_draft($db,$app_user,$id,false,$input['answers'],$input['revision']);
                    $result['url']=url_for('items/info','path=146-'.(int)$result['quote']);
                }
                break;
            default:throw new DomainException('Ação desconhecida.');
        }
    }
    echo json_encode(['ok'=>true,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(DomainException $error){
    if(http_response_code()===200)http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    error_log('VD product configurator API: '.get_class($error).': '.$error->getMessage());http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Falha interna. Nenhuma confirmação de sucesso; consulte o registo técnico.']);
}finally{$db->close();}
exit;

<?php
require_once __DIR__ . '/quote-ink.php';

final class VDQuoteInk
{
    private $db;
    private $workflow;
    private $actor;
    public function __construct(mysqli $db, VDQuoteWorkflow $workflow, $actor)
    {
        $this->db = $db;
        $this->workflow = $workflow;
        $this->actor = (int) $actor;
    }
    private function component($quote, $component, $action = 'view')
    {
        $this->workflow->access(146, $quote, $action);
        $row = $this->workflow->access(262, $component, $action);
        if ((int) $row['parent_item_id'] !== (int) $quote) throw new DomainException('Componente de outro orçamento.');
        return $row;
    }
    public function state($quote, $component)
    {
        $this->component($quote, $component);
        $versions = $this->workflow->rows('SELECT id,version_no,quantity,hash,state FROM app_vd_quote_versions WHERE quote_id=? AND component_id=? ORDER BY id DESC', [$quote,$component]);
        foreach ($versions as &$version) {
            $snapshot = $this->workflow->snapshot($quote,$version['id']);
            $version['rubric_order'] = array_map(static function($rubric) { return $rubric['name'] ?? '(sem descrição)'; }, $snapshot['calculation']['rubrics'] ?? []);
            $version['modes'] = array_values(array_unique(array_map(static function($part) {
                return (int)($part['modeId'] ?? $part['plan']['suggestion']['modeId'] ?? 0);
            }, $snapshot['calculation']['parts'] ?? [])));
        }
        unset($version);
        $pdfs = $this->workflow->rows('SELECT id,name,hash,result,created_at,created_by FROM app_vd_quote_ink_pdfs WHERE quote_id=? AND component_id=? ORDER BY id DESC LIMIT 20', [$quote,$component]);
        foreach ($pdfs as &$pdf) $pdf['result'] = json_decode($pdf['result'], true, 512, JSON_THROW_ON_ERROR);
        unset($pdf);
        $estimates = $this->workflow->rows('SELECT id,pdf_id,version_id,version_hash,pdf_hash,result,created_at,created_by FROM app_vd_quote_ink_estimates WHERE quote_id=? AND component_id=? ORDER BY id DESC LIMIT 20', [$quote,$component]);
        foreach ($estimates as &$estimate) $estimate['result'] = json_decode($estimate['result'], true, 512, JSON_THROW_ON_ERROR);
        return ['component_id'=>(int)$component,'versions'=>$versions,'pdfs'=>$pdfs,'estimates'=>$estimates];
    }
    public function pdf($quote, $component, $id)
    {
        $this->component($quote,$component);
        $row = $this->workflow->rows('SELECT * FROM app_vd_quote_ink_pdfs WHERE id=? AND quote_id=? AND component_id=?', [$id,$quote,$component]);
        if (!$row) throw new DomainException('PDF inexistente ou sem acesso.');
        if (!hash_equals($row[0]['hash'],hash('sha256',$row[0]['content']))) throw new RuntimeException('Integridade do PDF inválida.');
        return $row[0];
    }
    public function upload($quote, $component, $name, $content)
    {
        $this->component($quote,$component,'update');
        if ((int)$this->workflow->rows("SELECT GET_LOCK('vd_quote_ink_worker',0) acquired")[0]['acquired'] !== 1) {
            throw new DomainException('Está em curso outra análise PDF. Aguarde e tente novamente.');
        }
        try { $analysis = vd_ink_render($content); }
        finally { $this->workflow->rows("SELECT RELEASE_LOCK('vd_quote_ink_worker')"); }
        $name = basename(str_replace('\\','/', (string)$name));
        if ($name === '' || strlen($name)>240) throw new DomainException('Nome do PDF inválido.');
        $hash = hash('sha256',$content);
        $json = json_encode($analysis,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $time = time();
        $this->db->begin_transaction();
        try {
            $stmt=$this->db->prepare('INSERT INTO app_vd_quote_ink_pdfs(quote_id,component_id,name,content,hash,result,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iissssii',$quote,$component,$name,$content,$hash,$json,$this->actor,$time);
            $stmt->execute();
            $id=(int)$this->db->insert_id;
            $this->audit($quote,'ink_pdf_analyzed',['pdf'=>$id,'component'=>$component,'hash'=>$hash,'pages'=>count($analysis['pages']),'spots'=>$analysis['spots']]);
            $this->db->commit();
            return ['pdf_id'=>$id,'message'=>'PDF analisado e guardado. Confirme a versão, a ordem das páginas e os parâmetros para estimar os gramas.'];
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
    public function estimate($quote, $component, $pdfId, $versionId, array $parameters)
    {
        $this->component($quote,$component,'update');
        $pdf=$this->pdf($quote,$component,$pdfId);
        $version=$this->workflow->snapshot($quote,$versionId);
        if ((int)$version['component_id'] !== (int)$component) throw new DomainException('Versão de outro componente.');
        $metadata=$this->workflow->rows('SELECT hash FROM app_vd_quote_versions WHERE id=? AND quote_id=?',[$versionId,$quote])[0];
        $analysis=json_decode($pdf['result'],true,512,JSON_THROW_ON_ERROR);
        $result=vd_ink_estimate($analysis,$version['calculation'],$parameters);
        $result['engine']=$analysis['engine'];
        $result['pdf_name']=$pdf['name'];
        $result['quantity']=$version['quantity'];
        $json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $time=time();
        $this->db->begin_transaction();
        try {
            $stmt=$this->db->prepare('INSERT INTO app_vd_quote_ink_estimates(quote_id,component_id,pdf_id,version_id,version_hash,pdf_hash,result,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iiiisssii',$quote,$component,$pdfId,$versionId,$metadata['hash'],$pdf['hash'],$json,$this->actor,$time);
            $stmt->execute();
            $id=(int)$this->db->insert_id;
            $this->audit($quote,'ink_estimated',['estimate'=>$id,'pdf'=>$pdfId,'component'=>$component,'version'=>$versionId,'version_hash'=>$metadata['hash'],'pdf_hash'=>$pdf['hash']]);
            $this->db->commit();
            return ['estimate_id'=>$id,'message'=>'Estimativa guardada no histórico. Não altera preços, stock ou versões aprovadas.'];
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
    private function audit($quote,$event,array $details)
    {
        $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $time=time();
        $stmt=$this->db->prepare('INSERT INTO app_vd_quote_audit(quote_id,actor_id,event,detail,created_at) VALUES(?,?,?,?,?)');
        $stmt->bind_param('iissi',$quote,$this->actor,$event,$json,$time);
        $stmt->execute();
    }
}

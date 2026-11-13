<?php
function vd_workflow_sync_approval_attachment(mysqli $db, $documentId, ?array &$createdFiles = null)
{
    $stmt = $db->prepare('SELECT * FROM app_vd_quote_approval_files WHERE document_id=? FOR UPDATE');
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    if (!$file) throw new RuntimeException('Documento de aprovação inexistente.');
    if (!hash_equals($file['hash'], hash('sha256', $file['content']))) throw new RuntimeException('Anexo de aprovação com integridade inválida.');
    $field = $db->query("SELECT type FROM app_fields WHERE id=3929 AND entities_id=146")->fetch_assoc();
    if (!$field || $field['type'] !== 'fieldtype_attachments') throw new RuntimeException('Campo Anexos do orçamento indisponível.');
    $name = $file['native_name'] ?: $file['uploaded_at'] . '_Adjudicacao_' . $documentId . '_' . substr($file['hash'], 0, 12) . '_' .
        str_replace([' ', ','], '_', $file['name']);
    $folder = DIR_WS_ATTACHMENTS . date('Y/m/d', (int) $file['uploaded_at']);
    if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException('Não foi possível criar a pasta do anexo.');
    }
    $path = $folder . '/' . sha1($name);
    if (!is_file($path)) {
        $handle = fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('Não foi possível criar o anexo nativo.');
        try {
            $written = fwrite($handle, $file['content']);
        } finally {
            fclose($handle);
        }
        if ($written !== strlen($file['content'])) {
            if (!unlink($path)) error_log('VD approval attachment: failed to remove incomplete file.');
            throw new RuntimeException('Gravação incompleta do anexo.');
        }
        if ($createdFiles !== null) $createdFiles[] = $path;
    }
    if (!hash_equals($file['hash'], hash_file('sha256', $path))) throw new RuntimeException('O anexo nativo não coincide com a aprovação.');
    $stmt = $db->prepare("UPDATE app_entity_146 SET field_3929=CONCAT_WS(',',NULLIF(field_3929,''),?) WHERE id=? AND FIND_IN_SET(?,COALESCE(field_3929,''))=0");
    $stmt->bind_param('sis', $name, $file['quote_id'], $name);
    $stmt->execute();
    $stmt = $db->prepare('UPDATE app_vd_quote_approval_files SET native_name=? WHERE document_id=?');
    $stmt->bind_param('si', $name, $documentId);
    $stmt->execute();
    return $name;
}

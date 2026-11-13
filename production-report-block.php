<?php
require_once '_CustomDEVs/quote-workflow/vd_quote_workflow/quote-workflow-store.php';
$vdReportDb = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE, (int) DB_SERVER_PORT);
$vdReportDb->set_charset('utf8mb4');
try {
    $vdReportWorkflow = new VDQuoteWorkflow($vdReportDb, $app_user);
    $vdReportPath = (string) ($GLOBALS['app_path'] ?? '');
    if (!preg_match('/^121-([1-9][0-9]*)(?:\/159-([1-9][0-9]*))?$/', $vdReportPath, $vdReportMatch)) {
        throw new DomainException('Caminho inválido para a ficha de produção.');
    }
    $vdReportOrderId = (int) $vdReportMatch[1];
    if (!empty($vdReportMatch[2])) {
        $vdReportComponent = $vdReportWorkflow->access(159, (int) $vdReportMatch[2]);
        if ((int) $vdReportComponent['parent_item_id'] !== $vdReportOrderId) throw new DomainException('Componente de outra OP.');
    }
    $output_value = $vdReportWorkflow->productionPrintSummary($vdReportOrderId);
} catch (Throwable $vdReportError) {
    error_log('VD production report: ' . $vdReportError->getMessage());
    $output_value = '<div class="alert alert-danger">Não foi possível gerar a ficha de produção. Verifique o acesso à OP e às suas entidades. Não imprima uma ficha incompleta.</div>';
}
$vdReportDb->close();

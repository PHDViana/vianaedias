<?php
function vd_workflow_pdf_filename(array $document)
{
    $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $number = $snapshot['quote']['number'] ??
        (new DateTimeImmutable('@' . $document['issued_at']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('Y') . '/' . $document['quote_id'];
    return 'VeD_Orc_' . preg_replace('/[^0-9]/', '', $number) . '_' . (int) $document['id'] . '.pdf';
}

function vd_workflow_email_body(array $document, array $sender)
{
    $snapshot = json_decode($document['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $english = ($snapshot['quote']['language'] ?? 'pt') === 'en';
    $escape = static function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $number = $snapshot['quote']['number'] ?? (string) $document['quote_id'];
    $title = ($english ? 'Quotation ' : 'Orçamento ') . $number;
    $intro = $english ? 'Thank you for your enquiry. Please find our quotation attached as a PDF.' :
        'Agradecemos o seu pedido. Enviamos em anexo, em formato PDF, a nossa proposta de orçamento.';
    $reply = $english ? 'For any questions or further information, please reply to this email.' :
        'Para qualquer esclarecimento ou informação adicional, pode responder a este email.';
    return '<!doctype html><html lang="' . ($english ? 'en' : 'pt') . '"><head><meta charset="utf-8"></head>' .
        '<body style="margin:0;padding:0;background-color:#f4f5f7;color:#222;font-family:Arial,Helvetica,sans-serif">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">' .
        '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#fff">' .
        '<tr><td style="padding:24px;background-color:#333;color:#fff;font-size:22px;font-weight:bold">Viana &amp; Dias</td></tr>' .
        '<tr><td style="padding:28px;font-size:15px;line-height:1.6">' .
        '<h1 style="margin:0 0 20px;font-size:22px;line-height:1.3">' . $escape($title) . '</h1>' .
        '<p style="margin:0 0 16px">' . ($english ? 'Dear Customer,' : 'Ex.mo(s) Sr(s).,') . '</p>' .
        '<p style="margin:0 0 16px">' . $intro . '</p>' .
        '<p style="margin:0 0 16px"><strong>' . $escape($snapshot['quote']['description'] ?? '') . '</strong></p>' .
        '<p style="margin:0 0 16px">' . $reply . '</p>' .
        '<p style="margin:24px 0 0">' . ($english ? 'Thank you.<br>Kind regards,' : 'Obrigado.<br>Com os melhores cumprimentos,') .
        '<br><strong>' . $escape($sender['name']) . '</strong><br>Viana &amp; Dias<br>' .
        '<a style="color:#007ca8" href="mailto:' . $escape($sender['email']) . '">' . $escape($sender['email']) . '</a></p>' .
        '</td></tr></table></td></tr></table></body></html>';
}

function vd_workflow_pdf(array $document)
{
    require_once CFG_PATH_TO_DOMPDF;
    $html = $document['html'];
    // Dompdf cannot paginate a table cell containing the entire document.
    $html = str_replace('<table class="page-layout" role="presentation"><tfoot class="footer-space"><tr><td></td></tr></tfoot><tbody><tr><td>', '', $html);
    $html = str_replace('</td></tr></tbody></table></body></html>', '</body></html>', $html);
    $html = preg_replace(
        '/<header class="header">(<img class="logo"[^>]*>)<div>(.*?)<\/div><\/header>/s',
        '<table class="pdf-header" role="presentation"><tr><td class="pdf-logo">$1</td><td class="pdf-recipient">$2</td></tr></table>',
        $html
    );
    if ($html === null) throw new RuntimeException('Falha ao preparar o cabeçalho do PDF.');
    $css = '<style>@page{size:A4;margin:14mm 14mm 38mm}body{font-family:DejaVu Sans,sans-serif;font-size:10px;margin:0;padding:0;min-height:0;max-width:none;position:static}' .
        '.pdf-header{table-layout:fixed;margin-bottom:10px}.pdf-header td{vertical-align:top;padding:0;border:0}' .
        '.pdf-logo{width:52%}.pdf-recipient{width:48%}.pdf-header .logo{width:139px}' .
        '.footer{position:fixed;left:0;bottom:-24mm;width:100%;height:20mm}.footer img{width:100%;height:20mm}' .
        '.number{font-size:16px;white-space:nowrap}.customer{margin-top:20px;min-width:0;text-align:left}' .
        '.specifications{min-height:0;padding:6px 0}.spec{margin-bottom:4px}.spec dt{margin-bottom:0}.component h3{margin:8px 0 10px}' .
        '.intro{margin:10px 0}.no-print{display:none}</style>';
    $html = str_replace('</style><body>', '</style>' . $css . '<body>', $html);
    $pdf = new Dompdf\Dompdf();
    $pdf->set_option('isRemoteEnabled', false);
    $pdf->set_option('isPhpEnabled', false);
    $pdf->set_option('isJavascriptEnabled', false);
    $pdf->set_paper('a4', 'portrait');
    $pdf->load_html($html, 'UTF-8');
    $pdf->render();
    $output = $pdf->output();
    if (strncmp($output, '%PDF-', 5) !== 0) throw new RuntimeException('O gerador não devolveu um PDF válido.');
    return $output;
}

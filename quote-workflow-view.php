<?php
$vdEscape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
?>
<section id="vd-quote-workflow" data-path="<?= $vdEscape($current_path) ?>" data-token="<?= $vdEscape($app_session_token) ?>" data-send-only="<?= ($_GET['send_only'] ?? '') === '1' ? '1' : '0' ?>" data-accept-only="<?= ($_GET['accept_only'] ?? '') === '1' ? '1' : '0' ?>">
    <div class="ajax-modal-width-1200">
    <h1>Orçamento #<?= (int) $vdQuote ?> · Versões, aprovação e proposta</h1>
    <?php if (($_GET['send_only'] ?? '') !== '1' && ($_GET['accept_only'] ?? '') !== '1'): ?>
    <p>Guarde alternativas no configurador de cada componente. Confirme versões para gerar planos e operações por versão/quantidade. Selecione uma versão por componente em cada quantidade; a aprovação fixa o documento comercial durante 15 dias. Uma nova versão invalida a aprovação anterior.</p>
    <?php endif; ?>
    <?php if (($_GET['accept_only'] ?? '') === '1'): ?>
    <p>Selecione a alternativa aceite pelo cliente e registe a referência da adjudicação. A confirmação fixa os planos e operações dessa alternativa para produção.</p>
    <?php else: ?>
    <p>Cada envio é real e exige confirmação explícita. Pode alterar os destinatários e enviar novamente a mesma proposta. Um envio em curso ou sem resultado confirmado bloqueia novos envios até ser esclarecido.</p>
    <?php endif; ?>
    <div id="vd-workflow-message" role="status" aria-live="polite"></div>
    <div id="vd-workflow-content"></div>
    <script type="application/json" id="vd-workflow-state"><?= json_encode($vdState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
    </div>
</section>
<script src="_CustomDEVs/quote-workflow/js/vd-quote-workflow.js?v=<?= $vdEscape(hash_file('sha256', '_CustomDEVs/quote-workflow/js/vd-quote-workflow.js')) ?>"></script>

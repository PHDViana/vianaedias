<?php
$vdEscape=static function($value){return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
header('Cache-Control: private, no-store');
?>
<div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button><h4>Análise de tinta · orçamento #<?= $vdQuote ?></h4></div>
<div class="modal-body" style="max-height:calc(100vh - 130px);overflow:auto">
<section id="vd-quote-ink" class="ajax-modal-width-1200" data-path="<?= $vdEscape($current_path) ?>" data-token="<?= $vdEscape($app_session_token) ?>">
<p class="alert alert-warning">Estimativa informativa CMYK offset/SpeedMaster. Os valores são <strong>sempre indicativos</strong>. Não altera preços nem stock. Não substitui o RIP ou a medição de consumo real.</p>
<label>Componente <select class="form-control" id="vd-ink-component">
<?php foreach($vdComponents as $vdComponent): ?>
<option value="<?= (int)$vdComponent['id'] ?>" <?= (int)$vdComponent['id']===$vdComponentId?'selected':'' ?>>#<?= (int)$vdComponent['id'] ?> · <?= $vdEscape($vdComponent['name']) ?></option>
<?php endforeach; ?>
</select></label>
<p>PDF até 5 MB e 20 páginas, sem imposição: páginas ao tamanho do trabalho (pode incluir sangria). Pela ordem das rubricas da versão, frente e verso consecutivos de cada rubrica. Suporta impressão de uma face ou frente/verso normal; modos de viragem não são estimados.</p>
<div id="vd-ink-message" role="status" aria-live="polite"></div>
<div id="vd-ink-content"></div>
<script type="application/json" id="vd-ink-state"><?= json_encode($vdInkState,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</section></div>
<script src="_CustomDEVs/quote-workflow/js/vd-quote-ink.js?v=<?= $vdEscape(hash_file('sha256','_CustomDEVs/quote-workflow/js/vd-quote-ink.js')) ?>"></script>

<?php
require_once __DIR__.'/product-request-view.php';
$state=['id'=>123,'name'=>'Pedido <script>alert(1)</script>','customer_name'=>'Cliente & Filhos',
    'reference'=>'REF','revision'=>2,'notes'=>'Observações <b>sem HTML</b>','attachments'=>[['id'=>5,'name'=>'desenho<script>.pdf']],
    'conversion'=>['status'=>1,'quote'=>0,'error'=>''],
    'payload'=>['type'=>'standard','product_type'=>3711,'quantities'=>[250,500],
        'request_details'=>['dispatch'=>['pickup'],'final_format'=>['width_mm'=>'','height_mm'=>'']],
        'components'=>[['id'=>1,'name'=>'Capa','kind'=>'printed','fields'=>['4218'=>'440','4219'=>'310'],
            'request'=>['paper'=>'Cartolina 350 g/m²','printing'=>['mode'=>'cmyk']]]]]];
$catalog=['product_types'=>[['id'=>3707,'parent_id'=>0,'name'=>'Economato'],['id'=>3711,'parent_id'=>3707,'name'=>'Capas']],'choices'=>[]];
$html=vd_config_request_report_html($state,$catalog,'Em preparação','<button>Criar Orçamento em rascunho</button>',27,'index.php?module=items/vd_product_configurator&path=395');
$checks=[
    strpos($html,'id="vd-product-request-summary"')!==false,
    strpos($html,'Economato / Capas')!==false,
    strpos($html,'250, 500')!==false,
    strpos($html,'440 × 310 mm')!==false,
    strpos($html,'Cartolina 350 g/m²')!==false,
    strpos($html,'Impressão: CMYK')!==false,
    strpos($html,'Criar Orçamento em rascunho')!==false,
    strpos($html,'&lt;script&gt;')!==false,
    strpos($html,'<script>')===false,
    strpos($html,'attachment_id=5')!==false,
    strpos($html,'#configuration=123')!==false
];
$checks[]=strpos($html,'Formato final fechado:')===false;
$state['conversion']=['quote'=>999,'error'=>'Erro <b>validado</b>'];
$html=vd_config_request_report_html($state,$catalog,'Convertido em orçamento','',27,'api');
$checks[]=strpos($html,'path=146-999')!==false;
$checks[]=strpos($html,'#duplicate=123')!==false&&strpos($html,'Duplicar para novo orçamento')!==false;
$checks[]=strpos($html,'Erro &lt;b&gt;validado&lt;/b&gt;')!==false;
foreach($checks as $index=>$ok)if(!$ok)throw new RuntimeException('Request report assertion '.($index+1).' failed.');
echo count($checks)." request summary, escaping and navigation checks passed.\n";

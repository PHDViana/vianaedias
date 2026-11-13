const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const base = __dirname;
const read = file => fs.readFileSync(path.join(base, file), 'utf8');
const minify = source => execFileSync('npm', ['exec', '--yes', '--package', 'terser', '--',
    'terser', '--compress', '--mangle', '--ecma', '2020'], { input: source, encoding: 'utf8', maxBuffer: 1024 * 1024 });
const moduleAsset = minify(read('component-operation-costing.js'));
function replace(source, old, updated) {
    if (source.split(old).length !== 2) throw new Error('Expected one integration anchor: ' + old.slice(0, 100));
    return source.replace(old, updated);
}
let php = read('live-configurator.php');
php = replace(php, "c.field_1374 unit,c.field_1023 weight,", "c.field_1374 unit,c.field_1023 weight,c.field_1024 thickness,");
php = replace(php, 'field_6121 reduce_internal FROM app_entity_262',
    "field_6121 reduce_internal,(SELECT value FROM app_fields_choices WHERE id=field_6267 AND fields_id=6267) lamination_front,(SELECT value FROM app_fields_choices WHERE id=field_6268 AND fields_id=6268) lamination_back FROM app_entity_262");
php = replace(php, '#component-budget .cb-best{background:#e8f4eb!important}',
    '#component-budget .cb-best{background:#e8f4eb!important}#component-budget .cb-missing-data{background:#fff0d6!important}#component-budget .cb-missing-data td:first-child{border-left:4px solid #d98200}');
const filmWidths = { 75: 350, 151: 350, 263: 250, 271: 300, 307: 320, 341: 320, 533: 500,
    558: 450, 575: 500, 633: 250, 651: 450, 714: 230, 767: 320, 1217: 350, 1267: 230, 1670: 330,
    1676: 330, 1699: 300 };
const filmFinish = { gloss: [75, 263, 307, 558, 575, 714, 1676, 1699],
    matt: [151, 271, 341, 533, 633, 651, 1267, 1670], 'anti-scuff': [767, 1217] };
const inkColorCase = `CASE
    WHEN c.id=2177 THEN 'excluded'
    WHEN FIND_IN_SET('198',c.field_1019)>0 THEN 'Cyan'
    WHEN FIND_IN_SET('199',c.field_1019)>0 THEN 'Magenta'
    WHEN FIND_IN_SET('200',c.field_1019)>0 THEN 'Yellow'
    WHEN FIND_IN_SET('201',c.field_1019)>0 THEN 'Black'
    WHEN FIND_IN_SET('202',c.field_1019)>0 AND LOWER(c.field_1020) REGEXP 'yellow|amarelo|amarela|jaune|giallo|gelb' THEN 'Yellow'
    WHEN FIND_IN_SET('202',c.field_1019)>0 AND LOWER(c.field_1020) LIKE '%pantone%' THEN 'Pantone'
    ELSE 'excluded'
END`;
const queries = `
'material_records' => "SELECT m.id record_id,m.field_4280 internal_id,m.field_4278 article_id,m.field_4277 quantity,m.field_4324 sale_unit_price,i.field_4333 internal_name,c.field_1020 article_name,EXISTS(SELECT 1 FROM app_entity_99 a WHERE a.field_3632=i.id AND a.field_1374=3648 AND (FIND_IN_SET('190',a.field_1019)>0 OR FIND_IN_SET('322',a.field_1019)>0)) internal_paper,(c.field_1374=3648 AND (FIND_IN_SET('190',c.field_1019)>0 OR FIND_IN_SET('322',c.field_1019)>0)) article_paper FROM app_entity_277 m LEFT JOIN app_entity_281 i ON i.id=m.field_4280 LEFT JOIN app_entity_99 c ON c.id=m.field_4278 WHERE m.parent_item_id={$componentId} ORDER BY m.id",
'material_purchases' => "SELECT m.id record_id,(SELECT p.field_1037 FROM app_entity_98 p INNER JOIN app_entity_99 c ON c.id=p.field_1033 AND c.field_1374=p.field_1035 WHERE p.field_1032 IN(39,3790) AND p.field_1034>0 AND ((m.field_4278>0 AND p.field_1033=m.field_4278) OR (COALESCE(m.field_4278,0)=0 AND m.field_4280>0 AND (p.field_4334=m.field_4280 OR c.field_3632=m.field_4280))) ORDER BY p.date_added DESC,p.id DESC LIMIT 1) price FROM app_entity_277 m WHERE m.parent_item_id={$componentId}",
'ctp_equipment' => "SELECT id,field_2403 machines,field_6142 cost FROM app_entity_168 WHERE parent_item_id=3 ORDER BY id",
'ctp_labor' => "SELECT id,field_2411 operators,field_6160 people,field_6272 setup,field_6273 per_unit,field_6274 per_hour FROM app_entity_169 WHERE parent_item_id=3 ORDER BY id",
'ctp_operators' => "SELECT h.id,h.field_6269 cost FROM app_entity_141 h WHERE EXISTS(SELECT 1 FROM app_entity_169 r WHERE r.parent_item_id=3 AND FIND_IN_SET(h.id,r.field_2411)>0) ORDER BY h.id",
'imposition_operation' => "SELECT id,field_6275 proof_click FROM app_entity_36 WHERE id=43",
'imposition_equipment' => "SELECT id,field_2403 machine_id,field_6142 cost,field_2413 speed,field_6144 setup FROM app_entity_168 WHERE parent_item_id=43 ORDER BY id",
'imposition_labor' => "SELECT id,field_2411 operators,field_6160 people FROM app_entity_169 WHERE parent_item_id=43 ORDER BY id",
'imposition_operators' => "SELECT h.id,h.field_6269 cost FROM app_entity_141 h WHERE EXISTS(SELECT 1 FROM app_entity_169 r WHERE r.parent_item_id=43 AND FIND_IN_SET(h.id,r.field_2411)>0) ORDER BY h.id",
'imposition_papers' => "SELECT c.id,c.field_1020 name,c.field_1021 width,c.field_1022 height,c.field_1023 weight,c.field_1374 unit,(SELECT MAX(p.field_1037) FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374) price FROM app_entity_163 r INNER JOIN app_entity_99 c ON c.id=r.field_2300 WHERE r.parent_item_id=43 ORDER BY r.id",
'commercial_pricing' => "SELECT q.id,q.field_6271 markup FROM app_entity_146 q INNER JOIN app_entity_262 c ON c.parent_item_id=q.id WHERE c.id={$componentId}",
'lamination_articles' => "SELECT c.id,c.field_1020 name,c.field_3632 internal_id,c.field_1374 unit,CASE c.id ${Object.entries(filmWidths).map(([id, width]) => `WHEN ${id} THEN ${width}`).join(' ')} END width,CASE ${Object.entries(filmFinish).map(([finish, ids]) => `WHEN c.id IN(${ids}) THEN '${finish}'`).join(' ')} END finish,(SELECT MAX(p.field_1037) FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374) price FROM app_entity_99 c WHERE c.id IN(${Object.keys(filmWidths)}) ORDER BY c.id",
'explicit_operation_settings' => "SELECT r.id,r.field_4287 operation_id,r.field_4288 equipment,r.field_6180 operation_order,r.field_6125 lamination_entry,r.field_6126 lamination_exit,r.field_6127 lamination_narrow,r.field_6260 packaging_kg,COALESCE(NULLIF(pt.value,''),'1') packaging_type,bc.value packaging_box,r.field_6263 packaging_width,r.field_6264 packaging_length,r.field_6265 packaging_height,r.field_6266 packaging_overlap,r.field_6259 cut_pile_size,r.field_6254 adhesive_per_copy,r.field_6255 adhesive_width,r.field_6256 adhesive_height,r.field_6257 adhesive_margin,r.field_6258 adhesive_purchase_price,o.field_1982 name,o.field_1983 machines,o.field_6131 unit,o.field_6132 setup,o.field_2523 speed,o.field_6129 active FROM app_entity_276 r LEFT JOIN app_fields_choices pt ON pt.id=r.field_6261 AND pt.fields_id=6261 LEFT JOIN app_fields_choices bc ON bc.id=r.field_6262 AND bc.fields_id=6262 LEFT JOIN app_entity_36 o ON o.id=r.field_4287 WHERE r.parent_item_id={$componentId} AND r.vd_workflow_version IS NULL AND COALESCE(r.field_6179,'0') NOT IN('1','true') AND r.field_4287>0 ORDER BY r.field_6180+0,r.id",
'packaging_articles' => "SELECT c.id,c.field_1020 name,CASE WHEN c.id=1328 THEN 1200 ELSE c.field_1021 END width,c.field_1022 height,c.field_1374 unit,c.field_1023 weight,c.field_3632 internal_id,CASE WHEN c.id=1328 THEN '1' ELSE '2' END packaging_type,(SELECT MAX(p.field_1037) FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374) price FROM app_entity_99 c WHERE c.id=1328 OR (FIND_IN_SET('312',c.field_1019)>0 AND c.field_1374=3649) ORDER BY c.id",
'quote_components' => "SELECT c.id,c.field_4218 width,c.field_4219 height,c.field_4233 pages,c.field_6110 binding FROM app_entity_262 c INNER JOIN app_entity_262 current ON current.parent_item_id=c.parent_item_id WHERE current.id={$componentId} ORDER BY c.id",
'quote_packaging' => "SELECT COALESCE((SELECT MIN(r.id) FROM app_entity_276 r WHERE r.parent_item_id=c.id AND r.field_4287=10 AND r.vd_workflow_version IS NULL AND r.field_6179 NOT IN('1','true')),-(c.id*1000+10)) id,c.id component_id,COALESCE((SELECT r.field_6260 FROM app_entity_276 r WHERE r.parent_item_id=c.id AND r.field_4287=10 AND r.vd_workflow_version IS NULL AND r.field_6179 NOT IN('1','true') ORDER BY r.id LIMIT 1),10) packaging_kg FROM app_entity_262 c INNER JOIN app_entity_262 current ON current.parent_item_id=c.parent_item_id WHERE current.id={$componentId} ORDER BY c.id",
'quote_selections' => "SELECT s.id,s.parent_item_id component_id,s.field_6101 quantity_id,s.field_6102 quantity,s.field_6106 details FROM app_entity_388 s INNER JOIN app_entity_262 c ON c.id=s.parent_item_id INNER JOIN app_entity_262 current ON current.parent_item_id=c.parent_item_id WHERE current.id={$componentId} AND c.id<>{$componentId} AND s.id=(SELECT MAX(s2.id) FROM app_entity_388 s2 WHERE s2.parent_item_id=s.parent_item_id AND s2.field_6101=s.field_6101) ORDER BY s.id",
'workflow_component' => "SELECT c.*,(SELECT value FROM app_fields_choices WHERE fields_id=6267 AND id=c.field_6267) lamination_front,(SELECT value FROM app_fields_choices WHERE fields_id=6268 AND id=c.field_6268) lamination_back FROM app_entity_262 c WHERE c.id={$componentId}",
'workflow_rules' => "SELECT parent_item_id operation,field_6149 required,field_6176 \`condition\`,field_6177 alternatives FROM app_entity_120 WHERE field_6178 IN('1','true') AND field_6176<>'' ORDER BY id",
'color_materials' => "SELECT r.id record_id,r.field_4251 color,(SELECT name FROM app_global_lists_choices WHERE id=r.field_4251) color_name,r.field_4253 pantone,c.id article_id,c.field_1020 name,c.field_1374 unit,(SELECT name FROM app_global_lists_choices WHERE id=c.field_1374) unit_name,(SELECT p.field_1037 FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374 AND p.field_1032 IN(39,3790) AND p.field_1034>0 ORDER BY p.date_added DESC,p.id DESC LIMIT 1) price FROM app_entity_278 r LEFT JOIN app_entity_99 c ON c.id=r.field_4249 WHERE r.parent_item_id={$componentId} ORDER BY r.id",
'ink_counter_machines' => "SELECT id machine_id,field_1281 name,field_707 status,DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s') calibrated_at,DATE_FORMAT(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 36 MONTH),'%Y-%m-%d') window_start,DATE_FORMAT(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 1 DAY),'%Y-%m-%d') window_end,36 expected_periods FROM app_entity_78 WHERE id IN(5,6,7,9,56) ORDER BY id",
'ink_counter_periods' => "SELECT r.parent_item_id machine_id,DATE_FORMAT(FROM_UNIXTIME(r.field_3525),'%Y-%m') period_month,COUNT(*) records,SUM(r.field_3526 IS NULL OR r.field_3528 IS NULL) invalid_records,SUM(r.field_3526<r.field_3528) negative_records,SUM(CASE WHEN r.field_3526 IS NULL OR r.field_3528 IS NULL OR r.field_3526<r.field_3528 THEN NULL ELSE r.field_3526-r.field_3528 END) impressions FROM app_entity_241 r WHERE r.parent_item_id IN(5,6,7) AND r.field_3525>=UNIX_TIMESTAMP(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 36 MONTH)) AND r.field_3525<UNIX_TIMESTAMP(DATE_FORMAT(CURDATE(),'%Y-%m-01')) GROUP BY r.parent_item_id,r.field_3525 ORDER BY r.parent_item_id,r.field_3525",
'ink_purchase_totals' => "SELECT ${inkColorCase} color,p.field_1035 unit,(SELECT name FROM app_global_lists_choices WHERE id=p.field_1035) unit_name,COUNT(DISTINCT c.id) articles,COUNT(p.id) purchases,SUM(p.field_1034) quantity,SUM(CASE WHEN p.field_1037 IS NULL THEN p.field_1034 ELSE 0 END) unpriced_quantity,CASE WHEN SUM(CASE WHEN p.field_1037 IS NULL THEN p.field_1034 ELSE 0 END)=0 THEN SUM(p.field_1034*p.field_1037)/SUM(p.field_1034) ELSE NULL END weighted_price,GROUP_CONCAT(DISTINCT c.id ORDER BY c.id) article_ids,GROUP_CONCAT(DISTINCT c.field_1019 ORDER BY c.field_1019) categories FROM app_entity_98 p INNER JOIN app_entity_99 c ON c.id=p.field_1033 WHERE p.field_1032 IN(39,3790) AND p.field_1034>0 AND p.date_added>=UNIX_TIMESTAMP(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 36 MONTH)) AND p.date_added<UNIX_TIMESTAMP(DATE_FORMAT(CURDATE(),'%Y-%m-01')) AND FIND_IN_SET('191',c.field_1019)>0 GROUP BY color,p.field_1035 ORDER BY color,p.field_1035",
'ink_latest_prices' => "SELECT color,article_id,article_name,purchase_id,purchase_date,quantity,price,unit,category FROM (SELECT ${inkColorCase} color,c.id article_id,c.field_1020 article_name,p.id purchase_id,FROM_UNIXTIME(p.date_added) purchase_date,p.field_1034 quantity,p.field_1037 price,p.field_1035 unit,c.field_1019 category,ROW_NUMBER() OVER(PARTITION BY ${inkColorCase} ORDER BY p.date_added DESC,p.id DESC) purchase_rank FROM app_entity_98 p INNER JOIN app_entity_99 c ON c.id=p.field_1033 AND c.field_1374=p.field_1035 WHERE p.field_1032 IN(39,3790) AND p.field_1034>0 AND FIND_IN_SET('191',c.field_1019)>0 AND ${inkColorCase} IN('Cyan','Magenta','Yellow','Black')) ranked WHERE purchase_rank=1 ORDER BY color",
'administrative_calibration' => "SELECT DATE_FORMAT('2025-01-01','%Y-%m-%d') period_start,DATE_FORMAT('2026-11-06','%Y-%m-%d') period_end,'1475' excluded_type,(SELECT t.field_6624+0 FROM app_entity_167 t WHERE t.parent_item_id=44 AND t.field_6164='true' AND (t.field_6139=0 OR t.field_6139<=UNIX_TIMESTAMP(CURDATE())) AND (t.field_6165=0 OR t.field_6165>=UNIX_TIMESTAMP(CURDATE())) ORDER BY t.id DESC LIMIT 1) op_count,(SELECT COUNT(DISTINCT CONCAT(COALESCE(NULLIF(v.field_5653,''),'0'),'#',COALESCE(NULLIF(v.field_3986,''),CONCAT('missing:',v.id)))) FROM app_entity_264 v WHERE v.field_3981>=UNIX_TIMESTAMP('2025-01-01') AND v.field_3981<UNIX_TIMESTAMP('2026-11-07') AND COALESCE(NULLIF(v.field_5658,''),'0')<>'1475') invoice_count,(SELECT h.field_6269 FROM app_entity_141 h WHERE h.id=4) janine_rate,(SELECT h.field_6269 FROM app_entity_141 h WHERE h.id=5) carla_rate",
'costing_catalog' => "SELECT id,field_1982 name,field_1983 machines,field_6131 unit,field_6132 setup,field_2523 speed,field_6129 active,field_4440 waste FROM app_entity_36",
'costing_tariffs' => "SELECT id,parent_item_id operation_id,field_6135 basis,field_6136 cost,field_6137 sale,field_6138 version,field_6139 starts_at,field_6165 ends_at FROM app_entity_167 WHERE field_6164='true' AND (field_6139=0 OR field_6139<=UNIX_TIMESTAMP(CURDATE())) AND (field_6165=0 OR field_6165>=UNIX_TIMESTAMP(CURDATE())) ORDER BY parent_item_id,id",
'costing_equipment' => "SELECT e.id,e.parent_item_id operation_id,e.field_2403 machine_id,e.field_2413 speed,e.field_6143 unit,e.field_6144 setup,(SELECT m.field_1281 FROM app_entity_78 m WHERE m.id=e.field_2403) machine_name FROM app_entity_168 e ORDER BY e.parent_item_id,e.id",
'adhesive_articles' => "SELECT c.id,c.field_1020 name,c.field_1021 width,c.field_1022 height,c.field_1374 unit,c.field_3632 internal_id,(SELECT MAX(p.field_1037) FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374) price FROM app_entity_99 c WHERE c.id=201 AND c.field_1374=3648",
'spiral_articles' => "SELECT c.id,c.field_1020 name,c.field_1374 unit,(SELECT name FROM app_global_lists_choices WHERE id=c.field_1374) unit_name,latest_purchase.field_1037 price,latest_purchase.id purchase_id,latest_purchase.date_added purchase_date FROM app_entity_99 c INNER JOIN app_entity_98 latest_purchase ON latest_purchase.id=(SELECT p.id FROM app_entity_98 p WHERE p.field_1033+0=c.id AND p.field_1035=c.field_1374 AND p.field_1032 IN(39,3790) AND p.field_1034>0 ORDER BY p.date_added DESC,p.id DESC LIMIT 1) WHERE c.field_1374=3649 AND (FIND_IN_SET('274',c.field_1019)>0 OR FIND_IN_SET('275',c.field_1019)>0) AND LOWER(c.field_1020) LIKE '%espiral%' ORDER BY c.field_1020,c.id",
`;
if (!queries.includes("'explicit_operation_settings' =>")) throw new Error('Explicit operation settings query not found');
php = replace(php, '$simulationQueries = [', '$simulationQueries = [' + queries);
php = replace(php, 'p.field_2174 component_height,p.field_2178 width,',
    'p.field_2174 component_height,p.field_2175 `columns`,p.field_2176 `rows`,p.field_2178 width,');
php = replace(php, 'WHERE parent_item_id={$componentId}",\n];',
    'WHERE parent_item_id={$componentId} ORDER BY id",\n];');
php = replace(php, "echo '<script type=\"application/json\" id=\"component-simulation-data\">'",
    `require_once 'includes/vd_quote_workflow/quote-workflow-discovery.php';
$simulation['costing_operations'] = vd_workflow_discover_operations($simulation['workflow_component'][0],
    $simulation['explicit_operation_settings'], $simulation['costing_catalog'], $simulation['workflow_rules']);
unset($simulation['explicit_operation_settings']);
$simulation['workflow_versions'] = [];
$vdVersionQuery = db_query("SELECT id,component_id,quantity_id,state,native_id FROM app_vd_quote_versions WHERE component_id={$componentId} ORDER BY id");
while ($vdVersion = db_fetch_array($vdVersionQuery)) $simulation['workflow_versions'][] = $vdVersion;
$simulation['workflow_token'] = $GLOBALS['app_session_token'] ?? '';
echo '<script type="application/json" id="component-simulation-data">'`);
const eligiblePurchase = 'p.field_1032 IN(39,3790) AND p.field_1034>0';
const purchaseOrder = 'ORDER BY p.date_added DESC,p.id DESC LIMIT 1';
php = php.replace(/\(SELECT MAX\(p\.field_1037\)\s*FROM app_entity_98 p WHERE p\.field_1033=c\.id AND p\.field_1035=c\.field_1374\)/g,
    `(SELECT p.field_1037 FROM app_entity_98 p WHERE p.field_1033=c.id AND p.field_1035=c.field_1374 AND ${eligiblePurchase} ${purchaseOrder})`);
// Purchase provenance also makes family/format selection chronological instead of price-based.
php = php.replace(/(\(SELECT p\.field_1037 FROM app_entity_98 p WHERE p\.field_1033=c\.id AND p\.field_1035=c\.field_1374 AND [^)]*IN\(39,3790\)[^)]*LIMIT 1\))\s*(AS price|price)/g,
    (match, priceQuery, alias) => `${priceQuery} ${alias},` +
        `${priceQuery.replace('p.field_1037', 'p.id')} purchase_id,` +
        `${priceQuery.replace('p.field_1037', 'p.date_added')} purchase_date`);
php = replace(php,
    "(SELECT MAX(p.field_1037)FROM app_entity_98 p WHERE p.field_4334=i.id OR p.field_1033 IN(SELECT c.id FROM app_entity_99 c WHERE c.field_3632=i.id))AS max_price",
    `(SELECT p.field_1037 FROM app_entity_98 p INNER JOIN app_entity_99 c ON c.id=p.field_1033 AND c.field_1374=p.field_1035 WHERE (p.field_4334=i.id OR c.field_3632=i.id) AND ${eligiblePurchase} ${purchaseOrder})AS latest_price`);
php = replace(php, "'maxPrice' => $material['max_price']", "'latestPrice' => $material['latest_price']");
php = replace(php,
    "SELECT i.id,MAX(p.field_1037)AS price FROM app_entity_281 i LEFT JOIN app_entity_98 p ON p.field_4334=i.id OR p.field_1033 IN(SELECT c.id FROM app_entity_99 c WHERE c.field_3632=i.id)WHERE i.id IN(984,985)GROUP BY i.id",
    `SELECT i.id,(SELECT p.field_1037 FROM app_entity_98 p INNER JOIN app_entity_99 c ON c.id=p.field_1033 AND c.field_1374=p.field_1035 WHERE (p.field_4334=i.id OR c.field_3632=i.id) AND ${eligiblePurchase} ${purchaseOrder}) price FROM app_entity_281 i WHERE i.id IN(984,985)`);
php = php.replace(/(\(SELECT p\.field_1037 FROM app_entity_98 p INNER JOIN app_entity_99 c ON c\.id=p\.field_1033 AND c\.field_1374=p\.field_1035 WHERE p\.field_1032 IN\(39,3790\)[^\n]*?LIMIT 1\)) price FROM app_entity_277/g,
    (match, priceQuery) => `${priceQuery} price,${priceQuery.replace('p.field_1037', 'p.id')} purchase_id,` +
        `${priceQuery.replace('p.field_1037', 'p.date_added')} purchase_date FROM app_entity_277`);
if (/MAX\(p.field_1037\)/i.test(php.slice(0, php.indexOf('foreach ($simulationQueries')))) {
    throw new Error('Unconverted maximum purchase price in report queries');
}
php = php.replaceAll('field_2268 setup_minutes', 'field_6132 setup_minutes');
// Aggregate once with the original numeric-reference semantics (TEXT references can
// contain non-canonical numbers). Never skip a latest purchase with a NULL price.
const latestPurchases = `LEFT JOIN (SELECT article_id,unit,price,purchase_id,purchase_date FROM
    (SELECT p.field_1033+0 article_id,p.field_1035 unit,p.field_1037 price,p.id purchase_id,
        p.date_added purchase_date,ROW_NUMBER() OVER (PARTITION BY p.field_1033+0,p.field_1035
        ORDER BY p.date_added DESC,p.id DESC) purchase_rank FROM app_entity_98 p
        WHERE p.field_1032 IN(39,3790) AND p.field_1034>0) ranked WHERE purchase_rank=1)
    latest_purchase ON latest_purchase.article_id=c.id AND latest_purchase.unit=c.field_1374`;
const standardPurchase = /\(SELECT p\.(field_1037|id|date_added) FROM app_entity_98 p WHERE p\.field_1033=c\.id AND p\.field_1035=c\.field_1374 AND p\.field_1032 IN\(39,3790\) AND p\.field_1034>0 ORDER BY p\.date_added DESC,p\.id DESC LIMIT 1\)/g;
php = php.split('\n').map(line => {
    if (!line.includes('(SELECT p.field_1037 FROM app_entity_98 p WHERE p.field_1033=c.id')) return line;
    const updated = line.replace(standardPurchase, (_, field) => 'latest_purchase.' +
        ({ field_1037: 'price', id: 'purchase_id', date_added: 'purchase_date' }[field]));
    if (updated === line) throw new Error('Unrecognized latest-purchase query');
    return updated.replace(/app_entity_99 c(?: ON c\.id=r\.field_(?:2300|4249))? /,
        match => match + latestPurchases.replace(/\s+/g, ' ') + ' ');
}).join('\n');
const stockJoin = `LEFT JOIN (SELECT s.field_1187+0 article_id,
    SUM(CASE WHEN s.field_1089 IN(226,319) THEN s.field_1063 WHEN s.field_1089=225 THEN s.field_1090
        WHEN s.field_1089 IN(264,318) THEN -s.field_1063 END) available_stock,
    COUNT(*) stock_records,SUM((CASE WHEN s.field_1089=225 THEN s.field_1090 ELSE s.field_1063 END) IS NULL) invalid_stock
    FROM app_entity_100 s WHERE s.field_1089 IN(226,319,225,264,318) GROUP BY s.field_1187+0)
    paper_stock ON paper_stock.article_id=c.id`;
php = php.split('\n').map(line => {
    if (!line.startsWith("'papers' =>")) return line;
    return line
        .replace(/\(SELECT SUM\(CASE WHEN s\.field_1089[\s\S]*?\)AS available_stock/, 'paper_stock.available_stock AS available_stock')
        .replace(/\(SELECT COUNT\(\*\)FROM app_entity_100 s WHERE s\.field_1187=c\.id AND s\.field_1089 IN\(226,319,225,264,318\)\)AS stock_records/, 'COALESCE(paper_stock.stock_records,0) AS stock_records')
        .replace(/\(SELECT COUNT\(\*\)FROM app_entity_100 s WHERE s\.field_1187=c\.id AND s\.field_1089 IN\(226,319,225,264,318\)AND\(CASE WHEN s\.field_1089=225 THEN s\.field_1090 ELSE s\.field_1063 END\)IS NULL\)AS invalid_stock/, 'COALESCE(paper_stock.invalid_stock,0) AS invalid_stock')
        .replace('INNER JOIN app_entity_281 i ', stockJoin.replace(/\s+/g, ' ') + ' INNER JOIN app_entity_281 i ');
}).join('\n');
let ui = read('live-configurator.js');
ui = replace(ui,
    'x.append(r("h1", "Configurar e orçamentar"), r("p", g.title.replace(/^.*?\\|\\s*/, ""))); const C = [4224, 4218, 4219, 4233, 4229, 6110].map(e => { const a = g.querySelector(".form-group-" + e); return a ? o(a.querySelector("th").textContent) + ": " + o(a.querySelector("td").textContent) : ""; }).filter(Boolean); x.append(r("p", C.join(" · "), "cb-note"));',
    'void 0;');
ui = replace(ui, ', P.append(x, q); const E =', '; const E =');
ui = replace(ui,
    's("Abrir orçamento", "index.php?module=items/info&path=" + encodeURIComponent(l.split("/")[0]), "_blank")); const I =',
    's("Abrir orçamento", "index.php?module=items/info&path=" + encodeURIComponent(l.split("/")[0]), "_blank"), q); const I =');
ui = replace(ui, 'await async function t() {', `document.querySelectorAll(".page-title").forEach(title => {
    if (title.textContent.trim() === "Configurar e orçamentar") title.remove();
});
const initialReport = {
    catalog: document.querySelector("#component-material-catalog")?.textContent,
    simulation: document.querySelector("#component-simulation-data")?.textContent
};
await async function t(refresh = false) {`);
ui = replace(ui, '() => t().catch(Se)', '() => t(true).catch(Se)');
ui = replace(ui,
    'H = await i(url_for("report_page/view", "action=load&id=" + encodeURIComponent(V) + "&path=" + encodeURIComponent(l)), {}), W = H.querySelector("#component-material-catalog");',
    `H = !refresh && initialReport.catalog && initialReport.simulation ? null :
        await i(url_for("report_page/view", "action=load&id=" + encodeURIComponent(V) + "&path=" + encodeURIComponent(l)), {}),
    W = H ? H.querySelector("#component-material-catalog") : {textContent: initialReport.catalog};`);
ui = replace(ui, 'B = H.querySelector("#component-simulation-data");',
    'B = H ? H.querySelector("#component-simulation-data") : {textContent: initialReport.simulation};');
ui = ui.replaceAll('maxPrice', 'latestPrice').replaceAll('Preço mais alto', 'Última compra')
    .replaceAll('maior preço de compra registado para essa descrição', 'preço da última compra concluída dessa descrição');
ui = replace(ui, 'purchaseUnitPrice: d(u(e, 4323)), purchaseTotal: d(u(e, 4335)),',
    `purchaseReference: G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1])),
    purchaseUnitPrice: d(G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1]))?.price),
    purchaseTotal: (() => { const quantity = d(t.querySelector('[name="fields[4277]"]')?.value);
        const price = d(G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1]))?.price);
        return quantity !== null && price !== null ? quantity * price : null; })(),`);
ui = replace(ui, 'o = e.id ? J[e.id]?.latestPrice : null,', 'o = e.purchaseUnitPrice,');
const materialAnchor = 'const z = await Promise.all(m(L).map(async (e) => {';
ui = replace(ui, materialAnchor, `${materialAnchor}
    const record = G.material_records?.find(record =>
        Number(record.record_id) === Number(e.querySelector("[data_path]")?.getAttribute("data_path")?.split("-")[1]));
    if (record && [record.internal_id, record.article_id].every(value => !value || /^\\d+$/.test(String(value)))) {
        const articleId = Number(record.article_id) || null;
        const internalId = Number(record.internal_id) || Number(G.papers.find(paper => Number(paper.id) === articleId)?.internal_id) || undefined;
        const purchaseReference = G.material_purchases.find(purchase => Number(purchase.record_id) === Number(record.record_id));
        const quantity = d(record.quantity), purchaseUnitPrice = d(purchaseReference?.price);
        return {id: internalId, recordId: Number(record.record_id), materialId: articleId,
            name: record.internal_name || record.article_name || "Material sem descrição",
            materialName: record.article_name || null, quantity,
            paper: record.internal_id ? Number(record.internal_paper) === 1 : Number(record.article_paper) === 1,
            purchaseReference, purchaseUnitPrice,
            purchaseTotal: quantity !== null && purchaseUnitPrice !== null ? quantity * purchaseUnitPrice : null,
            saleUnitPrice: d(record.sale_unit_price), saleTotal: d(u(e, 4325))};
    }`);
ui = replace(ui, '["P.U. compra", [4323], !0], ["Compra", [4335], !0], ["Venda", [4325], !0]',
    '["P.U. compra", [4323], !0], ["Compra", [4335], !0]');
ui = replace(ui, '" · venda €/h: " + a(h.values.hourly) +', '" · " +');
ui = ui.replaceAll(' (excluído da venda).', ' (incluído no custo total).');
ui = ui.replaceAll('preço de venda €/h em falta.', 'custo de referência €/h em falta.');
ui = replace(ui, 'const G = JSON.parse(B.textContent);', `const G = JSON.parse(B.textContent);
G.ctp_resources = { equipment: G.ctp_equipment, labor: G.ctp_labor, operators: G.ctp_operators };
G.imposition_resources = { operation: G.imposition_operation[0], equipment: G.imposition_equipment,
    labor: G.imposition_labor, operators: G.imposition_operators, papers: G.imposition_papers,
    machine: (() => { const m = G.machines.find(row => Number(row.id) === 56); return m ?
        { id: m.id, width: m.max_width, height: m.max_height, print_width: m.print_width, print_height: m.print_height } : null; })() };
G.operations = G.operations.filter(row => Number(row.id) !== 43);
G.price_operations = G.price_operations.filter(row => Number(row.id) !== 43);
G.component[0].setupWasteOperations = G.costing_operations.map(row => ({
    operation_id: row.operation_id, name: row.name, machines: row.machines,
    waste: Number(row.operation_id) === 13 && G.component[0].lamination_front === 'none' &&
        G.component[0].lamination_back === 'none' ? 0 : row.waste
}));
G.material_price_policy = { version: 1, method: "last-completed-purchase", states: [39, 3790],
    order: ["date_added DESC", "id DESC"], quantity: "positive", unit: "article-native" };
for (const operation of [...G.operations, ...G.price_operations]) {
    const tariff = window.vdOperationCosting.currentTariff(G, operation.id);
    // The simulation engine's hourly slot is a cost proxy; commercial pricing is applied by the full ledger.
    operation.hourly = !tariff.error && tariff.basis === "h" ? tariff.sale : null;
    operation.cost_hourly = !tariff.error && tariff.basis === "h" ? tariff.cost : null;
    operation.consolidatedTariff = tariff;
    if (Number(operation.id) === 3) operation.ctpResources = G.ctp_resources;
}`);
ui = replace(ui, 'const Z = ye(G, J, z, R, Y, async function (e) {', `const operationPanel = r("div");
const operationCosting = window.vdOperationCosting.create(G, z, Y, operationPanel);
const Z = ye(G, J, z, R, Y, operationCosting.save = async function (e) {
operationCosting.check(e);
const versions = G.workflow_versions.filter(row => String(row.quantity_id || "rubrics") === String(e.quantityId));
const expectedVersion = Number(versions.at(-1)?.id || 0);
e.workflowRequestId ||= crypto.randomUUID();
const workflowResponse = await fetch("index.php?" + new URLSearchParams({module:"items/vd_quote_workflow", path:l}), {
    method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest"},
    body:new URLSearchParams({api:"save",form_session_token:G.workflow_token,payload:JSON.stringify({
        request_id:e.workflowRequestId,expected_version:expectedVersion,quantity_id:String(e.quantityId),
        calculation:e.parameters})})});
let workflowResult;
try { workflowResult = await workflowResponse.json(); } catch (_) { throw new Error("Resposta inválida. Não repita a gravação automaticamente."); }
if (!workflowResponse.ok || workflowResult.ok !== true) throw new Error(workflowResult.error || "Gravação não confirmada.");
const savedVersion = workflowResult.data;
G.workflow_versions.push({id:savedVersion.workflow_version,quantity_id:savedVersion.quantity_id});
Y.set(String(e.quantityId), savedVersion);
operationCosting.renderSaved();
return savedVersion;
`);
ui = replace(ui, 'window.vdOperationCosting.create(G, z, Y, operationPanel)',
    'window.vdOperationCosting.create(G, z, Y, operationPanel, _.querySelector("#cb-section-276 table"))');
ui = replace(ui, 'e.append(i), o.body.append(e);',
    'e.append(i), c && c[1] === "276" && (e.dataset.operationRow = c[2]), o.body.append(e);');
ui = replace(ui, 'return Y.set(String(e.quantityId), u), u;', 'Y.set(String(e.quantityId), u); operationCosting.renderSaved(); return u;');
ui = replace(ui, 'window.vdOperationCosting.detail(a.allOperations)',
    'window.vdOperationCosting.get(m.id).detail(a)');
ui = replace(ui, 'window.vdOperationCosting.detail(e.allOperations)',
    'window.vdOperationCosting.get(m.id).detail(e)');
ui = replace(ui, 'saleUnitPrice: d(t.querySelector(\'[name="fields[4324]"]\')?.value),',
    'saleUnitPrice: d(t.querySelector(\'[name="fields[4324]"]\')?.value), margin: d(t.querySelector(\'[name="fields[4336]"]\')?.value),');
ui = replace(ui, 'impressions: p("impressions"), paper: p("paper")', 'impressions: p("impressions"), clicks: p("clicks"), paper: p("paper")');
ui = replace(ui, 'g.length ? "Melhor encontrada: "', 'g.length && !g[0].errors.length ? "Melhor completa: "');
ui = replace(ui, 'a.machine.name + " · " + n(o.total) + " · consultar montagens"',
    'a.machine.name + " · " + window.vdOperationCosting.money(o.total) + " · consultar montagens"');
ui = ui.replaceAll('"Custo interno: " + n(e.machineCost)', '"Custo interno da impressão: " + n(e.machineCost)');
ui = replace(ui, 'oe = c(L, "Total Compra"), ne = c(L, "Total Venda")',
    `oe = z.reduce((totals, material) => {
        const cost = d(material.purchaseTotal);
        if (cost === null) totals.missing++;
        else totals.total += cost;
        return totals;
    }, { total: 0, missing: 0 }),
    ne = (() => {
        const pricing = window.vdOperationCosting.commercialPricing(G);
        return { total: pricing.markup === null ? null : oe.total * (1 + pricing.markup / 100),
            missing: oe.missing + pricing.errors.length };
    })()`);
ui = replace(ui, 'F.append(R, O)', 'R.style.gridColumn = "1 / -1", O.querySelectorAll("table,h2,.cb-warnings > p,.cb-warnings > strong").forEach(node => node.hidden = true), F.append(O)');
ui = replace(ui, 'A.append(_, F)', 'A.append(R, _, F)');
ui = replace(ui, 'e.replaceChildren(P, E, I, A, Z)', `(() => {
const workflowUrl = "index.php?" + new URLSearchParams({
    module:"items/vd_quote_workflow",path:l.split("/")[0],modal:"1"});
const workflowLink = s("Versões, confirmação, aprovação e proposta", "#");
workflowLink.classList.add("link-to-modalbox");
workflowLink.addEventListener("click", event => {
    event.preventDefault();
    window.open_dialog(workflowUrl);
});
E.append(workflowLink);
const manualPlans = _.querySelector("#cb-section-279");
manualPlans.hidden = true;
I.querySelector('a[href="#cb-section-279"]').hidden = true;
const operationsSection = _.querySelector("#cb-section-276");
const operationsHeading = operationsSection.querySelector("h2");
operationsHeading.textContent = "Parâmetros das operações";
operationsSection.querySelector(".cb-note").textContent =
    "Operações usadas pelo cálculo dinâmico. Edite apenas os requisitos, equipamentos e parâmetros específicos do componente; os tempos e custos são calculados na simulação.";
const operationsDetails = r("details");
operationsDetails.id = "cb-operation-parameters";
const requirementLabels = {
    mandatory: "Obrigatória",
    inferred: "Inferida dos campos",
    dependency: "Dependência automática",
    selected: "Selecionada explicitamente",
    plan_alternative: "Alternativa decidida pelo plano"
};
const requirementSummaryLabels = {
    mandatory: "obrigatórias",
    inferred: "inferidas dos campos",
    dependency: "dependências automáticas",
    selected: "selecionadas explicitamente",
    plan_alternative: "alternativas decididas pelo plano"
};
const requirementCounts = G.costing_operations.reduce((counts, row) => {
    const type = row.requirement_type || (row.discovered ? "inferred" : "selected");
    counts[type] = (counts[type] || 0) + 1;
    return counts;
}, {});
const requirementSummary = Object.entries(requirementLabels)
    .filter(([type]) => requirementCounts[type])
    .map(([type]) => requirementCounts[type] + " " + requirementSummaryLabels[type])
    .join(" · ");
operationsDetails.append(r("summary", "Operações e parâmetros do componente (" + requirementSummary + ")"));
operationsDetails.append(r("p",
    "Obrigatórias e inferidas são derivadas da configuração; dependências vêm das regras do catálogo. " +
    "Acabamentos opcionais, como Verniz UV e BiAdesivo, só entram quando selecionados no componente. " +
    "As alternativas de impressão são escolhidas pelo plano, não são operações obrigatórias em conjunto.",
    "cb-note"));
operationsSection.before(operationsDetails);
operationsDetails.append(operationsSection);
for (const row of G.costing_operations) {
    const tableRow = operationsSection.querySelector('[data-operation-row="' + row.id + '"]');
    if (!tableRow) continue;
    const type = row.requirement_type || (row.discovered ? "inferred" : "selected");
    const status = r("small", " · " + (requirementLabels[type] || "Selecionada explicitamente") +
        (row.requirement_reason ? ": " + row.requirement_reason : ""), "cb-note");
    tableRow.cells[0].append(status);
}
I.querySelector('a[href="#cb-section-276"]').textContent = "Parâmetros das operações";
I.replaceChildren();
I.setAttribute("role", "tablist");
I.setAttribute("aria-label", "Secções do configurador");
const panel = () => {
    const node = r("section", undefined, "cb-tab-panel");
    node.setAttribute("role", "tabpanel");
    node.style.cssText = "padding-top:16px";
    return node;
};
const tabDefinitions = [
    ["Comparativo Planos Dinâmicos", panel()],
    ["Papel e matérias-primas", panel()],
    ["Simular impressão · Digital × Offset", panel()],
    ["Planos Possíveis", panel()],
    ["Escolhas guardadas", panel()]
];
let activeTab = 0;
const activateTab = index => {
    activeTab = index;
    for (const [position, [label, content, button]] of tabDefinitions.entries()) {
        const selected = position === index;
        button.setAttribute("aria-selected", String(selected));
        button.tabIndex = selected ? 0 : -1;
        content.hidden = !selected;
    }
};
for (const [index, [label, content]] of tabDefinitions.entries()) {
    const button = r("button", label, "btn " + (index === 0 ? "btn-primary" : "btn-default"));
    button.type = "button";
    button.id = "cb-tab-" + (index + 1);
    button.setAttribute("role", "tab");
    button.setAttribute("aria-controls", "cb-tab-panel-" + (index + 1));
    button.setAttribute("aria-selected", String(index === 0));
    button.tabIndex = index === 0 ? 0 : -1;
    content.id = "cb-tab-panel-" + (index + 1);
    content.setAttribute("aria-labelledby", button.id);
    button.style.cssText = "margin:0 6px 6px 0";
    button.addEventListener("click", () => activateTab(index));
    button.addEventListener("keydown", event => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? 0 : event.key === "End" ? tabDefinitions.length - 1 :
            (index + (event.key === "ArrowRight" ? 1 : tabDefinitions.length - 1)) % tabDefinitions.length;
        activateTab(next);
        tabDefinitions[next][2].focus();
    });
    tabDefinitions[index][2] = button;
    I.append(button);
}
const [comparisonTab, materialsTab, simulationTab, plansTab, savedTab] = tabDefinitions.map(item => item[1]);
const planSection = Z.querySelector(".cb-plan-generator");
const comparisonOutput = planSection?.querySelector("#cb-dynamic-plan-results");
const rubricTable = _.querySelector("#cb-section-280")?.parentElement;
materialsTab.append(_.querySelector("#cb-section-277"), operationsDetails,
    _.querySelector("#cb-section-278")?.parentElement, rubricTable);
simulationTab.append(Z);
if (planSection) plansTab.append(planSection);
if (comparisonOutput) comparisonTab.append(comparisonOutput);
comparisonTab.append(A);
savedTab.append(operationPanel.querySelector("#cb-final-cost-lines"));
activateTab(activeTab);
e.replaceChildren(fe, E, I, materialsTab, simulationTab, plansTab, comparisonTab,
    savedTab, operationPanel); })()`);
ui = ui.replaceAll('Acabamento excluído.', 'Acabamento incluído nas linhas de cálculo; preencha os dados em falta.');
ui = ui.replaceAll('Sem acabamentos, margem ou IVA.', 'Operações e materiais discriminados; sem IVA.');
ui = ui.replaceAll('Sem acabamentos/margem/IVA.', 'Operações e materiais discriminados; sem IVA.');
ui = ui.replaceAll('Substituir escolha', 'Guardar nova versão');
ui = ui.replaceAll('substituir escolha', 'guardar nova versão');
// Each script is generated from readable sources; the existing PHP markup stays intact.
const scriptStart = php.lastIndexOf('<script>');
const scriptEnd = php.indexOf('</script>', scriptStart);
if (scriptStart < 0 || scriptEnd < 0) throw new Error('Configurator script boundary not found');
const asset = moduleAsset + ';\n' + minify(ui);
const assetHash = createHash('sha256').update(asset).digest('hex');
const assetName = 'vd-component-operation-costing-' + assetHash.slice(0, 12) + '.js';
fs.writeFileSync(path.join(base, 'costing-asset.js'), asset);
fs.writeFileSync(path.join(base, 'costing-asset.json'), JSON.stringify({ name: assetName, hash: assetHash }));
php = php.slice(0, scriptStart) + '<script src="js/' + assetName + '"></script>' + php.slice(scriptEnd + 9);
php = replace(php, '<?php\n', '<?php\ntry {\n');
php += '\n} catch (\\Throwable $vdReportError) { error_log("VD costing report: " . get_class($vdReportError) . ": " . $vdReportError->getMessage()); echo \'<div class="alert alert-danger">Não foi possível carregar os dados de cálculo. Nenhuma gravação foi efetuada; consulte o registo técnico.</div>\'; }\n';
fs.writeFileSync(path.join(base, 'costing-configurator.php'), php);

let engine = read('live-simulation-engine.js');
engine = replace(engine,
    'function Y(e, t, a, r, i, o, n) { if (e.replaceChildren(r("h3", "Comparativo · planos dinâmicos")), e.append(r("p", "Guardar exige confirmar o plano, pinças, viragem e corte. Não altera catálogo nem aprova uma OP. TESTE — A REVER. Maior capacidade não garante menor custo. Digital: planos e tempo de acerto apenas na primeira tiragem. Offset: acerto por montagem; um acerto CTP no total. Rubricas: permite excedentes para reduzir o custo total, nunca quantidades inferiores ao pedido. Sem teto percentual. Sem acabamentos, margem ou IVA. Pesquisa limitada, ótimo global não garantido.", "alert alert-warning")), t.errors.length',
    'function Y(e, t, a, r, i, o, n) { if (e.replaceChildren(), t.errors.length');
engine = replace(engine, 'r.addEventListener("change", () => { if (N().length > 3)\n        return r.checked = !1, void (b.textContent = "Máximo de 3 formatos. Desmarca outro para mudar."); b.textContent = "", f.replaceChildren(n("p", "Formatos alterados. Volta a calcular os planos possíveis.", "cb-note")); })',
    'r.addEventListener("change", () => { if (N().length > 3)\n        return r.checked = !1, void (b.textContent = "Máximo de 3 formatos. Desmarca outro para mudar."); b.textContent = "", h.click(); })');
engine = replace(engine, 'const P = () => f.replaceChildren(n("p", "Seleção alterada. Volta a calcular os planos possíveis.", "cb-note")); r.addEventListener("change", x), i?.addEventListener("change", P), x(), h.addEventListener("click", () => { f.replaceChildren(); try {',
    'r.addEventListener("change", () => { x(); h.click(); }), i?.addEventListener("change", () => h.click()), x(), h.hidden = !0, h.addEventListener("click", () => { f.replaceChildren(); document.querySelector("#cb-dynamic-plan-results")?.replaceChildren(); try {');
engine = replace(engine, 'y.addEventListener("change", () => f.replaceChildren(n("p", "Produção digital alterada. Volta a calcular os planos possíveis.", "cb-note")))',
    'y.addEventListener("change", () => h.click())');
engine = replace(engine, 's.addEventListener("input", () => f.replaceChildren(n("p", "Limites alterados. Volta a calcular os planos possíveis.", "cb-note")))',
    's.addEventListener("input", () => h.click())');
engine = replace(engine, 'e.style.borderColor = I.has(e.dataset.planKey) ? "#007c91" : "#c8d0d8"; }, L = () => _.replaceChildren(n("p", "Isolamento alterado. Volta a comparar os custos das sugestões.", "cb-note"));',
    'e.style.borderColor = I.has(e.dataset.planKey) ? "#007c91" : "#c8d0d8"; queueMicrotask(() => j.click()); }, L = () => { _.replaceChildren(n("p", "A recalcular comparativo…", "cb-note")); queueMicrotask(() => j.click()); };');
engine = replace(engine, 'i.checked ? I.add(o) : I.delete(o), H(); })',
    'i.checked ? I.add(o) : I.delete(o), H(); })');
engine = replace(engine, 'const j = n("button", "Comparar custos das sugestões", "btn btn-primary");\n    j.type = "button", j.addEventListener',
    'const j = n("button", "Comparar custos das sugestões", "btn btn-primary");\n    j.type = "button", j.hidden = !0, j.addEventListener');
engine = replace(engine, 'f.append(n("p", "Azul: peças ao corte. Verde: área útil assumida centrada. Laranja: pinça. Tracejado: divisão de viragem. Espaçamento e sangria ficam fora dos retângulos azuis. Primeiro, o plano com mais exemplares de cada máquina (Versafire, PrintMaster, SpeedMaster); depois as restantes opções.", "cb-note"), C, M, j, _);',
    '_.id = "cb-dynamic-plan-results"; f.append(n("p", "Azul: peças ao corte. Verde: área útil assumida centrada. Laranja: pinça. Tracejado: divisão de viragem. Espaçamento e sangria ficam fora dos retângulos azuis. Primeiro, o plano com mais exemplares de cada máquina (Versafire, PrintMaster, SpeedMaster); depois as restantes opções.", "cb-note"), C, M, j, _);');
engine = engine.replaceAll('maxPrice', 'latestPrice');
const purchaseSort = '(Number(a.purchase_date) || 0) - (Number(e.purchase_date) || 0) || (Number(a.purchase_id) || 0) - (Number(e.purchase_id) || 0)';
engine = replace(engine, '(t(a.price) ?? -1 / 0) - (t(e.price) ?? -1 / 0) || Number(e.id) - Number(a.id)',
    purchaseSort + ' || Number(e.id) - Number(a.id)');
engine = replace(engine, '(t(a.price) ?? -1 / 0) - (t(e.price) ?? -1 / 0)',
    purchaseSort);
engine = replace(engine, 'function s(e, a) { const r = [];', `function s(e, a) {
if (a?.ctpResources) {
    const speed = t(a.speed), setup = t(a.setup_minutes);
    const hours = speed > 0 && setup !== null && setup >= 0 ? e / speed + setup / 60 : null;
    const execution = window.vdOperationCosting.ctpExecution(a.ctpResources, e, hours);
    if (["3647", "3655"].includes(String(a.status))) execution.errors.push("CTP: operação Obsoleta ou Anulada.");
    return { hours, total: execution.errors.length ? null : execution.cost, errors: execution.errors, execution };
}
const r = [];`);
engine = replace(engine, '"Total estimado", "Estado"',
    '"Venda total estimada", "Custo/un. venda", "Venda/un. venda", "Estado"');
engine = replace(engine, 'l.append(r("td", String(a)));\n    const p =',
    `l.append(r("td", String(a)));
    const unitPrices = window.vdOperationCosting.unitPrices(e.allOperations);
    l.append(r("td", window.vdOperationCosting.unitMoney(unitPrices.cost)),
        r("td", window.vdOperationCosting.unitMoney(unitPrices.sale)));
    const p =`);
engine = replace(engine, 'positionsPerPlan: p = t(r.poses) }) { const c = [], u =',
    `positionsPerPlan: p = t(r.poses) }) {
const setupWaste = window.vdOperationCosting.setupWaste(s?.setupWasteOperations || [], i?.id, n);
n = setupWaste.plans;
const c = [...setupWaste.errors], u =`);
engine = replace(engine, 'paper: A, perSheet: u, goodPlans: w',
    'paper: A, setupWaste, perSheet: u, goodPlans: w');
engine = replace(engine, 'waste: i.waste, component: s }); return',
    'waste: i.waste, component: r ? s : { ...s, setupWasteOperations: [] } }); return');
engine = replace(engine, 'p = Math.max(l.waste, r.values.waste ?? NaN)',
    `p = Math.max(
        window.vdOperationCosting.setupWaste(l.component?.setupWasteOperations || [], l.machine?.id, l.waste).plans ?? NaN,
        window.vdOperationCosting.setupWaste(l.component?.setupWasteOperations || [], i?.id, r.values.waste).plans ?? NaN)`);
engine = replace(engine, 'Acerto inclui F/V.', 'Acerto = maior número de planos das operações usadas (não soma), por forma/montagem; inclui F/V.');
engine = replace(engine, '} return { results: B(c), errors: [...new Set(u)] }; } function B(e)', `}
const costing = window.vdOperationCosting.get(r.id);
for (const row of c) {
    const selection = U(row, { data: a, component: r, paperId: i[0]?.internal_id, rubrics: n, materials: [] });
    costing.apply(selection);
    row.allOperations = selection.allOperations;
    row.costingRevision = selection.parameters.costingRevision;
    row.total = selection.total;
    row.errors = selection.errors;
}
return { results: B(c), errors: [...new Set(u)] }; } function B(e)`);
engine = replace(engine, 'a = (e, t) => Math.round(100 * e.total) - Math.round(100 * t.total)',
    'a = (e, t) => Number(e.errors?.length > 0) - Number(t.errors?.length > 0) || Math.round(100 * (e.total ?? Infinity)) - Math.round(100 * (t.total ?? Infinity))');
engine = replace(engine, 'errors: [], total: e.total, parameters:', 'errors: e.errors || [], total: e.total, allOperations: e.allOperations, parameters:');
engine = replace(engine, 'version: 2, source: "dynamic"', 'version: 2, allOperations: e.allOperations, costingRevision: e.costingRevision, source: "dynamic"');
engine = replace(engine, 'unit: e.total / e.quantity, mountings:', 'unit: e.total === null ? null : e.total / e.quantity, mountings:');
engine = replace(engine, 'new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(e.total)', 'window.vdOperationCosting.money(e.total)');
engine = replace(engine, 'p.append(c), l.append(p), s.body.append(l)',
    'p.append(c, window.vdOperationCosting.get(n.component.id).detail(U(e, { ...n, rubrics: a }))), l.append(p), s.body.append(l)');
engine = replace(engine, '(e.bestForQuantity ? " · Melhor opção" : "")', '(e.bestForQuantity && !e.errors.length ? " · Melhor completa" : "")');
engine = engine.replaceAll('Sem acabamentos, margem ou IVA.', 'Operações e materiais discriminados; sem IVA.');
engine = engine.replaceAll('Sem acabamentos/margem/IVA.', 'Operações e materiais discriminados; sem IVA.');
engine = engine.replaceAll('preço de venda €/h em falta.', 'custo de referência €/h em falta.');
engine = replace(engine, '["hourly", "Venda €/h", "Preço de Venda", !1, !1]',
    '["hourly", "Custo de referência €/h", "Custo unitário de referência", !1, !1]');
engine = replace(engine,
    `const a = r("tr"), p = r("td"), c = r("details"), u = r("pre", s.details);
    u.style.whiteSpace = "pre-wrap", c.append(r("summary", "Consultar geometria, montagens e preços"), u), p.append(c);
    try {
        const e = JSON.parse(s.details).calculation;
        if ("dynamic" === e.source) {
            for (const [t, a] of e.parts.entries())
                c.append(r("h4", "Montagem " + (t + 1)), V({ ...a.plan.suggestion, machineName: e.machine.name }, e.component, { 3747: "Frente", 3748: "Frente e Verso", 3749: "Tira e Retira", 3750: "Cambalhota" }[a.modeId], r));
            "rubrics" === e.basis && c.append(M([{ machine: e.machine, parts: e.parts, digitalSeparate: e.digitalSeparate }], e.rubrics, { 3747: "Frente", 3748: "Frente e Verso", 3749: "Tira e Retira", 3750: "Cambalhota" }, r, i));
        }
        const t = [...new Set([l(e, o, {}), ...(e.parts || []).map(e => l(e, o, {}))].flat())];
        t.length && p.append(r("p", "Preços alterados: " + t.join("; ") + ". Histórico mantido; recalcula para substituir.", "alert alert-warning"));
    }
    catch (e) {
        p.append(r("p", "Não foi possível verificar os preços do histórico: " + e.message, "text-danger")), console.error("Histórico dinâmico", e);
    }`,
    `const a=r("tr"),p=r("td"),dialog=r("dialog"),open=r("button","Consultar geometria, montagens e preços","btn btn-default");
dialog.className="cb-saved-quotation-dialog";
dialog.setAttribute("aria-label","Geometria, montagens e preços configurados");
dialog.style.cssText="width:min(1100px,94vw);max-height:90vh;overflow:auto;border:1px solid #8794a2;border-radius:6px;padding:20px;overscroll-behavior:contain";
const close=r("button","Fechar","btn btn-default");
close.type="button";close.style.cssText="float:right;margin:0 0 12px 12px";
close.addEventListener("click",()=>dialog.close());
open.type="button";open.setAttribute("aria-haspopup","dialog");
open.addEventListener("click",()=>{if(!dialog.open)dialog.showModal()});
dialog.addEventListener("click",event=>{if(event.target===dialog){const bounds=dialog.getBoundingClientRect();if(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom)dialog.close()}});
const money=value=>value===null||value===undefined||!Number.isFinite(Number(value))?"Por completar":new Intl.NumberFormat("pt-PT",{style:"currency",currency:"EUR"}).format(Number(value));
const amount=value=>value===null||value===undefined||!Number.isFinite(Number(value))?"—":new Intl.NumberFormat("pt-PT",{maximumFractionDigits:4}).format(Number(value));
const addTable=(heading,headers,rows)=>{dialog.append(r("h3",heading));const table=i(headers);table.node.className="table table-bordered cb-saved-data-table";for(const values of rows){const tr=r("tr");values.forEach(value=>tr.append(r("td",String(value??"—"))));table.body.append(tr)}dialog.append(table.node)};
dialog.append(close,r("h2","Geometria, montagens e preços · "+s.selected_quantity+" unidades"));
try{
const saved=JSON.parse(s.details).calculation;
if(!saved||typeof saved!=="object")throw new Error("Instantâneo sem dados de cálculo.");
const geometryRows=[["Produto",saved.component?.width&&saved.component?.height?saved.component.width+" × "+saved.component.height+" mm":"—"],["Máquina",saved.machine?.name||"—"],["Quantidade",amount(saved.quantity)],["Montagens",amount(saved.results?.mountings)],["Folhas",amount(saved.results?.sheets)],["Chapas",amount(saved.results?.plates)],["Tempo estimado",saved.results?.hours===null||saved.results?.hours===undefined?"—":amount(saved.results.hours)+" h"],["Custo total",money(saved.allOperations?.cost??saved.results?.total)],["Venda total",money(saved.allOperations?.sale)]];
addTable("Resumo configurado",["Parâmetro","Valor"],geometryRows);
if(saved.source==="dynamic"&&Array.isArray(saved.parts)){
for(const[index,part]of saved.parts.entries()){
dialog.append(r("h3","Montagem "+(index+1)));
const suggestion=part.plan?.suggestion;
if(suggestion)dialog.append(R({...suggestion,machineName:saved.machine?.name||""},saved.component));
const paper=part.paperCalculation||{},plan=part.plan||{},allocated=Array.isArray(part.allocation)?part.allocation.map((value,index)=>value?value+" posições → "+(part.produced?.[index]??"—")+" un.":"—").join(" · "):"—";
addTable("Geometria e produção",["Configuração","Valor"],[["Modo",({3747:"Frente",3748:"Frente e Verso",3749:"Tira e Retira",3750:"Cambalhota"})[part.modeId]||"—"],["Formato do produto",plan.component_width&&plan.component_height?plan.component_width+" × "+plan.component_height+" mm":"—"],["Formato do plano",suggestion?.planWidth&&suggestion?.planHeight?suggestion.planWidth+" × "+suggestion.planHeight+" mm":"—"],["Formato da folha",paper.paper?.width&&paper.paper?.height?paper.paper.width+" × "+paper.paper.height+" mm":"—"],["Exemplares no plano",amount(suggestion?.poses??plan.poses)],["Grelha",suggestion?.columns&&suggestion?.rows?suggestion.columns+" × "+suggestion.rows:"—"],["Intervalo / sangria",suggestion?.gap!==undefined?suggestion.gap+" mm / "+(suggestion.bleed??"—")+" mm":"—"],["Posições e produção",allocated],["Planos úteis + acerto",amount(part.runs)+" + "+amount(paper.wastePlans)],["Folhas",amount(paper.sheets)],["Chapas",amount(part.result?.plates)]]);
if(suggestion)dialog.append(r("p","Folha "+amount(suggestion.sheetWidth)+" × "+amount(suggestion.sheetHeight)+" mm · "+amount(suggestion.perSheet)+" plano(s)/folha · pinça "+amount(suggestion.gripper)+" mm.","cb-note"));
}
if(saved.basis==="rubrics"&&Array.isArray(saved.rubrics))dialog.append(M([{machine:saved.machine,parts:saved.parts,digitalSeparate:saved.digitalSeparate}],saved.rubrics,{3747:"Frente",3748:"Frente e Verso",3749:"Tira e Retira",3750:"Cambalhota"},r,i).node);
}
const ledger=saved.allOperations;
if(Array.isArray(ledger?.lines)){
const priceRows=ledger.lines.map(line=>[line.name,amount(line.quantity)+" "+(line.unit||""),line.hours===null||line.hours===undefined?"—":amount(line.hours)+" h",money(line.cost),money(line.sale)]);
addTable("Discriminação de custos e preços",["Operação / material","Quantidade","Tempo","Custo","Venda"],priceRows);
if(ledger.errors?.length)dialog.append(r("p",ledger.errors.join(" · "),"alert alert-warning"));
}
const changed=[...new Set([l(saved,o,{}),...(saved.parts||[]).map(part=>l(part,o,{}))].flat())];
if(changed.length)dialog.append(r("p","Preços alterados desde esta gravação: "+changed.join("; ")+". Histórico mantido; recalcule para atualizar.","alert alert-warning"));
if(!Array.isArray(ledger?.lines))dialog.append(r("p","Este instantâneo não contém discriminação de preços por linha.","alert alert-info"));
}catch(error){dialog.append(r("p","Não foi possível apresentar os dados configurados: "+error.message,"alert alert-danger"));console.error("Instantâneo de geometria e preços",error)}
p.append(open,dialog);`);
let enginePhp = read('live-simulation-engine.php');
const engineStart = enginePhp.indexOf('<script>') + '<script>'.length;
const engineEnd = enginePhp.indexOf('</script>', engineStart);
if (engineStart < '<script>'.length || engineEnd < 0) throw new Error('Engine script boundary not found');
enginePhp = enginePhp.slice(0, engineStart) + '\n' + minify(engine) + '\n' + enginePhp.slice(engineEnd);
fs.writeFileSync(path.join(base, 'costing-simulation-engine.php'), enginePhp);
fs.writeFileSync(path.join(base, 'costing-configurator.js'), ui);
fs.writeFileSync(path.join(base, 'costing-simulation-engine.js'), engine);
console.log('Built costing report blocks from readable sources:', Buffer.byteLength(php), Buffer.byteLength(enginePhp), 'bytes.');

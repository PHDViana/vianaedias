const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const base = __dirname;
const hash = content => createHash('sha256').update(content).digest('hex');
const read = name => fs.readFileSync(path.join(base, name), 'utf8');
const previous = fs.existsSync(path.join(base, 'workflow-manifest.json')) ?
    JSON.parse(read('workflow-manifest.json')).files : {};
const files = {};
const dependencyBase = read('native-dependencies-baseline.php').replace(/^<\?php\n/, '');
const dependencyAnchor = 'FROM app_entity_276 WHERE parent_item_id={$componentId} ORDER BY id';
if (dependencyBase.split(dependencyAnchor).length !== 2) throw new Error('Native dependency query changed');
const dependencyCurrent = dependencyBase.replace(dependencyAnchor,
    'FROM app_entity_276 WHERE parent_item_id={$componentId} AND vd_workflow_version IS NULL ORDER BY id')
    .replace("'plates' => true,", "'plates' => $flags['plates'] ?? true,")
    .replace("AND field_4287={$operation} AND field_6179 IN ('1','true') AND id<>{$ignoreItemId}",
        "AND field_4287={$operation} AND vd_workflow_version IS NULL AND field_6179 IN ('1','true') AND id<>{$ignoreItemId}");
const dependencyPrior = dependencyCurrent.replace("'plates' => $flags['plates'] ?? true,", "'plates' => true,");
const dependencyCode = dependencyCurrent;
fs.writeFileSync(path.join(base, 'native-dependencies-workflow.php'), '<?php\n' + dependencyCode);
for (const name of ['quote-workflow-domain.php', 'quote-workflow-store.php', 'quote-workflow-discovery.php', 'quote-operation-dependencies.php']) {
    files['includes/vd_quote_workflow/' + name] = { source: name, hash: hash(read(name)) };
}
for (const [source, target] of [['quote-workflow-action.php', 'modules/items/actions/vd_quote_workflow.php'],
    ['quote-workflow-view.php', 'modules/items/views/vd_quote_workflow.php'],
    ['quote-workflow-ui.js', 'js/vd-quote-workflow.js']]) files[target] = { source, hash: hash(read(source)) };
const infoBase = read('native-info-baseline.php');
const info = infoBase + `\n<?php if (in_array((int) $current_entity_id, [146,262], true) && users::has_access_to_entity(146, 'view')): ?>
<div class="alert alert-info"><?= link_to_modalbox('Versões, aprovação e proposta comercial do orçamento', url_for('items/vd_quote_workflow', 'path=' . urlencode($current_path) . '&modal=1')) ?></div>
<?php endif; ?>\n`;
const infoWithConfigurationNote = info.replace(/\n$/, '') + `<?php if ((int) $current_entity_id === 276 && (int) ($parent_entity_item_id ?? 0) > 0): ?>
<div class="alert alert-info">As linhas manuais configuram a simulação; as operações de produção só são materializadas após a aprovação do orçamento.</div>
<?php endif; ?>\n`;
fs.writeFileSync(path.join(base, 'native-info-workflow.php'), infoWithConfigurationNote);
files['modules/items/views/info.php'] = { source: 'native-info-workflow.php', hash: hash(infoWithConfigurationNote), baseline: hash(infoBase) };
const itemsBase = read('native-items-baseline.php');
const anchor = 'switch ($app_module_action)';
if (itemsBase.split(anchor).length !== 2) throw new Error('Native mutation anchor changed');
const items = itemsBase.replace(anchor, `// Immutable workflow snapshots and projections are never edited by native single-item actions.
if ((int) $current_entity_id === 276 && in_array($app_module_action, ['save','delete'], true)) {
    $vdOperation = isset($_GET['id']) ? db_fetch_array(db_query("SELECT parent_item_id,vd_workflow_version,field_6179 FROM app_entity_276 WHERE id='" . db_input(_get::int('id')) . "'")) : false;
    $vdComponentId = (int) ($vdOperation['parent_item_id'] ?? $parent_entity_item_id ?? 0);
    $vdQuoteComponent = $vdComponentId ? db_fetch_array(db_query("SELECT c.id,q.field_2030 quote_state FROM app_entity_262 c INNER JOIN app_entity_146 q ON q.id=c.parent_item_id WHERE c.id=$vdComponentId")) : false;
    if ($vdQuoteComponent) {
        $vdProjected = $vdOperation && $vdOperation['vd_workflow_version'] !== null;
        $vdExistingAutomatic = $vdOperation && in_array((string) $vdOperation['field_6179'], ['1','true'], true);
        $vdSubmittedAutomatic = isset($_POST['fields'][6179]) && is_scalar($_POST['fields'][6179]) &&
            in_array((string) $_POST['fields'][6179], ['1','true'], true);
        $vdPreparing = in_array((string) $vdQuoteComponent['quote_state'], ['', '36', '3653', '3646', '3681'], true);
        if (!$vdPreparing || $vdProjected || $vdExistingAutomatic || $vdSubmittedAutomatic) {
            http_response_code(409);
            die('Só é possível editar configurações manuais de operação enquanto o orçamento está em preparação. Operações automáticas e aprovadas são imutáveis.');
        }
    }
}
if (isset($_GET['id']) && in_array((int) $current_entity_id, [388,276,279], true) &&
    in_array($app_module_action, ['save','delete'], true)) {
    $vdId = (int) $_GET['id'];
    $vdQuery = $current_entity_id == 388 ?
        "SELECT id FROM app_vd_quote_versions WHERE native_id=$vdId" :
        "SELECT id FROM app_vd_quote_projection WHERE entity_id=$current_entity_id AND native_id=$vdId";
    if (db_fetch_array(db_query($vdQuery))) {
        http_response_code(409);
        die('Instantâneo imutável: crie uma nova versão no configurador.');
    }
}
${anchor}`);
fs.writeFileSync(path.join(base, 'native-items-workflow.php'), items);
files['modules/items/actions/items.php'] = { source: 'native-items-workflow.php', hash: hash(items), baseline: hash(itemsBase) };
const listingBase = read('native-listing-baseline.php');
const listingAnchor = '//filter items by parent';
if (listingBase.split(listingAnchor).length !== 2) throw new Error('Native listing filter anchor changed');
const listing = listingBase.replace(listingAnchor, `${listingAnchor}
if ($current_entity_id == 276 && $parent_entity_item_id > 0) {
    $listing_sql_query .= " and (
        not exists (
            select 1 from app_vd_quote_control workflow_control
            where workflow_control.quote_id = (
                select workflow_component.parent_item_id from app_entity_262 workflow_component
                where workflow_component.id=e.parent_item_id
            )
        )
        or (
            e.vd_workflow_version is not null
            and exists (
                select 1 from app_vd_quote_versions workflow_version
                inner join app_vd_quote_documents workflow_document
                    on workflow_document.quote_id=workflow_version.quote_id
                    and workflow_document.state='approved'
                where workflow_version.id=e.vd_workflow_version
                    and workflow_version.component_id=e.parent_item_id
                    and JSON_CONTAINS(workflow_document.snapshot, JSON_ARRAY(workflow_version.id), '$.version_ids')
            )
        )
        or (
            e.vd_workflow_version is null
            and COALESCE(e.field_6179,'0') not in ('1','true')
        )
    )";
}`);
fs.writeFileSync(path.join(base, 'native-listing-workflow.php'), listing);
files['modules/items/actions/listing.php'] = { source: 'native-listing-workflow.php', hash: hash(listing), baseline: hash(listingBase) };
for (const [local, source, target] of [
    ['customdevs/product-request-import/product-configurator.php', '../../_CustomDEVs/product-request-import/product-configurator.php', 'includes/vd_quote_workflow/product-configurator.php'],
    ['customdevs/product-request-import/product-configurator-native.php', '../../_CustomDEVs/product-request-import/product-configurator-native.php', 'includes/vd_quote_workflow/product-configurator-native.php'],
    ['customdevs/product-request-import/product-configurator-queries.php', '../../_CustomDEVs/product-request-import/product-configurator-queries.php', 'includes/vd_quote_workflow/product-configurator-queries.php'],
    ['customdevs/product-request-import/product-configurator-view.php', '../../_CustomDEVs/product-request-import/product-configurator-view.php', 'includes/vd_quote_workflow/product-configurator-view.php'],
    ['customdevs/product-request-import/product-configurator-action.php', '../../_CustomDEVs/product-request-import/product-configurator-action.php', 'modules/items/actions/vd_product_configurator.php'],
    ['customdevs/product-request-import/product-configurator-ui.js', '../../_CustomDEVs/product-request-import/product-configurator-ui.js', 'js/vd-product-configurator.js'],
    ['customdevs/product-request-import/product-request.css', '../../_CustomDEVs/product-request-import/product-request.css', 'js/vd-product-request.css'],
    ['customdevs/product-request-import/product-request-import.php', '../../_CustomDEVs/product-request-import/product-request-import.php', '_CustomDEVs/product-request-import/product-request-import.php'],
    ['customdevs/product-request-import/.htaccess', '../../_CustomDEVs/product-request-import/.htaccess', '_CustomDEVs/product-request-import/.htaccess'],
    ['customdevs/product-request-import/deploy-product-request-import.php', '../../_CustomDEVs/product-request-import/deploy-product-request-import.php', '_CustomDEVs/product-request-import/deploy-product-request-import.php'],
    ['customdevs/product-request-import/SYSTEM-DEPENDENCIES.txt', '../../_CustomDEVs/product-request-import/SYSTEM-DEPENDENCIES.txt', '_CustomDEVs/product-request-import/SYSTEM-DEPENDENCIES.txt'],
    ['customdevs/product-request-import/product-request-import-test.php', '../../_CustomDEVs/product-request-import/product-request-import-test.php', '_CustomDEVs/product-request-import/product-request-import-test.php'],
    ['customdevs/product-request-import/product-request-import-integration-test.php', '../../_CustomDEVs/product-request-import/product-request-import-integration-test.php', '_CustomDEVs/product-request-import/product-request-import-integration-test.php'],
    ['customdevs/product-request-import/README.md', '../../_CustomDEVs/product-request-import/README.md', '_CustomDEVs/product-request-import/README.md'],
    ['customdevs/product-request-import/build-quote-workflow.cjs', '../../_CustomDEVs/product-request-import/build-quote-workflow.cjs', '_CustomDEVs/product-request-import/build-quote-workflow.cjs']
]) {
    files[target] = { source, hash: hash(read(local)) };
}
for (const [target, entry] of Object.entries(previous)) {
    if (!files[target]) files[target] = entry;
}
for (const [target, entry] of Object.entries(files)) {
    if (previous[target] && previous[target].hash !== entry.hash) entry.baseline = previous[target].hash;
    else if (previous[target]?.baseline && !entry.baseline) entry.baseline = previous[target].baseline;
}
fs.writeFileSync(path.join(base, 'workflow-manifest.json'), JSON.stringify({ files,
    custom_php: { 5: { source: 'native-dependencies-workflow.php', baseline: hash(dependencyPrior),
        hash: hash(dependencyCode) } } }, null, 2));

const fs = require('node:fs');
const path = require('node:path');
const base = __dirname;
const read = file => fs.readFileSync(path.join(base, file), 'utf8');
const php = read('costing-configurator.php');
const start = php.indexOf('$simulationQueries = [');
const end = php.indexOf('\n];', start);
if (start < 0 || end < 0) throw new Error('Costing query boundary missing');
fs.writeFileSync(path.join(base, 'product-configurator-queries.php'),
    '<?php\nfunction vd_config_costing_queries($componentId) {\n' + php.slice(start, end + 3) + '\nreturn $simulationQueries;\n}\n');
const ui = read('costing-configurator.js');
const marker = ui.indexOf('function Se(');
if (marker < 0 || !ui.startsWith('!async function () {')) throw new Error('Costing helpers boundary changed');
const helpers = ui.slice('!async function () {'.length, marker);
fs.writeFileSync(path.join(base, 'product-configurator-print.js'), `
window.vdProductPrintEditor = async function(data, materials, saved, persist) {
${helpers}
{
const G = data;
G.ctp_resources = {equipment:G.ctp_equipment,labor:G.ctp_labor,operators:G.ctp_operators};
G.imposition_resources = {operation:G.imposition_operation[0],equipment:G.imposition_equipment,
    labor:G.imposition_labor,operators:G.imposition_operators,papers:G.imposition_papers,
    machine:(() => {const m=G.machines.find(m=>Number(m.id)===56);return m?{id:m.id,width:m.max_width,height:m.max_height,print_width:m.print_width,print_height:m.print_height}:null;})()};
G.operations = G.operations.filter(o=>Number(o.id)!==43);
G.price_operations = G.price_operations.filter(o=>Number(o.id)!==43);
G.component[0].setupWasteOperations = G.costing_operations.map(o=>({operation_id:o.operation_id,name:o.name,machines:o.machines,waste:o.waste}));
for(const op of [...G.operations,...G.price_operations]) {
    const tariff=window.vdOperationCosting.currentTariff(G,op.id);
    op.hourly=!tariff.error&&tariff.basis==='h'?tariff.sale:null;
    op.cost_hourly=!tariff.error&&tariff.basis==='h'?tariff.cost:null;
    if(Number(op.id)===3)op.ctpResources=G.ctp_resources;
}
const panel=r('div');
const costing=window.vdOperationCosting.create(G,materials,saved,panel);
const persistSelection=costing.save=async selection=>{
    costing.check(selection);
    const result=await persist(selection);
    saved.set(String(selection.quantityId),result);
    costing.renderSaved();
    return result;
};
const comparison=r('div');
e.append(ye(G,{},materials,comparison,saved,persistSelection),comparison,panel);
return costing;
}
};
`);
fs.copyFileSync(path.join(base,'component-operation-costing.js'),path.join(base,'product-configurator-costing.js'));
fs.copyFileSync(path.join(base,'costing-simulation-engine.js'),path.join(base,'product-configurator-simulation.js'));
const css = php.match(/<style>([\s\S]*?)<\/style>/);
if (!css) throw new Error('Costing styles missing');
fs.writeFileSync(path.join(base,'product-configurator.css'),css[1]);
console.log('Reused costing queries, print simulator, operation costing and styles.');

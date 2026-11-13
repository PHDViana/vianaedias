const { test } = require('node:test');
const assert = require('node:assert/strict');
const costing = require('./component-operation-costing.js');
const clone = value => JSON.parse(JSON.stringify(value));
test('machine crease includes setup plans once across mountings without changing other operations', () => {
    const snapshot={quantity:4500,component:{folding_count:3},results:{goodPlans:1500},
        paperCalculation:{goodPlans:1500,wastePlans:150}};
    assert.equal(costing.automaticQuantity('folhas/h',snapshot,48),1650);
    assert.equal(costing.automaticQuantity('folhas/h',snapshot,18),1500);
    assert.equal(costing.automaticQuantity('folhas/h',snapshot),1500);
    for(const key of ['parts','pagination']) {
        const parts=[{paperCalculation:{goodPlans:1000,wastePlans:100}},
            {paperCalculation:{goodPlans:500,wastePlans:50}}];
        const multi={...snapshot,[key]:key==='parts'?parts:{parts}};
        assert.equal(costing.automaticQuantity('folhas/h',multi,48),1650);
        parts[1].paperCalculation.wastePlans=0;
        assert.equal(costing.automaticQuantity('folhas/h',multi,48),1600);
        delete parts[1].paperCalculation.wastePlans;
        assert.equal(costing.automaticQuantity('folhas/h',multi,48),null);
    }
    for(const paperCalculation of [undefined,{goodPlans:1500},{goodPlans:1500,wastePlans:-1}]) {
        assert.equal(costing.automaticQuantity('folhas/h',{...snapshot,paperCalculation},48),null);
    }
});
test('detail subtotals preserve duration columns, editors, grouping and totals in both pricing modes', () => {
    const elements = [];
    function element(tag) {
        const node = {tag, children:[], cells:[], rows:[], style:{}, dataset:{}, listeners:{},
            validity:{badInput:false}, classList:{add(){}}, textContent:'',
            append(...items){this.children.push(...items);if(this.tag==='tr')this.cells.push(...items);},
            prepend(...items){this.children.unshift(...items);},
            querySelectorAll(){return this.children.filter(item=>item.tag==='input');},
            setAttribute(){}, addEventListener(name,fn){this.listeners[name]=fn;},
            insertCell(){const cell=element('td');this.cells.push(cell);return cell;},
            insertRow(index=-1){const row=element('tr');this.rows.splice(index<0?this.rows.length:index,0,row);return row;},
            createTHead(){return this.head=element('thead');},
            createTBody(){return this.body=element('tbody');},
            createTFoot(){return this.foot=element('tfoot');}
        };
        if(tag==='table') {
            Object.defineProperties(node,{
                tBodies:{get:()=>[node.body]},tHead:{get:()=>node.head},tFoot:{get:()=>node.foot}
            });
            Object.defineProperty(node,'rows',{get:()=>[...(node.head?.rows||[]),...(node.body?.rows||[]),...(node.foot?.rows||[])]});
            node.querySelectorAll=()=>elements.filter(item=>item.tag==='input'&&item.dataset.lineCost);
        }
        elements.push(node);
        return node;
    }
    const previous = global.document;
    global.document = {createElement:element};
    try {
        for (const costPlus of [false,true]) {
            elements.length=0;
            const ledger = {quantity:1,cost:10,sale:20,complete:true,errors:[],
                pricing:{method:costPlus?'cost-plus':'legacy',markup:100,increase:10},
                lines:[null,0,1.25,25.5].map((hours,index)=>({key:'material:'+index,
                    name:'Test '+index,quantity:1,unit:'un.',hours,cost:2.5,sale:5}))};
            const before=JSON.stringify(ledger), changes=[];
            const section=costing.detail(ledger,{overrides:{},
                changeCost:(...args)=>changes.push(['cost',...args]),
                change:(...args)=>changes.push(['markup',...args])});
            const table=elements.find(node=>node.tag==='table');
            const headers=table.head.rows[0].children.map(node=>node.textContent);
            assert.deepEqual(headers.slice(2,4),['Tempo (h)','Tempo (hh:mm:ss)']);
            assert.equal(headers.length,costPlus?9:7);
            assert.deepEqual(table.body.rows.filter(row=>row.dataset.costLine).map(row=>row.cells[3].textContent),
                ['—','00:00:00','01:15:00','25:30:00']);
            assert.equal(table.body.rows.filter(row=>row.dataset.costSubtotal!==undefined).length,2);
            assert.equal(table.foot.rows[0].cells.length,headers.length);
            assert.equal(table.foot.rows[0].cells[3].textContent,'');
            assert.equal(table.head.rows[0].cells[3].style.width,'86px');
            for(const cell of table.foot.rows[0].cells) {
                assert.equal(cell.style.fontWeight,'bold');
                assert.equal(cell.style.color,'#17365d');
            }
            if(costPlus) {
                for(const row of table.rows) {
                    for(const index of [5,6,8]) assert.equal(row.cells[index].style.textAlign,'right');
                }
                for(const input of table.querySelectorAll('input[data-line-cost]')) {
                    assert.equal(input.style.textAlign,'right');
                }
            }
            for (const row of table.body.rows) {
                assert.equal(row.cells.length,headers.length);
                if (!costPlus || !row.dataset.costLine) continue;
                for (const [index,type] of [[6,'cost'],[7,'markup']]) {
                    const input=row.cells[index].children[0];
                    assert.equal(input.tag,'input');
                    input.value='12';
                    input.listeners.change();
                    assert.deepEqual(changes.pop(),[type,row.dataset.costLine,'12',section]);
                }
            }
            assert.equal(JSON.stringify(ledger),before);
        }
        for (const costPlus of [false,true]) {
            for(const incomplete of [false,true]) {
                elements.length=0;
                const ledger={quantity:1,cost:5,sale:10,errors:[],complete:!incomplete,
                    pricing:{method:costPlus?'cost-plus':'legacy',markup:100,increase:5},
                    lines:[{key:'color:1',name:'Cyan',quantity:1,hours:null,cost:2,sale:4},
                        {key:'color:2',name:'Black',quantity:1,hours:null,cost:incomplete?null:3,sale:6}]};
                const before=JSON.stringify(ledger);
                costing.detail(ledger);
                const table=elements.find(node=>node.tag==='table');
                const group=table.body.rows.find(row=>row.dataset.inkGroup);
                const inks=table.body.rows.filter(row=>row.dataset.costLine?.startsWith('color:'));
                assert.equal(group.cells[costPlus?6:5].textContent,costing.money(incomplete?null:5));
                assert.equal(group.cells.at(-1).textContent,costing.money(10));
                const toggle=group.cells[0].children[0];
                assert.ok(inks.every(row=>row.style.display==='none'));
                toggle.listeners.click();
                assert.ok(inks.every(row=>row.style.display===''));
                toggle.listeners.click();
                assert.ok(inks.every(row=>row.style.display==='none'));
                assert.equal(table.body.rows.find(row=>row.dataset.costSubtotal==='0')
                    .cells.at(-1).textContent,costing.money(10));
                assert.equal(JSON.stringify(ledger),before);
            }
            const line = (key,cost,extra={}) => ({key,name:key,quantity:1,hours:0,cost,sale:cost*2,...extra});
            const ledger = {quantity:1,cost:93,sale:186,errors:[],complete:true,
                pricing:{method:costPlus?'cost-plus':'legacy',markup:100,increase:82},
                lines:[line('operation:12',11,{operationId:12}),line('operation:44',10,{operationId:44}),line('printing',7,{operationIds:[7]}),
                    line('clicks',8,{saleReference:999}),line('paper',10),line('plates',20,{calculatedCost:18}),
                    line('operation:3',3,{operationId:3,operationRow:3}),
                    line('operation:43',4,{operationId:43,operationRow:43}),
                    line('imposition:43:equipment',5,{operationId:43,parentOperationRow:43}),
                    line('imposition:43:paper',6,{operationId:43,parentOperationRow:43}),
                    line('packaging:10',9,{parentOperationRow:10})]};
            for (const incomplete of [false,true]) {
                if(incomplete) {
                    ledger.lines.find(line=>line.key==='paper').cost=null;
                    ledger.lines.find(line=>line.key==='clicks').sale=null;
                }
                elements.length=0;
                const before=JSON.stringify(ledger);
                costing.detail(ledger);
                const table=elements.find(node=>node.tag==='table'), rows=table.body.rows;
                const first=rows.find(row=>row.dataset.costSubtotal==='0');
                const second=rows.find(row=>row.dataset.costSubtotal==='1');
                assert.equal(rows[rows.indexOf(first)+1].dataset.costLine,'printing');
                assert.equal(rows[rows.indexOf(second)+1].dataset.costLine,'operation:44');
                assert.equal(rows[rows.indexOf(second)+2].dataset.costLine,'operation:12');
                assert.equal(first.cells[costPlus?6:5].textContent,costing.money(incomplete?null:57));
                assert.equal(first.cells[costPlus?8:6].textContent,costing.money(114));
                assert.equal(second.cells[costPlus?6:5].textContent,costing.money(15));
                assert.equal(second.cells[costPlus?8:6].textContent,costing.money(incomplete?null:30));
                if(costPlus) assert.equal(first.cells[5].textContent,costing.money(incomplete?null:55));
                assert.equal(table.foot.rows[0].cells.at(-1).textContent,costing.money(186));
                assert.equal(first.hidden,undefined);
                assert.equal(rows.find(row=>row.dataset.costLine==='imposition:43:equipment').hidden,true);
                assert.equal(JSON.stringify(ledger),before);
            }
        }
    } finally {
        if(previous===undefined) delete global.document;
        else global.document=previous;
    }
});
test('hour durations display rounded hh:mm:ss without wrapping after 24 hours', () => {
    for (const [hours, expected] of [[0,'00:00:00'],[1.25,'01:15:00'],[0.5,'00:30:00'],
        [1/3600,'00:00:01'],[59.6/3600,'00:01:00'],[3599.6/3600,'01:00:00'],
        [25.5,'25:30:00'],[100,'100:00:00'],[0.00001,'00:00:00']]) {
        assert.equal(costing.formatHours(hours),expected);
    }
    for (const hours of [undefined,null,'', '1.25',NaN,Infinity,-1,Number.MAX_VALUE]) {
        assert.equal(costing.formatHours(hours),'—');
    }
});
test('detail lines place plate production and imposition immediately after plates without changing the ledger', () => {
    const operation = (id, row = id) => ({key:'operation:'+row,operationId:id,operationRow:row,cost:id,sale:id*2});
    const ledger = {lines:[
        operation(12),operation(10),operation(21),operation(48),operation(9),
        {key:'printing',operationIds:[7],cost:50,sale:100},
        {key:'clicks',cost:5,sale:10},
        operation(3),operation(43),operation(1),operation(2),operation(44),
        {key:'plates',cost:4,sale:8},{key:'paper',cost:30,sale:60},
        {key:'color:1',cost:2,sale:4},
        {key:'packaging:10',parentOperationRow:10,cost:1,sale:2},
        {key:'lamination:13:front:1',parentOperationRow:13,cost:1,sale:2},
        {key:'adhesive:42',parentOperationRow:42,cost:1,sale:2},
        {key:'spiral-material:32',operationId:32,parentOperationRow:32,cost:1,sale:2},
        {key:'imposition:43:paper',operationId:43,parentOperationRow:43,cost:1,sale:2},
        {key:'imposition:43:equipment',operationId:43,parentOperationRow:43,cost:1,sale:2},
        {key:'material:7',cost:1,sale:2},
    ],sourceOperations:[{operation_id:3,requirement_type:'dependency',requirement_parents:[7]}]};
    const before = JSON.stringify(ledger);
    const ordered = costing.groupedLines(ledger);
    const materials = ['plates','paper','color:1','packaging:10','lamination:13:front:1','adhesive:42',
        'spiral-material:32','imposition:43:paper','material:7'];
    assert.deepEqual(ordered.slice(0,materials.length+3).map(row=>row.line.key),
        ['plates','operation:3','operation:43','imposition:43:equipment',...materials.slice(1)]);
    assert.ok(ordered.filter(row=>materials.includes(row.line.key)).every(row=>row.ancestors.length===0));
    assert.deepEqual(ordered.slice(materials.length+3).map(row=>row.line.key),
        ['operation:1','operation:2','printing',
            'clicks','operation:48','operation:21','operation:9','operation:10','operation:44','operation:12']);
    assert.deepEqual(ordered.find(row=>row.line.key==='clicks').ancestors,['printing']);
    assert.equal(JSON.stringify(ledger),before);
    assert.equal(new Set(ordered.map(row=>row.line.key)).size,ledger.lines.length);
    for (const row of ordered) assert.ok(ledger.lines.includes(row.line));
    const missing = {lines:[{key:'paper'},{key:'plates'},{key:'color:1'},{key:'ctp-missing'}]};
    assert.deepEqual(costing.groupedLines(missing).map(row=>row.line.key),
        ['paper','plates','ctp-missing','color:1']);
    const withoutPlates = {...ledger,lines:ledger.lines.filter(line=>line.key!=='plates')};
    assert.deepEqual(costing.groupedLines(withoutPlates).slice(materials.length-1,materials.length+3)
        .map(row=>row.line.key),['operation:43','imposition:43:equipment','operation:3','operation:1']);
});
test('flat leaflet final cutting uses geometry without treating folds as pagination', () => {
    const snapshot = cuttingSnapshot();
    snapshot.component = { ...snapshot.component, binding: 1545, flat_folding: true };
    const result = costing.cuttingCalculation(snapshot, 9);
    assert.deepEqual(result.errors, []);
    assert.ok(result.cuts > 0);
    delete snapshot.component.flat_folding;
    assert.ok(costing.cuttingCalculation(snapshot, 9).errors.length);
    snapshot.component.flat_folding = true;
    snapshot.component.binding = 1546;
    assert.ok(costing.cuttingCalculation(snapshot, 9).errors.length);
});
test('delivery quantity is explicit per component, never inferred from print quantity', () => {
    assert.equal(costing.automaticQuantity('entregas/h', {quantity:4500}), null);
    for (const deliveries of [0, -1, 1.5, '', 'invalid']) {
        assert.equal(costing.automaticQuantity('entregas/h', {component:{operation_quantities:{12:deliveries}}}), null);
    }
    assert.equal(costing.automaticQuantity('entregas/h', {component:{operation_quantities:{12:1}}}), 1);
});
test('Plotter A3 counts good and setup plans and uses plans per hour', () => {
    const snapshot = { quantity: 1092, paperCalculation: { goodPlans: 38, wastePlans: 2 } };
    assert.equal(costing.automaticQuantity('peças/h', snapshot, 30), 40);
    assert.equal(costing.automaticQuantity('peças/h', {
        parts: [{ paperCalculation: { goodPlans: 38, wastePlans: 2 } },
            { paperCalculation: { goodPlans: 4, wastePlans: 1 } }]
    }, 30), 45);
    assert.equal(costing.automaticQuantity('peças/h', {
        quantity: 1092, paperCalculation: { goodPlans: 38 }
    }, 30), null);

    const { data, snapshot: base } = fixture();
    data.costing_operations.push({ id: 130, operation_id: 30, name: 'Corte Plotter A3',
        equipment: '57', machines: '57', active: 'true' });
    data.costing_tariffs.push({ id: 30, operation_id: 30, basis: 'h', cost: 22, sale: 35, version: 'v1' });
    data.costing_equipment.push({ id: 30, operation_id: 30, machine_id: 57,
        speed: 60, setup: 10, unit: 'peças/h' });
    base.quantity = 1092;
    base.paperCalculation = { goodPlans: 38, wastePlans: 2 };
    const line = costing.calculate(data, base).lines.find(item => item.operationId === 30);
    assert.equal(line.quantity, 40);
    assert.equal(line.unit, 'planos');
    near(line.hours, 10 / 60 + 40 / 60);
    near(line.cost, (10 / 60 + 40 / 60) * 22);
});
test('creasing demonstration workload counts each fold on each copy', () => {
    assert.equal(costing.automaticQuantity('vincos/h', {quantity:4500,component:{folding_count:3}}), 13500);
    for (const folds of [undefined, 0, -1, 1.5, 'invalid']) {
        assert.equal(costing.automaticQuantity('vincos/h', {quantity:4500,component:{folding_count:folds}}), null);
    }
});
test('unit prices divide complete cost and sale by sales quantity, with independent missing values', () => {
    assert.deepEqual(costing.unitPrices({quantity: 140, cost: 280, sale: 420}), {cost: 2, sale: 3});
    assert.deepEqual(costing.unitPrices({quantity: 140, cost: 0, sale: null}), {cost: 0, sale: null});
    for (const quantity of [0, '', null, -1]) {
        assert.deepEqual(costing.unitPrices({quantity, cost: 280, sale: 420}), {cost: null, sale: null});
    }
    assert.equal(costing.unitMoney(null), 'Por completar');
    assert.match(costing.unitMoney(0.0012), /0,0012/);
});
test('final artwork costs one file per component and accepts equivalent selected equipment', () => {
    const { data, snapshot } = fixture();
    const operation = { id: 144, operation_id: 1, name: 'Artes finais', equipment: '11,16',
        unit: '', speed: '', setup: '', active: 'true' };
    data.costing_operations.push(operation);
    data.costing_equipment = [
        { id: 5, operation_id: 1, machine_id: 11, unit: 'ficheiros/h', speed: 2, setup: 20 },
        { id: 6, operation_id: 1, machine_id: 16, unit: 'ficheiros/h', speed: 2, setup: 20 }
    ];
    data.costing_tariffs.push({ id: 1, operation_id: 1, basis: 'h', cost: 22, sale: 35, version: '1' });
    const parameters = costing.production(data, operation);
    assert.equal(parameters.ambiguous, false);
    assert.equal(parameters.speed, 2);
    assert.equal(parameters.setup, 20);
    const ledger = costing.calculate(data, snapshot);
    const artwork = ledger.lines.find(line => line.operationId === 1);
    assert.equal(artwork.quantity, 1);
    assert.equal(artwork.unit, 'ficheiros');
    near(artwork.hours, 5 / 6);
    near(artwork.cost, 22 * 5 / 6);
    near(artwork.sale, 35 * 5 / 6);
    assert.match(artwork.source, /1 ficheiro de arte final por componente/);
    assert.equal(costing.automaticQuantity('ficheiros/h', snapshot), 1);
});
const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-8, `${actual} != ${expected}`);
function ctpResources() {
    return { equipment: [{ id: 1, cost: 15 }], labor: [
        { id: 1, operators: '1', people: 1, setup: 5, per_unit: 0.1 },
        { id: 2, operators: '16', people: 1, setup: 5, per_unit: 0.1 }
    ], operators: [{ id: 1, cost: 12.5 }, { id: 16, cost: 20 }] };
}
test('CTP hourly intervention uses exactly five minutes per equipment hour, no setup or per-plate labor', () => {
    const r = ctpResources();
    for (const row of r.labor) row.per_hour = 5;
    for (const hours of [0, 0.5766666666666667, 1, 2.5]) {
        const c = costing.ctpExecution(r, 4, hours);
        assert.deepEqual(c.errors, []);
        near(c.laborHours, hours * 5 / 60);
        near(c.cost, hours * 15 + hours * 5 / 60 * 20);
    }
    r.labor[0].per_hour = 61;
    assert.equal(costing.ctpExecution(r, 4, 1).cost, null);
});
function impositionResources() {
    return { operation: { id: 43, proof_click: 0.2 }, equipment: [
        { machine_id: 11, cost: 7, speed: 12, setup: 0 },
        { machine_id: 56, cost: 30, speed: 800, setup: 15 }
    ], labor: [{ operators: '5', people: 1 }], operators: [{ id: 5, cost: 12.5 }],
    papers: [{ id: 228, name: 'INASET 100 g', width: 700, height: 1000, weight: 100, unit: 3648, price: 0.10292 }],
    machine: { id: 56, width: 330, height: 487, print_width: 320, print_height: 477 } };
}
test('imposition uses five minutes per plan, one proof per mounting, and full source sheets for A3', () => {
    const r = impositionResources();
    const single = costing.impositionCalculation(r, { forms: 1, modeId: 3747, quantity: 100000 });
    assert.deepEqual(single.errors, []);
    near(single.hours, 5 / 60);
    near(single.cost, 19.5 * 5 / 60);
    assert.equal(single.plans, 1);
    assert.equal(single.faces, 1);
    assert.equal(single.sheets, 1);
    assert.equal(single.perSheet, 4);
    near(single.clickCost, 0.2);
    near(single.paperCost, 0.10292);
    near(single.proofCost, (15 / 60 + 1 / 800) * 30);
    const many = costing.impositionCalculation(r, { parts: [{ forms: 3, modeId: 3748 }, { forms: 2, modeId: 3747 }] });
    assert.deepEqual(many.errors, []);
    assert.equal(many.plans, 5);
    assert.equal(many.faces, 8);
    assert.equal(many.sheets, 2);
    near(many.hours, 25 / 60);
    near(many.clickCost, 1.6);
});
test('automatic duplex proofs charge two clicks on one paper sheet without setup', () => {
    const resources = impositionResources();
    resources.equipment[1].setup = 0;
    for (const modeId of [3748, 3749, 3750]) {
        const proof = costing.impositionCalculation(resources, { forms: 1, modeId });
        assert.deepEqual(proof.errors, []);
        assert.equal(proof.plans, 1);
        assert.equal(proof.faces, 2);
        assert.equal(proof.sheets, 1);
        near(proof.paperCost, 0.10292);
        near(proof.clickCost, 0.4);
        near(proof.proofHours, 2 / 800);
        near(proof.proofCost, 0.075);
    }
});
test('imposition does not substitute missing source costs, geometry or number of mountings', () => {
    for (const edit of [
        r => r.operation.proof_click = '',
        r => r.papers[0].price = '',
        r => r.operators[0].cost = '',
        r => r.equipment[0].speed = '',
        r => r.equipment[1].cost = '',
        r => r.machine.print_width = ''
    ]) {
        const r = impositionResources();
        edit(r);
        assert.ok(costing.impositionCalculation(r, { forms: 1, modeId: 3747 }).errors.length);
    }
    assert.ok(costing.impositionCalculation(impositionResources(), { forms: 1 }).errors.length);
    assert.ok(costing.impositionCalculation(impositionResources(), { forms: 0, modeId: 3747 }).errors.length);
});
test('dynamic generated plans represent one mounting per part without a forms property', () => {
    const r = impositionResources();
    const result = costing.impositionCalculation(r, { source: 'dynamic', basis: 'quantity',
        parts: [{ modeId: '3747', runs: 140 }, { modeId: '3748', runs: 300 }] });
    assert.deepEqual(result.errors, []);
    assert.equal(result.plans, 2);
    assert.equal(result.faces, 3);
    near(result.hours, 10 / 60);
    near(result.clickCost, 0.6);
});
test('imposition linked to Versafire is never classified as production printing; proofs are separate cost-plus lines', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 20 }];
    data.imposition_resources = impositionResources();
    data.costing_operations.push({ id: 999, operation_id: 43, name: 'Imposição', machines: '11,56', active: 'true' });
    snapshot.forms = 1;
    snapshot.modeId = 3747;
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    const lines = ledger.lines.filter(l => l.imposition);
    assert.equal(lines.length, 4);
    assert.equal(lines[0].hours, 5 / 60);
    near(lines.reduce((total, l) => total + l.cost, 0), 19.5 / 12 + 30 * (0.25 + 1 / 800) + 0.10292 + 0.2);
    for (const line of lines) near(line.sale, line.cost * 1.2);
    assert.equal(ledger.lines.find(l => l.key === 'printing').operationIds.includes(43), false);
    data.costing_operations.pop();
    const missing = costing.calculate(data, snapshot, inputs);
    assert.equal(missing.complete, false);
    assert.equal(missing.cost, null);
    assert.match(missing.errors.join(' '), /Falta a operação Imposição/);
});
test('autonomous CTP charges machine hours separately from five minutes setup and 0.1 minute per plate', () => {
    const resources = ctpResources();
    const hours = 4 / 25 + 25 / 60;
    const result = costing.ctpExecution(resources, 4, hours);
    assert.deepEqual(result.errors, []);
    near(result.machineCost, 8.65);
    near(result.laborHours * 60, 5.4);
    near(result.laborCost, 1.8);
    near(result.cost, 10.45);
    assert.equal(result.laborRate, 20);
    resources.operators[1].cost = 12.5;
    near(costing.ctpExecution(resources, 4, hours).cost, 9.775);
});
test('individual proof line manual costs are validated, retained and isolated from other calculations', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 20 }];
    data.imposition_resources = impositionResources();
    data.imposition_resources.equipment[1].setup = 0;
    data.costing_operations.push({ id: 999, operation_id: 43, name: 'Imposição', machines: '11,56', active: 'true' });
    snapshot.forms = 1;
    snapshot.modeId = 3747;
    const baseline = costing.calculate(data, snapshot, inputs);
    const originalInputs = JSON.stringify(inputs);
    const originalSnapshot = JSON.stringify(snapshot);
    const key = 'imposition:999:equipment';
    const adjusted = costing.calculate(data, snapshot, { ...inputs, [key]: { cost: '1.25' } });
    assert.equal(adjusted.complete, true, adjusted.errors.join('; '));
    assert.equal(adjusted.lines.find(line => line.key === key).cost, 1.25);
    near(adjusted.lines.find(line => line.key === key).sale, 1.5);
    assert.equal(adjusted.inputs[key].cost, '1.25');
    assert.deepEqual(costing.calculate(data, snapshot, inputs), baseline);
    assert.equal(JSON.stringify(inputs), originalInputs);
    assert.equal(JSON.stringify(snapshot), originalSnapshot);
    for (const cost of ['-1', 'inválido']) {
        assert.equal(costing.calculate(data, snapshot, { ...inputs, [key]: { cost } }).complete, false);
    }
    near(costing.calculate(data, snapshot, { ...inputs, [key]: { cost: '' } })
        .lines.find(line => line.key === key).cost, 0.0375);
});
test('CTP intervention requires explicit consistent timings, operators, rates and unique equipment', () => {
    for (const change of [
        r => r.labor[0].setup = '',
        r => r.labor[0].per_unit = '',
        r => r.labor[0].people = '',
        r => r.labor[0].operators = '',
        r => r.labor[0].setup = 3,
        r => r.operators[0].cost = '',
        r => r.equipment.push({ id: 2, cost: 15 }),
        r => r.equipment[0].cost = ''
    ]) {
        const resources = ctpResources();
        change(resources);
        const result = costing.ctpExecution(resources, 4, 0.5);
        assert.ok(result.errors.length);
        assert.equal(result.cost, null);
    }
});
test('CTP ledger uses resource costs instead of consolidated tariff and snapshots independent RH time', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 20 }];
    data.ctp_resources = ctpResources();
    const ledger = costing.calculate(data, snapshot, inputs);
    const line = ledger.lines.find(l => l.operationId === 3);
    assert.ok(line.ctp);
    near(line.cost, line.hours * 15 + (5 + line.quantity * 0.1) / 60 * 20);
    near(line.sale, line.cost * 1.2);
    assert.equal(line.tariff, undefined);
    assert.match(line.source, /CTP autónomo/);
    assert.deepEqual(line.ctp.resources, data.ctp_resources);
});
test('individual line markups replace the common markup, preserve saved costs and persist independently', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    const original = costing.calculate(data, snapshot, inputs);
    const frozen = JSON.stringify(original);
    const key = original.lines.find(line => line.cost > 0).key;
    for (const markup of [0, 15.5, 100, '']) {
        const draft = costing.repriceLines(original, original.pricing, { [key]: markup });
        const selected = draft.lines.find(line => line.key === key);
        assert.equal(draft.cost, original.cost);
        near(selected.sale, selected.cost * (1 + (markup === '' ? 30 : markup) / 100));
        near(draft.sale, draft.lines.reduce((sum, line) => sum + line.sale, 0));
        assert.equal(draft.inputs.commercial.lines[key], markup);
        assert.equal(draft.pricing.version, 2);
        assert.equal(draft.complete, true);
    }
    inputs.commercial = { markup: 30, lines: { [key]: 0 } };
    const recalculated = costing.calculate(data, snapshot, inputs);
    assert.equal(recalculated.lines.find(line => line.key === key).sale,
        recalculated.lines.find(line => line.key === key).cost);
    assert.equal(JSON.stringify(original), frozen);
});
test('invalid per-line markups block saving without losing the known cost', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    const ledger = costing.calculate(data, snapshot, inputs);
    for (const markup of [-1, 'invalid', null]) {
        const draft = costing.repriceLines(ledger, ledger.pricing, { [ledger.lines[0].key]: markup });
        assert.equal(draft.cost, ledger.cost);
        assert.equal(draft.sale, null);
        assert.equal(draft.complete, false);
        assert.match(draft.errors.join(' '), /acréscimo inválido/);
    }
});
test('legacy saved calculations may be explicitly repriced without updating tariffs or consumption', () => {
    const { data, snapshot, inputs } = fixture();
    const old = costing.calculate(data, snapshot, inputs);
    const before = JSON.stringify(old);
    const draft = costing.repriceLines(old, { method: 'cost-plus', quoteId: 24, markup: 30, quoteMarkup: 30 }, {});
    assert.equal(draft.cost, old.cost);
    near(draft.sale, old.cost * 1.3);
    assert.deepEqual(draft.lines.map(l => [l.key, l.cost, l.quantity, l.hours]),
        old.lines.map(l => [l.key, l.cost, l.quantity, l.hours]));
    assert.equal(JSON.stringify(old), before);
});
test('commercial default is 30 percent cost markup, explicit zero is valid, and invalid input never silently defaults', () => {
    for (const markup of ['', null, undefined]) {
        assert.equal(costing.commercialPricing({ commercial_pricing: [{ id: 24, markup }] }).markup, 30);
    }
    assert.equal(costing.commercialPricing({ commercial_pricing: [{ id: 24, markup: 0 }] }).markup, 0);
    assert.equal(costing.commercialPricing({ commercial_pricing: [{ id: 24, markup: 30 }] },
        { commercial: { markup: '12,5' } }).markup, 12.5);
    for (const markup of ['', 'bad', -1]) {
        assert.ok(costing.commercialPricing({ commercial_pricing: [{ id: 24, markup: 30 }] },
            { commercial: { markup } }).errors.length);
    }
    assert.ok(costing.commercialPricing({ commercial_pricing: [] }).errors.length);
});
test('customer pricing applies validated client discounts after the offered sale', () => {
    const data = { commercial_pricing: [{ discount: 10, extra_discount: 5 }] };
    assert.deepEqual(costing.customerPrice(data, 100, 4), {
        discountPercent: 10, extraDiscountPercent: 5, totalDiscountPercent: 15,
        grossSale: 100, discountAmount: 15, sale: 85, unitSale: 21.25
    });
    assert.deepEqual(costing.customerPrice(data, null, 4), {
        discountPercent: 10, extraDiscountPercent: 5, totalDiscountPercent: 15, sale: null
    });
    for (const commercial_pricing of [
        [{ discount: 101, extra_discount: 0 }],
        [{ discount: 10, extra_discount: 91 }]
    ]) assert.throws(() => costing.customerPrice({ commercial_pricing }, 100, 4));
    assert.throws(() => costing.customerPrice(data, -1, 4));
    assert.throws(() => costing.customerPrice(data, 100, 0));
});
test('new commercial pricing applies exactly one markup to full costs and ignores old sales and material margins', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    const materials = [{ recordId: 99, materialId: 100, id: 100, name: 'Material', quantity: 2,
        purchaseUnitPrice: 10, saleUnitPrice: 100, saleTotal: 500, margin: 200 }];
    inputs['operation:142'].sale = 'invalid old sale';
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    near(ledger.cost, 287.2);
    near(ledger.sale, 287.2 * 1.3);
    near(ledger.pricing.increase, 287.2 * 0.3);
    assert.equal(ledger.pricing.method, 'cost-plus');
    assert.equal(ledger.pricing.markup, 30);
    assert.ok(ledger.lines.every(line => Math.abs(line.sale - line.cost * 1.3) < 1e-8));
    assert.equal(ledger.inputs['operation:142'].sale, undefined);
    assert.equal(inputs['operation:142'].sale, 'invalid old sale');
    const units = costing.unitPrices(ledger);
    near(units.sale, ledger.cost * 1.3 / 140);
});
test('operation sale rates may be empty in cost-plus mode without blocking production costs', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    for (const tariff of data.costing_tariffs) tariff.sale = '';
    assert.equal(costing.currentTariff(data, 7).sale, costing.currentTariff(data, 7).cost);
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    near(ledger.sale, ledger.cost * 1.3);
});
test('markup override changes sale only, can exceed 100 percent, and zero means sale equals cost', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    const initial = costing.calculate(data, snapshot, inputs);
    for (const markup of [0, 12.5, 100, 250]) {
        inputs.commercial = { markup };
        const ledger = costing.calculate(data, snapshot, inputs);
        assert.equal(ledger.cost, initial.cost);
        near(ledger.sale, ledger.cost * (1 + markup / 100));
        assert.equal(ledger.inputs.commercial.markup, markup);
    }
    inputs.commercial.markup = '';
    const invalid = costing.calculate(data, snapshot, inputs);
    assert.equal(invalid.complete, false);
    assert.equal(invalid.cost, initial.cost);
    assert.equal(invalid.sale, null);
    assert.match(invalid.errors.join(' '), /percentagem/);
});
test('missing internal costs cannot be replaced by old sales in cost-plus pricing', () => {
    const { data, snapshot, inputs } = fixture();
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    delete inputs['operation:142'].cost;
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, false);
    assert.equal(ledger.cost, null);
    assert.equal(ledger.sale, null);
    assert.match(ledger.errors.join(' '), /Custo interno em falta/);
});
test('new percentage does not mutate historical sales, inputs or existing calculation snapshots', () => {
    const { data, snapshot, inputs } = fixture();
    const legacy = costing.calculate(data, snapshot, inputs);
    const before = JSON.stringify(legacy);
    data.commercial_pricing = [{ id: 24, markup: 30 }];
    const first = costing.calculate(data, snapshot, inputs);
    const frozen = clone(first);
    data.commercial_pricing[0].markup = 45;
    const next = costing.calculate(data, snapshot, inputs);
    assert.equal(JSON.stringify(legacy), before);
    assert.equal(frozen.pricing.markup, 30);
    near(frozen.sale, frozen.cost * 1.3);
    near(next.sale, next.cost * 1.45);
});
test('commercial markup replaces lamination film and packaging material markups, without changing consumption', () => {
    const { data, snapshot, inputs } = laminationFixture();
    const previous = costing.calculate(data, snapshot, inputs);
    data.commercial_pricing = [{ id: 24, markup: 25 }];
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.cost, previous.cost);
    near(ledger.sale, ledger.cost * 1.25);
    const film = ledger.lines.find(line => line.laminationMaterial);
    assert.equal(film.quantity, 108);
    near(film.sale, film.cost * 1.25);
    assert.doesNotMatch(film.source, /venda sobre compra/);
});
function fixture() {
    const operation = (id, name, unit, machine) => ({ id: id + 100, operation_id: id, name, unit,
        equipment: String(machine || ''), machines: String(machine || ''), speed: '', setup: '', active: 'true' });
    const data = {
        costing_operations: [
            operation(2, 'Corte de Papel', 'cortes/h', 8),
            operation(3, 'Produção de Chapas', 'chapas/h', 10),
            operation(7, 'SpeedMaster', 'folhas/h', 6),
            operation(13, 'Plastificação', 'folhas/h', 20),
            operation(42, 'Aplicação de BiAdesivo', '', null),
            operation(10, 'Embalamento', 'volumes/h', 40)
        ],
        costing_catalog: [{ id: 7, name: 'SpeedMaster' }],
        costing_tariffs: [[2, 28, 45], [3, 30, 45], [7, 85, 120], [13, 30, 48], [10, 18, 30]]
            .map(([id, cost, sale]) => ({ id, operation_id: id, basis: 'h', cost, sale, version: 'v1', start: 0, end: 0 })),
        costing_equipment: [
            { id: 1, operation_id: 2, machine_id: 8, speed: 300, setup: 10, unit: 'cortes/h' },
            { id: 2, operation_id: 13, machine_id: 20, speed: 600, setup: 15, unit: 'folhas/h' },
            { id: 3, operation_id: 10, machine_id: 40, speed: 60, setup: 5, unit: 'volumes/h' }
        ]
    };
    const snapshot = { quantity: 140, tariff: { operationId: 7 }, plateProduction: { hours: 0.5 },
        results: { goodPlans: 140, sheets: 145, plates: 4, hours: 1, paper: 100, printing: 120,
            plate: 40, machineCost: 85, click: 0, clicks: 0, plateProduction: 22.5, total: 282.5 } };
    const inputs = { 'operation:102': { quantity: '10' }, 'operation:110': { quantity: '2' },
        'operation:142': { cost: '5', sale: '10' } };
    return { data, snapshot, inputs };
}
test('all selected operations appear exactly once, printing and CTP are not added twice', () => {
    const { data, snapshot, inputs } = fixture();
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.lines.length, 8);
    near(ledger.cost, 267.2);
    near(ledger.sale, 328.2);
    assert.deepEqual(ledger.lines.flatMap(line => line.operationRows || [line.operationRow]).filter(Boolean).sort(),
        data.costing_operations.map(row => row.id).sort());
    assert.equal(ledger.lines.filter(line => line.operationId === 3).length, 1);
});
test('missing cuts, volumes and new operation tariff keep both totals incomplete', () => {
    const { data, snapshot } = fixture();
    const ledger = costing.calculate(data, snapshot);
    assert.equal(ledger.complete, false);
    assert.equal(ledger.cost, null);
    assert.equal(ledger.sale, null);
    assert.match(ledger.errors.join(' '), /Corte de Papel/);
    assert.match(ledger.errors.join(' '), /Embalamento/);
    assert.match(ledger.errors.join(' '), /BiAdesivo.*Sem tarifa/);
});
test('manual hours replace quantity calculation and include setup once', () => {
    const { data, snapshot, inputs } = fixture();
    inputs['operation:102'] = { hours: '0,5' };
    const ledger = costing.calculate(data, snapshot, inputs);
    const line = ledger.lines.find(line => line.operationId === 2);
    assert.equal(line.hours, 0.5);
    assert.equal(line.cost, 14);
    assert.equal(line.sale, 22.5);
});
function laminationFixture() {
    const f = fixture();
    f.snapshot.component = { width: 480, height: 499, lamination_front: 'matt', lamination_back: 'none' };
    f.data.component = [clone(f.snapshot.component)];
    f.snapshot.parts = [{ plan: { width: 720, height: 510, suggestion: {
        planWidth: 720, planHeight: 510, occupiedWidth: 505, occupiedHeight: 486,
        bleed: 3, gripper: 10, reservedGrippers: 1, usableHeight: 500
    } }, paperCalculation: { goodPlans: 140, wastePlans: 150, setupWaste: { plans: 150, governing: ['SpeedMaster'] } } }];
    f.row = f.data.costing_operations.find(row => row.operation_id === 13);
    Object.assign(f.row, { waste: 10, lamination_entry: 10, lamination_exit: 10, lamination_narrow: 'true' });
    f.data.lamination_articles = [
        { id: 533, internal_id: 1017, name: 'FILME TERMO 500 MATE', finish: 'matt', width: 500, unit: 3771, price: 0.055 },
        { id: 651, internal_id: 1000, name: 'FILME TERMO 450 MATE', finish: 'matt', width: 450, unit: 3771, price: 0.04725 },
        { id: 575, internal_id: 1001, name: 'FILME TERMO 500 BRILHO', finish: 'gloss', width: 500, unit: 3771, price: 0.051 },
        { id: 1217, internal_id: 1033, name: 'Anti-Scuff 350', finish: 'anti-scuff', width: 350, unit: 3771, price: 0.1575 },
        { id: 767, internal_id: 1034, name: 'Anti-Scuff 320', finish: 'anti-scuff', width: 320, unit: 3771, price: null }
    ];
    return f;
}
test('lamination margins preserve configured defaults and simulation overrides', () => {
    const row = { lamination_entry: 12, lamination_exit: 14 };
    assert.equal(costing.laminationMargin(row, 'entry'), 12);
    assert.equal(costing.laminationMargin(row, 'exit'), 14);
    assert.equal(costing.laminationMargin(row, 'entry', { entry: 20 }), 20);
    assert.equal(costing.laminationMargin(row, 'entry', { entry: '' }), 12);
    assert.equal(costing.laminationMargin({}, 'entry'), 10);
    assert.equal(costing.laminationMargin(row, 'entry', { entry: 'invalid' }), null);
});
test('lamination uses the finish of each face and own 10 setup plans, never 150 printing setup plans', () => {
    const { data, snapshot, row } = laminationFixture();
    const result = costing.laminationCalculation(data, snapshot, row);
    assert.deepEqual(result.errors, []);
    assert.equal(result.quantity, 150);
    assert.equal(result.setups, 1);
    assert.equal(result.materials.length, 1);
    const material = result.materials[0];
    assert.equal(material.article.id, 533);
    assert.equal(material.requiredWidth, 480);
    assert.equal(material.travel, 720);
    assert.equal(material.setupPlans, 10);
    near(material.metres, 108);
    near(material.cost, 5.94);
    assert.equal(material.face, 'front');
});
test('lamination adds separate film costs and sale to operation totals without duplicating linked materials', () => {
    const { data, snapshot, inputs } = laminationFixture();
    const materials = [{ recordId: 99, materialId: 533, id: 1017, margin: '', quantity: 999, purchaseUnitPrice: 1 }];
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    const operation = ledger.lines.find(line => line.operationId === 13);
    assert.equal(operation.quantity, 150);
    near(operation.hours, 0.5);
    near(operation.cost, 15);
    near(operation.sale, 24);
    const film = ledger.lines.find(line => line.laminationMaterial);
    assert.equal(film.parentOperationRow, operation.operationRow);
    assert.equal(film.unit, 'm');
    assert.equal(film.quantity, 108);
    near(film.cost, 5.94);
    near(film.sale, 7.722);
    assert.ok(!ledger.lines.some(line => line.key === 'material:99'));
    assert.match(film.source, /0,0550/);
});
test('different films on two faces get separate material lines and machine setups; same film reuses setup', () => {
    const { data, snapshot, row, inputs } = laminationFixture();
    snapshot.component.lamination_back = 'gloss';
    const both = costing.calculate(data, snapshot, inputs);
    const operation = both.lines.find(line => line.operationId === 13);
    assert.equal(operation.quantity, 300);
    near(operation.hours, 1);
    assert.equal(operation.lamination.setups, 2);
    assert.deepEqual(both.lines.filter(line => line.laminationMaterial).map(line => line.article.id), [533, 575]);
    snapshot.component.lamination_back = 'matt';
    const same = costing.laminationCalculation(data, snapshot, row);
    assert.equal(same.setups, 1);
    near(same.materials.reduce((sum, item) => sum + item.metres, 0), 216);
    snapshot.component.lamination_front = 'none';
    assert.equal(costing.laminationCalculation(data, snapshot, row).materials[0].face, 'back');
});
test('no lamination on either face produces no labor or film cost', () => {
    const { data, snapshot, row, inputs } = laminationFixture();
    snapshot.component.lamination_front = 'none';
    const result = costing.laminationCalculation(data, snapshot, row);
    assert.equal(result.quantity, 0);
    assert.deepEqual(result.materials, []);
    const operation = costing.calculate(data, snapshot, inputs).lines.find(line => line.operationId === 13);
    assert.equal(operation.hours, 0);
    assert.equal(operation.cost, 0);
    assert.equal(operation.sale, 0);
});
test('missing finish, unsupported geometry, malformed setup or margins block rather than omit film', () => {
    for (const change of [
        f => { delete f.snapshot.component.lamination_back; },
        f => { f.snapshot.component.lamination_front = 'bad'; },
        f => { delete f.snapshot.parts[0].plan.suggestion; },
        f => { f.row.waste = ''; },
        f => { f.row.waste = 1.5; },
        f => { f.row.lamination_entry = ''; },
        f => { f.row.lamination_entry = 120; },
        f => { f.row.lamination_exit = 120; },
        f => { f.row.lamination_narrow = 'false'; },
        f => { f.snapshot.forms = 1.5; }
    ]) {
        const f = laminationFixture();
        change(f);
        const result = costing.laminationCalculation(f.data, f.snapshot, f.row);
        assert.ok(result.errors.length);
        const ledger = costing.calculate(f.data, f.snapshot, f.inputs);
        assert.equal(ledger.complete, false);
        assert.equal(ledger.cost, null);
        assert.equal(ledger.sale, null);
    }
});
test('lamination explains when the final piece cannot pass within the configured margins', () => {
    const { data, snapshot, row } = laminationFixture();
    snapshot.parts[0].plan = { width: 330, height: 510, suggestion: {
        planWidth: 330, planHeight: 510, occupiedWidth: 320, occupiedHeight: 505,
        bleed: 3, gripper: 0, reservedGrippers: 1, usableHeight: 500
    } };
    const result = costing.laminationCalculation(data, snapshot, row);
    assert.match(result.errors.join(' '), /499\.00 mm.*plano 510 mm deixa 490\.00 mm.*entrada \(10 mm\).*saída \(10 mm\)/);
});
test('selected film must match finish, width, purchase unit and have a price; no silent substitute', () => {
    const { data, snapshot, row } = laminationFixture();
    for (const frontArticle of [651, 575, 767, 999]) {
        assert.ok(costing.laminationCalculation(data, snapshot, row, { frontArticle }).errors.length);
    }
    data.lamination_articles[0].unit = 3650;
    assert.ok(costing.laminationCalculation(data, snapshot, row).errors.length);
    data.lamination_articles[0].unit = 3771;
    data.lamination_articles[0].price = null;
    assert.ok(costing.laminationCalculation(data, snapshot, row).errors.length);
    data.lamination_articles[0].price = 0.055;
    data.lamination_articles[0].width = 520;
    assert.ok(costing.laminationCalculation(data, snapshot, row).errors.length);
});
test('lamination rolls must cover the final pieces but need not cover sheet trim edges', () => {
    const { data, snapshot, row } = laminationFixture();
    data.lamination_articles[0].width = 480;
    assert.deepEqual(costing.laminationCalculation(data, snapshot, row).errors, []);
    data.lamination_articles[0].width = 479.999;
    assert.ok(costing.laminationCalculation(data, snapshot, row).errors.length);
    data.lamination_articles[0].width = 500;
    row.waste = 0;
    assert.equal(costing.laminationCalculation(data, snapshot, row).quantity, 140);
});
test('antiscuff remains its own finish and cannot use ordinary matt film or a width that is too narrow', () => {
    const { data, snapshot, row } = laminationFixture();
    snapshot.component.lamination_front = 'anti-scuff';
    assert.match(costing.laminationCalculation(data, snapshot, row).errors.join(' '), /Antirrisco/);
    snapshot.parts[0].plan.suggestion.occupiedHeight = 326;
    assert.equal(costing.laminationCalculation(data, snapshot, row).materials[0].article.id, 1217);
    assert.ok(costing.laminationCalculation(data, snapshot, row, { frontArticle: 767 }).errors.length);
});
test('lamination multiplies its own setup by forms and handles all mountings independently', () => {
    const { data, snapshot, row } = laminationFixture();
    snapshot.forms = 2;
    const result = costing.laminationCalculation(data, snapshot, row);
    assert.equal(result.quantity, 160);
    assert.equal(result.materials[0].setupPlans, 20);
    delete snapshot.forms;
    snapshot.parts.push(clone(snapshot.parts[0]));
    const multi = costing.laminationCalculation(data, snapshot, row);
    assert.equal(multi.quantity, 300);
    near(multi.materials.reduce((sum, material) => sum + material.metres, 0), 216);
});
test('manual labor totals cannot bypass missing film and explicit film choices survive snapshots', () => {
    const { data, snapshot, inputs } = laminationFixture();
    inputs['lamination:113'] = { frontArticle: 533 };
    inputs['operation:113'] = { cost: 0, sale: 0 };
    const first = costing.calculate(data, snapshot, inputs);
    const saved = clone(first);
    assert.equal(saved.inputs['lamination:113'].frontArticle, 533);
    data.lamination_articles[0].price = 2;
    const second = costing.calculate(data, snapshot, inputs);
    near(saved.lines.find(line => line.laminationMaterial).cost, 5.94);
    near(second.lines.find(line => line.laminationMaterial).cost, 216);
    snapshot.component.lamination_front = 'anti-scuff';
    const invalid = costing.calculate(data, snapshot, inputs);
    assert.equal(invalid.complete, false);
    assert.equal(invalid.cost, null);
});
test('missing or duplicate lamination operation cannot silently produce complete totals', () => {
    const { data, snapshot, inputs, row } = laminationFixture();
    data.costing_operations = data.costing_operations.filter(item => item !== row);
    let result = costing.calculate(data, snapshot, inputs);
    assert.equal(result.cost, null);
    assert.equal(result.sale, null);
    assert.match(result.errors.join(' '), /falta a operação/);
    data.costing_operations.push(row, { ...row, id: 999 });
    result = costing.calculate(data, snapshot, inputs);
    assert.equal(result.cost, null);
    assert.equal(result.sale, null);
    assert.match(result.errors.join(' '), /Plastificação repetida/);
});
test('explicit zero values are valid and unknown values are never silently zero', () => {
    assert.equal(costing.number(''), null);
    assert.equal(costing.number('bad'), null);
    assert.equal(costing.number('-1'), null);
    assert.equal(costing.number('0'), 0);
    const { data, snapshot, inputs } = fixture();
    inputs['operation:142'] = { cost: '0', sale: '0' };
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true);
    assert.equal(ledger.lines.find(line => line.operationId === 42).cost, 0);
});
test('invalid manual hours or negative values cannot fall back to automatic success', () => {
    const { data, snapshot, inputs } = fixture();
    for (const value of ['invalid', '-1', 'Infinity']) {
        const copy = clone(inputs);
        copy['operation:113'] = { hours: value };
        const ledger = costing.calculate(data, snapshot, copy);
        assert.equal(ledger.complete, false);
        assert.equal(ledger.sale, null);
        assert.equal(ledger.cost, null);
    }
});
test('consolidated tariffs do not add equipment-only cost again', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_equipment[0].cost = 99999;
    const ledger = costing.calculate(data, snapshot, inputs);
    near(ledger.cost, 267.2);
});
test('duplicate tariffs cause an explicit error rather than choosing an arbitrary rate', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_tariffs.push({ ...data.costing_tariffs[0], id: 99 });
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, false);
    assert.match(ledger.errors.join(' '), /Mais de uma tarifa vigente/);
});
test('explicit total cost and sale allow a service without configured tariff', () => {
    const { data, snapshot, inputs } = fixture();
    const ledger = costing.calculate(data, snapshot, inputs);
    const line = ledger.lines.find(line => line.operationId === 42);
    assert.equal(line.cost, 5);
    assert.equal(line.sale, 10);
    assert.equal(line.errors.length, 0);
});
test('equivalent equipment is accepted for extra operations while differing equipment stays ambiguous', () => {
    const { data, snapshot, inputs } = fixture();
    const operation = { id: 132, operation_id: 32, name: 'Espiral', equipment: '24,25',
        unit: 'cadernos/h', speed: '', setup: '', active: 'true' };
    data.costing_operations.push(operation);
    data.costing_equipment = [
        { id: 24, operation_id: 32, machine_id: 24, unit: 'cadernos/h', speed: 80, setup: 10 },
        { id: 25, operation_id: 32, machine_id: 25, unit: 'cadernos/h', speed: 80, setup: 10 }
    ];
    data.costing_tariffs.push({ id: 32, operation_id: 32, basis: 'h', cost: 25, sale: 35, version: 'v1' });
    inputs['operation:132'] = { quantity: '140' };

    const parameters = costing.production(data, operation);
    assert.equal(parameters.ambiguous, false);
    assert.equal(parameters.speed, 80);
    assert.equal(parameters.setup, 10);
    const line = costing.calculate(data, snapshot, inputs).lines.find(item => item.operationId === 32);
    assert.equal(line.quantity, 140);
    near(line.hours, 10 / 60 + 140 / 80);
    near(line.cost, 25 * (10 / 60 + 140 / 80));

    data.costing_equipment[1].speed = 90;
    assert.equal(costing.production(data, operation).ambiguous, true);
    inputs['operation:132'].equipment = '25';
    const selected = costing.calculate(data, snapshot, inputs).lines.find(item => item.key === 'operation:132');
    assert.equal(selected.parameters.equipment.machineId, 25);
    near(selected.hours, 10 / 60 + 140 / 90);
    assert.ok(!selected.errors.some(error => error.includes('único equipamento')));
    inputs['operation:132'].equipment = '99999';
    assert.ok(costing.calculate(data, snapshot, inputs).lines.find(item => item.key === 'operation:132')
        .errors.some(error => error.includes('Equipamento selecionado inválido')));
});
test('spiral binding records diameter, turns, manual material quantity and latest purchased article cost', () => {
    const { data, snapshot } = fixture();
    const operation = { id: 132, operation_id: 32, name: 'Espiral', equipment: '24,25',
        unit: 'cadernos/h', setup: '10', speed: '', active: 'true' };
    data.costing_operations.push(operation);
    data.costing_equipment = [
        { id: 42, operation_id: 32, machine_id: 24, unit: 'cadernos/h', speed: 80, setup: 10 },
        { id: 44, operation_id: 32, machine_id: 25, unit: 'cadernos/h', speed: 80, setup: 10 }
    ];
    data.costing_tariffs.push({ id: 32, operation_id: 32, basis: 'h', cost: 25, sale: 35, version: 'v1' });
    data.spiral_articles = [{ id: 230, name: 'Espiral Duplo Nº 4 24v Prata', unit: 3649,
        unit_name: 'Un.', price: 0.12, purchase_id: 4570, purchase_date: '2026-11-01' }];

    const missing = costing.calculate(data, snapshot, { 'spiral:132': {} });
    const missingOperation = missing.lines.find(line => line.operationId === 32);
    const missingMaterial = missing.lines.find(line => line.key === 'spiral-material:132');
    assert.equal(missingOperation.quantity, snapshot.quantity);
    assert.ok(missingOperation.errors.some(error => error.includes('diâmetro')));
    assert.ok(missingOperation.errors.some(error => error.includes('voltas')));
    assert.ok(missingMaterial.errors.some(error => error.includes('artigo de compra')));
    assert.ok(missingMaterial.errors.some(error => error.includes('quantidade total')));

    const complete = costing.calculate(data, snapshot, { 'spiral:132': {
        diameter: '4', turns: '24', articleId: '230', materialQuantity: '98'
    } });
    const operationLine = complete.lines.find(line => line.operationId === 32);
    const materialLine = complete.lines.find(line => line.key === 'spiral-material:132');
    assert.equal(operationLine.spiral.diameter, 4);
    assert.equal(operationLine.spiral.turns, 24);
    assert.equal(operationLine.errors.length, 0);
    assert.equal(materialLine.quantity, 98);
    assert.equal(materialLine.article.purchaseId, 4570);
    assert.equal(materialLine.cost, 11.76);
    assert.equal(materialLine.errors.length, 0);

    const mismatch = costing.calculate(data, snapshot, { 'spiral:132': {
        diameter: '5', turns: '24', articleId: '230', materialQuantity: '98'
    } }).lines.find(line => line.key === 'spiral-material:132');
    assert.ok(mismatch.errors.some(error => error.includes('compatível')));
});
test('service tariff multiplies the number of services, never leadtime', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_tariffs.push({ id: 42, operation_id: 42, basis: 'serviço', cost: 8, sale: 12, version: 'v1' });
    data.costing_operations.find(row => row.operation_id === 42).setup = 4320;
    inputs['operation:142'] = { quantity: '3' };
    const line = costing.calculate(data, snapshot, inputs).lines.find(line => line.operationId === 42);
    assert.equal(line.cost, 24);
    assert.equal(line.sale, 36);
});
test('ambiguous equipment needs explicit hours, and selected equipment is respected', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_equipment.push({ ...data.costing_equipment[0], id: 88, machine_id: 99, speed: 301 });
    assert.equal(costing.calculate(data, snapshot, inputs).complete, true);
    data.costing_operations[0].equipment = '';
    assert.equal(costing.production(data, data.costing_operations[0]).ambiguous, true);
    assert.equal(costing.calculate(data, snapshot, inputs).complete, false);
    inputs['operation:102'] = { hours: '1' };
    assert.equal(costing.calculate(data, snapshot, inputs).complete, true);
});
test('existing material quantities and sale totals add once, selected paper and plates do not double count', () => {
    const { data, snapshot, inputs } = fixture();
    const materials = [
        { id: 1, recordId: 1, paper: true, quantity: 99, purchaseUnitPrice: 999, saleTotal: 99999, margin: 0, saleUnitPrice: 0 },
        { id: 984, recordId: 2, quantity: 99, purchaseUnitPrice: 999, saleTotal: 99999 },
        { id: 88, recordId: 3, name: 'Película', quantity: 10, purchaseUnitPrice: 2, saleTotal: 30 }
    ];
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    near(ledger.cost, 287.2);
    near(ledger.sale, 358.2);
    assert.equal(ledger.lines.length, 9);
});
test('material quantity overrides do not reuse an old sale total', () => {
    const { data, snapshot, inputs } = fixture();
    const materials = [{ id: 88, recordId: 3, name: 'Película', quantity: 10, purchaseUnitPrice: 2, saleUnitPrice: 0, saleTotal: 30 }];
    inputs['material:3'] = { quantity: '20' };
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    assert.equal(ledger.sale, null);
    assert.equal(ledger.lines.at(-1).cost, 40);
});
test('digital clicks require actual internal cost, not the sale click price', () => {
    const { data, snapshot, inputs } = fixture();
    snapshot.results.clicks = 100;
    snapshot.results.click = 8;
    const incomplete = costing.calculate(data, snapshot, inputs);
    assert.equal(incomplete.cost, null);
    assert.equal(incomplete.complete, false);
    const clickLine = incomplete.lines.find(line => line.key === 'clicks');
    assert.equal(clickLine.quantity, 100);
    assert.equal(clickLine.cost, null);
    assert.equal(clickLine.sale, 8);
    assert.equal(clickLine.saleReference, 8);
    assert.equal(clickLine.saleReferenceRate, 0.08);
    inputs.clicks = { cost: '4' };
    const complete = costing.calculate(data, snapshot, inputs);
    assert.equal(complete.complete, true);
    near(complete.cost, 271.2);
    near(complete.sale, 336.2);
});
test('generated and publication quantities use production plans, not uncut parent sheets', () => {
    const { data, snapshot, inputs } = fixture();
    delete snapshot.results.goodPlans;
    snapshot.parts = [
        { operation: { id: 7 }, paperCalculation: { goodPlans: 100 } },
        { operation: { id: 7 }, paperCalculation: { goodPlans: 40 } }
    ];
    const ledger = costing.calculate(data, snapshot, inputs);
    near(ledger.sale, 328.2);
    delete snapshot.parts;
    assert.equal(costing.calculate(data, snapshot, inputs).sale, null);
});
test('different production quantities recalculate automatic operations', () => {
    const { data, snapshot, inputs } = fixture();
    const first = costing.calculate(data, snapshot, inputs);
    snapshot.results.goodPlans *= 2;
    const second = costing.calculate(data, snapshot, inputs);
    assert.ok(second.sale > first.sale);
    assert.equal(second.lines.find(line => line.operationId === 13).quantity, 280);
});
test('old saved snapshots stay fixed after tariff, operation and input changes', () => {
    const { data, snapshot, inputs } = fixture();
    const ledger = costing.calculate(data, snapshot, inputs);
    const saved = JSON.stringify(ledger);
    const historic = JSON.parse(saved);
    data.costing_tariffs[0].sale = 999;
    data.costing_operations[0].name = 'Novo nome';
    inputs['operation:142'].cost = '999';
    assert.equal(JSON.stringify(historic), saved);
    assert.equal(historic.sale, 328.2);
    assert.equal(historic.inputs['operation:142'].cost, '5');
});
test('duplicate CTP operation is represented and cannot produce a complete total', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_operations.push({ ...data.costing_operations[1], id: 999 });
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, false);
    assert.equal(ledger.sale, null);
    assert.ok(ledger.lines.find(line => line.operationRow === 999));
});
test('print operation rows for unselected machines do not invalidate another chosen production', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_operations.push({ id: 999, operation_id: 5, name: 'PrintMaster', machines: '5', active: 'true' });
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.lines.some(line => line.operationRow === 999), false);
});
test('inactive selected operation is visible and blocks complete total', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_operations[0].active = 'false';
    assert.match(costing.calculate(data, snapshot, inputs).errors.join(' '), /inativa/);
});
test('totals retain calculation precision rather than rounding each line prematurely', () => {
    const { data, snapshot, inputs } = fixture();
    inputs['operation:102'].quantity = '1';
    const line = costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2);
    near(line.cost, (10 / 60 + 1 / 300) * 28);
});
test('paper sales follow the existing material markup, cost remains separate', () => {
    const { data, snapshot, inputs } = fixture();
    const material = { id: 1, recordId: 1, paper: true, margin: 30, saleUnitPrice: 0 };
    const ledger = costing.calculate(data, snapshot, inputs, [material]);
    near(ledger.cost, 267.2);
    near(ledger.sale, 358.2);
    assert.equal(ledger.lines[0].cost, 100);
    assert.equal(ledger.lines[0].sale, 130);
});
test('the material sale helper follows all three branches of native field 4325', () => {
    assert.equal(costing.materialSale(10, 2, 0, 30), 26);
    assert.equal(costing.materialSale(10, 2, 5, 0), 50);
    assert.equal(costing.materialSale(10, 2, 5, 30), 76);
    assert.equal(costing.materialSale(10, 2, 0, null), 26);
    assert.equal(costing.materialSale(10, 2, 0, 0), 20);
    assert.equal(costing.materialSale(10, null, 5, 0), 50);
});
test('all blank material margins use 30 percent while explicit zero and chosen margins are respected', () => {
    for (const margin of [undefined, null, '', ' ']) assert.equal(costing.materialSale(10, 2, 0, margin), 26);
    assert.equal(costing.materialSale(10, 2, 0, 0), 20);
    assert.equal(costing.materialSale(10, 2, 0, 10), 22);
    assert.equal(costing.materialSale(10, 2, 0, 'invalid'), null);
});
test('unfilled template materials with default zero quantity are incomplete until explicitly entered', () => {
    const { data, snapshot, inputs } = fixture();
    const materials = [{ recordId: 99, name: 'Material', quantity: 0, purchaseUnitPrice: 1, saleUnitPrice: 0, margin: 30 }];
    assert.equal(costing.calculate(data, snapshot, inputs, materials).complete, false);
    inputs['material:99'] = { quantity: '0' };
    assert.equal(costing.calculate(data, snapshot, inputs, materials).complete, true);
});
test('CTP manual time changes that operation only and does not add a second CTP charge', () => {
    const { data, snapshot, inputs } = fixture();
    inputs['operation:103'] = { hours: '1' };
    const ledger = costing.calculate(data, snapshot, inputs);
    const line = ledger.lines.find(row => row.operationId === 3);
    assert.equal(line.cost, 30);
    assert.equal(line.sale, 45);
    near(ledger.sale, 350.7);
    assert.equal(ledger.lines.filter(row => row.operationId === 3).length, 1);
});
test('mixed Offset and Digital printing have separate ledger lines, with paper and CTP charged once', () => {
    const { data, snapshot, inputs } = fixture();
    data.costing_tariffs.push({ id: 4, operation_id: 4, basis: 'h', cost: 30, sale: 60, version: 'v1' });
    snapshot.results.hours = 2;
    snapshot.results.printing = 180;
    snapshot.results.machineCost = 115;
    snapshot.mixed = {
        offset: { tariff: { operationId: 7 }, result: { hours: 1, printing: 120, machineCost: 85 } },
        digital: { tariff: { operationId: 4 }, result: { hours: 1, printing: 60, machineCost: 30 } }
    };
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.lines.filter(line => line.key.startsWith('printing:')).length, 2);
    assert.equal(ledger.lines.filter(line => line.key === 'paper').length, 1);
    assert.equal(ledger.lines.filter(line => line.operationId === 3).length, 1);
    near(ledger.cost, 297.2);
    near(ledger.sale, 388.2);
});
function adhesiveFixture() {
    const f = fixture();
    f.data.adhesive_articles = [{ id: '201', name: 'Biadesivo 70X100', width: '700', height: '1000',
        unit: '3648', internal_id: '1035', price: '2.87' }];
    f.inputs['adhesive:142'] = { perCopy: '1', width: '100', height: '100', margin: '30' };
    return f;
}
test('BiAdesivo calculates whole purchased sheets from pieces per copy and dimensions', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    const line = ledger.lines.find(row => row.key === 'adhesive:142');
    assert.equal(line.adhesive.pieces, 140);
    assert.equal(line.adhesive.layout.piecesPerSheet, 70);
    assert.equal(line.quantity, 2);
    near(line.cost, 5.74);
    near(line.sale, 7.462);
    near(ledger.cost, 272.94);
    near(ledger.sale, 335.662);
    assert.equal(line.article.id, '201');
    assert.equal(ledger.lines.filter(row => row.operationId === 42).length, 1);
});
test('BiAdesivo rotates pieces and combines mixed-orientation strips', () => {
    const rotated = costing.adhesiveLayout(700, 1000, 480, 499);
    assert.equal(rotated.piecesPerSheet, 2);
    const mixed = costing.adhesiveLayout(700, 1000, 300, 400);
    assert.equal(mixed.piecesPerSheet, 5);
    for (const result of [rotated, mixed]) {
        assert.ok(result.wasteFraction >= 0);
        assert.equal(result.strips.reduce((total, strip) => total + strip.rows * strip.perRow, 0), result.piecesPerSheet);
    }
});
test('BiAdesivo sheet count is ceil(total pieces / capacity), including multiple pieces per copy', () => {
    const { data, inputs } = adhesiveFixture();
    const input = { ...inputs['adhesive:142'], perCopy: 3 };
    const result = costing.adhesiveCalculation(data.adhesive_articles[0], input, 141);
    assert.equal(result.pieces, 423);
    assert.equal(result.sheets, 7);
    near(result.cost, 20.09);
});
test('BiAdesivo does not silently skip missing dimensions, excessive pieces, wrong units or absent price', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    for (const change of [{ width: '' }, { height: '0' }, { width: '1001', height: '1001' },
        { perCopy: '0' }, { perCopy: '1.5' }, { perCopy: '1e100' }, { width: '0.0001' }]) {
        const values = { ...inputs, 'adhesive:142': { ...inputs['adhesive:142'], ...change } };
        const ledger = costing.calculate(data, snapshot, values);
        assert.equal(ledger.complete, false, JSON.stringify(change));
        assert.equal(ledger.sale, null);
        assert.equal(ledger.cost, null);
    }
    data.adhesive_articles[0].price = '';
    assert.equal(costing.calculate(data, snapshot, inputs).complete, false);
    inputs['adhesive:142'].purchasePrice = '3';
    assert.equal(costing.calculate(data, snapshot, inputs).complete, true);
    data.adhesive_articles[0].unit = 'm2';
    assert.equal(costing.calculate(data, snapshot, inputs).complete, false);
});
test('BiAdesivo defaults to 30 percent, preserves explicit zero and rejects absent articles', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    inputs['adhesive:142'].margin = '';
    const defaulted = costing.calculate(data, snapshot, inputs);
    assert.equal(defaulted.complete, true);
    assert.equal(defaulted.lines.find(row => row.adhesive).materialPricing.margin, 30);
    inputs['adhesive:142'].margin = '0';
    assert.equal(costing.calculate(data, snapshot, inputs).complete, true);
    data.adhesive_articles = [];
    assert.equal(costing.calculate(data, snapshot, inputs).complete, false);
});
test('native BiAdesivo margin defaults only missing values and never overwrites an explicit margin', () => {
    for (const margin of ['', null, undefined, ' ']) {
        assert.equal(costing.adhesiveInputs({ adhesive_margin: margin }).margin, '30');
    }
    for (const margin of ['0', '10', '35']) {
        assert.equal(costing.adhesiveInputs({ adhesive_margin: margin }).margin, margin);
    }
});
test('BiAdesivo material already in materials is charged once, using its configured margin', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    inputs['adhesive:142'].margin = '';
    const material = { id: 1035, materialId: 201, recordId: 55, paper: true, margin: 30,
        quantity: 999, purchaseUnitPrice: 999, saleTotal: 99999, saleUnitPrice: 0 };
    const ledger = costing.calculate(data, snapshot, inputs, [material]);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    near(ledger.cost, 272.94);
    near(ledger.sale, 335.662);
    assert.equal(ledger.lines.filter(row => row.key === 'material:55').length, 0);
    assert.equal(ledger.lines.find(row => row.key === 'adhesive:142').quantity, 2);
});
test('BiAdesivo tariffs, layout and inputs are fixed in the serialized historical snapshot', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    const ledger = JSON.parse(JSON.stringify(costing.calculate(data, snapshot, inputs)));
    data.adhesive_articles[0].price = '99';
    inputs['adhesive:142'].width = '500';
    assert.equal(ledger.lines.find(row => row.key === 'adhesive:142').article.price, '2.87');
    assert.equal(ledger.inputs['adhesive:142'].width, '100');
    assert.equal(ledger.lines.find(row => row.key === 'adhesive:142').adhesive.sheets, 2);
});
test('BiAdesivo strip plans are physically feasible, including decimal dimensions and both axes', () => {
    for (const [width, height] of [[85, 55], [300, 400], [480, 499], [499.5, 350], [600, 700], [1000, 700], [100.125, 99.875]]) {
        const layout = costing.adhesiveLayout(700, 1000, width, height);
        assert.deepEqual(layout.errors, []);
        const availableWidth = layout.axis === 'horizontal' ? 700 : 1000;
        const availableHeight = layout.axis === 'horizontal' ? 1000 : 700;
        let usedHeight = 0;
        for (const strip of layout.strips) {
            const w = strip.rotated ? height : width;
            const h = strip.rotated ? width : height;
            assert.ok(strip.perRow * w <= availableWidth + 1e-8);
            usedHeight += strip.rows * h;
        }
        assert.ok(usedHeight <= availableHeight + 1e-8);
        assert.ok(layout.piecesPerSheet * width * height <= 700000 + 1e-8);
    }
});
test('native operation fields feed adhesive sizing and prices without simulation overrides', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    delete inputs['adhesive:142'];
    const row = data.costing_operations.find(item => item.operation_id === 42);
    Object.assign(row, { adhesive_per_copy: '2', adhesive_width: '300', adhesive_height: '400',
        adhesive_margin: '30', adhesive_purchase_price: '' });
    const ledger = costing.calculate(data, snapshot, inputs);
    const line = ledger.lines.find(item => item.key === 'adhesive:142');
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(line.quantity, 56);
    near(line.cost, 160.72);
    near(line.sale, 208.936);
    assert.equal(ledger.sourceOperations.find(item => item.operation_id === 42).adhesive_width, '300');
});
test('explicit simulation overrides replace native operation values, and clearing a dimension blocks costing', () => {
    const { data, snapshot, inputs } = adhesiveFixture();
    const row = data.costing_operations.find(item => item.operation_id === 42);
    Object.assign(row, { adhesive_per_copy: '2', adhesive_width: '300', adhesive_height: '400',
        adhesive_margin: '30', adhesive_purchase_price: '4' });
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.equal(ledger.lines.find(item => item.key === 'adhesive:142').quantity, 2);
    assert.equal(ledger.lines.find(item => item.key === 'adhesive:142').cost, 8);
    inputs['adhesive:142'].width = '';
    assert.equal(costing.calculate(data, snapshot, inputs).complete, false);
});
function cuttingSnapshot(sheets = 501, goodPlans = 1001) {
    return {
        component: { width: 100, height: 100, binding: 1544, has_die: 'false' },
        parts: [{
            plan: { id: 'suggestion-0', width: 720, height: 510, per_sheet: 2, sheet_width: 720, sheet_height: 1020,
                suggestion: { planWidth: 720, planHeight: 510, sheetWidth: 720, sheetHeight: 1020, cutsX: 1, cutsY: 2,
                    columns: 6, rows: 4, poses: 24, rotated: false, gap: 6, bleed: 3, gripper: 10,
                    reservedGrippers: 1, usableHeight: 500, occupiedWidth: 636, occupiedHeight: 424 } },
            paperCalculation: { sheets, goodPlans }
        }]
    };
}
function packagingFixture() {
    const snapshot = { component: { id: 30, width: 100, height: 100, pages: '', binding: 1544 },
        quantity: 140, quantityId: 14, parts: [{ paperCalculation: {
            paper: { id: 2652, weight: 300 }, sheetArea: 500000000, totalWaste: 0.8
        } }] };
    const row = { id: 154, operation_id: 10 };
    const data = { quote_components: [{ ...snapshot.component }],
        quote_packaging: [{ id: 154, component_id: 30, packaging_kg: 10 }], quote_selections: [] };
    return { data, snapshot, row };
}
test('packaging uses g/m2 and net paper area excluding setup sheets and trim waste', () => {
    const { data, snapshot, row } = packagingFixture();
    const weight = costing.paperWeight(snapshot);
    near(weight.kg, 30);
    near(weight.details[0].netAreaM2, 100);
    const result = costing.packagingCalculation(data, snapshot, row, 10);
    assert.deepEqual(result.errors, []);
    near(result.kg, 30);
    assert.equal(result.copiesPerPackage, 46);
    assert.equal(result.packages, 4);
    assert.ok(result.copiesPerPackage * result.kgPerCopy <= 10);
    assert.equal(costing.packagingCalculation(data, snapshot, row, 5).packages, 7);
});
test('all mountings contribute once to packaging weight, not once per printed face', () => {
    const { data, snapshot, row } = packagingFixture();
    snapshot.parts.push(clone(snapshot.parts[0]));
    snapshot.parts[1].paperCalculation.paper.weight = 150;
    snapshot.parts[0].passes = 2;
    near(costing.packagingCalculation(data, snapshot, row, 10).kg, 45);
});
test('quotation packaging joins sibling component weights at the same quantity and counts once', () => {
    const { data, snapshot, row } = packagingFixture();
    const sibling = clone(snapshot);
    sibling.component.id = 31;
    data.quote_components.push({ ...sibling.component });
    data.quote_selections.push({ id: 5, component_id: 31, quantity_id: 14, quantity: 140,
        details: JSON.stringify({ calculation: sibling }) });
    const result = costing.packagingCalculation(data, snapshot, row, 10);
    near(result.kg, 60);
    assert.equal(result.packages, 7);
    assert.equal(result.weights.length, 2);
    const nonOwner = costing.packagingCalculation(data, sibling, { id: 155, operation_id: 10 }, 10);
    assert.equal(nonOwner.packages, 0);
    assert.match(nonOwner.source, /não duplicado/);
});
test('missing, duplicate, corrupt or outdated sibling selections block quote packaging explicitly', () => {
    const { data, snapshot, row } = packagingFixture();
    const sibling = clone(snapshot);
    sibling.component.id = 31;
    data.quote_components.push({ ...sibling.component });
    const check = () => assert.ok(costing.packagingCalculation(data, snapshot, row, 10).errors.length);
    check();
    const selection = { id: 5, component_id: 31, quantity_id: 14, quantity: 140,
        details: JSON.stringify({ calculation: sibling }) };
    data.quote_selections = [{ ...selection, quantity_id: 99 }];
    check();
    data.quote_selections = [selection, selection];
    check();
    data.quote_selections = [{ ...selection, details: '{' }];
    check();
    data.quote_selections = [selection];
    data.quote_components[1].width = 200;
    check();
});
test('packaging never treats missing weight as zero or splits an overweight complete exemplar', () => {
    const { data, snapshot, row } = packagingFixture();
    for (const limit of ['', 0, -1, 'bad', 0.1]) {
        assert.ok(costing.packagingCalculation(data, snapshot, row, limit).errors.length);
    }
    for (const gsm of ['', 0, -1, 'bad']) {
        snapshot.parts[0].paperCalculation.paper.weight = gsm;
        assert.ok(costing.packagingCalculation(data, snapshot, row, 10).errors.length);
    }
});
test('package count uses whole exemplars and handles exact weight boundaries', () => {
    const { data, snapshot, row } = packagingFixture();
    snapshot.quantity = 100;
    snapshot.parts[0].paperCalculation = { paper: { weight: 100 }, sheetArea: 1000000000, totalWaste: 0 };
    const result = costing.packagingCalculation(data, snapshot, row, 10);
    assert.equal(result.copiesPerPackage, 10);
    assert.equal(result.packages, 10);
});
test('packaging ledger applies packages/hour with setup once, and zero on a second operation', () => {
    const { data, snapshot, inputs } = fixture();
    const p = packagingFixture();
    Object.assign(data, p.data);
    Object.assign(snapshot, p.snapshot);
    delete inputs['operation:110'];
    data.quote_packaging[0].id = 110;
    const line = costing.calculate(data, snapshot, inputs).lines.find(l => l.operationId === 10);
    assert.equal(line.quantity, 4);
    near(line.hours, 5 / 60 + 4 / 60);
    near(line.cost, 2.7);
    const fixed = JSON.parse(JSON.stringify(line.packaging));
    snapshot.parts[0].paperCalculation.paper.weight = 600;
    near(fixed.kg, 30);
    data.quote_packaging[0].id = 99;
    const duplicate = costing.calculate(data, snapshot, inputs).lines.find(l => l.operationId === 10);
    assert.equal(duplicate.quantity, 0);
    assert.equal(duplicate.hours, 0);
    assert.equal(duplicate.cost, 0);
    assert.equal(duplicate.sale, 0);
});
function packagingArticles() {
    return [
        { id: 1328, packaging_type: '1', name: 'Kraft rolo 1.2m 90g', width: 1200, weight: 90, unit: 3650, price: 2.21, internal_id: 555 },
        { id: 24, packaging_type: '2', name: 'Caixa 46.32.30', unit: 3649, price: 0.78, internal_id: 556 },
        { id: 1403, packaging_type: '2', name: 'Caixa 46.32.20', unit: 3649, price: 0.8, internal_id: 557 },
        { id: 879, packaging_type: '2', name: 'Caixa sem compras', unit: 3649, price: null }
    ];
}
test('kraft uses physical wrap panels with end flaps and overlap, not surface-area ratios', () => {
    const data = { packaging_articles: packagingArticles() };
    const input = { type: '1', width: 200, length: 300, height: 50, overlap: 30 };
    const result = costing.packagingMaterial(data, input, 5);
    assert.deepEqual(result.errors, []);
    assert.equal(result.layout.piecesAcross, 2);
    near(result.layout.linearMetres, 1.29);
    near(result.quantity, 1.2 * 1.29 * 0.09);
    near(result.cost, 1.2 * 1.29 * 0.09 * 2.21);
    assert.equal(result.layout.panelWidth, 530);
    assert.equal(result.layout.panelHeight, 430);
    input.width = 480; input.length = 499; input.height = 30;
    const large = costing.packagingMaterial(data, input, 2);
    assert.deepEqual(large.errors, []);
    near(large.layout.linearMetres, 1.05);
    near(large.quantity, 1.2 * 1.05 * 0.09);
    assert.match(large.source, /kg.*sobra lateral/);
    assert.doesNotMatch(large.source, /folhas/);
});
test('roll wrapping has no length limit but checks its exact width, rotation and full-width consumption', () => {
    const data = { packaging_articles: packagingArticles() };
    const input = { type: '1', width: 1168, length: 1168, height: 1, overlap: 30 };
    const edge = costing.packagingMaterial(data, input, 3);
    assert.deepEqual(edge.errors, []);
    near(edge.layout.linearMetres, 7.104);
    near(edge.quantity, 7.104 * 1.2 * 0.09);
    assert.match(costing.packagingMaterial(data, { ...input, width: 1168.001, length: 1168.001 }, 3).errors.join(' '), /largura do rolo/);
    const tall = costing.packagingMaterial(data, { ...input, width: 200, length: 1500, height: 10 }, 1);
    assert.deepEqual(tall.errors, []);
    near(tall.layout.linearMetres, 1.55);
    const rotated = costing.packagingMaterial(data, { ...input, width: 1168, length: 584 }, 1);
    assert.deepEqual(rotated.errors, []);
    near(rotated.quantity, 1.2 * 1.2 * 0.09);
    for (const overlap of [700, 1000]) {
        assert.ok(costing.packagingMaterial(data, { ...input, overlap }, 1).errors.length);
    }
    assert.ok(costing.packagingMaterial(data, { ...input, width: 2000, length: 2000 }, 1).errors.length);
});
test('wrapping requires automatic geometry when dimensions are absent, and validates overlap, gsm, units and purchases', () => {
    const data = { packaging_articles: packagingArticles() };
    const input = { type: '1', width: 200, length: 300, height: 50, overlap: 30 };
    for (const key of ['width', 'length', 'height', 'overlap']) {
        assert.ok(costing.packagingMaterial(data, { ...input, [key]: '' }, 2).errors.length);
    }
    assert.ok(costing.packagingMaterial(data, { ...input, type: 'bad' }, 2).errors.length);
    for (const change of [{ weight: 80 }, { unit: 3648 }, { price: null }, { width: '' }]) {
        const copy = clone(data);Object.assign(copy.packaging_articles[0], change);
        assert.ok(costing.packagingMaterial(copy, input, 2).errors.length);
    }
});
test('native form accepts roll dimensions and rejects panels beyond its width', () => {
    const controls = new Map([4287, 6261, 6262, 6263, 6264, 6265, 6266].map(id => [id, {
        value: '', classList: { toggle() {} }, closest: () => ({ after() {}, append() {} })
    }]));
    controls.get(4287).value = '10';
    controls.get(6261).value = '1570';
    const form = { querySelector: selector => controls.get(Number(selector.match(/\d+/)[0])) };
    const document = { getElementById: () => form, createElement: () => ({ scrollIntoView() {} }) };
    const source = require('node:fs').readFileSync(require('node:path').join(__dirname, 'operation-packaging-material-form.js'), 'utf8')
        .replace('__PACKAGING_CONFIG__', JSON.stringify({ wrap: 1570, box: 1571, boxChoices: [1580] }));
    require('node:vm').runInNewContext(source, { document, $: () => ({ on() {}, trigger() {} }) });
    const validate = (width, length, height, overlap) => {
        [width, length, height, overlap].forEach((value, i) => { controls.get(6263 + i).value = String(value); });
        return form.validatePackagingMaterial();
    };
    assert.equal(validate(200, 300, 50, 30), true);
    assert.equal(validate(480, 499, 30, 30), true);
    assert.equal(validate(669, 948, 1, 30), true);
    assert.equal(validate(1168.001, 1168.001, 1, 30), false);
    assert.equal(validate(200, 1500, 10, 30), true);
    assert.equal(validate(480, 499, 30, 0), true);
    assert.equal(validate(2000, 2000, 1, 30), false);
    assert.equal(validate('', '', '', 30), true);
});
test('box price comes from the chosen article, never the most expensive different box', () => {
    const data = { packaging_articles: packagingArticles() };
    const result = costing.packagingMaterial(data, { type: '2', box: 24 }, 3);
    assert.deepEqual(result.errors, []);
    near(result.cost, 2.34);
    assert.equal(result.quantity, 3);
    assert.equal(result.article.id, 24);
    near(costing.packagingMaterial(data, { type: '2', box: 1403 }, 3).cost, 2.4);
    for (const box of ['', 879, 999]) assert.ok(costing.packagingMaterial(data, { type: '2', box }, 3).errors.length);
});
test('native packaging defaults and simulation overrides preserve chosen type and overlap', () => {
    assert.deepEqual(costing.packagingInputs({}), { type: '1', box: '', width: '', length: '', height: '', overlap: 30 });
    const input = costing.packagingInputs({ packaging_type: '2', packaging_box: 24, packaging_overlap: 0 }, { box: 1403 });
    assert.equal(input.type, '2');assert.equal(input.box, 1403);assert.equal(input.overlap, 0);
});
test('material and labor are separate, packaging material is counted once and snapshotted', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(data, packagingFixture().data);
    Object.assign(snapshot, packagingFixture().snapshot);
    data.quote_packaging[0].id = 110;
    data.packaging_articles = packagingArticles();
    delete inputs['operation:110'];
    inputs['packaging:110'] = { type: '2', box: 24 };
    const materials = [{ recordId: 99, materialId: 24, id: 556, quantity: 100, purchaseUnitPrice: 1, saleTotal: 100, margin: '' }];
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    const labor = ledger.lines.find(l => l.operationId === 10);
    const material = ledger.lines.find(l => l.key === 'packaging:110');
    assert.equal(labor.quantity, 4);
    assert.equal(material.quantity, 4);
    near(material.cost, 3.12);near(material.sale, 4.056);
    assert.ok(!ledger.lines.some(l => l.key === 'material:99'));
    const historical = clone(material);
    data.packaging_articles[1].price = 2;
    near(historical.cost, 3.12);
    near(historical.article.price, 0.78);
});
test('roll material is charged in kg at its own highest purchase price and preserves historical consumption', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(data, packagingFixture().data);
    Object.assign(snapshot, packagingFixture().snapshot);
    data.quote_packaging[0].id = 110;
    data.packaging_articles = packagingArticles();
    delete inputs['operation:110'];
    inputs['packaging:110'] = { type: '1', width: 480, length: 499, height: 30, overlap: 30 };
    const materials = [{ recordId: 99, materialId: 1328, id: 555, quantity: 100, purchaseUnitPrice: 1, saleTotal: 100, margin: '' }];
    const ledger = costing.calculate(data, snapshot, inputs, materials);
    const material = ledger.lines.find(line => line.key === 'packaging:110');
    assert.equal(material.unit, 'kg');
    assert.equal(material.article.id, 1328);
    near(material.packagingMaterial.layout.linearMetres, 2.1);
    near(material.quantity, 2.1 * 1.2 * 0.09);
    near(material.cost, material.quantity * 2.21);
    near(material.sale, material.cost * 1.3);
    assert.ok(!ledger.lines.some(line => line.key === 'material:99'));
    const historical = clone(material);
    data.packaging_articles[0].price = 10;
    inputs['packaging:110'].height = 100;
    near(historical.cost, 2.1 * 1.2 * 0.09 * 2.21);
    assert.equal(historical.packagingMaterial.version, 4);
});
test('second component has zero packaging material; manual hours do not silently omit material', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(data, packagingFixture().data);
    Object.assign(snapshot, packagingFixture().snapshot);
    data.quote_packaging[0].id = 99;
    data.packaging_articles = packagingArticles();
    delete inputs['operation:110'];
    let material = costing.calculate(data, snapshot, inputs).lines.find(l => l.key === 'packaging:110');
    assert.equal(material.cost, 0);
    assert.equal(material.sale, 0);
    data.quote_packaging[0].id = 110;
    inputs['operation:110'] = { hours: 1 };
    material = costing.calculate(data, snapshot, inputs).lines.find(l => l.key === 'packaging:110');
    assert.equal(material.cost, null);
    assert.match(material.errors.join(' '), /espessura|Calcule/);
});
test('automatic packaging geometry uses real paper thickness and complete copies, never gram weight as thickness', () => {
    const { data, snapshot, row } = packagingFixture();
    snapshot.parts[0].paperCalculation.paper.thickness = 0.3;
    const pkg = costing.packagingCalculation(data, snapshot, row, 10);
    const geometry = costing.packagingDimensions(pkg);
    assert.deepEqual(geometry.errors, []);
    assert.equal(geometry.width, 100);
    assert.equal(geometry.length, 100);
    near(geometry.height, 100 * 1000000 / (100 * 100 * 140) * 0.3 * 46);
    delete snapshot.parts[0].paperCalculation.paper.thickness;
    assert.match(costing.packagingDimensions(costing.packagingCalculation(data, snapshot, row, 10)).errors.join(' '), /espessura real/);
});
test('missing thickness can be entered per article for wrapping and sibling components without changing source data', () => {
    const { data, snapshot, row } = packagingFixture();
    const overrides = { 'paper:2652': { thickness: '0,3' } };
    const second = clone(snapshot);
    second.component.id = 31;
    data.quote_components.push(second.component);
    data.quote_selections.push({ id: 22, component_id: 31, quantity_id: 14, quantity: 140,
        details: JSON.stringify({ calculation: second }) });
    const pkg = costing.packagingCalculation(data, snapshot, row, 10, overrides);
    const geometry = costing.packagingDimensions(pkg);
    assert.deepEqual(geometry.errors, []);
    assert.equal(geometry.details.length, 2);
    assert.ok(geometry.details.every(paper => paper.thickness === 0.3));
    assert.equal(snapshot.parts[0].paperCalculation.paper.thickness, undefined);
    snapshot.parts[0].paperCalculation.paper.thickness = 0.4;
    assert.equal(costing.paperWeight(snapshot, overrides).details[0].thickness, 0.4);
});
test('entered thickness is saved in ledger inputs and missing or invalid values remain explicit errors', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(data, packagingFixture().data);
    Object.assign(snapshot, packagingFixture().snapshot);
    data.quote_packaging[0].id = 110;
    data.packaging_articles = packagingArticles();
    delete inputs['operation:110'];
    inputs['paper:2652'] = { thickness: 0.01 };
    const ledger = costing.calculate(data, snapshot, inputs);
    assert.deepEqual(ledger.inputs['paper:2652'], { thickness: 0.01 });
    const material = ledger.lines.find(line => line.key === 'packaging:110');
    assert.deepEqual(material.errors, []);
    assert.equal(material.packagingMaterial.dimensions.automatic.details[0].thickness, 0.01);
    inputs['paper:2652'].thickness = '';
    assert.match(costing.calculate(data, snapshot, inputs).errors.join(' '), /Introduza a espessura/);
    for (const value of [0, -1, 81, 'inválido']) {
        inputs['paper:2652'].thickness = value;
        assert.match(costing.calculate(data, snapshot, inputs).errors.join(' '), /espessura real/);
    }
});
test('entered paper thickness also supplies the automatic cutting pile', () => {
    const snapshot = cuttingSnapshot();
    snapshot.parts[0].paperCalculation.paper = { id: 2652 };
    const calculation = costing.cuttingCalculation(snapshot, 2, undefined, { 'paper:2652': { thickness: 0.5 } });
    assert.deepEqual(calculation.errors, []);
    assert.equal(calculation.details[0].pile.capacity, 160);
    assert.equal(calculation.details[0].paperThickness, 0.5);
    assert.equal(snapshot.parts[0].paperCalculation.paper.thickness, undefined);
});
test('automatic wrapper dimensions can be individually overridden and aggregate component thickness', () => {
    const { data, snapshot, row } = packagingFixture();
    snapshot.component.width = 200; snapshot.component.height = 300;
    snapshot.parts[0].paperCalculation.paper.thickness = 0.2;
    const pkg = costing.packagingCalculation(data, snapshot, row, 10);
    const result = costing.packagingMaterial({ packaging_articles: packagingArticles() },
        { type: '1', width: '', length: '', height: '', overlap: 30 }, pkg.packages, pkg);
    assert.deepEqual(result.errors, []);
    assert.equal(result.dimensions.width, 200);
    assert.equal(result.dimensions.length, 300);
    assert.ok(result.dimensions.automatic);
    const overridden = costing.packagingMaterial({ packaging_articles: packagingArticles() },
        { type: '1', width: '', length: '', height: 5, overlap: 30 }, pkg.packages, pkg);
    assert.equal(overridden.dimensions.height, 5);
    const second = structuredClone(pkg.weights[0]);
    second.componentId = 31;
    pkg.weights.push(second);
    near(costing.packagingDimensions(pkg).height, result.dimensions.automatic.height * 2);
});
test('initial paper cutting counts one separation per pile for two exact half-sheet plans', () => {
    const snapshot = cuttingSnapshot();
    const calculation = costing.cuttingCalculation(snapshot, 2);
    assert.deepEqual(calculation.errors, []);
    assert.equal(calculation.cuts, 3);
    assert.equal(calculation.details[0].batches, 3);
    assert.equal(calculation.capacity, 250);
});
test('initial paper cutting is zero when purchased sheets already match the press plan', () => {
    const snapshot = cuttingSnapshot();
    const plan = snapshot.parts[0].plan;
    Object.assign(plan, { per_sheet: 1, sheet_height: 510 });
    Object.assign(plan.suggestion, { sheetHeight: 510, cutsY: 1 });
    const calculation = costing.cuttingCalculation(snapshot, 2);
    assert.equal(calculation.cuts, 0);
    assert.equal(calculation.details[0].outerCutsPerBatch, 0);
});
test('final cutting includes four outside trims, double gap cuts, and restacking capacity', () => {
    const calculation = costing.cuttingCalculation(cuttingSnapshot(), 9);
    assert.deepEqual(calculation.errors, []);
    const grid = calculation.details[0];
    assert.equal(grid.outerCutsPerBatch, 4);
    assert.equal(grid.boundaryCutsX, 10);
    assert.equal(grid.boundaryCutsY, 6);
    assert.equal(grid.batches, 5);
    // Five piles: 20 outside cuts; rows first: 6*5 + 10*ceil(4*1001/250) = 200.
    assert.equal(calculation.cuts, 220);
    assert.equal(grid.direction, 'colunas primeiro');
});
test('capacity can be specified independently for each operation and changes total cuts', () => {
    const snapshot = cuttingSnapshot();
    assert.equal(costing.cuttingCalculation(snapshot, 2, 100).cuts, 6);
    assert.equal(costing.cuttingCalculation(snapshot, 2, 1000).cuts, 1);
    assert.ok(costing.cuttingCalculation(snapshot, 9, 100).cuts >
        costing.cuttingCalculation(snapshot, 9, 250).cuts);
    for (const capacity of [0, -1, 1.5, 'bad']) {
        assert.ok(costing.cuttingCalculation(snapshot, 2, capacity).errors.length, String(capacity));
    }
});
test('80 mm pile uses the selected paper thickness, rounds down, and permits manual 250', () => {
    assert.equal(costing.cuttingPile('0,3').capacity, 266);
    assert.equal(costing.cuttingPile(0.32).capacity, 250);
    assert.equal(costing.cuttingPile(0.1).capacity, 800);
    assert.equal(costing.cuttingPile(2.5).capacity, 32);
    for (const thickness of [undefined, null, '', '0']) {
        assert.equal(costing.cuttingPile(thickness).capacity, 250);
    }
    for (const thickness of [-1, 'invalid', 81, 300]) {
        assert.ok(costing.cuttingPile(thickness).errors.length);
    }
    assert.equal(costing.cuttingPile('invalid', 250).capacity, 250);
    const snapshot = cuttingSnapshot();
    snapshot.parts[0].paperCalculation.paper = { id: 12, thickness: 0.1 };
    const initial = costing.cuttingCalculation(snapshot, 2);
    assert.equal(initial.capacity, 800);
    assert.equal(initial.cuts, 1);
    assert.equal(costing.cuttingCalculation(snapshot, 2, 250).cuts, 3);
    assert.equal(costing.cuttingCalculation(snapshot, 9).capacity, 800);
    assert.match(initial.details[0].pile.source, /80 mm/);
});
test('each mounting uses its own paper thickness and historical capacity stays fixed', () => {
    const snapshot = cuttingSnapshot();
    snapshot.parts[0].paperCalculation.paper = { id: 12, thickness: 0.1 };
    const other = JSON.parse(JSON.stringify(snapshot.parts[0]));
    other.paperCalculation.paper = { id: 13, thickness: 0.5 };
    snapshot.parts.push(other);
    const result = costing.cuttingCalculation(snapshot, 2);
    assert.equal(result.capacity, null);
    assert.deepEqual(result.details.map(d => d.capacity), [800, 160]);
    assert.equal(result.cuts, 5);
    snapshot.parts[0].paperCalculation.paper.thickness = 2;
    assert.equal(result.details[0].paperThickness, 0.1);
    assert.deepEqual(costing.cuttingCalculation(snapshot, 2, 100).details.map(d => d.capacity), [100, 100]);
});
test('guillotine grid counts shared edges only once and no exterior cut on a matching edge', () => {
    const grid = costing.guillotineGrid({ width: 200, height: 200, pieceWidth: 100, pieceHeight: 100,
        columns: 2, rows: 2, sheets: 251, capacity: 250 });
    assert.equal(grid.outerCutsPerBatch, 0);
    assert.equal(grid.boundaryCutsX, 1);
    assert.equal(grid.boundaryCutsY, 1);
    assert.equal(grid.cuts, 5);
});
test('initial cuts include outside remnants, never charge four trims when only two are necessary', () => {
    const grid = costing.guillotineGrid({ width: 720, height: 1020, pieceWidth: 350, pieceHeight: 500,
        columns: 2, rows: 2, sheets: 250 });
    assert.equal(grid.outerCutsPerBatch, 2);
    assert.equal(grid.cuts, 5);
});
test('cutting recalculates each mounting, without multiplying by print faces or offset passes', () => {
    const snapshot = cuttingSnapshot(250, 250);
    const one = costing.cuttingCalculation(snapshot, 9);
    snapshot.parts.push(clone(snapshot.parts[0]));
    snapshot.passes = 2;
    assert.equal(costing.cuttingCalculation(snapshot, 9).cuts, one.cuts * 2);
    assert.equal(costing.cuttingCalculation(snapshot, 2).cuts, 2);
});
test('unsupported impositions are explicitly incomplete, not area-ratio cutting guesses', () => {
    const snapshot = cuttingSnapshot();
    snapshot.pagination = { parts: snapshot.parts };
    assert.match(costing.cuttingCalculation(snapshot, 9).errors.join(' '), /cadernos/);
    delete snapshot.pagination;
    snapshot.component.has_die = 'true';
    assert.ok(costing.cuttingCalculation(snapshot, 9).errors.length);
    snapshot.component.has_die = 'false';
    delete snapshot.parts[0].plan.suggestion;
    assert.match(costing.cuttingCalculation(snapshot, 9).errors.join(' '), /sem grelha/);
});
test('automatic cutting uses configured cuts/hour and setup once, with zero cost if no initial cut', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(snapshot, cuttingSnapshot(501, 1001));
    delete inputs['operation:102'];
    const initial = costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2);
    assert.equal(initial.quantity, 3);
    near(initial.hours, 10 / 60 + 3 / 300);
    near(initial.cost, initial.hours * 28);
    data.costing_operations[0].cut_pile_size = '100';
    const smaller = costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2);
    assert.equal(smaller.quantity, 6);
    inputs['operation:102'] = { pileSize: '1000' };
    assert.equal(costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2).quantity, 1);
    Object.assign(snapshot.parts[0].plan, { per_sheet: 1, sheet_height: 510 });
    Object.assign(snapshot.parts[0].plan.suggestion, { sheetHeight: 510, cutsY: 1 });
    const noCut = costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2);
    assert.equal(noCut.quantity, 0);
    assert.equal(noCut.hours, 0);
    assert.equal(noCut.cost, 0);
    assert.equal(noCut.sale, 0);
});
test('manual cut quantities, hours and total amounts remain valid overrides', () => {
    const { data, snapshot, inputs } = fixture();
    Object.assign(snapshot, cuttingSnapshot());
    inputs['operation:102'] = { quantity: '12' };
    assert.equal(costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2).quantity, 12);
    inputs['operation:102'] = { hours: '0.5' };
    assert.equal(costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2).hours, 0.5);
    inputs['operation:102'] = { cost: '5', sale: '10' };
    delete snapshot.parts;
    const manual = costing.calculate(data, snapshot, inputs).lines.find(row => row.operationId === 2);
    assert.equal(manual.cost, 5);
    assert.equal(manual.sale, 10);
    assert.equal(manual.errors.length, 0);
});

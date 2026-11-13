const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const costing = require('./component-operation-costing.js');
const context = { window: { vdOperationCosting: costing }, document: { dispatchEvent() {} }, Event };
vm.runInNewContext(fs.readFileSync(__dirname + '/costing-simulation-engine.js', 'utf8'), context);
const engine = context.window.componentSimulation;
test('configurator material totals use structured prices without requiring native total columns', () => {
    const ui = fs.readFileSync(__dirname + '/costing-configurator.js', 'utf8');
    assert.ok(!ui.includes('c(L, "Total Compra")'));
    assert.ok(!ui.includes('c(L, "Total Venda")'));
    const start = ui.indexOf('oe = z.reduce(');
    const end = ui.indexOf(', re =', start);
    assert.ok(start >= 0 && end > start);
    const expression = 'const ' + ui.slice(start, end) + '; result = { oe, ne };';
    const scope = { z: [{ purchaseTotal: 10 }, { purchaseTotal: 20 }], d: costing.number,
        G: { commercial_pricing: [{ id: 24, markup: 30 }] }, window: { vdOperationCosting: costing } };
    vm.runInNewContext(expression, scope);
    assert.equal(scope.result.oe.total, 30);
    assert.equal(scope.result.ne.total, 39);
    scope.z.push({ purchaseTotal: null });
    vm.runInNewContext('{ ' + expression + ' }', scope);
    assert.equal(scope.result.oe.missing, 1);
    assert.equal(scope.result.ne.missing, 1);
});
const operations = [
    { operation_id: 7, name: 'SpeedMaster', machines: '6', waste: '150' },
    { operation_id: 4, name: 'Digital', machines: '56', waste: '15' },
    { operation_id: 13, name: 'Plastificação', machines: '20', waste: '10' },
    { operation_id: 42, name: 'BiAdesivo', machines: '', waste: '0' }
];
function args() {
    return { quantity: 140, forms: 1, waste: 150,
        component: { width: 480, height: 499, setupWasteOperations: structuredClone(operations) },
        plan: { width: 720, height: 510, poses: 1, per_sheet: 2, sheet_width: 720, sheet_height: 1020,
            component_width: 480, component_height: 499 },
        machine: { id: 6, min_width: 280, min_height: 280, max_width: 740, max_height: 530 },
        papers: [{ id: 2652, unit: 3648, width: 720, height: 1020, weight: 450, price: 1.032885714285714 }]
    };
}
test('paper choice uses latest completed purchase provenance instead of highest price across matching articles', () => {
    const input = args();
    input.papers = [
        { ...input.papers[0], id: 1, price: 9, purchase_date: 100, purchase_id: 50 },
        { ...input.papers[0], id: 2, price: 2.5, purchase_date: 200, purchase_id: 60 },
        { ...input.papers[0], id: 3, price: 1.75, purchase_date: 200, purchase_id: 61 }
    ];
    const result = engine.calculatePaper(input);
    assert.equal(result.paper.id, 3);
    assert.equal(result.paper.price, 1.75);
    assert.equal(result.paper.purchase_id, 61);
    assert.equal(result.paper.purchase_date, 200);
    input.papers[2].price = null;
    const missing = engine.calculatePaper(input);
    assert.equal(missing.paper.id, 3);
    assert.equal(missing.paper.price, null);
});
test('real generated engine prices autonomous CTP with partial RH and no consolidated tariff', () => {
    const operation = { id: 3, speed: 25, setup_minutes: 25, hourly: null, ctpResources: {
        equipment: [{ id: 1, cost: 15 }],
        labor: [{ id: 1, operators: '1', people: 1, setup: 5, per_unit: 0.1 }],
        operators: [{ id: 1, cost: 12.5 }]
    } };
    const result = engine.productionPlates(4, operation);
    assert.equal(result.errors.length, 0);
    assert.ok(Math.abs(result.hours - (4 / 25 + 25 / 60)) < 1e-8);
    assert.ok(Math.abs(result.total - 9.775) < 1e-8);
    assert.ok(Math.abs(result.execution.laborHours - 0.09) < 1e-8);
    operation.ctpResources.operators[0].cost = '';
    assert.equal(engine.productionPlates(4, operation).total, null);
});
test('generated simulation engine accepts cost-only operation tariffs without a sale price, including CTP', () => {
    const data = { commercial_pricing: [{ id: 24, markup: 30 }], costing_tariffs: [
        { id: 1, operation_id: 7, basis: 'h', cost: 85, sale: '' },
        { id: 2, operation_id: 3, basis: 'h', cost: 35, sale: '' }
    ] };
    const operations = [{ id: 7, machines: '6', speed: 7000, setup_minutes: 30, waste: 150 },
        { id: 3, machines: '18', speed: 25, setup_minutes: 25 }];
    for (const operation of operations) {
        const tariff = costing.currentTariff(data, operation.id);
        operation.hourly = tariff.sale;
        operation.cost_hourly = tariff.cost;
    }
    const settings = engine.machineSettings({ operations }, 6, 7);
    assert.equal(settings.missing.length, 0);
    assert.equal(settings.values.hourly, 85);
    assert.equal(settings.values.costHourly, 85);
    const input = args();
    const result = engine.simulatePlan({ quantity: 140, forms: 1, platesPerForm: 4, passes: 1,
        plan: { width: 720, height: 510, poses: 1, perSheet: 2 },
        machine: { id: 6, minWidth: 280, minHeight: 280, maxWidth: 740, maxHeight: 530 },
        paperPrice: input.papers[0].price, platePrice: 4.2, tariff: settings.values,
        paperCalculation: engine.calculatePaper(input), plateOperation: operations[1]
    });
    assert.equal(result.errors.length, 0, result.errors.join('; '));
    assert.equal(result.printing, result.machineCost);
    assert.equal(engine.productionPlates(4, operations[1]).errors.length, 0);
});
test('140 useful plans plus max 150 setup plans means 70 useful plus 75 setup purchase sheets', () => {
    const result = engine.calculatePaper(args());
    assert.equal(result.errors.length, 0);
    assert.equal(result.goodPlans, 140);
    assert.equal(result.wastePlans, 150);
    assert.equal(result.usefulSheets, 70);
    assert.equal(result.setupSheets, 75);
    assert.equal(result.sheets, 145);
    assert.deepEqual(Array.from(result.setupWaste.governing), ['SpeedMaster']);
});
test('higher finishing allowance replaces printing allowance, never adds it', () => {
    const input = args();
    input.component.setupWasteOperations[2].waste = '300';
    const result = engine.calculatePaper(input);
    assert.equal(result.wastePlans, 300);
    assert.equal(result.sheets, 220);
    assert.equal(result.setupSheets, 150);
    assert.deepEqual(Array.from(result.setupWaste.governing), ['Plastificação']);
});
test('an unused printing alternative never controls setup allowance', () => {
    const input = args();
    input.component.setupWasteOperations[1].waste = 5000;
    assert.equal(engine.calculatePaper(input).wastePlans, 150);
    input.machine.id = 56;
    input.waste = 15;
    assert.equal(engine.calculatePaper(input).wastePlans, 5000);
    input.component.setupWasteOperations[1].waste = 15;
    assert.equal(engine.calculatePaper(input).wastePlans, 15);
});
test('allowance applies per form with whole parent sheets rounded independently', () => {
    const input = args();
    input.quantity = 141;
    input.forms = 3;
    const result = engine.calculatePaper(input);
    assert.equal(result.goodPlans, 423);
    assert.equal(result.wastePlans, 450);
    assert.equal(result.sheets, 438);
    assert.equal(result.setupSheets + result.usefulSheets, result.sheets);
});
test('manual allowance may increase but cannot undercut the maximum configured requirement', () => {
    const input = args();
    input.waste = 0;
    assert.equal(engine.calculatePaper(input).wastePlans, 150);
    input.waste = 301;
    assert.equal(engine.calculatePaper(input).wastePlans, 301);
    assert.equal(engine.calculatePaper(input).sheets, 221);
});
test('missing requirements stay unspecified; explicit malformed or fractional requirements block calculation', () => {
    const input = args();
    input.component.setupWasteOperations[2].waste = '';
    assert.equal(engine.calculatePaper(input).errors.length, 0);
    for (const value of ['bad', -1, 1.5]) {
        input.component.setupWasteOperations[2].waste = value;
        assert.ok(engine.calculatePaper(input).errors.length);
    }
});
test('paper allowance updates printing impressions and time as well as paper purchase cost', () => {
    const input = args();
    input.component.setupWasteOperations[2].waste = 300;
    const paper = engine.calculatePaper(input);
    const result = engine.simulatePlan({ quantity: 140, forms: 1, platesPerForm: 4, passes: 1,
        plan: { width: 720, height: 510, poses: 1, perSheet: 2 },
        machine: { id: 6, minWidth: 280, minHeight: 280, maxWidth: 740, maxHeight: 530 },
        paperPrice: input.papers[0].price, platePrice: 4.2,
        tariff: { waste: 150, speed: 7000, setupMinutes: 30, hourly: 120, costHourly: 85 },
        paperCalculation: paper,
        plateOperation: { id: 3, speed: 25, hourly: 45, setup_minutes: 25 }
    });
    assert.equal(result.impressions, 440);
    assert.equal(result.sheets, 220);
    assert.ok(Math.abs(result.paper - 220 * input.papers[0].price) < 1e-8);
    assert.ok(Math.abs(result.hours - (0.5 + 440 / 7000)) < 1e-8);
});

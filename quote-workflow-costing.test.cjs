const { test } = require('node:test');
const assert = require('node:assert/strict');
const costing = require('./component-operation-costing.js');
function inkCalibrationData() {
    const months = Array.from({ length: 36 }, (_, index) =>
        new Date(Date.UTC(2023, 10 + index, 1)).toISOString().slice(0, 7));
    const colors = [
        ['Cyan', '72', '191,198', '200', '20'],
        ['Magenta', '36', '191,199', '201', '21'],
        ['Yellow', '36', '191,200', '202', '22'],
        ['Black', '36', '191,201', '203', '23'],
        ['Pantone', '18', '191,202', '204,205', '14.5']
    ];
    return {
        ink_counter_machines: [
            ...[5, 6, 7, 9, 56].map((machine_id, index) => ({
                machine_id, name: ['PrintMaster 52-4', 'SpeedMaster 74-5-HL', 'GTO 52-2',
                    'Versafire Pro C7100x', 'Versafire Pro C7500x'][index],
                status: [5, 6, 56].includes(machine_id) ? '39' : '3647',
                window_start: '2023-11-01', window_end: '2026-10-31', expected_periods: '36'
            }))
        ],
        ink_counter_periods: [5, 6].flatMap(machine_id => months.map(period_month => ({
            machine_id, period_month, records: '1', invalid_records: '0',
            negative_records: '0', impressions: '100'
        }))),
        ink_purchase_totals: colors.map(([color, quantity, categories, article_ids, weighted_price]) => ({
            color, unit: '3650', articles: String(article_ids.split(',').length),
            purchases: '12', quantity, unpriced_quantity: '0', weighted_price,
            article_ids, categories
        })),
        ink_latest_prices: colors.slice(0, 4).map(([color, , categories, article_ids, price], index) => ({
            color, article_id: article_ids, article_name: color + ' kg',
            purchase_id: String(100 + index), purchase_date: '2026-11-02 10:00:00',
            quantity: '5', price, unit: '3650', category: categories
        }))
    };
}
function fixture() {
    return {
        data: { ...inkCalibrationData(), commercial_pricing: [{ id: 1, markup: 30 }],
            costing_operations: [{ id: 7, operation_id: 7, name: 'Offset', machines: '6', active: 'true' }],
            costing_catalog: [{ id: 7, name: 'Offset' }], costing_equipment: [],
            costing_tariffs: [{ id: 1, operation_id: 7, basis: 'h', cost: 10, sale: 13 }],
            color_materials: [{ record_id: 9, color: 3728, article_id: 200, unit: 3650, name: 'Cyan', price: 20 }] },
        snapshot: { quantity: 100, tariff: { operationId: 7 }, results: {
            paper: 25, sheets: 100, machineCost: 10, printing: 13, hours: 1, plates: 0, clicks: 0, click: 0 } }
    };
}
test('offset ink consumption uses calibrated kg per press impression and the latest CMYK price per kg', () => {
    const { data, snapshot } = fixture();
    const incomplete = costing.calculate(data, snapshot);
    assert.equal(incomplete.complete, false);
    const ledger = costing.calculate(data, snapshot, { 'color:9': { quantity: '0.5' } });
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.lines.find(line => line.key === 'color:9').cost, 10);
    assert.equal(ledger.cost, 45);
    assert.equal(ledger.sale, 58.5);
    snapshot.results.impressions = 1000;
    const automatic = costing.calculate(data, snapshot);
    const ink = automatic.lines.find(line => line.key === 'color:9');
    assert.equal(ink.quantity, 10);
    assert.equal(ink.unit, 'kg');
    assert.equal(ink.cost, 200);
    assert.match(ink.source, /7,200|7200/);
    assert.match(ink.source, /#5 PrintMaster.*#6 SpeedMaster/);
    assert.equal(automatic.inkCalibration.totalImpressions, 7200);
    assert.deepEqual(automatic.inkCalibration.machineIds, [5, 6]);
});
test('ink manual totals and explicit zero consumption are frozen without inventing missing prices', () => {
    const { data, snapshot } = fixture();
    data.color_materials[0].price = null;
    data.ink_latest_prices[0].price = null;
    assert.equal(costing.calculate(data, snapshot, { 'color:9': { quantity: '1' } }).complete, false);
    const ledger = costing.calculate(data, snapshot, { 'color:9': { cost: '7.5' } });
    assert.equal(ledger.lines.find(line => line.key === 'color:9').cost, 7.5);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    data.ink_latest_prices[0].price = 20;
    assert.equal(costing.calculate(data, snapshot, { 'color:9': { quantity: '0' } }).lines.find(line => line.key === 'color:9').cost, 0);
});
test('Pantone consumption is per active spot row and uses the kg-weighted purchase price', () => {
    const { data, snapshot } = fixture();
    data.color_materials = [{ record_id: 10, color: 3732, color_name: 'Pantone',
        pantone: 'PANTONE 123', article_id: 999, unit: 3649, name: 'PANTONE 123', price: 999 }];
    snapshot.results.impressions = 1000;
    const ledger = costing.calculate(data, snapshot);
    const ink = ledger.lines.find(line => line.key === 'color:10');
    assert.equal(ink.quantity, 2.5);
    assert.equal(ink.unit, 'kg');
    assert.equal(ink.cost, 36.25);
    assert.equal(ink.inkCalibration.priceSource.method, 'weighted-average-by-kg');
    assert.equal(ink.inkCalibration.pricePerKg, 14.5);
    assert.match(ink.source, /média ponderada/);
});
test('offset ink uses only offset impressions in a mixed offset/digital simulation', () => {
    const { data, snapshot } = fixture();
    snapshot.results.impressions = 10000;
    snapshot.mixed = { offset: { result: { impressions: 2000 } },
        digital: { result: { impressions: 8000 } } };
    const ink = costing.calculate(data, snapshot).lines.find(line => line.key === 'color:9');
    assert.equal(ink.quantity, 20);
});
test('calibration stores separate CMYK bases, weighted Pantone price, period and machine provenance', () => {
    const data = inkCalibrationData();
    const calibration = costing.inkCalibration(data);
    assert.equal(calibration.complete, true, calibration.generalErrors.join('; '));
    assert.equal(calibration.window.start, '2023-11-01');
    assert.equal(calibration.window.end, '2026-10-31');
    assert.deepEqual(calibration.machineIds, [5, 6]);
    assert.equal(calibration.machines.find(machine => machine.id === 5).monthly.length, 36);
    assert.deepEqual(['Cyan', 'Magenta', 'Yellow', 'Black'].map(color =>
        calibration.colors[color].pricePerKg), [20, 21, 22, 23]);
    assert.deepEqual(['Cyan', 'Magenta', 'Yellow', 'Black'].map(color =>
        calibration.colors[color].kilograms), [72, 36, 36, 36]);
    assert.equal(calibration.colors.Pantone.pricePerKg, 14.5);
    assert.equal(calibration.colors.Pantone.priceSource.method, 'weighted-average-by-kg');
});
test('non-kg Pantone purchases are recorded but never converted into the kg rate', () => {
    const data = inkCalibrationData();
    const pantone = data.ink_purchase_totals.find(row => row.color === 'Pantone');
    pantone.unit = '3649';
    pantone.quantity = '12';
    pantone.weighted_price = '999';
    const calibration = costing.inkCalibration(data);
    assert.equal(calibration.colors.Pantone.kilograms, null);
    assert.equal(calibration.colors.Pantone.pricePerKg, null);
    assert.ok(calibration.colors.Pantone.errors.some(error => error.includes('kg')));
    assert.ok(calibration.excludedPurchases.some(row => row.color === 'Pantone' && row.unit === '3649'));
});
test('known digital white toner is excluded, while an unknown Pantone-category article blocks calibration', () => {
    const data = inkCalibrationData();
    data.ink_purchase_totals.push({ color: 'excluded', unit: '3649', unit_name: 'Un.',
        quantity: '9', purchases: '9', article_ids: '2177', categories: '191,202' });
    assert.equal(costing.inkCalibration(data).generalErrors.length, 0);
    data.ink_purchase_totals.at(-1).article_ids = '2177,99999';
    assert.ok(costing.inkCalibration(data).generalErrors.some(error => error.includes('sem classificação')));
});
test('incomplete counter periods block automatic ink estimates but manual quantity and cost remain explicit', () => {
    const { data, snapshot } = fixture();
    data.ink_counter_periods.pop();
    snapshot.results.impressions = 1000;
    const incomplete = costing.calculate(data, snapshot);
    assert.equal(incomplete.complete, false);
    assert.equal(incomplete.lines.find(line => line.key === 'color:9').quantity, null);
    assert.ok(incomplete.inkCalibration.generalErrors.some(error => error.includes('36 meses')));
    const manual = costing.calculate(data, snapshot, { 'color:9': { quantity: '1' } });
    assert.equal(manual.lines.find(line => line.key === 'color:9').quantity, 1);
    assert.equal(manual.lines.find(line => line.key === 'color:9').cost, 20);
});
test('printing machine alternatives are selected by the plan, not required component operations', () => {
    const { data, snapshot } = fixture();
    data.color_materials = [];
    data.costing_operations.push({ id: -4, operation_id: 4, name: 'Digital', machines: '56',
        active: 'true', discovered: true });
    assert.equal(costing.calculate(data, snapshot).complete, true);
    data.costing_operations[1].discovered = false;
    const alternative = costing.calculate(data, snapshot);
    assert.equal(alternative.complete, true, alternative.errors.join('; '));
    assert.equal(alternative.lines.some(line => line.operationId === 4), false);
});
test('explicit CTP is not charged when the selected alternative produces no plates', () => {
    const { data, snapshot } = fixture();
    data.color_materials = [];
    data.costing_operations.push({ id: 138, operation_id: 3, name: 'Produção de Chapas', active: 'true' });
    data.costing_operations.push({ id: -43, operation_id: 43, name: 'Imposição', active: 'true', discovered: true });
    snapshot.results.plates = 0;
    const ledger = costing.calculate(data, snapshot);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
    assert.equal(ledger.lines.some(line => line.operationId === 3), false);
    assert.equal(ledger.lines.some(line => line.operationId === 43), false);
});
test('administrative operation converts annual OPs and annualized invoices to documents per hour', () => {
    const { data, snapshot } = fixture();
    data.costing_operations = [{ id: -44, operation_id: 44, name: 'Custos administrativos', active: 'true' }];
    data.costing_tariffs = [];
    data.color_materials = [];
    data.administrative_calibration = [{
        period_start: '2025-01-01',
        period_end: '2026-11-06',
        excluded_type: '1475',
        op_count: '2000',
        invoice_count: '1758',
        janine_rate: '12.5',
        carla_rate: '12.5'
    }];
    const ledger = costing.calculate(data, snapshot);
    const admin = ledger.lines.find(line => line.operationId === 44);
    const periodYears = 1 + 310 / 365;
    const annualInvoices = 1758 / periodYears;
    const documentsPerHour = (2000 + annualInvoices) / (220 * 8);
    const expected = 25 / documentsPerHour;
    assert.equal(admin.quantity, 1);
    assert.equal(admin.unit, 'componente');
    assert.ok(Math.abs(admin.cost - expected) < 1e-8);
    assert.ok(Math.abs(admin.sale - expected * 1.3) < 1e-8);
    assert.equal(admin.adminCalibration.annualWorkingHours, 1760);
    assert.ok(Math.abs(admin.adminCalibration.annualInvoices - annualInvoices) < 1e-8);
    assert.ok(Math.abs(admin.adminCalibration.documentsPerHour - documentsPerHour) < 1e-8);
    assert.ok(Math.abs(admin.cost - 14.9121089337) < 1e-8);
    assert.match(admin.source, /documentos\/h/);
    assert.equal(ledger.complete, true, ledger.errors.join('; '));
});
test('administrative operation stays explicit when calibration data is missing', () => {
    const { data, snapshot } = fixture();
    data.costing_operations = [{ id: -44, operation_id: 44, name: 'Custos administrativos', active: 'true' }];
    data.costing_tariffs = [];
    data.color_materials = [];
    const ledger = costing.calculate(data, snapshot);
    const admin = ledger.lines.find(line => line.operationId === 44);
    assert.equal(admin.cost, null);
    assert.equal(ledger.complete, false);
    assert.ok(admin.errors.some(error => error.includes('custo/h de Janine ou Carla em falta')));
});

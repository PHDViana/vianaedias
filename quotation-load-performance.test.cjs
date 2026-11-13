const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ui = fs.readFileSync(path.join(__dirname, 'costing-configurator.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, 'costing-configurator.php'), 'utf8');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const start = ui.indexOf('H = !refresh && initialReport.catalog && initialReport.simulation');
const end = ui.indexOf(',\n    W =', start);
assert.ok(start > 0 && end > start, 'generated report-loading anchor');
const load = new AsyncFunction('refresh', 'initialReport', 'i', 'url_for', 'V', 'l',
    'return (' + ui.slice(start + 4, end) + ');');

test('initial load uses embedded data without a duplicate report request', async () => {
    let calls = 0;
    assert.equal(await load(false, {catalog: '{}', simulation: '{}'}, async () => { calls++; }), null);
    assert.equal(calls, 0);
});

test('each refresh fetches fresh report data once, never the initial snapshot', async () => {
    const responses = [];
    const fetchReport = async () => { const response = {revision: responses.length + 1}; responses.push(response); return response; };
    for (let revision = 1; revision <= 2; revision++) {
        const response = await load(true, {catalog: '{}', simulation: '{}'}, fetchReport, (_, query) => query, 25, '146-24/262-30');
        assert.equal(response.revision, revision);
    }
    assert.equal(responses.length, 2);
    assert.match(ui, /addEventListener\("click", \(\) => t\(true\)\.catch\(Se\)\)/);
});

test('missing embedded JSON falls back to one real report fetch', async () => {
    let calls = 0;
    await load(false, {}, async () => { calls++; return {}; }, (_, query) => query, 25, '146-24/262-30');
    assert.equal(calls, 1);
});

const materialStart = ui.indexOf('    const record = G.material_records?.find');
const materialEnd = ui.indexOf(' const a = e.querySelector', materialStart);
assert.ok(materialStart > 0 && materialEnd > materialStart, 'structured-material anchor');
const material = new AsyncFunction('G', 'e', 'd', 'u',
    ui.slice(materialStart, materialEnd) + '\nreturn null;');
const row = {querySelector: () => ({getAttribute: () => '277-7'})};
const number = value => value == null || value === '' ? null : Number(value);
const data = (price = '4') => ({
    material_records: [{record_id: '7', internal_id: '8', article_id: '9', quantity: '3',
        sale_unit_price: '5', internal_name: 'Papel', article_name: 'Artigo', internal_paper: '1', article_paper: '1'}],
    material_purchases: [{record_id: '7', price, purchase_id: '42', purchase_date: '100'}],
    papers: []
});

test('structured material preserves names, quantities and purchase provenance without native forms', async () => {
    const result = await material(data(), row, number, () => '');
    assert.equal(result.name, 'Papel');
    assert.equal(result.materialName, 'Artigo');
    assert.equal(result.recordId, 7);
    assert.equal(result.materialId, 9);
    assert.equal(result.paper, true);
    assert.equal(result.quantity, 3);
    assert.equal(result.purchaseTotal, 12);
    assert.equal(result.purchaseReference.purchase_id, '42');
});

test('NULL latest material price blocks calculation instead of falling back', async () => {
    const result = await material(data(null), row, number, () => '');
    assert.equal(result.purchaseUnitPrice, null);
    assert.equal(result.purchaseTotal, null);
});

test('multi-value material references retain the native-form fallback', async () => {
    const values = data();
    values.material_records[0].article_id = '9,10';
    assert.equal(await material(values, row, number, () => ''), null);
});

test('bulk SQL preserves numeric references, purchase chronology and stock rules', () => {
    assert.match(php, /PARTITION BY p\.field_1033\+0,p\.field_1035 ORDER BY p\.date_added DESC,p\.id DESC/);
    assert.match(php, /WHERE p\.field_1032 IN\(39,3790\) AND p\.field_1034>0/);
    assert.match(php, /GROUP BY s\.field_1187\+0/);
    assert.match(php, /COALESCE\(paper_stock\.stock_records,0\)/);
    assert.match(php, /COALESCE\(paper_stock\.invalid_stock,0\)/);
    assert.doesNotMatch(php, /purchase_rank=1 AND.*price IS NOT NULL/);
});

test('spiral articles are unit-filtered and use the latest purchase per compatible article', () => {
    const query = php.match(/'spiral_articles' => "([^"]+)"/)?.[1];
    assert.ok(query, 'spiral article query');
    assert.match(query, /c\.field_1374=3649/);
    assert.match(query, /FIND_IN_SET\('274',c\.field_1019\).*FIND_IN_SET\('275',c\.field_1019\)/);
    assert.match(query, /p\.field_1032 IN\(39,3790\) AND p\.field_1034>0/);
    assert.match(query, /ORDER BY p\.date_added DESC,p\.id DESC LIMIT 1/);
    assert.doesNotMatch(query, /ROW_NUMBER/);
});

test('all simulation detail surfaces use the editable costing context', () => {
    const engine = fs.readFileSync(path.join(__dirname, 'costing-simulation-engine.js'), 'utf8');
    assert.match(ui, /vdOperationCosting\.get\(m\.id\)\.detail\(a\)/);
    assert.match(ui, /vdOperationCosting\.get\(m\.id\)\.detail\(e\)/);
    assert.match(engine, /vdOperationCosting\.get\(n\.component\.id\)\.detail\(U\(e,/);
    assert.doesNotMatch(ui + engine, /vdOperationCosting\.detail\([ae]\.allOperations\)/);
});

test('saved choices live in their own accessible tab', () => {
    assert.match(ui, /\["Escolhas guardadas", panel\(\)\]/);
    assert.match(ui, /savedTab\.append\(operationPanel\.querySelector\("#cb-final-cost-lines"\)\)/);
    assert.match(ui, /savedTab, operationPanel/);
});

test('Escape closes only the foreground dialog and respects cancellation during saving', () => {
    const vm = require('node:vm');
    let handler;
    let closed = 0;
    let cancelled = false;
    const background = { close: () => { throw new Error('Background dialog must remain open'); } };
    const foreground = {
        dispatchEvent: event => { assert.equal(event.type, 'cancel'); return !cancelled; },
        close: () => { closed++; }
    };
    const window = {
        document: { querySelectorAll: () => [background, foreground] },
        addEventListener: (name, listener, capture) => {
            assert.equal(name, 'keydown');
            assert.equal(capture, true);
            handler = listener;
        }
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, 'component-operation-costing.js'), 'utf8'),
        { window, Event });
    const event = { key: 'Escape', preventDefault() {}, stopImmediatePropagation() {} };
    handler(event);
    assert.equal(closed, 1);
    cancelled = true;
    handler(event);
    assert.equal(closed, 1);
});

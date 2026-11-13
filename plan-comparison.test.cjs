const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const costing = require('./component-operation-costing.js');
const context = { window: { vdOperationCosting: costing }, document: { dispatchEvent() {} }, Event };
vm.runInNewContext(fs.readFileSync(__dirname + '/costing-simulation-engine.js', 'utf8'), context);
const engine = context.window.componentSimulation;
const plan = { machineId: 6, sheetWidth: 720, sheetHeight: 1020,
    planWidth: 720, planHeight: 510, cutsX: 1, cutsY: 2, perSheet: 2,
    modeId: '3747', rotated: false, poses: 1, waste: 0.3 };

test('all distinct geometries reach automatic costing, including lower-capacity compact plans', () => {
    const compact = { ...plan, planWidth: 510, planHeight: 520, perSheet: 2, waste: 0.1 };
    const larger = { ...plan, planWidth: 740, planHeight: 530, poses: 2, perSheet: 3 };
    const plans = engine.usefulGeneratedPlans([plan, compact, larger, { ...plan }]);
    assert.equal(plans.length, 3);
    const selected = engine.selectedGeneratedPlans(plans, new Set());
    assert.equal(selected.length, 3);
    assert.ok(selected.includes(compact));
    const manual = engine.selectedGeneratedPlans(plans, new Set([engine.generatedPlanKey(compact)]));
    assert.equal(manual.length, 1);
    assert.equal(manual[0], compact);
});

function row(id, total, errors = []) {
    return { id, quantity: 140, quantityId: 1, basis: 'quantity', total, errors,
        parts: [{ option: { modeId: '3747' } }] };
}
test('ranking uses complete totals, not input order, paper price or capacity', () => {
    const expensiveFilm = row('cheap-paper-expensive-film', 150);
    expensiveFilm.paper = 10;
    const economical = row('higher-paper-lower-complete-total', 120);
    economical.paper = 20;
    const ranked = engine.rankGeneratedResults([expensiveFilm, economical, row('incomplete', null, ['Missing tariff'])]);
    assert.equal(ranked[0].id, economical.id);
    assert.equal(ranked[0].bestForQuantity, true);
    assert.equal(ranked.filter(row => row.bestForQuantity).length, 1);
});
test('incomplete prices never become a green best plan or a zero-price winner', () => {
    const incomplete = engine.rankGeneratedResults([row(1, null), row(2, null, ['Missing film'])]);
    assert.ok(incomplete.every(row => !row.bestForQuantity));
    const finite = engine.rankGeneratedResults([row(1, null), row(2, 35)]);
    assert.equal(finite[0].id, 2);
    assert.equal(finite[0].bestForQuantity, true);
});
test('comparison reflects the customer percentage and the rounded final total without discounting costs', () => {
    const data = {commercial_pricing:[{discount:'12,5'}]};
    const price = costing.customerPrice(data, 341.75, 140);
    assert.equal(price.discountPercent, 12.5);
    assert.equal(price.grossSale, 341.75);
    assert.equal(price.sale, 299.03);
    assert.equal(price.unitSale, 299.03 / 140);
    assert.equal(costing.customerPrice(data, null, 140).sale, null);
    assert.equal(costing.customerPrice({commercial_pricing:[{discount:100}]}, 12, 100).sale, 0);
    assert.throws(() => costing.customerPrice({commercial_pricing:[{discount:101}]}, 12, 100));
    const extra = costing.customerPrice({commercial_pricing:[{discount:25,extra_discount:5}]},100,10);
    assert.equal(extra.sale,70);
    assert.equal(extra.totalDiscountPercent,30);
    assert.equal(extra.extraDiscountPercent,5);
    assert.throws(() => costing.customerPrice({commercial_pricing:[{discount:80,extra_discount:21}]},100,10));
});
test('lamination exclusion bands are red and follow the actual direction and margins', () => {
    const nodes = [];
    context.document.createElementNS = (ns, tag) => ({
        tag, attrs:{}, children:[],
        setAttribute(name, value) { this.attrs[name] = value; },
        append(node) { this.children.push(node); }
    });
    const svg = {append(node) { nodes.push(node); }};
    const component = {lamination_front:'matt',lamination_back:'none'};
    context.drawLaminationMargins(svg, {planWidth:510,planHeight:520,
        laminationMargins:{entry:10,exit:20}}, component);
    assert.equal(nodes.length, 4);
    assert.equal(nodes[0].attrs.fill, '#dc2626');
    assert.equal(nodes[0].attrs.height, '10');
    assert.equal(nodes[2].attrs.y, '500');
    assert.equal(nodes[2].attrs.height, '20');
    assert.equal(nodes[1].textContent, 'Entrada: 10 mm');
    assert.equal(nodes[3].textContent, 'Saída: 20 mm');
    nodes.length = 0;
    context.drawLaminationMargins(svg, {planWidth:720,planHeight:510,
        laminationMargins:{entry:12,exit:10}}, component);
    assert.equal(nodes[0].attrs.width, '12');
    assert.equal(nodes[2].attrs.x, '710');
    assert.equal(nodes[1].textContent, 'Entrada: 12 mm');
    assert.match(nodes[1].attrs.transform, /^rotate\(-90 /);
    nodes.length = 0;
    context.drawLaminationMargins(svg, {planWidth:720,planHeight:510},
        {lamination_front:'none',lamination_back:'none'});
    assert.equal(nodes.length, 0);
});
test('Versafire proposes standard 487 × 330 sheets and keeps long-sheet alternatives', () => {
    const component = { width: 85, height: 55, bleed: 3, spacing: 3, faces: 1256 };
    const machine = { id: 56, min_width: 150, min_height: 100, max_width: 1250, max_height: 330,
        print_width: 1240, print_height: 320, gripper: 0 };
    const { plans, errors } = engine.possiblePlans(component, machine, [{ id: 1, unit: 3648, width: 720, height: 1020 }]);
    assert.equal(errors.length, 0);
    const sizes = new Set(plans.map(plan => plan.planWidth + 'x' + plan.planHeight));
    assert.ok(sizes.has('487x330'));
    assert.ok([...sizes].some(size => Number(size.split('x')[0]) > 487));
    const standard = plans.find(plan => plan.planWidth === 487 && plan.sheetWidth === 1020);
    assert.equal(standard.cutsX, 2);
    assert.equal(standard.trimWidth, 1020 - 2 * 487);
});
test('publications above 4 pages are counted as distinct leaves', () => {
    assert.equal(context.paginatedLeaves({ pages: 76 }, 2), 38);
    assert.equal(context.paginatedLeaves({ pages: 77 }, 2), 39);
    assert.equal(context.paginatedLeaves({ pages: 12 }, 1), 12);
    assert.equal(context.paginatedLeaves({ pages: 4 }, 2), 0);
    assert.equal(context.paginatedLeaves({ pages: '' }, 2), 0);
});
test('sewn publications use folded signatures in multiples of 4 pages', () => {
    assert.equal(context.paginatedLeaves({ pages: 76 }, 2, true), 38);
    assert.equal(context.paginatedLeaves({ pages: 74 }, 2, true), 38);
    assert.equal(context.sewnSignatures(8), 1);
    assert.equal(context.sewnSignatures(6), 2);
    assert.equal(context.sewnSignatures(2), 1);
    assert.equal(context.sewnSignatures(32), 2);
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, 'product-configurator-ui.js'), 'utf8');
const start = source.indexOf('    function boxDrawing(');
const end = source.indexOf('    function bookNumbering()', start);
assert.ok(start >= 0 && end > start);
const draw = vm.runInNewContext(source.slice(start, end) + '\nboxDrawing;');
const models = ['folding_flaps', 'automatic_bottom', 'lid_base', 'mailer', 'rigid', 'insert', 'custom'];
let checks = 0;
const check = value => { assert.ok(value); checks++; };

for (const model of models) {
    const views = [];
    for (let rotation = 0; rotation < 4; rotation++) {
        const svg = draw(model, 300, 200, 100, rotation);
        views.push(svg);
        check(!/NaN|Infinity/.test(svg));
        const polygons = [...svg.matchAll(/<polygon data-box-face="([^"]+)" points="([^"]+)"/g)];
        check(polygons.length >= 3);
        for (const [, , points] of polygons) {
            const vertices = points.split(' ').map(point => point.split(',').map(Number));
            check(vertices.every(([x, y]) => x >= 23.99 && x <= 296.01 && y >= 19.99 && y <= 260.01));
            const area = vertices.reduce((sum, [x, y], i) => {
                const next = vertices[(i + 1) % vertices.length];
                return sum + x * next[1] - next[0] * y;
            }, 0);
            check(Math.abs(area) > 0.001);
        }
        if (model === 'custom') check(polygons.length === 3);
        if (model === 'folding_flaps') check((svg.match(/data-box-face="(?:top-flap|dust-flap)"/g) || []).length === 4);
        if (model === 'automatic_bottom') check((svg.match(/data-box-face="automatic-bottom"/g) || []).length === 4);
        if (model === 'lid_base') check(svg.includes('data-box-face="separate-lid"') && svg.includes('data-box-face="lid-rim"'));
        if (model === 'mailer') check(svg.includes('data-box-face="hinged-lid"') && (svg.match(/data-box-face="lid-ear"/g) || []).length === 2);
        if (model === 'insert') check(svg.includes('data-box-face="divider"'));
    }
    check(views[0] !== views[1]);
    check(views[0] === draw(model, 300, 200, 100, 4));
}
check(new Set(models.map(model => draw(model, 300, 200, 100))).size === 7);
check(draw('mailer', 100, 100, 50, 0) !== draw('mailer', 100, 100, 50, 2));
for (const dimensions of [[0, 20, 10], [-1, 20, 10], ['', 20, 10], [Infinity, 20, 10], ['bad', 20, 10]]) {
    check(!draw('custom', ...dimensions).includes('<svg'));
}
check(draw('custom', 300, 200, 100) === draw('custom', 600, 400, 200));
check(draw('custom', 300, 200, 100) !== draw('custom', 300, 200, 200));
for (const model of models) {
    for (const dimensions of [[1, 10000, 1], [10000, 1, 10000], [10000, 10000, 1]]) {
        const svg = draw(model, ...dimensions);
        check(!/NaN|Infinity/.test(svg) && svg.includes('<polygon'));
    }
}
console.log(`${checks} box geometry, rotation, visibility and bounds checks passed.`);
const formatStart = source.indexOf('    function formatDrawing(');
const format = vm.runInNewContext(source.slice(formatStart, start) + '\nformatDrawing;');
for (const [width, height] of [[210, 297], [297, 210], [100, 100], [1, 10000], [10000, 1], [210.5, 297.5]]) {
    const svg = format(width, height);
    const rect = svg.match(/data-format-sheet x="([^"]+)" y="([^"]+)" width="([^"]+)" height="([^"]+)"/);
    assert.ok(rect);
    const [, x, y, w, h] = rect.map(Number);
    assert.ok(Math.abs(w / h - width / height) < 1e-8);
    assert.ok(x >= 70 && y >= 32 && x + w <= 290 && y + h <= 192);
    assert.ok(svg.includes(`Largura: ${width} mm`) && svg.includes(`Altura: ${height} mm`));
    assert.ok(!/NaN|Infinity/.test(svg));
}
for (const pair of [[0, 100], [100, ''], [-1, 100], [Infinity, 100], ['<script>', 100]]) {
    assert.ok(!format(...pair).includes('<svg'));
}
console.log('Proportional format dimensions, labels, orientation and invalid-input checks passed.');

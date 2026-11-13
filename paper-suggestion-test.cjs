const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, 'product-configurator-ui.js'), 'utf8');
const start = source.indexOf('    function paperSuggestion(');
const end = source.indexOf('    function renderComponent(', start);
assert.ok(start >= 0 && end > start);
const suggest = vm.runInNewContext(source.slice(start, end) + '\npaperSuggestion;');
const printed = {kind: 'printed', name: 'Produto', request: {paper: 'Papel escolhido'}};
const before = JSON.stringify(printed);
for (const [type, category, component, expected, menu] of [
    [3711, 3707, printed, '350'],
    [3709, 3707, printed, 'envelope'],
    [3824, 3800, printed, '170'],
    [3753, 3752, printed, 'autocopiativo'],
    [3832, 3743, printed, 'kraft'],
    [3761, 3743, printed, 'vinil'],
    [3751, 3743, printed, '350'],
    [3751, 3743, {kind: 'material'}, '2 mm'],
    [3757, 3756, {kind: 'printed', role: 'cover'}, '250'],
    [3758, 3756, {kind: 'printed', role: 'cover'}, 'revestimento'],
    [3758, 3756, {kind: 'printed', name: 'Miolo'}, 'creme'],
    [3714, 3712, {kind: 'printed', role: 'jacket'}, 'sobrecapa'],
    [3714, 3712, {kind: 'printed', name: 'Miolo'}, '115'],
    [9999, 3800, printed, '250', true],
    [9998, 9997, printed, '170'],
    [3825, 3825, printed, 'Indique']
]) {
    assert.ok(suggest(type, category, component, menu).includes(expected), `Suggestion for ${type}`);
}
assert.equal(JSON.stringify(printed), before);
assert.ok(source.includes('placeholder="${esc(paperSuggestion('));
console.log('18 contextual paper suggestion and input-preservation checks passed.');

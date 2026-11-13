const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const root = process.argv[2] || __dirname;
const bundled = fs.readFileSync(path.join(root, 'costing-asset.js'), 'utf8');
const readable = fs.readFileSync(path.join(root, 'costing-configurator.js'), 'utf8');
let assertions = 0;
const equal = (actual, expected) => { assertions++; assert.deepEqual(actual, expected); };
const callbacks = [
    {text: bundled, start: 'Q.save=async function(e){', end: '}),K=r("a","Comparar planos")', data: 'H', saved: 'z', panel: 'Q'},
    {text: readable, start: 'operationCosting.save = async function (e) {', end: '}), ee = r("a", "Comparar planos")', data: 'G', saved: 'Y', panel: 'operationCosting'},
];
async function harness(config, versions, response) {
    const start = config.text.indexOf(config.start);
    const end = config.text.indexOf(config.end, start);
    assert.ok(start >= 0 && end > start, 'Actual save callback boundaries');
    const callback = config.text.slice(start, end + 1).replace(/^[^=]+=\s*/, '');
    const requests = [];
    const data = {workflow_versions: versions, workflow_token: 'fixture-token'};
    const saved = new Map();
    let renders = 0;
    const context = {
        URLSearchParams, crypto, console,
        l: '146-495/262-780',
        [config.data]: data, [config.saved]: saved,
        [config.panel]: {check() {}, renderSaved() { renders++; }},
        fetch: async (url, options) => {
            requests.push(JSON.parse(options.body.get('payload')));
            return response ? response(requests.length) : {
                ok: true, json: async () => ({ok: true, data: {
                    workflow_version: 900 + requests.length,
                    quantity_id: requests.at(-1).quantity_id === 'rubrics' ? '' : requests.at(-1).quantity_id,
                }}),
            };
        },
    };
    const save = vm.runInNewContext('(' + callback + ')', context);
    return {save, requests, data, saved, renders: () => renders};
}
(async () => {
    for (const config of callbacks) {
        for (const zero of ['0', 0, '', null]) {
            const test = await harness(config, [{id: '884', quantity_id: zero}]);
            const selection = {quantityId: 'rubrics', parameters: {unchanged: true}};
            await test.save(selection);
            equal(test.requests[0].expected_version, 884);
            equal(test.requests[0].quantity_id, 'rubrics');
            equal(test.requests[0].calculation.unchanged, true);
            await test.save(selection);
            equal(test.requests[1].expected_version, 901);
            equal(test.requests[1].request_id, test.requests[0].request_id);
            equal(test.renders(), 2);
        }
        const test = await harness(config, [
            {id: '884', quantity_id: '0'}, {id: '885', quantity_id: '120'},
            {id: '886', quantity_id: '0'}, {id: '887', quantity_id: '121'},
        ]);
        await test.save({quantityId: 'rubrics', parameters: {}});
        equal(test.requests[0].expected_version, 886);
        await test.save({quantityId: '120', parameters: {}});
        equal(test.requests[1].expected_version, 885);
        await test.save({quantityId: '122', parameters: {}});
        equal(test.requests[2].expected_version, 0);
        const empty = await harness(config, []);
        await empty.save({quantityId: 'rubrics', parameters: {}});
        equal(empty.requests[0].expected_version, 0);
        const failure = await harness(config, [{id: '884', quantity_id: '0'}], async () => ({
            ok: false, json: async () => ({ok: false, error: 'Já existe uma versão mais recente. Atualize antes de gravar.'}),
        }));
        await assert.rejects(() => failure.save({quantityId: 'rubrics', parameters: {}}), /Já existe uma versão mais recente/);
        assertions++;
        equal(failure.requests.length, 1);
        equal(failure.data.workflow_versions.length, 1);
        equal(failure.saved.size, 0);
        equal(failure.renders(), 0);
    }
    console.log(`PASS ${assertions} workflow save-version assertions (actual production/source callbacks)`);
})().catch(error => { console.error(error); process.exitCode = 1; });

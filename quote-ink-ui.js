(function () {
    'use strict';
    const root = document.querySelector('#vd-quote-ink');
    if (!root) return;
    const content = root.querySelector('#vd-ink-content');
    const message = root.querySelector('#vd-ink-message');
    const component = root.querySelector('#vd-ink-component');
    let state = JSON.parse(root.querySelector('#vd-ink-state').textContent);
    let selectedPdf = null;
    let busy = false;
    const node = (tag, text) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        return element;
    };
    const number = value => Number(value).toLocaleString('pt-PT', { maximumFractionDigits: 3 });
    const date = value => new Date(Number(value) * 1000).toLocaleString('pt-PT', { timeZone: 'Europe/Lisbon' });
    const url = () => {
        const result = new URL('index.php', location.href);
        result.search = new URLSearchParams({ module: 'items/vd_quote_ink', path: root.dataset.path, component: component.value });
        return result;
    };
    function table(headers, rows) {
        const element = node('table');
        element.className = 'table table-bordered';
        const header = element.createTHead().insertRow();
        headers.forEach(text => header.append(node('th', text)));
        const body = element.createTBody();
        rows.forEach(values => {
            const row = body.insertRow();
            values.forEach(text => row.append(node('td', text)));
        });
        return element;
    }
    async function readState() {
        const request = url();
        request.searchParams.set('api', 'state');
        const response = await fetch(request, { credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.error || 'Não foi possível ler a análise.');
        state = result.data;
    }
    async function perform(action, payload, file) {
        const body = new FormData();
        body.append('api', action);
        body.append('component', component.value);
        body.append('payload', JSON.stringify(payload));
        body.append('form_session_token', root.dataset.token);
        if (file) body.append('ink_pdf', file);
        const response = await fetch(url(), { method: 'POST', credentials: 'same-origin', body });
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.error || 'Análise não confirmada.');
        if (result.data.pdf_id) selectedPdf = String(result.data.pdf_id);
        await readState();
        render();
        message.textContent = result.data.message;
        message.className = 'alert alert-success';
    }
    async function run(callback) {
        if (busy) return;
        busy = true;
        component.disabled = true;
        content.querySelectorAll('button').forEach(button => { button.disabled = true; });
        message.textContent = 'A processar…';
        message.className = 'alert alert-info';
        try { await callback(); }
        catch (error) {
            message.textContent = error.message || 'Falha ao processar a análise.';
            message.className = 'alert alert-danger';
        } finally {
            busy = false;
            component.disabled = false;
            content.querySelectorAll('button').forEach(button => { button.disabled = false; });
        }
    }
    function button(text, callback) {
        const element = node('button', text);
        element.type = 'button';
        element.className = 'btn btn-default';
        element.addEventListener('click', () => run(callback));
        return element;
    }
    function input(label, value, max) {
        const wrapper = node('label', label + ' ');
        wrapper.style.marginRight = '15px';
        const element = node('input');
        element.type = 'number';
        element.min = '0';
        element.max = String(max);
        element.step = '0.01';
        element.value = String(value);
        element.className = 'form-control';
        wrapper.append(element);
        return { wrapper, element };
    }
    function coverage(pdf) {
        content.append(node('h3', 'Cobertura do PDF · ' + pdf.name),
            node('p', 'PDF #' + pdf.id + ' · ' + date(pdf.created_at) + ' · utilizador #' + pdf.created_by + ' · SHA-256 ' + pdf.hash));
        if (pdf.result.spots.length) {
            const warning = node('p', 'Cores diretas detetadas: ' + pdf.result.spots.join(', ') +
                '. A cobertura abaixo é equivalente CMYK; os gramas ficam bloqueados até existir um PDF CMYK validado.');
            warning.className = 'alert alert-warning';
            content.append(warning);
        }
        const link = node('a', 'Descarregar PDF analisado');
        const download = url();
        download.searchParams.set('pdf', pdf.id);
        link.href = download;
        content.append(link, table(['Página', 'Formato (mm)', 'C (%)', 'M (%)', 'Y (%)', 'K (%)'],
            pdf.result.pages.map(page => [page.page, number(page.width_mm) + ' × ' + number(page.height_mm),
                ...['C', 'M', 'Y', 'K'].map(ink => number(page.coverage_percent[ink]))])));
    }
    function render() {
        content.replaceChildren();
        if (!state) { content.append(node('p', 'Crie um componente no orçamento.')); return; }
        content.append(node('h3', '1. Anexar e analisar PDF'));
        const file = node('input');
        file.type = 'file';
        file.accept = '.pdf,application/pdf';
        content.append(file, button('Analisar cobertura CMYK', () => {
            if (!file.files[0]) throw new Error('Selecione um PDF.');
            if (file.files[0].size > 5 * 1024 * 1024) throw new Error('O PDF deve ter até 5 MB.');
            return perform('upload', {}, file.files[0]);
        }));
        if (state.pdfs.length) {
            const choice = node('select');
            choice.className = 'form-control';
            state.pdfs.forEach(pdf => {
                const option = node('option', '#' + pdf.id + ' · ' + pdf.name + ' · ' + date(pdf.created_at));
                option.value = pdf.id;
                choice.append(option);
            });
            if (!state.pdfs.some(pdf => String(pdf.id) === selectedPdf)) selectedPdf = String(state.pdfs[0].id);
            choice.value = selectedPdf;
            choice.addEventListener('change', () => { selectedPdf = choice.value; render(); });
            content.append(node('h3', 'PDFs analisados (últimos 20)'), choice);
            const pdf = state.pdfs.find(item => String(item.id) === selectedPdf);
            coverage(pdf);
            if (state.versions.length && !pdf.result.spots.length) {
                content.append(node('h3', '2. Estimar consumo · valores sempre indicativos'));
                const version = node('select');
                version.className = 'form-control';
                state.versions.forEach(item => {
                    const option = node('option', 'Versão #' + item.version_no + ' (ID ' + item.id + ') · ' + item.quantity + ' un. · ' + item.state);
                    option.value = item.id;
                    version.append(option);
                });
                content.append(version);
                const mapping = node('p');
                const showMapping = () => {
                    const selected = state.versions.find(item => String(item.id) === version.value);
                    const faces = selected.modes.length === 1 && selected.modes[0] === 3747 ? 'uma página por rubrica' :
                        selected.modes.length === 1 && selected.modes[0] === 3748 ? 'frente e verso consecutivos de cada rubrica' : 'modo não suportado';
                    mapping.textContent = 'Correspondência esperada: ' + faces + '. Ordem das rubricas: ' +
                        selected.rubric_order.map((name, i) => (i + 1) + '. ' + name).join(' → ');
                };
                version.addEventListener('change', showMapping);
                showMapping();
                content.append(mapping);
                const last = state.estimates[0]?.result.parameters;
                const rates = {};
                ['C', 'M', 'Y', 'K'].forEach(ink => {
                    const field = input(ink + ' (g/m² a 100%)', last?.rates[ink] ?? 1.5, 20);
                    rates[ink] = field.element;
                    content.append(field.wrapper);
                });
                const loss = input('Perdas adicionais (%)', last?.loss_percent ?? 10, 200);
                const startup = input('Perda fixa/tinta/plano (g)', last?.startup_g ?? 5, 5000);
                content.append(loss.wrapper, startup.wrapper);
                const confirmation = node('label');
                confirmation.style.display = 'block';
                const checkbox = node('input');
                checkbox.type = 'checkbox';
                confirmation.append(checkbox, document.createTextNode(' Confirmo que o PDF corresponde às rubricas da versão selecionada, pela mesma ordem, frente e verso consecutivos; não está imposto.'));
                content.append(confirmation, button('Calcular e guardar estimativa', () => {
                    if (!checkbox.checked) throw new Error('Confirme a correspondência e a ordem das páginas.');
                    return perform('estimate', { pdf_id: Number(pdf.id), version_id: Number(version.value), mapping_confirmed: true,
                        parameters: { rates: Object.fromEntries(Object.entries(rates).map(([ink, field]) => [ink, field.value])),
                            loss_percent: loss.element.value, startup_g: startup.element.value } });
                }));
            } else if (!state.versions.length) content.append(node('p', 'Guarde uma versão no configurador para cruzar o PDF com a imposição e a tiragem.'));
        }
        content.append(node('h3', 'Histórico de estimativas (últimas 20)'));
        state.estimates.forEach(estimate => {
            const details = node('details');
            details.open = estimate === state.estimates[0];
            details.append(node('summary', '#' + estimate.id + ' · versão ID ' + estimate.version_id + ' · PDF #' + estimate.pdf_id +
                ' · ' + estimate.result.quantity + ' un. · ' + date(estimate.created_at)));
            details.append(node('p', estimate.result.pdf_name + ' · utilizador #' + estimate.created_by +
                ' · PDF SHA-256 ' + estimate.pdf_hash + ' · versão SHA-256 ' + estimate.version_hash));
            details.append(table(['Tinta', 'Impressão (g)', 'Perdas adicionais (g)', 'Perda fixa (g)', 'Total estimado (g)'],
                Object.entries(estimate.result.inks).map(([ink, values]) => [ink, number(values.printing_g),
                    number(values.extra_loss_g), number(values.fixed_loss_g), number(values.total_g)])));
            details.append(table(['Página', 'Rubrica', 'Face', 'Exemplares com desperdício'],
                estimate.result.pages.map(page => [page.page, page.rubric, page.face, number(page.printed_copies_including_waste)])));
            details.append(node('p', estimate.result.method), node('p', 'Parâmetros fixados: ' +
                Object.entries(estimate.result.parameters.rates).map(([ink, value]) => ink + ' ' + number(value) + ' g/m²').join(' · ') +
                ' · perdas ' + number(estimate.result.parameters.loss_percent) + '% · fixa ' + number(estimate.result.parameters.startup_g) + ' g/tinta/plano.'));
            estimate.result.warnings.forEach(warning => details.append(node('p', warning)));
            content.append(details);
        });
    }
    component.addEventListener('change', () => run(async () => { selectedPdf = null; await readState(); render(); message.textContent = ''; }));
    render();
})();

(() => {
    'use strict';
    const root = document.getElementById('vd-quote-preparation');
    const setup = document.getElementById('vd-quote-preparation-data');
    if (!root || !setup) return;
    const initial = JSON.parse(setup.textContent);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let model = initial.model, answers = {}, busy = false, validated = model.missing === 0;
    function render() {
        root.innerHTML = `<h3>Completar dados para o orçamento</h3>
            <p>O pedido e os valores padrão da orçamentação já foram aproveitados. Preencha apenas o que falta.
            Estas escolhas ficam no rascunho; não alteram o pedido original nem criam preços, versões ou produção.</p>
            ${model.resolved?.length ? `<div class="alert alert-info"><strong>Obtido automaticamente do pedido e do catálogo</strong><ul>${model.resolved.map(text => `<li>${escape(text)}</li>`).join('')}</ul></div>` : ''}
            <div data-message role="status"></div><form>
            ${model.fields.map(field => `<div class="form-group">
                <label style="display:block">${escape(field.label)}
                ${field.kind === 'select' ? `${field.options.length > 30 ? '<input type="search" class="form-control" data-search placeholder="Filtrar opções do catálogo" aria-label="Filtrar opções do catálogo">' : ''}
                    <select class="form-control" data-key="${escape(field.key)}" required>
                    <option value="">Escolher…</option>${field.options.map(option => `<option value="${escape(option.id)}"${String(option.id) === String(field.value) ? ' selected' : ''}>${escape(option.name)}</option>`).join('')}</select>` :
                    `<input type="number" step="any" min="${escape(field.min)}"${field.key.includes('.thickness.') ? ' max="80"' : ''}
                    class="form-control" data-key="${escape(field.key)}" value="${escape(field.value)}" required>`}
                </label></div>`).join('')}
            <p data-completeness>${model.missing ? `Faltam ${model.missing} dados obrigatórios. Algumas escolhas podem exigir dados adicionais.` : 'Dados obrigatórios completos. Pode criar o rascunho.'}</p>
            <button type="button" class="btn btn-default" data-review>Verificar dados</button>
            <button type="submit" class="btn btn-primary" data-create${model.missing ? ' disabled' : ''}>Criar Orçamento em rascunho</button>
            </form>`;
        for (const search of root.querySelectorAll('[data-search]')) {
            search.addEventListener('input', () => {
                const term = search.value.toLocaleLowerCase('pt-PT');
                for (const option of search.parentElement.querySelectorAll('option'))
                    option.hidden = Boolean(option.value) && !option.selected && !option.textContent.toLocaleLowerCase('pt-PT').includes(term);
            });
        }
        root.querySelector('form').addEventListener('change', event => {
            const key = event.target.dataset.key;
            if (!key) return;
            validated = false;
            root.querySelector('[data-create]').disabled = true;
            if (event.target.tagName === 'SELECT') review(key);
        });
        root.querySelector('[data-review]').addEventListener('click', review);
        root.querySelector('form').addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            collect();
            await run(async () => {
                const result = await api('create_quote');
                location.assign(result.url);
            });
        });
    }
    function collect() {
        for (const input of root.querySelectorAll('[data-key]')) answers[input.dataset.key] = input.value;
    }
    async function api(action) {
        const body = new URLSearchParams({api:action,form_session_token:initial.token,
            payload:JSON.stringify({config_id:initial.id,revision:model.revision,answers})});
        const response = await fetch(initial.url, {method:'POST',credentials:'same-origin',body});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível preparar o orçamento.');
        return result.data;
    }
    async function run(action) {
        if (busy) return;
        busy = true;
        for (const input of root.querySelectorAll('button,input,select')) input.disabled = true;
        try { await action(); }
        catch (error) {
            const message = root.querySelector('[data-message]');
            message.className = 'alert alert-danger'; message.textContent = error.message;
        } finally {
            busy = false;
            for (const input of root.querySelectorAll('input,select')) input.disabled = false;
            root.querySelector('[data-review]').disabled = false;
            root.querySelector('[data-create]').disabled = !validated || model.missing !== 0;
        }
    }
    async function review(changedKey) {
        if (busy) return;
        collect();
        if (typeof changedKey === 'string') {
            const prefix = changedKey.split('.')[0];
            for (const previous of Object.keys(answers)) {
                if (changedKey.endsWith('.paper') && previous.startsWith(prefix + '.thickness.')) delete answers[previous];
                if (changedKey.includes('.variant.') && previous.startsWith(prefix + '.equipment.')) delete answers[previous];
            }
        }
        await run(async () => {
            model = await api('quote_preparation');
            if (model.quote) {
                location.assign('index.php?module=items/info&path=146-' + model.quote);
                return;
            }
            validated = model.missing === 0;
            render();
        });
    }
    render();
})();

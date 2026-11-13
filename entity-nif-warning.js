(function (root) {
    function init(form, request, environment = root) {
        const field = form.querySelector('[name="fields[222]"]');
        if (!field || field.dataset.nifWarningReady) return;
        const url = new URL(form.action, environment.location.href);
        if (url.searchParams.has('id')) return;
        url.searchParams.set('module', 'items/items');
        url.searchParams.set('action', 'check_unique');
        url.searchParams.set('path', '26');
        const warning = field.ownerDocument.createElement('div');
        warning.className = 'alert alert-warning';
        warning.style.marginTop = '8px';
        warning.hidden = true;
        warning.id = field.id + '-duplicate-warning';
        warning.setAttribute('role', 'status');
        warning.setAttribute('aria-live', 'polite');
        field.insertAdjacentElement('afterend', warning);
        const descriptions = field.getAttribute('aria-describedby');
        field.setAttribute('aria-describedby', [descriptions, warning.id].filter(Boolean).join(' '));
        field.dataset.nifWarningReady = '1';
        let revision = 0, timer;
        const check = async () => {
            environment.clearTimeout(timer);
            const value = field.value.trim(), current = ++revision;
            warning.hidden = true;
            if (!value) return;
            try {
                const result = await request(url.href, value);
                if (current !== revision || field.value.trim() !== value || !form.isConnected) return;
                if (result !== true && typeof result !== 'string') throw new Error('Invalid duplicate check response');
                if (result !== true) {
                    warning.textContent = 'Este NIF já existe noutra entidade. Pode continuar e guardar, mas confirme se pretende criar um registo duplicado.';
                    warning.hidden = false;
                }
            } catch (error) {
                if (current !== revision || !form.isConnected) return;
                warning.textContent = 'Não foi possível verificar se o NIF já existe. Pode continuar a guardar; volte a verificar antes de criar uma entidade duplicada.';
                warning.hidden = false;
                environment.console.error('Falha na verificação de NIF duplicado.', error);
            }
        };
        field.addEventListener('input', () => {
            ++revision;
            warning.hidden = true;
            environment.clearTimeout(timer);
            timer = environment.setTimeout(check, 350);
        });
        field.addEventListener('blur', check);
        if (field.value.trim()) check();
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = init;
    else {
        for (const form of root.document.querySelectorAll('#items_form, #sub_items_form')) {
            init(form, async (url, value) => {
                const response = await root.fetch(url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: new URLSearchParams({fields_id: '222', fields_value: value, unique_for_each_parent: '0'})
                });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            });
        }
    }
})(typeof window === 'undefined' ? globalThis : window);

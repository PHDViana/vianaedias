(() => {
    'use strict';
    const root = document.getElementById('vd-product-configurator');
    const setup = document.getElementById('vd-product-configurator-data');
    if (!root || !setup) return;
    const initial = JSON.parse(setup.textContent), catalog = initial.catalog;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const options = (rows, selected) => rows.map(row => `<option value="${esc(row.id)}"${String(row.id) === String(selected) ? ' selected' : ''}>${esc(row.name)}</option>`).join('');
    const types = catalog.product_types.filter(row => ![3822,3823].includes(Number(row.id)))
        .sort((a,b) => Number(Number(a.id)===3825)-Number(Number(b.id)===3825) ||
            a.name.localeCompare(b.name,'pt-PT',{sensitivity:'base'}) || Number(a.id) - Number(b.id));
    let state, draft, identity, request, pending, uploads, removed, dirty;
    let importText = '', importFiles = [], importResultText = '', importSuggestions = {}, importWarnings = [];
    // Multi-request import: candidates under review in the modal, then a queue opened one request at a time.
    let importCandidates = [], importAttach = [], importQueue = [], importQueueTotal = 0, importQueuePosition = 0;
    let reviewTimer, reviewSequence = 0;
    const finishingRules = catalog.finishing_rules;
    const internalFinishingIds = new Set([18,21,33,35,...(finishingRules.crease || [])].map(Number));
    const productRules = catalog.product_rules;
    const selectedOperations = group => [...group.querySelectorAll('[data-finishing-choice]:checked')].map(input => {
        const operation = {id:Number(input.value),name:input.dataset.name};
        if(input.dataset.variants)operation.variants=JSON.parse(input.dataset.variants);
        if (operation.id === 36) {
            const details = input.closest('[data-thread-option]');
            operation.thread = {type:details.querySelector('[data-thread-type]').value.trim(),color:details.querySelector('[data-thread-color]').value.trim(),
                length_mm:details.querySelector('[data-thread-length]').value};
        }
        if (operation.id === 42) {
            const details = input.closest('[data-tape-option]');
            operation.tape = {quantity:Number(details.querySelector('[data-tape-quantity]').value),
                x_mm:details.querySelector('[data-tape-x]').value,y_mm:details.querySelector('[data-tape-y]').value};
        }
        return operation;
    });
    const printPresets = {cmyk:[3728,3729,3730,3731],pantone:[3732],cmyk_pantone:[3728,3729,3730,3731,3732],white:[3821],none:[3804]};
    function renderImportQueue() {
        const list = root.querySelector('[data-import-queue]');
        if (!list) return;
        list.replaceChildren();
        importFiles.forEach((file,index) => {
            const item = document.createElement('li');
            item.append(document.createTextNode(file.name + ' · ' + size(file.size) + ' '),
                button('Retirar',() => {importFiles.splice(index,1);dirty=true;renderImportQueue();}));
            list.append(item);
        });
        if (!importFiles.length) list.textContent = 'Pode colar uma imagem ou selecionar até 5 PDFs/imagens.';
    }
    function renderImportResult() {
        const area = root.querySelector('[data-import-result]');
        if (!area) return;
        area.replaceChildren();
        const values = Object.entries(importSuggestions || {}).filter(([key]) => key !== 'product_type_name');
        if (values.length) {
            const summary = document.createElement('p');
            summary.className = 'vr-import-summary';
            summary.textContent = 'Sugestões detetadas: ' + values.map(([key,value]) =>
                key === 'product_type' ? 'tipo de produto: ' + (importSuggestions.product_type_name || '') :
                    key === 'name' ? 'nome do trabalho' :
                    key === 'reference' ? 'referência' :
                    key === 'quantities' ? 'quantidade(s) ' + value.join(', ') :
                    key === 'width_mm' ? 'dimensões ' + value + ' × ' + (importSuggestions.height_mm || '?') + ' mm' :
                    key === 'height_mm' ? '' :
                    key === 'total_pages' ? value + ' páginas' :
                    key === 'paper' ? 'papel/material' :
                    key === 'cover_paper' ? 'papel da capa' :
                    key === 'inner_paper' ? 'papel do miolo' :
                    key === 'lamination' ? 'plastificação' + (value.target === 'cover' ? ' da capa' : '') :
                    key === 'colors' ? 'cores de impressão' :
                    key === 'faces' ? 'faces de impressão' :
                    key === 'print_mode' ? 'impressão' :
                    key === 'pantone' ? 'Pantone ' + value :
                    key === 'delivery' ? 'data pretendida' : key
            ).filter(Boolean).join(' · ') + '.';
            area.append(summary);
        }
        for (const warning of importWarnings) {
            const note = document.createElement('p');
            note.className = 'vr-warning';
            note.textContent = warning;
            area.append(note);
        }
        if (importResultText) {
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            const pre = document.createElement('pre');
            summary.textContent = 'Rever texto reconhecido';
            pre.textContent = importResultText;
            details.append(summary,pre);
            area.append(details);
        }
    }
    function queueImportFiles(files) {
        const incoming = [...files].filter(file => file && file.size).map((file,index) => {
            if (file.name) return file;
            const extension = file.type === 'image/jpeg' ? 'jpg' : file.type === 'image/tiff' ? 'tif' : 'png';
            return new File([file],`imagem-colada-${Date.now()}-${index+1}.${extension}`,{type:file.type || 'image/png'});
        });
        const existing = (state.attachments || []).filter(file => !removed.includes(Number(file.id)));
        const all = [...existing,...uploads,...importFiles,...incoming];
        if (importFiles.length + incoming.length > 5 || all.length > 10 ||
            incoming.some(file => file.size > 10*1024*1024) ||
            all.reduce((sum,file) => sum + Number(file.size),0) > 25*1024*1024) {
            message('Importe até 5 fontes de cada vez; máximo de 10 documentos por pedido, 10 MB por ficheiro e 25 MB no total.',true);
            return;
        }
        for (const file of incoming) {
            if (!/\.(pdf|jpe?g|png|tiff?|txt)$/i.test(file.name)) {
                message('A importação aceita PDF, JPG, PNG, TIFF e TXT. Outros documentos podem ser anexados na secção Documentos.',true);
                continue;
            }
            importFiles.push(file);
        }
        if (incoming.length) dirty = true;
        renderImportQueue();
    }
    function applyImportSuggestions(suggestions) {
        if (suggestions.product_type && (!categoryOf(draft.product_type) || Number(draft.product_type) === 3823)) {
            const productType = Number(suggestions.product_type);
            const category = categoryOf(productType);
            draft.product_type = productType;
            const magazine = [3712,3756,Number(productRules.brochure_category)].includes(category) &&
                productType !== Number(productRules.brochure_simple);
            const structureType = magazine ? 'magazine' : productType === 3751 ? 'folding_box' : 'standard';
            if (!draft.components.length || draft.type !== structureType) structure(structureType);
        }
        if (!identity.name && suggestions.name) identity.name = suggestions.name;
        if (!identity.reference && suggestions.reference) identity.reference = suggestions.reference;
        if (!draft.quantities.length && suggestions.quantities?.length) draft.quantities = suggestions.quantities;
        if (suggestions.delivery && !draft.request_details.delivery) draft.request_details.delivery = suggestions.delivery;
        if (suggestions.total_pages && !draft.request_details.total_pages && hasPages()) {
            draft.request_details.total_pages = suggestions.total_pages;
        }
        if (suggestions.width_mm && suggestions.height_mm) {
            if (hasPages() && !draft.request_details.final_format) {
                draft.request_details.final_format = {width_mm:suggestions.width_mm,height_mm:suggestions.height_mm};
            } else {
                const part = draft.components.find(component => component.kind === 'printed');
                if (part) {
                    part.fields = part.fields || {};
                    if (!part.fields[4218]) part.fields[4218] = String(suggestions.width_mm);
                    if (!part.fields[4219]) part.fields[4219] = String(suggestions.height_mm);
                }
            }
        }
        const printed = draft.components.filter(component => component.kind === 'printed');
        const cover = printed.find(component => component.role === 'cover');
        const inner = cover ? printed.find(component => component !== cover) : null;
        const setPaper = (component,paper) => {
            if (component && paper && !component.request?.paper) component.request = {...component.request,paper};
        };
        if (cover && (suggestions.cover_paper || suggestions.inner_paper)) {
            // "Capa: couché 300 gr" / "Miolo: 250gr" go to their own components.
            setPaper(cover,suggestions.cover_paper);
            setPaper(inner,suggestions.inner_paper);
            const missing = printed.filter(component => !component.request?.paper).map(component => component.name);
            if (missing.length) importWarnings.push('Papel não indicado para: ' + missing.join(', ') + '; complete no formulário.');
        } else if (suggestions.paper && printed.length && printed.every(component => !component.request?.paper)) {
            printed[0].request = {...printed[0].request,paper:suggestions.paper};
            if (printed.length > 1) importWarnings.push('O papel foi preenchido apenas no primeiro componente; confirme o papel de cada componente.');
        }
        if (suggestions.print_mode && printed.length) {
            for (const component of printed) {
                if (component.colors?.length) continue;
                component.request = {...component.request,printing:{mode:suggestions.print_mode,pantone:suggestions.pantone || ''}};
                component.colors = [...(suggestions.colors || [])];
                if (suggestions.faces) {
                    component.fields = component.fields || {};
                    if (!component.fields[4229]) component.fields[4229] = String(suggestions.faces);
                }
            }
        } else if (suggestions.faces && printed.length && !printed[0].fields?.[4229]) {
            printed[0].fields = printed[0].fields || {};
            printed[0].fields[4229] = String(suggestions.faces);
        }
        if (suggestions.lamination && printed.length) {
            const target = (suggestions.lamination.target === 'cover' ? cover : null) || cover || printed[0];
            target.request = {...target.request,lamination:true};
            target.fields = target.fields || {};
            const finish = field => catalog.choices.find(choice => Number(choice.fields_id) === field && choice.value === suggestions.lamination.finish);
            if (suggestions.lamination.finish) {
                const front = finish(6267), back = finish(6268);
                if (front && !target.fields[6267]) target.fields[6267] = String(front.id);
                if (suggestions.lamination.faces === 'both' && back && !target.fields[6268]) target.fields[6268] = String(back.id);
            } else {
                importWarnings.push(`Plastificação detetada (${target.name}); escolha o tipo: brilho, mate ou antirrisco.`);
            }
        }
    }
    function productTypeOptions(selected) {
        const roots = types.filter(row => !Number(row.parent_id));
        return '<option value="">Escolha o tipo de produto</option>' + roots.map(parent => {
            const children = types.filter(row => Number(row.parent_id) === Number(parent.id));
            if (!children.length) return options([parent],selected);
            return `<optgroup label="${esc(parent.name)}">` +
                options([{id:parent.id,name:parent.name + ' (escolher subcategoria no formulário)'}],selected) +
                options(children.map(row => ({id:row.id,name:parent.name + ' › ' + row.name})),selected) + '</optgroup>';
        }).join('');
    }
    function importDetails(suggestions) {
        const parts = [];
        if (suggestions.width_mm && suggestions.height_mm) parts.push(suggestions.width_mm + ' × ' + suggestions.height_mm + ' mm');
        if (suggestions.total_pages) parts.push(suggestions.total_pages + ' páginas');
        if (suggestions.print_mode === 'none') parts.push('sem impressão');
        else if (suggestions.print_mode) parts.push({cmyk:'CMYK',pantone:'Pantone',cmyk_pantone:'CMYK + Pantone'}[suggestions.print_mode] +
            (suggestions.pantone ? ' ' + suggestions.pantone : ''));
        if (suggestions.faces) parts.push({1255:'só frente',1256:'frente e verso',1257:'só verso'}[suggestions.faces] || '');
        if (suggestions.cover_paper || suggestions.inner_paper) {
            if (suggestions.cover_paper) parts.push('capa ' + suggestions.cover_paper);
            if (suggestions.inner_paper) parts.push('miolo ' + suggestions.inner_paper);
        } else if (suggestions.paper) parts.push(suggestions.paper);
        if (suggestions.lamination) parts.push('plastificação' + (suggestions.lamination.target === 'cover' ? ' da capa' : '') +
            ({matt:' mate',gloss:' brilho','anti-scuff':' antirrisco'}[suggestions.lamination.finish] || ''));
        if (suggestions.delivery) parts.push('entrega ' + suggestions.delivery.split('-').reverse().join('/'));
        return parts.filter(Boolean).join(' · ') || 'Sem outros dados detetados.';
    }
    function parseQuantities(value) {
        return [...new Set(String(value).split(/[;,\/]|\s+e\s+|\s+ou\s+/).map(part => Number(part.replace(/[.\s]/g,'')))
            .filter(number => Number.isInteger(number) && number > 0 && number <= 10000000))].slice(0,12);
    }
    function renderImportCandidates() {
        const dialog = root.querySelector('[data-import-dialog]'), area = root.querySelector('[data-import-candidates]');
        if (!dialog || !area) return;
        dialog.classList.toggle('vr-import-review',importCandidates.length > 0);
        area.replaceChildren();
        if (!importCandidates.length) return;
        const intro = document.createElement('p');
        intro.className = 'vr-import-summary';
        intro.textContent = `Foram detetados ${importCandidates.length} pedidos. Cada pedido incluído será aberto no formulário, um de cada vez, para validar e completar antes de guardar; só depois gera orçamento.`;
        const list = document.createElement('ol');
        list.className = 'vr-import-candidates';
        importCandidates.forEach((candidate,index) => {
            const item = document.createElement('li');
            item.className = candidate.include ? '' : 'vr-excluded';
            item.innerHTML = `<label class="vr-import-include"><input type="checkbox" data-include${candidate.include ? ' checked' : ''}> Pedido ${index + 1}</label>
                <div class="vr-grid"><label class="vr-label">Tipo de produto<select data-candidate-type>${productTypeOptions(candidate.suggestions.product_type || '')}</select></label>
                <label class="vr-label">Quantidades<input data-candidate-quantities value="${esc((candidate.suggestions.quantities || []).join(', '))}" placeholder="Ex.: 100, 200, 500"></label></div>
                <p class="vr-hint">${esc(importDetails(candidate.suggestions))}</p>
                <details><summary>Texto deste pedido</summary><pre>${esc(candidate.text)}</pre></details>`;
            item.querySelector('[data-include]').addEventListener('change',event => {
                candidate.include = event.target.checked; item.className = candidate.include ? '' : 'vr-excluded';
                start.textContent = startLabel();
            });
            item.querySelector('[data-candidate-type]').addEventListener('change',event => {
                const value = Number(event.target.value);
                if (value) {
                    candidate.suggestions.product_type = value;
                    candidate.suggestions.product_type_name = (types.find(row => Number(row.id) === value) || {}).name || '';
                } else { delete candidate.suggestions.product_type; delete candidate.suggestions.product_type_name; }
            });
            item.querySelector('[data-candidate-quantities]').addEventListener('change',event => {
                const quantities = parseQuantities(event.target.value);
                if (quantities.length) candidate.suggestions.quantities = quantities; else delete candidate.suggestions.quantities;
                event.target.value = quantities.join(', ');
            });
            list.append(item);
        });
        const startLabel = () => {
            const count = importCandidates.filter(candidate => candidate.include).length;
            return count ? `Abrir pedido 1 de ${count}` : 'Selecione pelo menos um pedido';
        };
        const start = button(startLabel(),startImportQueue,true);
        const actions = document.createElement('div');
        actions.className = 'vr-dialog-actions';
        actions.append(button('Voltar ao texto',() => {importCandidates = []; renderImportCandidates();}),start);
        area.append(intro,list,actions);
    }
    function loadImported(item) {
        importSuggestions = item.suggestions || {};
        importWarnings = [...(item.warnings || [])];
        importResultText = item.text || '';
        applyImportSuggestions(importSuggestions);
        uploads.push(...importAttach);
    }
    async function startImportQueue() {
        const chosen = importCandidates.filter(candidate => candidate.include);
        if (!chosen.length) throw new Error('Selecione pelo menos um pedido para abrir.');
        collect();
        importQueue = chosen.slice(1); importQueueTotal = chosen.length; importQueuePosition = 1;
        importCandidates = []; importText = '';
        loadImported(chosen[0]);
        dirty = true;
        render();
        message(`Pedido 1 de ${importQueueTotal} preenchido a partir da importação. Valide e complete os dados e guarde; depois abra o pedido seguinte.`);
    }
    function openNextImported() {
        if (!importQueue.length) return;
        if (dirty && !confirm('O pedido atual tem alterações por guardar. Abrir o pedido seguinte e descartá-las?')) return;
        collect();
        const keep = {customer:identity.customer,customer_name:identity.customer_name,
            contact:draft.request_details?.contact || '',contact_id:draft.request_details?.contact_id || 0};
        const next = importQueue.shift();
        fresh();
        identity.customer = keep.customer; identity.customer_name = keep.customer_name;
        if (keep.contact) {draft.request_details.contact = keep.contact; draft.request_details.contact_id = keep.contact_id;}
        history.replaceState(null,'',location.pathname + location.search);
        importQueuePosition += 1;
        loadImported(next);
        dirty = true;
        render();
        message(`Pedido ${importQueuePosition} de ${importQueueTotal} preenchido, com o mesmo cliente e contacto. Valide e complete os dados e guarde.`);
        root.querySelector('[data-message]').scrollIntoView({block:'center',behavior:'smooth'});
    }
    function renderImportQueueStatus() {
        const area = root.querySelector('[data-import-queue-status]');
        if (!area || !importQueueTotal) return;
        const saved = Boolean(state.id) && !dirty;
        const note = document.createElement('p');
        note.className = 'vr-import-queue-note';
        if (!importQueue.length && saved) {
            note.textContent = `Importação múltipla concluída: ${importQueueTotal} pedido(s) abertos.`;
            area.replaceChildren(note);
            importQueueTotal = 0; importQueuePosition = 0; importAttach = [];
            return;
        }
        note.textContent = `Importação múltipla: pedido ${importQueuePosition} de ${importQueueTotal}. ` + (importQueue.length ?
            (saved ? 'Pedido guardado; pode abrir o seguinte.' : `Valide e guarde este pedido para passar ao seguinte (${importQueue.length} por abrir).`) :
            'Este é o último pedido da importação.');
        area.replaceChildren(note);
        if (importQueue.length) {
            const actions = document.createElement('div');
            actions.className = 'vr-dialog-actions vr-queue-actions';
            actions.append(button('Descartar restantes',() => {
                if (!confirm(`Descartar os ${importQueue.length} pedido(s) importados que ainda não abriu?`)) return;
                importQueue = []; importQueueTotal = 0; importQueuePosition = 0; importAttach = []; render();
            }),button(`Abrir pedido seguinte (${importQueuePosition + 1} de ${importQueueTotal})`,openNextImported,saved));
            area.append(actions);
        }
    }
    async function importRequest() {
        collect();
        const source = root.querySelector('[data-import-text]').value.trim();
        importText = source;
        if (!source && !importFiles.length) throw new Error('Cole o texto do email, cole uma imagem ou selecione um PDF/imagem.');
        const emailFile = source ? new File([source],`email-original-${Date.now()}.txt`,{type:'text/plain'}) : null;
        const attach = [...importFiles,...(emailFile ? [emailFile] : [])];
        const existing = (state.attachments || []).filter(file => !removed.includes(Number(file.id)));
        if (existing.length + uploads.length + attach.length > 10 ||
            [...existing,...uploads,...attach].reduce((sum,file) => sum + Number(file.size),0) > 25*1024*1024) {
            throw new Error('Os documentos desta importação excedem os limites do pedido.');
        }
        const result = await api('extract_request',{config_id:state.id,source_text:source},false,importFiles);
        if ((result.requests || []).length > 1) {
            importCandidates = result.requests.map(item => ({...item,include:true,suggestions:{...item.suggestions}}));
            importAttach = attach; importResultText = result.text; importFiles = []; renderImportQueue();
            renderImportCandidates();
            return;
        }
        importQueue = []; importQueueTotal = 0; importQueuePosition = 0;
        importResultText = result.text;
        importSuggestions = result.suggestions || {};
        importWarnings = [...(result.warnings || [])];
        applyImportSuggestions(importSuggestions);
        uploads.push(...attach);
        importFiles = [];
        importText = '';
        dirty = true;
        render();
        message('Importação concluída como rascunho. Reveja os campos preenchidos, complete o que falta e guarde para criar o registo do pedido.');
    }
    function productReady() {
        const category = categoryOf(draft.product_type);
        return Boolean(category)&&!types.some(row=>Number(row.parent_id)===Number(draft.product_type));
    }
    const rubricTypes = (catalog.rubric_types || []).map(Number);
    const stickerCuttingChoices = new Set(['cut_and_crease','plotter_a3','cutting_table']);
    function isFoldedLeaflet() { return productRules.folding_types.includes(Number(draft.product_type)); }
    function isSticker() { return Number(draft.product_type) === 3761; }
    function rubricsApply() { return rubricTypes.includes(Number(draft.product_type)); }
    function rubricTotal() { return (draft.rubrics || []).reduce((sum,row) => sum + (Number.isInteger(row.quantity) ? row.quantity : 0),0); }
    function rubricsHtml() {
        const rows = draft.rubrics || [];
        return `<fieldset class="vr-choice-group vr-rubrics" data-rubrics><legend>Rubricas / versões / nomes / tamanhos <small>opcional</small></legend>
            <p class="vr-hint">Indique cada versão (nome, tamanho, modelo…) e a respetiva quantidade. Com rubricas, a quantidade do pedido é a soma.</p>
            <table class="vr-rubrics-table"><thead><tr><th scope="col">Quant. <small>obrigatório</small></th><th scope="col">Descrição <small>obrigatório</small></th><th scope="col">Código <small>opcional</small></th><th scope="col"><span class="vr-sr-only">Remover</span></th></tr></thead><tbody>
            ${rows.map((row,index) => `<tr data-rubric><td><input type="number" min="1" step="1" required data-rubric-quantity aria-label="Quantidade da rubrica ${index+1}" value="${esc(row.quantity)}"></td>
                <td><input maxlength="200" required data-rubric-description aria-label="Descrição da rubrica ${index+1}" value="${esc(row.description)}" placeholder="Ex.: João Silva / Tamanho M"></td>
                <td><input maxlength="100" data-rubric-code aria-label="Código da rubrica ${index+1}" value="${esc(row.code)}"></td>
                <td><button type="button" class="vr-button" data-rubric-remove aria-label="Remover rubrica ${index+1}">×</button></td></tr>`).join('')}
            </tbody><tfoot><tr><td><strong>Total: <span data-rubric-total>${rubricTotal()}</span></strong></td><td colspan="3"><button type="button" class="vr-button" data-rubric-add>Adicionar rubrica</button></td></tr></tfoot></table></fieldset>`;
    }
    function finishingApplies(associations) {
        let type = Number(draft.product_type);
        const visited = new Set();
        while (type && !visited.has(type)) {
            if ((associations || []).includes(type)) return true;
            visited.add(type);
            type = Number(types.find(row => Number(row.id) === type)?.parent_id) || 0;
        }
        return false;
    }
    function finishingRows(component = null, stage = 'assembly') {
        const rows = catalog.finishing_operations.filter(row => {
            const id = Number(row.id);
            if(internalFinishingIds.has(id))return false;
            if(id===Number(initial.schema['stages.jacket_operation'])&&!draft.components.some(c=>c.role==='jacket'))return false;
            return finishingApplies(row.product_types) && id !== finishingRules.die_operation && !finishingRules.lamination.includes(id) &&
                (row.stage || 'component') === (component ? 'component' : stage);
        });
        if(component)return rows;
        const groups = new Map();
        for(const row of rows) {
            const name=row.base || row.name;
            if(!groups.has(name))groups.set(name,{...row,name,variants:[]});
            groups.get(name).variants.push(Number(row.id));
        }
        return [...groups.values()];
    }
    function finishingOptions(selected, component = null, stage = 'assembly') {
        selected = selected.filter(operation => !internalFinishingIds.has(Number(operation.id)));
        if (component) selected = selected.filter(operation => operation.id !== finishingRules.die_operation);
        const rows = [...finishingRows(component,stage)];
        for (const operation of selected) if (!rows.some(row => (row.variants||[Number(row.id)]).includes(operation.id))) rows.push(operation);
        return rows.sort((a,b) => a.name.localeCompare(b.name,'pt-PT',{sensitivity:'base'})).map(row => {
            const saved = selected.find(operation => (row.variants||[Number(row.id)]).includes(operation.id));
            const choice = `<label class="vr-choice"><input type="checkbox" data-finishing-choice value="${esc(row.id)}" data-name="${esc(saved?.name || row.name)}"${row.variants ? ` data-variants="${esc(JSON.stringify(row.variants))}"` : ''}${saved ? ' checked' : ''}${Number(row.id)===Number(initial.schema['stages.jacket_operation'])?' disabled':''}><span>${esc(saved?.name || row.name)}</span></label>`;
            if (Number(row.id) === 42) return `<div data-tape-option>${choice}<div data-tape-details${saved ? '' : ' hidden'}>
                <label class="vr-label">Tiras por exemplar <small>obrigatório</small><input type="number" min="1" max="10000" step="1" data-tape-quantity value="${esc(saved?.tape?.quantity ?? '')}"${saved ? ' required' : ''}></label>
                <label class="vr-label">X (mm) <small>obrigatório</small><input type="number" min="0" step="any" data-tape-x value="${esc(saved?.tape?.x_mm ?? '')}"${saved ? ' required' : ''}></label>
                <label class="vr-label">Y (mm) <small>obrigatório</small><input type="number" min="0" step="any" data-tape-y value="${esc(saved?.tape?.y_mm ?? '')}"${saved ? ' required' : ''}></label>
                </div></div>`;
            if (Number(row.id) !== 36) return choice;
            return `<div data-thread-option>${choice}<div data-thread-details${saved ? '' : ' hidden'}>
                <label class="vr-label">Tipo de fio <small>obrigatório</small><input data-thread-type maxlength="500" value="${esc(saved?.thread?.type || '')}"${saved ? ' required' : ''} placeholder="Ex.: algodão, nylon, elástico"></label>
                <label class="vr-label">Cor do fio <small>obrigatório</small><input data-thread-color maxlength="500" value="${esc(saved?.thread?.color || '')}"${saved ? ' required' : ''} placeholder="Ex.: branco, preto, vermelho"></label>
                <label class="vr-label">Comprimento do fio (mm) <small>obrigatório</small><input type="number" min="0" step="any" data-thread-length value="${esc(saved?.thread?.length_mm ?? '')}"${saved ? ' required' : ''} placeholder="Ex.: 300"></label>
                </div></div>`;
        }).join('');
    }
    function showReview(review) {
        const area = root.querySelector('[data-finishing-review]');
        area.replaceChildren();
        for (const [kind, messages] of Object.entries({error:review.errors,warning:review.warnings})) {
            for (const text of messages) {
                const item = document.createElement('p');
                item.className = kind === 'error' ? 'vr-error' : 'vr-warning';
                item.textContent = text; area.append(item);
            }
        }
    }
    function scheduleReview() {
        clearTimeout(reviewTimer);
        const sequence = ++reviewSequence;
        if (!categoryOf(draft.product_type)) return;
        reviewTimer = setTimeout(async () => {
            try {
                collect();
                const review = await api('finishing_review',{config_id:state.id,payload:draft});
                if (sequence === reviewSequence) showReview(review);
            } catch (error) {
                if (sequence === reviewSequence) message('Não foi possível validar os acabamentos: ' + error.message,true);
            }
        },250);
    }
    const categoryOf = id => {
        const visited=new Set();
        let type=types.find(row=>Number(row.id)===Number(id));
        while(type&&Number(type.parent_id)&&!visited.has(Number(type.id))) {
            visited.add(Number(type.id));type=types.find(row=>Number(row.id)===Number(type.parent_id));
        }
        return type?Number(type.id):0;
    };
    const isMenu = () => {
        let type=types.find(row=>Number(row.id)===Number(draft.product_type));
        while(type) {if(Number(type.id)===3851)return true;type=types.find(row=>Number(row.id)===Number(type.parent_id));}
        return false;
    };
    const hasPages = () => [3712,3756,Number(productRules.brochure_category)].includes(categoryOf(draft.product_type))||isMenu();
    const subtypeName = row => {
        const parent=types.find(type=>Number(type.id)===Number(row.parent_id));
        return Number(parent?.parent_id)?parent.name+' — '+row.name:row.name;
    };
    const subtypes = category => types.filter(row=>Number(row.id)!==category&&categoryOf(row.id)===category&&
        !types.some(child=>Number(child.parent_id)===Number(row.id)));
    const isDocument = () => categoryOf(draft.product_type) === 3752;
    const isCorrugated = () => Boolean(initial.schema['config.corrugated_type']) && Number(draft.product_type) === Number(initial.schema['config.corrugated_type']);
    const isBox = () => Number(draft.product_type) === 3751 || isCorrugated();
    function formatDrawing(width,height) {
        const w=Number(width),h=Number(height);
        if(![w,h].every(n=>Number.isFinite(n)&&n>0))
            return '<p class="vr-help">Indique a largura e a altura para visualizar o formato.</p>';
        const scale=Math.min(220/w,160/h),rw=w*scale,rh=h*scale;
        const x=180-rw/2,y=112-rh/2,right=x+rw,bottom=y+rh;
        return `<svg viewBox="0 0 360 250" role="img" aria-label="Formato: largura ${w} mm, altura ${h} mm" xmlns="http://www.w3.org/2000/svg">
            <rect data-format-sheet x="${x}" y="${y}" width="${rw}" height="${rh}" rx="0" fill="#e1eaf2" stroke="#41647e" stroke-width="1.5"/>
            <path d="M${x} ${bottom+5}V214 M${right} ${bottom+5}V214 M${x} 208H${right}" fill="none" stroke="#41647e"/>
            <path d="M${x+5} 205L${x} 208L${x+5} 211 M${right-5} 205L${right} 208L${right-5} 211" fill="none" stroke="#41647e"/>
            <text x="180" y="234" text-anchor="middle" fill="#294a64" font-size="13">Largura: ${w} mm</text>
            <path d="M${right+5} ${y}H314 M${right+5} ${bottom}H314 M308 ${y}V${bottom}" fill="none" stroke="#41647e"/>
            <path d="M305 ${y+5}L308 ${y}L311 ${y+5} M305 ${bottom-5}L308 ${bottom}L311 ${bottom-5}" fill="none" stroke="#41647e"/>
            <text x="334" y="112" text-anchor="middle" transform="rotate(-90 334 112)" fill="#294a64" font-size="13">Altura: ${h} mm</text>
            </svg>`;
    }
    function boxDrawing(model,length,width,height,rotation=0) {
        const dimensions=[Number(length),Number(width),Number(height)];
        if(!dimensions.every(n=>Number.isFinite(n)&&n>0))return '<p>Indique as três dimensões para visualizar o volume.</p>';
        const [l,w,h]=dimensions.map(n=>n*120/Math.max(...dimensions));
        const angle=(rotation%4)*Math.PI/2,cos=Math.cos(angle),sin=Math.sin(angle);
        const rotate=([x,y,z])=>[(x-l/2)*cos-(y-w/2)*sin,(x-l/2)*sin+(y-w/2)*cos,z];
        const project=([x,y,z])=>[(x-y)*0.866,(x+y)*0.5-z];
        const faces=[];
        const face=(points,color,doubleSided=false,detail='body')=>{
            const vertices=points.map(rotate);
            const a=vertices[1].map((n,i)=>n-vertices[0][i]),b=vertices[2].map((n,i)=>n-vertices[0][i]);
            const normal=[a[1]*b[2]-a[2]*b[1],a[2]*b[0]-a[0]*b[2],a[0]*b[1]-a[1]*b[0]];
            if(!doubleSided&&normal[0]+normal[1]+normal[2]<=1e-8)return;
            faces.push({points:vertices.map(project),color,detail,depth:vertices.reduce((sum,[x,y,z])=>sum+x+y+z,0)/vertices.length});
        };
        const carton=['#d9b786','#ba915e','#f2dfbd'],board=['#a8bdcd','#7895aa','#e1e9ee'];
        const palette=['rigid','lid_base','custom'].includes(model)?board:carton;
        const solid=(x0,y0,z0,x1,y1,z1,colors=palette,detail='body')=>{
            face([[x0,y0,z0],[x0,y0,z1],[x0,y1,z1],[x0,y1,z0]],colors[1],false,detail);
            face([[x1,y0,z0],[x1,y1,z0],[x1,y1,z1],[x1,y0,z1]],colors[0],false,detail);
            face([[x0,y0,z0],[x1,y0,z0],[x1,y0,z1],[x0,y0,z1]],colors[0],false,detail);
            face([[x0,y1,z0],[x0,y1,z1],[x1,y1,z1],[x1,y1,z0]],colors[1],false,detail);
            face([[x0,y0,z1],[x1,y0,z1],[x1,y1,z1],[x0,y1,z1]],colors[2],false,detail);
            face([[x0,y0,z0],[x0,y1,z0],[x1,y1,z0],[x1,y0,z0]],colors[1],false,detail);
        };
        const t=Math.min(l,w,h)*(model==='rigid'?0.065:0.025);
        const shell=(bottom=true)=>{
            if(bottom)solid(0,0,0,l,w,t);
            solid(0,0,t,l,t,h);
            solid(0,w-t,t,l,w,h);
            solid(0,t,t,t,w-t,h);
            solid(l-t,t,t,l,w-t,h);
        };
        if(model==='custom')solid(0,0,0,l,w,h);
        else shell(model!=='automatic_bottom');
        if(model==='folding_flaps'||model==='automatic_bottom') {
            const flap=Math.min(l,w)*0.46,spread=flap*0.6,rise=flap*0.8;
            face([[0,0,h],[l,0,h],[l,-spread,h+rise],[0,-spread,h+rise]],carton[2],true,'top-flap');
            face([[0,w,h],[0,w+spread,h+rise],[l,w+spread,h+rise],[l,w,h]],carton[2],true,'top-flap');
            face([[0,0,h],[-spread*0.6,0,h+rise*0.7],[-spread*0.6,w,h+rise*0.7],[0,w,h]],carton[0],true,'dust-flap');
            face([[l,0,h],[l,w,h],[l+spread*0.6,w,h+rise*0.7],[l+spread*0.6,0,h+rise*0.7]],carton[0],true,'dust-flap');
            if(model==='automatic_bottom') {
                // Show the interlocking bottom partly open, not as an extra exterior wall.
                const drop=Math.min(l,w)*0.38;
                face([[0,0,t],[l,0,t],[l,w*0.28,-drop],[l*0.45,w*0.58,-drop],[0,w*0.4,-drop]],carton[2],true,'automatic-bottom');
                face([[0,w,t],[0,w*0.6,-drop],[l*0.55,w*0.42,-drop],[l,w*0.72,-drop],[l,w,t]],carton[0],true,'automatic-bottom');
                face([[0,0,t],[0,w,t],[l*0.38,w*0.7,-drop*0.7],[l*0.38,w*0.3,-drop*0.7]],carton[1],true,'automatic-bottom');
                face([[l,0,t],[l*0.62,w*0.3,-drop*0.7],[l*0.62,w*0.7,-drop*0.7],[l,w,t]],carton[1],true,'automatic-bottom');
            }
        }else if(model==='lid_base') {
            const z=h+Math.min(l,w)*0.4,lip=Math.min(h*0.25,w*0.15);
            solid(-t,-t,z,l+t,w+t,z+t,palette,'separate-lid');
            solid(-t,-t,z-lip,l+t,0,z,palette,'lid-rim');
            solid(-t,w,z-lip,l+t,w+t,z,palette,'lid-rim');
            solid(-t,0,z-lip,0,w,z,palette,'lid-rim');
            solid(l,0,z-lip,l+t,w,z,palette,'lid-rim');
        }else if(model==='mailer') {
            const back=w*0.55,up=w*0.84,ear=Math.min(l*0.12,w*0.18);
            face([[0,0,h],[l,0,h],[l,-back,h+up],[0,-back,h+up]],carton[2],true,'hinged-lid');
            face([[0,-back,h+up],[l,-back,h+up],[l-ear,-back-ear*0.5,h+up+ear],[ear,-back-ear*0.5,h+up+ear]],carton[0],true,'lid-tuck');
            face([[0,0,h],[-ear,-back*0.2,h+up*0.2],[-ear,-back*0.8,h+up*0.8],[0,-back,h+up]],carton[0],true,'lid-ear');
            face([[l,0,h],[l,-back,h+up],[l+ear,-back*0.8,h+up*0.8],[l+ear,-back*0.2,h+up*0.2]],carton[0],true,'lid-ear');
        }else if(model==='insert') {
            solid(l/2-t/2,t,t,l/2+t/2,w-t,h*0.86,board,'divider');
            solid(t,w/2-t/2,t,l-t,w/2+t/2,h*0.86,board,'divider');
        }
        faces.sort((a,b)=>a.depth-b.depth);
        const points=faces.flatMap(item=>item.points),xs=points.map(p=>p[0]),ys=points.map(p=>p[1]);
        const minX=Math.min(...xs),minY=Math.min(...ys),width2d=Math.max(...xs)-minX,height2d=Math.max(...ys)-minY;
        const fit=Math.min(272/width2d,240/height2d);
        const shapes=faces.map(item=>`<polygon data-box-face="${item.detail}" points="${item.points.map(([x,y])=>
            [(160+(x-minX-width2d/2)*fit).toFixed(2),(140+(y-minY-height2d/2)*fit).toFixed(2)].join(',')).join(' ')}" fill="${item.color}" stroke="#53616a" stroke-width="0.9" stroke-linejoin="round"/>`).join('');
        return `<svg viewBox="0 0 320 280" role="img" aria-label="Vista ilustrativa da caixa" xmlns="http://www.w3.org/2000/svg">${shapes}</svg>`;
    }
    function bookNumbering() {
        const area = root.querySelector('[data-book-numbering]');
        if (!area) return;
        const start = root.querySelector('[data-book-start]').value, sets = Number(root.querySelector('[data-book-sets]').value),
            copies = Number(root.querySelector('[data-book-copies]').value);
        const quantities = root.querySelector('[data-quantities]').value.split(/[;,\s]+/).filter(Boolean).map(Number);
        const valid = /^\d{1,12}$/.test(start) && Number.isInteger(sets) && sets > 0 && sets <= 10000 &&
            Number.isInteger(copies) && copies > 0 && copies <= 20;
        area.replaceChildren();
        if (!valid) {area.textContent = 'Preencha o início, os conjuntos por livro e as vias para calcular a numeração.'; return;}
        for (const quantity of quantities) {
            const end = Number(start) + sets * quantity - 1, line = document.createElement('p');
            line.textContent = Number.isInteger(quantity) && quantity > 0 && quantity <= 10000000 && end <= 999999999999 ?
                `${quantity} livro(s) · ${sets} × ${copies} vias (${sets * copies} folhas/livro) · início ${start} → fim ${String(end).padStart(start.length,'0')}` :
                'Quantidade de livros ou numeração final inválida.';
            area.append(line);
        }
        if (!quantities.length) area.textContent = 'Indique a quantidade de livros para calcular o número de fim.';
    }
    function duplicateRequest(payload, identification) {
        const copy = JSON.parse(JSON.stringify(payload));
        delete copy.selections;
        return {draft:copy,identity:JSON.parse(JSON.stringify(identification))};
    }
    function duplicateCurrentRequest() {
        collect();
        const source = state.id;
        const documents = state.attachments.length + uploads.length;
        const copy = duplicateRequest(draft,identity);
        fresh(); draft = copy.draft; identity = copy.identity; dirty = true;
        history.replaceState(null,'',location.pathname + location.search);
        render();
        message('Cópia do pedido #' + source + ' aberta como novo pedido, ainda por guardar. Reveja os dados e guarde para gerar outro orçamento.' +
            (documents ? ' Os documentos não foram copiados; volte a anexar os necessários.' : ''));
    }
    function fresh() {
        state = {id:0, revision:0, attachments:[]};
        draft = {product_type:3823, type:'standard', quantities:[], components:[], request_details:{workflow_version:2}};
        identity = {name:'', customer:0, customer_name:'', reference:'', notes:''};
        request = crypto.randomUUID(); pending = null; uploads = []; removed = []; dirty = false;
        importText='';importFiles=[];importResultText='';importSuggestions={};importWarnings=[];
    }
    fresh();
    function message(text, error = false) {
        const dialog = root.querySelector('[data-import-dialog]');
        const box = dialog?.open ? dialog.querySelector('[data-import-message]') : root.querySelector('[data-message]');
        box.className = text ? 'vr-message ' + (error ? 'vr-error' : 'vr-success') : '';
        box.textContent = text;
        if (error) box.scrollIntoView({block:'center', behavior:'smooth'});
    }
    async function api(action, input = {}, get = false, files = []) {
        const body = new FormData();
        if (!get) {
            body.set('api',action); body.set('form_session_token',initial.token); body.set('payload',JSON.stringify(input));
            for (const file of files) body.append('documents[]',file,file.name);
        }
        const response = await fetch(initial.url + (get ? '&' + new URLSearchParams({api:action,...input}) : ''), {
            credentials:'same-origin', ...(get ? {} : {method:'POST',body})
        });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('O servidor não aceitou o envio. Confirme a ligação e o tamanho dos documentos; o pedido não está confirmado.');
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível guardar o pedido.');
        return result.data;
    }
    function button(text, action, primary = false) {
        const element = document.createElement('button');
        element.type = 'button'; element.className = primary ? 'vr-button vr-primary' : 'vr-button'; element.textContent = text;
        element.addEventListener('click',async () => {
            element.disabled = true;
            try { await action(); } catch (error) { message(error.message,true); }
            finally { element.disabled = false; }
        });
        return element;
    }
    function collect() {
        identity.name = root.querySelector('[data-title]').value.trim();
        identity.reference = root.querySelector('[data-reference]').value;
        identity.notes = root.querySelector('[data-notes]').value;
        const customer = root.querySelector('[data-customer]');
        identity.customer = Number(customer.value);
        identity.customer_name = customer.selectedOptions[0]?.textContent || '';
        draft.quantities = root.querySelector('[data-quantities]').value.split(/[;,\s]+/).filter(Boolean).map(Number);
        if (rubricsApply()) {
            draft.rubrics = [...root.querySelectorAll('[data-rubric]')].map(row => {
                const quantity = row.querySelector('[data-rubric-quantity]').value.trim();
                return {quantity:quantity === '' ? '' : Number(quantity),description:row.querySelector('[data-rubric-description]').value.trim(),
                    code:row.querySelector('[data-rubric-code]').value.trim()};
            }).filter(row => row.quantity !== '' || row.description || row.code);
            if (draft.rubrics.length) draft.quantities = [rubricTotal()];
        } else delete draft.rubrics;
        draft.request_details = {...draft.request_details,
            workflow_version:2,
            delivery:root.querySelector('[data-delivery]').value,
            contact:root.querySelector('[data-contact]').selectedOptions[0]?.textContent || '',
            contact_id:Number(root.querySelector('[data-contact]').value) || 0,
            finishing:root.querySelector('[data-finishing]').value,
            finishing_operations:[],
            packaging_operations:selectedOperations(root.querySelector('[data-packaging-operations]')),
            dispatch:root.querySelector('[data-dispatch]:checked') ? [root.querySelector('[data-dispatch]:checked').value] :
                (draft.request_details.dispatch?.length > 1 ? draft.request_details.dispatch : []),
            other_product:root.querySelector('[data-other-product]')?.value || ''};
        const jacketId=Number(initial.schema['stages.jacket_operation']);
        if(draft.components.some(c=>c.role==='jacket')&&jacketId&&!draft.request_details.finishing_operations.some(op=>op.id===jacketId))
            draft.request_details.finishing_operations.push({id:jacketId,name:'Colocar sobrecapa',variants:[jacketId]});
        if(!draft.components.some(c=>c.role==='jacket'))
            draft.request_details.finishing_operations=draft.request_details.finishing_operations.filter(op=>op.id!==jacketId);
        if(root.querySelector('[data-final-width]'))draft.request_details.final_format={
            width_mm:root.querySelector('[data-final-width]').value,height_mm:root.querySelector('[data-final-height]').value,
            thickness_mm:root.querySelector('[data-final-thickness]').value};
        if(hasPages())draft.request_details.total_pages=Number(root.querySelector('[data-total-pages]').value);
        else delete draft.request_details.total_pages;
        if(isFoldedLeaflet())draft.request_details.folding={
            count:Number(root.querySelector('[data-fold-count]').value),
            delivery:root.querySelector('[data-fold-delivery]').value};
        else delete draft.request_details.folding;
        if(isSticker())draft.request_details.cutting_operation=root.querySelector('[data-cutting-operation]').value;
        else delete draft.request_details.cutting_operation;
        if (isDocument()) {
            draft.request_details.document_book = {start:root.querySelector('[data-book-start]').value,
                sets_per_book:Number(root.querySelector('[data-book-sets]').value),copies:Number(root.querySelector('[data-book-copies]').value),
                binding:root.querySelector('[data-book-binding]').value,unit:'livro',
                paper_colors:[...root.querySelectorAll('[data-book-paper-color]')].map(input => input.value.trim())};
        } else delete draft.request_details.document_book;
        if (isBox())draft.request_details.box = {
            length_mm:root.querySelector('[data-box-length]').value,width_mm:root.querySelector('[data-box-width]').value,
            height_mm:root.querySelector('[data-box-height]').value,measurement:root.querySelector('[data-box-measurement]').value};
        if(isBox()) {
            draft.request_details.box.model=root.querySelector('[data-box-model]:checked')?.value||'';
            draft.request_details.box.model_description=root.querySelector('[data-box-description]').value.trim();
        }
        else delete draft.request_details.box;
        for (const row of root.querySelectorAll('[data-component]')) {
            const component = draft.components.find(c => c.id === Number(row.dataset.component));
            component.request = {...component.request, paper:isDocument() ? 'Autocopiativo' : row.querySelector('[data-paper]').value,
                finishing_operations:[...(component.request?.finishing_operations || []).filter(operation => internalFinishingIds.has(Number(operation.id))),
                    ...selectedOperations(row.querySelector('[data-component-finishing]'))]};
            const dieStatus = row.querySelector('[data-die-enabled]').checked ? row.querySelector('[data-die-status]').value : 'none';
            component.request.die = {status:dieStatus,number:dieStatus === 'used' ? row.querySelector('[data-die-number]').value.trim() : ''};
            if (dieStatus === 'new') {
                const operation = catalog.finishing_operations.find(operation => Number(operation.id) === finishingRules.die_operation);
                if (operation) component.request.finishing_operations.push({id:Number(operation.id),name:operation.name});
            }
            if (component.kind === 'printed') component.request.lamination = row.querySelector('[data-lamination]')?.checked || false;
            for (const field of row.querySelectorAll('[data-field]')) component.fields[field.dataset.field] = field.value;
            if(hasPages()&&Number.isInteger(draft.request_details.total_pages)&&draft.request_details.total_pages>0&&component.role!=='jacket')
                component.fields[4233]=String(draft.type==='magazine'?(component.role==='cover'?4:draft.request_details.total_pages-4):draft.request_details.total_pages);
            const color = row.querySelector('[data-print-color]:checked');
            if (component.kind === 'printed') {
                component.request.printing = {mode:color?.value || '',pantone:row.querySelector('[data-pantone]').value.trim()};
                component.colors = color ? (printPresets[color.value] || [...row.querySelectorAll('[data-combined-color]:checked')].map(input => Number(input.value))) : [];
            }
            if (isCorrugated()) {
                component.request.paper = 'Cartão canelado';
                component.request.corrugated = {thickness_mm:row.querySelector('[data-corrugated-thickness]').value};
                component.request.printing = {mode:'none',pantone:''};
                component.colors = [3804];component.fields[4229] = '';
            } else delete component.request.corrugated;
            if(row.querySelector('[data-flap-left]')) {
                component.request.cover={left_mm:row.querySelector('[data-flap-left]').value,right_mm:row.querySelector('[data-flap-right]').value,
                    spine_mm:row.querySelector('[data-cover-spine]').value};
                const format=draft.request_details.final_format,cover=component.request.cover;
                if(Number(format?.width_mm)>0&&Number(format?.height_mm)>0) {
                    component.fields[4218]=String(2*Number(format.width_mm)+Number(cover.spine_mm)+Number(cover.left_mm)+Number(cover.right_mm));
                    component.fields[4219]=format.height_mm;
                }
                if(component.role==='cover')component.fields[4233]='4';
            }
        }
    }
    function structure(type) {
        const names = {standard:[['Produto','printed']],magazine:[['Capa','printed'],['Miolo','printed'],['Encadernação','assembly']],
            folding_box:[['Caixa','printed'],['Montagem da caixa','assembly']],
            rigid_box:[['Estrutura da caixa','material'],['Revestimento','printed'],['Montagem da caixa','assembly']],
            lid_base_box:[['Tampa','printed'],['Fundo','printed'],['Montagem da caixa','assembly']],
            accessory_box:[['Caixa','printed'],['Interior / divisórias','material'],['Montagem da caixa','assembly']]};
        // Keep collected details when switching between related products.
        const previous = draft.components;
        draft.type = type;
        draft.components = names[type].map(([name,kind],index) => {
            const old = previous.find(c => c.name === name && c.kind === kind) ||
                (index === 0 && previous.filter(c => c.kind !== 'assembly').length === 1 && kind === 'printed' ? previous.find(c => c.kind === 'printed') : null);
            const part={...(old ? structuredClone(old) : {fields:{},papers:[],colors:[],rubrics:[],request:{}}),id:index+1,name,kind,units_per_product:1,inputs:[],
                ...(type==='magazine'&&index===0?{role:'cover'}:{})};
            if(type!=='magazine'){delete part.role;if(part.request)delete part.request.cover;}
            return part;
        });
        for (const c of draft.components) if (c.kind === 'assembly') c.inputs = draft.components.filter(item => item.kind !== 'assembly').map(item => item.id);
    }
    function chooseCategory(id) {
        collect();
        if (categoryOf(draft.product_type) && categoryOf(draft.product_type) !== id &&
            draft.components.some(c => Object.entries(c.fields || {}).some(([field,value]) => value !== '' &&
                !catalog.choices.some(choice => Number(choice.fields_id) === Number(field) && String(choice.id) === String(value) && choice.value === 'none')) ||
                Object.values(c.request || {}).some(value => Array.isArray(value) ? value.length > 0 : Boolean(value))) &&
            !confirm('Mudar de categoria pode alterar as partes do produto. Os dados comuns serão mantidos. Quer continuar?')) {render();return;}
        const children = types.filter(row => Number(row.parent_id) === id);
        draft.product_type = id;
        if (id !== 3752) delete draft.request_details.document_book;
        const type = [3712,3756,Number(productRules.brochure_category)].includes(id) ? 'magazine' : 'standard';
        structure(type); dirty = true; render();
        if (children.length) root.querySelector('[data-subcategories]').scrollIntoView({block:'nearest',behavior:'smooth'});
    }
    async function open(id) {
        if (dirty && !confirm('Tem alterações por guardar. Quer descartá-las para abrir outro pedido?')) return;
        const loaded = await api('state',{config_id:id},true);
        state = loaded; draft = loaded.payload;
        identity = {name:loaded.name,customer:Number(loaded.customer),customer_name:loaded.customer_name,reference:loaded.reference || '',notes:loaded.notes || ''};
        importText='';importFiles=[];importResultText='';importSuggestions={};importWarnings=[];
        const migrated = [];
        if(![1,2].includes(draft.request_details.workflow_version)) {
            const details=draft.request_details,assembly=[...(details.finishing_operations||[])],packaging=[];
            for(const component of draft.components) {
                component.request=component.request||{};
                component.request.finishing_operations=(component.request.finishing_operations||[]).filter(operation=>{
                    const row=catalog.finishing_operations.find(row=>Number(row.id)===operation.id);
                    if(row?.stage==='packaging'){packaging.push(operation);return false;}
                    if(row?.stage==='assembly'){assembly.push(operation);return false;}
                    return true;
                });
            }
            details.finishing_operations=assembly.filter((op,index,all)=>all.findIndex(other=>other.id===op.id)===index);
            details.packaging_operations=packaging.filter((op,index,all)=>all.findIndex(other=>other.id===op.id)===index);
            details.workflow_version=1;
            migrated.push('Etapas de assemblagem e embalamento separadas; confirme o formato final fechado e as escolhas.');
        }
        if(draft.type==='magazine') {
            const cover=draft.components.find(c=>c.name==='Capa');if(cover){cover.role='cover';cover.fields[4233]='4';}
        }
        if(draft.request_details.workflow_version!==2) {
            const previous=draft.request_details.finishing_operations||[];
            if(previous.length)identity.notes+=(identity.notes?'\n':'')+'Assemblagem indicada no pedido anterior (rever na orçamentação): '+previous.map(op=>op.name).join('; ');
            draft.request_details.finishing_operations=[];
            draft.request_details.workflow_version=2;
            if(hasPages()&&!draft.request_details.total_pages) {
                const parts=draft.components.filter(c=>c.kind==='printed'&&c.role!=='jacket');
                if(parts.every(c=>Number(c.fields?.[4233])>0))draft.request_details.total_pages=parts.reduce((sum,c)=>sum+Number(c.fields[4233]),0);
            }
            migrated.push('Encadernação obtida pelo subtipo; confirme as páginas totais e o modelo da caixa, se aplicável.');
        }
        for (const component of draft.components) {
            if (component.request?.description) {
                identity.notes += (identity.notes ? '\n' : '') + component.name + ': ' + component.request.description;
                component.request.description = '';
                migrated.push('Os pormenores anteriores foram colocados nas observações.');
            }
            if ((component.colors || []).includes(3742)) {
                component.colors = component.colors.filter(id => id !== 3742);
                const varnish = catalog.finishing_operations.find(operation => Number(operation.id) === 6);
                component.request = component.request || {};
                component.request.finishing_operations = component.request.finishing_operations || [];
                if (varnish && !component.request.finishing_operations.some(operation => operation.id === 6))
                    component.request.finishing_operations.push({id:6,name:varnish.name});
                else if (!varnish) identity.notes += (identity.notes ? '\n' : '') + component.name + ': verniz solicitado anteriormente; confirmar acabamento.';
                migrated.push('O verniz anterior foi retirado das cores e preservado como acabamento ou observação.');
            }
        }
        uploads = []; removed = []; request = crypto.randomUUID(); pending = null; dirty = false;
        dirty = migrated.length > 0;
        render(); location.hash = 'configuration=' + state.id;
        message('Pedido #' + state.id + ' aberto. Pode completar os dados e guardar.' + (migrated.length ? ' ' + [...new Set(migrated)].join(' ') + ' Guarde para confirmar.' : ''));
    }
    async function recent() {
        const rows = await api('recent',{},true), area = root.querySelector('[data-recent]');
        area.replaceChildren();
        if (!rows.length) area.textContent = 'Ainda não há pedidos guardados.';
        for (const row of rows) {
            const paragraph = document.createElement('p');
            paragraph.append(button('#' + row.id + ' · ' + row.name,() => open(Number(row.id)))); area.append(paragraph);
        }
    }
    function render() {
        clearTimeout(reviewTimer); ++reviewSequence;
        const previousCustomer = root.querySelector('[data-customer]');
        if (previousCustomer && window.jQuery(previousCustomer).data('select2')) window.jQuery(previousCustomer).select2('destroy');
        const previousContact = root.querySelector('[data-contact]');
        if (previousContact && window.jQuery(previousContact).data('select2')) window.jQuery(previousContact).select2('destroy');
        const category = categoryOf(draft.product_type), details = draft.request_details || {};
        root.innerHTML = `<div class="vr-intro"><div><p class="vr-eyebrow">CONFIGURADOR DE PRODUTO</p>
            <h2>${state.id ? 'Pedido #' + state.id : 'Novo pedido'}</h2>
            <p>Uso interno · Recolha de requisitos para orçamento.</p>
            ${state.id && initial.schema['conversion.manual_process'] ? `<a class="btn btn-info" href="index.php?module=items/info&amp;path=${Number(initial.schema['config.entity'])}-${Number(state.id)}">Estado do pedido e conversão para orçamento</a>` : ''}
            </div><div data-top-actions></div></div>
            <div data-message role="status" aria-live="polite"></div>
            ${state.conversion?.error ? `<p class="vr-warning" role="alert">Pedido guardado, mas não convertido em orçamento: ${esc(state.conversion.error)}</p>` : ''}
            ${state.conversion?.quote ? `<p>Orçamento associado: <a href="index.php?module=items/info&amp;path=146-${Number(state.conversion.quote)}" target="_blank" rel="noopener">#${Number(state.conversion.quote)}</a>. Alterar este pedido não altera o orçamento nem o histórico já guardado.</p>` : ''}
            <section class="vr-section vr-import"><div class="vr-section-heading"><h3><span>0</span> Importar pedido de email, PDF ou imagem</h3><div data-import-open></div></div>
                <div data-import-queue-status></div><div data-import-result></div>
                <dialog class="vr-import-dialog" data-import-dialog aria-labelledby="vr-import-title">
                    <div class="vr-dialog-head"><h3 id="vr-import-title">Importar pedido de email, PDF ou imagem</h3><button type="button" class="vr-dialog-close" data-import-close aria-label="Fechar">×</button></div>
                    <div data-import-message role="status" aria-live="polite"></div>
                    <div data-import-input><p class="vr-hint">Cole texto ou uma imagem (Ctrl/Cmd+V), ou selecione um PDF/imagem. A leitura é local no servidor; em PDFs digitalizados o OCR lê até 6 páginas. Os dados detetados preenchem um rascunho editável; confirme-os antes de guardar. O original será anexado ao pedido.</p>
                    <label class="vr-label">Texto do email ou pedido<textarea data-import-text rows="5" maxlength="100000" placeholder="Cole aqui o texto do email, incluindo quantidades, formato, papel e acabamentos.">${esc(importText)}</textarea></label>
                    <label class="vr-upload">Selecionar PDF ou imagem<input type="file" data-import-files multiple accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.txt"></label>
                    <ul data-import-queue class="vr-files"></ul></div>
                    <div data-import-candidates></div><div data-import-actions class="vr-dialog-actions"></div>
                </dialog>
            </section>
            <section class="vr-section"><h3><span>1</span> Produto <small>obrigatório</small></h3>
            <fieldset class="vr-choice-group" aria-required="true"><legend class="vr-sr-only">Categoria do produto (obrigatório)</legend><div class="vr-categories">
            ${types.filter(row => !Number(row.parent_id)).map(row => `<label class="vr-choice"><input type="radio" name="vr-category" data-category required value="${row.id}"${Number(row.id) === category ? ' checked' : ''}><span>${esc(row.name)}</span></label>`).join('')}</div></fieldset>
            <div data-subcategories>${category&&subtypes(category).length ? `<fieldset class="vr-choice-group"><legend>Subcategoria</legend><div class="vr-subcategories">
            ${subtypes(category).map(row => `<label class="vr-choice"><input type="radio" name="vr-subtype" data-subtype value="${row.id}"${Number(row.id) === Number(draft.product_type) ? ' checked' : ''}><span>${esc(subtypeName(row))}</span></label>`).join('')}</div></fieldset>` : ''}</div>
            ${Number(draft.product_type) === Number(initial.schema['config.other_type']) ? `<label class="vr-label">Especifique <small>obrigatório</small><input data-other-product maxlength="500" required value="${esc(details.other_product || '')}" placeholder="Que produto pretende?"></label>` : ''}
            </section>
            <section class="vr-section"><div class="vr-section-heading"><h3><span>2</span> Identifique o pedido</h3><label class="vr-internal"><input type="checkbox" data-internal-customer${identity.customer === Number(initial.schema['config.internal_customer']) ? ' checked' : ''}> Pedido interno</label></div><div class="vr-grid">
            <label class="vr-label">Nome do trabalho <small>obrigatório</small><input data-title maxlength="200" required value="${esc(identity.name)}" placeholder="Ex.: Revista de Natal"></label>
            <label class="vr-label">Referência <small>opcional</small><input data-reference maxlength="200" value="${esc(identity.reference)}" placeholder="A sua referência interna"></label>
            <label class="vr-label">${isDocument() ? 'Quantos livros?' : 'Quantos exemplares?'}<input data-quantities value="${esc(rubricsApply() && draft.rubrics?.length ? String(rubricTotal()) : draft.quantities.join(', '))}"${rubricsApply() && draft.rubrics?.length ? ' readonly' : ''} placeholder="${isDocument() ? 'Ex.: 10 ou 10, 20, 50' : 'Ex.: 500 ou 250, 500, 1000'}"><small data-quantities-hint>${rubricsApply() && draft.rubrics?.length ? 'Soma das rubricas.' : 'Se for mais do que uma quantidade separe por vírgulas.'}</small></label>
            <label class="vr-label">Quando precisa do trabalho?<input type="date" data-delivery value="${esc(details.delivery || '')}"></label>
            <div class="vr-customer"><label class="vr-label" for="vr-customer">Cliente <small>obrigatório</small></label><select id="vr-customer" data-customer required aria-label="Cliente" aria-required="true"${identity.customer === Number(initial.schema['config.internal_customer']) ? ' disabled' : ''}><option value=""></option>${identity.customer ? options([{id:identity.customer,name:identity.customer_name}],identity.customer) : ''}</select></div>
            <div class="vr-customer"><label class="vr-label" for="vr-contact">Contacto <small>opcional</small></label><select id="vr-contact" data-contact aria-label="Contacto"${!identity.customer ? ' disabled' : ''}><option value=""></option>${details.contact ? options([{id:details.contact_id || 'manual:'+details.contact,name:details.contact}],details.contact_id || 'manual:'+details.contact) : ''}</select></div></div>${rubricsApply() ? rubricsHtml() : ''}</section>
            <section class="vr-section" data-product-details${productReady() ? '' : ' hidden'}><h3><span>3</span> Como pretende o produto?</h3>
            ${isFoldedLeaflet()?`<fieldset class="vr-choice-group"><legend>Dobras e entrega do desdobrável <small>obrigatório</small></legend><div class="vr-grid">
                <label class="vr-label">Número de dobras<input type="number" min="1" step="1" required data-fold-count value="${esc(details.folding?.count??'')}"></label>
                <label class="vr-label">Entregue aberto ou fechado<select required data-fold-delivery><option value="">Escolha</option>${options([{id:'open',name:'Aberto'},{id:'closed',name:'Fechado'}],details.folding?.delivery)}</select></label></div>
                <p class="vr-hint">O orçamento terá vinco. A operação de dobrar é acrescentada apenas para entrega fechada.</p></fieldset>`:''}
            ${isSticker()?`<fieldset class="vr-choice-group"><legend>Operação de corte pretendida <small>obrigatório</small></legend>
                <label class="vr-label">Operação<select required data-cutting-operation><option value="">Escolha a operação</option>${options([
                    {id:'cut_and_crease',name:'Corte e vinco'},
                    {id:'plotter_a3',name:'Corte de plotter A3'},
                    {id:'cutting_table',name:'Mesa de corte'}
                ],details.cutting_operation)}</select></label>
                <p class="vr-hint">Esta indicação será incluída na preparação do orçamento. A variante de corte e vinco e a imposição serão confirmadas tecnicamente.</p></fieldset>`:''}
            ${hasPages()?`<label class="vr-label">Número total de páginas — incluindo capa <small>obrigatório</small><input type="number" min="${draft.type==='magazine'?5:1}" max="100000" step="1" required data-total-pages value="${esc(details.total_pages??'')}"></label>
            <small>A capa separada conta 4 páginas; o restante é miolo. Mais de 4 páginas nas brochuras/ementas exige subtipo com agrafes, espiral ou costura; as revistas podem ser brochadas.</small>`:''}
            ${productReady()&&!isBox() ? `<fieldset class="vr-choice-group" data-final-format${draft.type==='magazine'||details.packaging_operations?.length?'':' hidden'}><legend>Formato final fechado</legend><div class="vr-dimensions-layout"><div class="vr-dimensions-fields">
                <label class="vr-label">Largura fechada (mm)<input type="number" min="0" step="any" data-final-width value="${esc(details.final_format?.width_mm??'')}"></label>
                <label class="vr-label">Altura fechada (mm)<input type="number" min="0" step="any" data-final-height value="${esc(details.final_format?.height_mm??'')}"></label>
                <label class="vr-label">Espessura final (mm) — confirmar<input type="number" min="0" step="any" data-final-thickness value="${esc(details.final_format?.thickness_mm??'')}"></label></div>
                <div class="vr-dimensions-drawing"><div class="vr-format-preview" data-final-preview></div><small>Formato fechado proporcional: largura na horizontal, altura na vertical.</small></div></div></fieldset>` : ''}
            ${draft.type==='magazine'?`<label class="vr-choice"><input type="checkbox" data-jacket${draft.components.some(c=>c.role==='jacket')?' checked':''}> Sobrecapa independente</label>`:''}
            ${isBox()?`<fieldset class="vr-choice-group"><legend>Modelo da caixa <small>obrigatório</small></legend><div class="vr-box-models">
            ${Object.entries(productRules.box_models).map(([id,name])=>`<label class="vr-choice vr-box-model"><input type="radio" name="vr-box-model" data-box-model required value="${id}"${details.box?.model===id?' checked':''}>
            <span>${boxDrawing(id,...(id==='folding_flaps'||id==='automatic_bottom'?[65,50,100]:id==='mailer'?[120,85,32]:[110,80,55]))}${esc(name)}</span></label>`).join('')}</div>
            <label class="vr-label">Descrição / referência do modelo${details.box?.model==='custom'?' — obrigatório':''}<input data-box-description maxlength="1000" value="${esc(details.box?.model_description??'')}"${details.box?.model==='custom'?' required':''}></label>
            ${details.box?.model==='custom'&&!isCorrugated()?`<label class="vr-label">Estrutura da caixa personalizada<select data-box-type>${options([
                {id:'folding_box',name:'Dobrável'},{id:'rigid_box',name:'Rígida'},{id:'lid_base_box',name:'Tampa e fundo'},{id:'accessory_box',name:'Com interior / divisórias'}],draft.type)}</select></label>`:''}
            </fieldset>`:''}
            ${isBox() ? `<fieldset class="vr-choice-group"><legend>Dimensões da caixa montada <small>obrigatório</small></legend><div class="vr-dimensions-layout"><div class="vr-dimensions-fields">
                <label class="vr-label">Comprimento (mm)<input type="number" min="0" step="any" required data-box-length value="${esc(details.box?.length_mm ?? '')}"></label>
                <label class="vr-label">Largura (mm)<input type="number" min="0" step="any" required data-box-width value="${esc(details.box?.width_mm ?? '')}"></label>
                <label class="vr-label">Altura (mm)<input type="number" min="0" step="any" required data-box-height value="${esc(details.box?.height_mm ?? '')}"></label>
                <label class="vr-label">Tipo de medidas<select data-box-measurement required><option value="">Escolha</option>${options([{id:'internal',name:'Interiores úteis'},{id:'external',name:'Exteriores'}],details.box?.measurement)}</select></label>
                ${!isCorrugated() ? '<small>Estas medidas são da caixa montada. As dimensões dos componentes abaixo são independentes.</small>' : ''}</div>
                <div class="vr-dimensions-drawing"><div data-box-preview></div><button type="button" class="btn btn-default btn-sm" data-box-rotate>Rodar vista 3D</button>
                <small>Vista 3D ilustrativa, proporcional às medidas indicadas. Não é uma planificação, simulação de dobragem ou cortante validado. Para modelos personalizados a vista representa apenas o volume exterior. Junte o desenho técnico em PDF, SVG, DXF ou AI nos Documentos.</small></div></div></fieldset>` : ''}
            ${isDocument() ? (() => {
                const book = details.document_book || {}, copies = book.copies ?? 3;
                return `<fieldset class="vr-choice-group"><legend>Livro autocopiativo · unidade: 1 livro</legend>
                <div class="vr-grid"><label class="vr-label">Número de início <small>obrigatório</small><input data-book-start inputmode="numeric" pattern="[0-9]{1,12}" maxlength="12" value="${esc(book.start ?? '')}" required></label>
                <label class="vr-label">Conjuntos por livro <small>obrigatório</small><input data-book-sets type="number" min="1" max="10000" step="1" value="${esc(book.sets_per_book ?? 50)}" required></label>
                <label class="vr-label">Vias (folhas) por conjunto <small>obrigatório</small><input data-book-copies type="number" min="1" max="20" step="1" value="${esc(copies)}" required></label>
                <label class="vr-label">Picotado ou colado <small>obrigatório</small><select data-book-binding required><option value="">Escolha</option>${options([{id:'perforated',name:'Picotado'},{id:'glued',name:'Colado'}],book.binding)}</select></label></div>
                <div class="vr-grid" data-book-colors>${Array.from({length:Number.isInteger(copies) && copies > 0 && copies <= 20 ? copies : 0},(_,index) =>
                    `<label class="vr-label">Cor do papel — via ${index+1}<input data-book-paper-color maxlength="80" required value="${esc(book.paper_colors?.[index] ?? (index === 0 ? 'Branco' : ''))}" placeholder="${index === 0 ? 'Branco' : 'Ex.: amarelo, rosa, azul'}"></label>`).join('')}</div>
                <small>As vias do mesmo conjunto têm o mesmo número. Ex.: 50 × 3 = 50 conjuntos numerados, 150 folhas por livro.</small>
                <div data-book-numbering role="status" aria-live="polite"></div></fieldset>`;
            })() : ''}
            <div data-components></div>
            <div${finishingRows(null,'packaging').length||details.packaging_operations?.length?'':' hidden'}><fieldset class="vr-choice-group"><legend>Embalamento individual — após o produto pronto</legend>
                <div data-packaging-operations class="vr-subcategories">${finishingOptions(details.packaging_operations||[],null,'packaging')}</div>
                <small>Uma aplicação por exemplar terminado. Validação pelo formato final fechado; confirmar espessura e capacidade do saco/envelope na preparação técnica.</small></fieldset></div>
            <div data-finishing-review role="status" aria-live="polite"></div>
            <label class="vr-label">Outros requisitos dos acabamentos<input data-finishing maxlength="2000" value="${esc(details.finishing || '')}" placeholder="Ex.: posição dos furos, localização do relevo…"></label>
            <label class="vr-label">Observações<textarea data-notes rows="2" maxlength="20000" placeholder="Requisitos, dúvidas ou indicações adicionais.">${esc(identity.notes)}</textarea></label></section>
            <section class="vr-section"><h3><span>4</span> Expedição</h3><div class="vr-subcategories">
            ${[{id:'pickup',name:'Levantar nas instalações'},{id:'carrier',name:'Envio por Transportador'},{id:'delivery',name:'Entrega (Sujeito a disponibilidade e confirmação)'}].map(method =>
                `<label class="vr-choice"><input type="radio" name="vr-dispatch" data-dispatch value="${method.id}"${details.dispatch?.length === 1 && details.dispatch.includes(method.id) ? ' checked' : ''}><span>${method.name}</span></label>`).join('')}</div>
                ${details.dispatch?.length > 1 ? '<p class="vr-warning">Este pedido tinha várias opções de expedição. Escolha apenas uma antes de guardar.</p>' : ''}</section>
            <section class="vr-section"><h3><span>5</span> Documentos</h3>
            <label class="vr-upload">Escolher documentos / desenhos da caixa<input type="file" data-documents multiple accept=".pdf,.svg,.dxf,.ai,.jpg,.jpeg,.png,.tif,.tiff,.docx,.xlsx,.pptx,.odt,.ods,.zip"></label>
            <small class="vr-help">Desenhos: PDF, SVG, DXF ou AI (anexos, sem conversão automática em 3D). Também imagens/documentos · Até 10 documentos · 10 MB/ficheiro · 25 MB no total.</small>
            <ul data-files class="vr-files"></ul></section>
            <div class="vr-submit"><p>${state.conversion?.status === Number(initial.schema['conversion.ready']) ?
                'Este pedido está pronto para orçamentar. Guardar volta a executar o automatismo nativo de criação do rascunho, sem calcular preços.' :
                'Guardar recolhe a informação. Não cria um orçamento nem calcula preços.'}</p><div data-actions></div></div>
            <details class="vr-section"><summary>Consultar pedidos guardados</summary><div data-recent-actions></div><div data-recent></div></details>`;
        root.querySelector('[data-top-actions]').append(button('Novo pedido',() => {
            if (dirty && !confirm('Descartar as alterações que ainda não guardou?')) return;
            fresh(); location.hash = ''; render();
        }));
        const importDialog = root.querySelector('[data-import-dialog]');
        root.querySelector('[data-import-open]').append(button(importText || importFiles.length ? 'Continuar importação' : 'Importar pedido',() => {
            importDialog.showModal(); importDialog.querySelector('[data-import-text]').focus();
        },true));
        root.querySelector('[data-import-close]').addEventListener('click',() => importDialog.close());
        importDialog.addEventListener('click',event => { if (event.target === importDialog) importDialog.close(); });
        importDialog.addEventListener('close',() => {
            const box = importDialog.querySelector('[data-import-message]'); box.className = ''; box.textContent = '';
        });
        root.querySelector('[data-import-actions]').append(button('Cancelar',() => importDialog.close()),button('Extrair e preencher rascunho',importRequest,true));
        root.querySelector('[data-import-text]').addEventListener('input',event => {
            importText=event.target.value;dirty=true;
        });
        root.querySelector('[data-import-text]').addEventListener('paste',event => {
            const pastedText=event.clipboardData?.getData('text/plain')||'';
            const images=[...(event.clipboardData?.items||[])].filter(item=>item.kind==='file'&&item.type.startsWith('image/'))
                .map(item=>item.getAsFile()).filter(Boolean);
            if(!images.length)return;
            event.preventDefault();
            if(pastedText) {
                const area=event.currentTarget,start=area.selectionStart,end=area.selectionEnd;
                area.setRangeText(pastedText,start,end,'end');
                importText=area.value;dirty=true;
            }
            queueImportFiles(images);
        });
        root.querySelector('[data-import-files]').addEventListener('change',event => {
            queueImportFiles(event.target.files||[]);event.target.value='';
        });
        renderImportQueue();
        renderImportResult();
        renderImportCandidates();
        renderImportQueueStatus();
        if (state.id) root.querySelector('[data-top-actions]').append(button('Duplicar pedido',duplicateCurrentRequest));
        for (const input of root.querySelectorAll('[data-category]')) input.addEventListener('change',() => chooseCategory(Number(input.value)));
        for (const input of root.querySelectorAll('[data-subtype]')) input.addEventListener('change',() => {
            collect(); draft.product_type = Number(input.value);
            const type = [3712,3756,Number(productRules.brochure_category)].includes(category)&&
                Number(input.value)!==Number(productRules.brochure_simple) ? 'magazine' : Number(input.value) === 3751 ? 'folding_box' : 'standard';
            if (type !== draft.type) structure(type);
            dirty = true; render();
        });
        const rubricArea = root.querySelector('[data-rubrics]');
        if (rubricArea) {
            rubricArea.querySelector('[data-rubric-add]').addEventListener('click',() => {
                collect(); draft.rubrics = [...(draft.rubrics || []),{quantity:'',description:'',code:''}]; dirty = true; render();
                [...root.querySelectorAll('[data-rubric-quantity]')].pop()?.focus();
            });
            for (const remove of rubricArea.querySelectorAll('[data-rubric-remove]')) remove.addEventListener('click',() => {
                remove.closest('[data-rubric]').remove(); collect(); dirty = true; render();
            });
            const updateRubricTotal = () => {
                const total = [...rubricArea.querySelectorAll('[data-rubric-quantity]')].reduce((sum,input) => {
                    const value = Number(input.value); return sum + (Number.isInteger(value) && value > 0 ? value : 0);
                },0);
                rubricArea.querySelector('[data-rubric-total]').textContent = String(total);
                const quantities = root.querySelector('[data-quantities]');
                quantities.value = String(total); quantities.readOnly = true;
                root.querySelector('[data-quantities-hint]').textContent = 'Soma das rubricas.';
            };
            for (const input of rubricArea.querySelectorAll('[data-rubric-quantity]')) input.addEventListener('input',updateRubricTotal);
        }
        const customer = window.jQuery(root.querySelector('[data-customer]'));
        customer.select2({
            width:'100%', placeholder:'Escreva para escolher o cliente…', minimumInputLength:2,
            dropdownParent:window.jQuery(root),
            language:{
                inputTooShort:() => 'Escreva pelo menos 2 caracteres.',
                searching:() => 'A procurar clientes…',
                noResults:() => 'Nenhum cliente encontrado.',
                errorLoading:() => 'Não foi possível carregar os clientes. Volte a escrever para tentar novamente.'
            },
            ajax:{
                url:initial.url, dataType:'json', delay:250,
                data:params => ({api:'customers',term:params.term}),
                transport:(params,success,failure) => {
                    const request = window.jQuery.ajax(params);
                    request.done(result => {
                        if (result.ok) success(result);
                        else {
                            message(result.error || 'Não foi possível consultar os clientes.',true);
                            failure();
                        }
                    });
                    request.fail((xhr,status) => {
                        if (status !== 'abort') {
                            message(xhr.responseJSON?.error || 'Não foi possível consultar os clientes. Tente novamente.',true);
                            failure();
                        }
                    });
                    return request;
                },
                processResults:result => ({results:result.data.map(row => ({id:row.id,text:row.name}))})
            }
        });
        customer.on('change',() => {
            const changed = identity.customer !== Number(customer.val());
            identity.customer = Number(customer.val());
            identity.customer_name = customer.find('option:selected').text();
            root.querySelector('[data-internal-customer]').checked = identity.customer === Number(initial.schema['config.internal_customer']);
            if (changed) {
                draft.request_details.contact = ''; draft.request_details.contact_id = 0;
                window.jQuery(root.querySelector('[data-contact]')).select2('close').empty().append(new Option('','')).val('').prop('disabled',!identity.customer).trigger('change');
            }
            dirty = true;
        });
        root.querySelector('[data-internal-customer]').addEventListener('change',async event => {
            const checked = event.target.checked;
            event.target.disabled = true;
            try {
                if (checked) {
                    const internal = await api('internal_customer',{},true);
                    if (!customer.find('option').toArray().some(option => Number(option.value) === Number(internal.id)))
                        customer.append(new Option(internal.name,internal.id));
                    customer.val(String(internal.id)).trigger('change').prop('disabled',true);
                }else customer.prop('disabled',false).val('').trigger('change');
            }catch(error) {
                event.target.checked = identity.customer === Number(initial.schema['config.internal_customer']);
                message(error.message,true);
            }finally{event.target.disabled = false;}
        });
        const contact = window.jQuery(root.querySelector('[data-contact]'));
        contact.select2({
            width:'100%', placeholder:'Escolha ou escreva um contacto…', allowClear:true, tags:true,
            dropdownParent:window.jQuery(root),
            createTag:params => {
                const text = params.term.trim();
                return text && text.length <= 500 ? {id:'manual:'+text,text,newTag:true} : null;
            },
            language:{
                searching:() => 'A procurar contactos…',
                noResults:() => 'Sem contactos. Pode escrever outro.',
                errorLoading:() => 'Não foi possível carregar os contactos. Tente novamente.'
            },
            ajax:{
                url:initial.url, dataType:'json', delay:250,
                data:params => ({api:'contacts',customer_id:identity.customer,term:params.term || ''}),
                transport:(params,success,failure) => {
                    const customerId = identity.customer;
                    const request = window.jQuery.ajax(params);
                    request.done(result => {
                        if (customerId !== identity.customer) return;
                        if (result.ok) success(result);
                        else {message(result.error || 'Não foi possível consultar os contactos.',true);failure();}
                    });
                    request.fail((xhr,status) => {
                        if (status !== 'abort' && customerId === identity.customer) {
                            message(xhr.responseJSON?.error || 'Não foi possível consultar os contactos. Tente novamente.',true);
                            failure();
                        }
                    });
                    return request;
                },
                processResults:result => ({results:result.data.map(row => ({id:row.id,text:row.text}))})
            }
        });
        contact.on('change',() => {
            draft.request_details.contact = contact.find('option:selected').text();
            draft.request_details.contact_id = Number(contact.val()) || 0;
            dirty = true;
        });
        for (const component of draft.components.filter(c => c.kind !== 'assembly')) renderComponent(component);
        root.querySelector('[data-box-type]')?.addEventListener('change',event=>{
            collect();structure(event.target.value);dirty=true;render();
        });
        if(hasPages()) {
            const updatePages=()=>{
                collect();
                for(const component of draft.components.filter(c=>c.kind!=='assembly'&&c.role!=='jacket')) {
                    const input=root.querySelector(`[data-component="${component.id}"] [data-field="4233"]`);
                    if(input)input.value=Number(draft.request_details.total_pages)>0?component.fields[4233]:'';
                }
            };
            root.querySelector('[data-total-pages]').addEventListener('input',updatePages);
            updatePages();
        }
        if(isBox()) {
            let rotation=0;
            const updateBox=()=>{
                const model=root.querySelector('[data-box-model]:checked')?.value;
                const dimensions=['length','width','height'].map(key=>root.querySelector('[data-box-'+key+']').value);
                root.querySelector('[data-box-preview]').innerHTML=model?boxDrawing(model,...dimensions,rotation):'<p>Escolha o modelo da caixa.</p>';
            };
            root.querySelector('[data-box-rotate]').addEventListener('click',()=>{rotation=(rotation+1)%4;updateBox();});
            for(const input of root.querySelectorAll('[data-box-length],[data-box-width],[data-box-height]'))input.addEventListener('input',updateBox);
            const structures={folding_flaps:'folding_box',automatic_bottom:'folding_box',lid_base:'lid_base_box',mailer:'folding_box',rigid:'rigid_box',insert:'accessory_box'};
            for(const input of root.querySelectorAll('[data-box-model]'))input.addEventListener('change',()=>{
                collect();
                if(!isCorrugated()&&structures[input.value]&&structures[input.value]!==draft.type)structure(structures[input.value]);
                dirty=true;render();
            });
            updateBox();
        }
        const updateFormatPreviews=()=>{
            const finalPreview=root.querySelector('[data-final-preview]');
            if(finalPreview)finalPreview.innerHTML=formatDrawing(root.querySelector('[data-final-width]').value,root.querySelector('[data-final-height]').value);
            for(const row of root.querySelectorAll('[data-component]')) {
                const preview=row.querySelector('[data-format-preview]');
                if(preview)preview.innerHTML=formatDrawing(row.querySelector('[data-field="4218"]').value,row.querySelector('[data-field="4219"]').value);
            }
        };
        const updateCoverFormat=()=>{
            collect();
            for(const component of draft.components.filter(c=>['cover','jacket'].includes(c.role))) {
                const row=root.querySelector(`[data-component="${component.id}"]`);
                for(const field of [4218,4219])row.querySelector(`[data-field="${field}"]`).value=component.fields[field]||'';
            }
            updateFormatPreviews();
        };
        for(const input of root.querySelectorAll('[data-final-width],[data-final-height],[data-flap-left],[data-flap-right],[data-cover-spine]'))
            input.addEventListener('input',updateCoverFormat);
        for(const input of root.querySelectorAll('[data-field="4218"],[data-field="4219"]'))
            input.addEventListener('input',updateFormatPreviews);
        updateFormatPreviews();
        root.querySelector('[data-jacket]')?.addEventListener('change',event=>{
            collect();
            if(event.target.checked) {
                const id=Math.max(0,...draft.components.map(c=>c.id))+1;
                const spine=draft.components.find(c=>c.role==='cover')?.request?.cover?.spine_mm||'0';
                const format=draft.request_details.final_format;
                draft.components.push({id,name:'Sobrecapa',role:'jacket',kind:'printed',units_per_product:1,inputs:[],
                    fields:format?.width_mm&&format?.height_mm?{4218:String(2*Number(format.width_mm)+Number(spine)),4219:format.height_mm}:{},
                    papers:[],colors:[],rubrics:[],request:{cover:{left_mm:'0',right_mm:'0',spine_mm:spine}}});
            }else draft.components=draft.components.filter(c=>c.role!=='jacket');
            const jacketId=Number(initial.schema['stages.jacket_operation']);
            draft.request_details.finishing_operations=draft.request_details.finishing_operations.filter(op=>op.id!==jacketId);
            if(event.target.checked&&jacketId)draft.request_details.finishing_operations.push({id:jacketId,name:'Colocar sobrecapa',variants:[jacketId]});
            dirty=true;render();
        });
        root.querySelector('[data-book-copies]')?.addEventListener('change',() => {
            collect(); dirty = true; render();
        });
        for (const input of root.querySelectorAll('[data-book-start],[data-book-sets],[data-book-copies],[data-quantities]'))
            input.addEventListener('input',bookNumbering);
        bookNumbering();
        for (const input of root.querySelectorAll('[data-finishing-choice]'))
            input.addEventListener('change',() => {
                const finalFormat=root.querySelector('[data-final-format]');
                if(finalFormat)finalFormat.hidden=draft.type!=='magazine'&&!root.querySelector('[data-packaging-operations] [data-finishing-choice]:checked');
                const detailType = {36:'thread',42:'tape'}[Number(input.value)];
                if (detailType) {
                    const details = input.closest(`[data-${detailType}-option]`).querySelector(`[data-${detailType}-details]`);
                    details.hidden = !input.checked;
                    for (const field of details.querySelectorAll('input')) {
                        field.required = input.checked;
                        if (!input.checked) field.value = '';
                    }
                }
                dirty = true;scheduleReview();
            });
        for (const input of root.querySelectorAll('[data-thread-type],[data-thread-color],[data-thread-length],[data-tape-quantity],[data-tape-x],[data-tape-y]'))
            input.addEventListener('input',() => {dirty = true;scheduleReview();});
        for (const input of root.querySelectorAll('[data-lamination]')) input.addEventListener('change',() => {
            const row = input.closest('[data-component]');
            row.querySelector('[data-lamination-faces]').hidden = !input.checked;
            if (!input.checked) for (const select of row.querySelectorAll('[data-lamination-face]')) {
                select.value = catalog.choices.find(choice => Number(choice.fields_id) === Number(select.dataset.field) && choice.value === 'none').id;
            }
            dirty = true; collect(); scheduleReview();
        });
        for (const select of root.querySelectorAll('[data-lamination-face]')) select.addEventListener('change',scheduleReview);
        for (const row of root.querySelectorAll('[data-component]')) {
            const updateEnveloping = () => {
                const width = Number(row.querySelector('[data-field="4218"]').value);
                const height = Number(row.querySelector('[data-field="4219"]').value);
                const fits = width > 0 && height > 0 && Math.max(width,height) < finishingRules.envelope_max[0] && Math.min(width,height) < finishingRules.envelope_max[1];
                const inputs = [...row.querySelectorAll('[data-finishing-choice]')].filter(input => finishingRules.size_limited_operations.includes(Number(input.value)));
                for (const input of inputs) {
                    input.disabled = !fits && !input.checked;
                    input.closest('label').title = fits ? '' : input.dataset.name + ': a peça deve ser inferior a 324 × 254 mm, considerando a rotação.';
                }
                row.querySelector('[data-envelope-hint]').hidden = fits || !inputs.length;
            };
            updateEnveloping();
            for (const input of row.querySelectorAll('[data-field="4218"],[data-field="4219"]'))
                input.addEventListener('input',() => {updateEnveloping();scheduleReview();});
            for (const input of row.querySelectorAll('[data-finishing-choice]'))
                if (finishingRules.size_limited_operations.includes(Number(input.value))) input.addEventListener('change',updateEnveloping);
            const updateDie = () => {
                const enabled = row.querySelector('[data-die-enabled]').checked;
                const used = row.querySelector('[data-die-status]').value === 'used';
                row.querySelector('[data-die-details]').hidden = !enabled;
                row.querySelector('[data-die-number-label]').hidden = !enabled || !used;
                row.querySelector('[data-die-number]').required = enabled && used;
                dirty = true; scheduleReview();
            };
            for (const input of row.querySelectorAll('[data-die-enabled],[data-die-status],[data-die-number]')) input.addEventListener('change',updateDie);
            const updatePrint = () => {
                const mode = row.querySelector('[data-print-color]:checked')?.value;
                const combined = mode === 'combined';
                const pantone = ['pantone','cmyk_pantone'].includes(mode) || (combined && [...row.querySelectorAll('[data-combined-color]:checked')].some(input => [3732,3820].includes(Number(input.value))));
                row.querySelector('[data-combined-colors]').hidden = !combined;
                row.querySelector('[data-pantone-label]').hidden = !pantone;
                row.querySelector('[data-pantone]').required = pantone;
                row.querySelector('[data-print-faces]').hidden = mode === 'none';
                row.querySelector('[data-field="4229"]').required = Boolean(mode) && mode !== 'none';
                dirty = true;
            };
            for (const input of row.querySelectorAll('[data-print-color],[data-combined-color]')) input.addEventListener('change',updatePrint);
        }
        if (!draft.components.length) root.querySelector('[data-components]').innerHTML = '<p class="vr-hint">Escolha um produto acima para indicar o tamanho, o papel e a impressão. Se ainda não souber o tipo de produto, basta descrevê-lo abaixo.</p>';
        root.querySelector('[data-documents]').addEventListener('change',event => {
            const selected = [...event.target.files];
            const existing = (state.attachments || []).filter(file => !removed.includes(Number(file.id)));
            if (uploads.length + selected.length + existing.length > 10 || selected.some(file => !file.size || file.size > 10*1024*1024) ||
                [...existing,...uploads,...selected].reduce((sum,file) => sum + Number(file.size),0) > 25*1024*1024) {
                message('Pode juntar até 10 documentos: máximo de 10 MB por ficheiro e 25 MB no total.',true);
            } else {uploads.push(...selected);dirty = true;renderFiles();}
            event.target.value = '';
        });
        renderFiles();
        root.querySelector('[data-actions]').append(button(state.id ? 'Guardar alterações' : 'Guardar pedido',submit,true));
        root.querySelector('[data-recent-actions]').append(button('Mostrar pedidos guardados',recent));
        scheduleReview();
    }
    function paperSuggestion(productType,category,component,menu=false) {
        const type=Number(productType);
        if(type===3855)return 'Ex.: cartão canelado microcanelado E ou canelado B';
        if(category===3752)return 'Ex.: papel autocopiativo CB / CFB / CF, 1.ª via branca';
        if(component.kind==='material')return 'Ex.: cartão cinzento 2 mm, cartão compacto ou microcanelado';
        if(type===3751)return 'Ex.: cartolina gráfica 350 g/m² ou kraft 300 g/m²';
        if(component.role==='jacket')return 'Ex.: couché mate 150 g/m² para sobrecapa';
        if(component.role==='cover')return [3758,3760].includes(type)?
            'Ex.: papel de revestimento 135 g/m² sobre cartão cinzento 2 mm':
            'Ex.: couché mate 250 g/m² ou cartolina 300 g/m²';
        if(category===3756)return 'Ex.: offset 80 g/m², papel creme 90 g/m² ou couché mate 115 g/m²';
        if(category===3712||component.name==='Miolo')return 'Ex.: couché mate 115 ou 135 g/m², offset 100 g/m²';
        const examples={
            3708:'cartolina branca 300 ou 350 g/m²',
            3709:'envelope offset branco 90 g/m² ou kraft 100 g/m²',
            3710:'offset branco 80 ou 90 g/m²',
            3711:'cartolina gráfica 300 ou 350 g/m²',
            3826:'offset branco 80 ou 90 g/m²',
            3828:'offset branco 90 ou 100 g/m²',
            3829:'cartolina 300 g/m² ou papel texturado 250 g/m²',
            3854:'cartão compacto 1,5 ou 2 mm com revestimento',
            3744:'papel autocolante mate/brilho ou polipropileno adesivo',
            3761:'vinil autocolante branco/transparente ou papel autocolante',
            3745:'cartolina gráfica 400 g/m² ou cartão microcanelado',
            3746:'couché 170 g/m² ou cartolina 250 g/m²',
            3832:'papel kraft 100 ou 120 g/m²',
            3833:'papel kraft 80 ou 100 g/m²',
            3834:'papel autocolante mate/brilho ou resistente à humidade',
            3835:'cartolina 300 ou 350 g/m², kraft 300 g/m²',
            3801:'couché mate 135 ou 170 g/m²',
            3802:'couché brilho 115 ou 135 g/m²',
            3810:'offset branco 80 ou 90 g/m²',
            3824:'couché mate 150 ou 170 g/m²',
            3847:'papel texturado 250 g/m² ou cartolina 300 g/m²',
            3848:'cartolina gráfica 300 ou 350 g/m²',
            3849:'couché mate 170 ou 200 g/m²',
            3850:'cartolina 300 ou 350 g/m²',
            3852:'papel vergé 120 g/m² ou texturado 200 g/m²',
            3853:'offset 100 g/m² ou cartolina 160 g/m²'
        };
        if(examples[type])return 'Ex.: '+examples[type];
        if(menu)return 'Ex.: couché mate 250 g/m² ou cartolina 300 g/m²';
        if(category!==3800&&category!==3707&&category!==3743&&category!==3825)
            return 'Ex.: couché mate 170 g/m² ou offset 120 g/m²';
        return 'Indique o papel/material, gramagem ou espessura pretendidos';
    }
    function renderComponent(component) {
        const fields = component.fields || {}, req = component.request || {};
        const openFormat=Number(draft.product_type)===3711||['cover','jacket'].includes(component.role);
        if(component.role==='cover')fields[4233]='4';
        component.fields = fields;
        const colors = [...(component.colors || [])].map(Number).sort((a,b) => a-b).join(',');
        const color = (req.printing?.mode === 'black' ? 'combined' : req.printing?.mode) || Object.keys(printPresets).find(mode => [...printPresets[mode]].sort((a,b) => a-b).join(',') === colors) || (colors ? 'combined' : '');
        const pantone = ['pantone','cmyk_pantone'].includes(color) || (color === 'combined' && (component.colors || []).some(id => [3732,3820].includes(Number(id))));
        const die = req.die || {status:(req.finishing_operations || []).some(operation => operation.id === finishingRules.die_operation) ? 'new' : 'none',number:''};
        const row = document.createElement('div'); row.className = 'vr-component'; row.dataset.component = component.id;
        row.innerHTML = `${draft.components.filter(c => c.kind !== 'assembly').length > 1 ? `<h4>${esc(component.name)}</h4>` : ''}
            <div class="vr-grid"><label class="vr-label"${isCorrugated() ? ' hidden' : ''}>Largura ${isBox() ? 'do componente ' : openFormat?'aberta ':''}(mm)<input type="number" min="0" step="any" data-field="4218" value="${esc(fields[4218] ?? '')}"${['cover','jacket'].includes(component.role)?' readonly':''} placeholder="Ex.: 210"></label>
            <label class="vr-label"${isCorrugated() ? ' hidden' : ''}>Altura ${openFormat?'aberta ':''}(mm)<input type="number" min="0" step="any" data-field="4219" value="${esc(fields[4219] ?? '')}"${['cover','jacket'].includes(component.role)?' readonly':''} placeholder="Ex.: 297"></label>
            ${isCorrugated() ? `<label class="vr-label">Espessura do cartão canelado (mm) <small>obrigatório</small><input type="number" min="0" step="any" required data-corrugated-thickness value="${esc(req.corrugated?.thickness_mm ?? '')}" placeholder="Ex.: 3"></label>` : ''}
            ${hasPages()&&component.role!=='jacket' ? `<label class="vr-label">Páginas ${component.role==='cover'?'da capa':draft.type==='magazine'?'do miolo':'do produto'} — calculadas<input type="number" min="1" data-field="4233" value="${esc(fields[4233] ?? '')}" readonly></label>` : ''}
            <label class="vr-label">Papel / material pretendido<input data-paper maxlength="1000" value="${esc(isCorrugated() ? 'Cartão canelado' : isDocument() ? 'Autocopiativo' : req.paper || '')}"${isDocument() || isCorrugated() ? ' readonly' : ''} placeholder="${esc(paperSuggestion(draft.product_type,categoryOf(draft.product_type),component,isMenu()))}"></label></div>
            ${['cover','jacket'].includes(component.role)?`<div class="vr-grid">
                <label class="vr-label">Aba esquerda (mm)<input type="number" min="0" step="any" data-flap-left value="${esc(req.cover?.left_mm??0)}"></label>
                <label class="vr-label">Aba direita (mm)<input type="number" min="0" step="any" data-flap-right value="${esc(req.cover?.right_mm??0)}"></label>
                <label class="vr-label">Lombada (mm)<input type="number" min="0" step="any" data-cover-spine value="${esc(req.cover?.spine_mm??0)}"></label></div>
                <small>Formato aberto = 2 × largura fechada + lombada + abas. A capa tem 4 páginas, independentemente das faces impressas. A sobrecapa tem impressão e acabamentos próprios.</small>`:''}
            ${!isCorrugated()?`<div class="vr-format-figure"><div class="vr-format-preview" data-format-preview></div><small>${Number(draft.product_type)===3711?'Formato aberto da capa: largura na horizontal, altura na vertical.':openFormat?'Formato aberto proporcional, incluindo lombada e abas.':'Formato do componente proporcional: largura na horizontal, altura na vertical.'}</small></div>`:''}
            ${component.kind === 'printed' ? `<div class="vr-print-row"><fieldset class="vr-choice-group"><legend>Impressão <small>obrigatório</small></legend><div class="vr-subcategories">
            ${(isCorrugated() ? [{id:'none',name:'Sem impressão'}] : [{id:'cmyk',name:'CMYK'},{id:'pantone',name:'Pantone'},{id:'cmyk_pantone',name:'CMYK + Pantone'},{id:'white',name:'Branco'},{id:'combined',name:'Combinar cores'},{id:'none',name:'Sem impressão'}]).map(item => `<label class="vr-choice"><input type="radio" required name="color-${component.id}" data-print-color value="${item.id}"${item.id === (isCorrugated() ? 'none' : color) ? ' checked' : ''}${isCorrugated() ? ' disabled' : ''}><span>${item.name}</span></label>`).join('')}</div></fieldset>
            <label class="vr-label" data-print-faces${color === 'none' ? ' hidden' : ''}>Faces de impressão <small>obrigatório</small><select data-field="4229"${color && color !== 'none' && !isCorrugated() ? ' required' : ''}><option value="">Escolha as faces de impressão</option>${options(catalog.choices.filter(c => Number(c.fields_id) === 4229),fields[4229])}</select></label></div>
            <div data-combined-colors${color === 'combined' ? '' : ' hidden'}><fieldset class="vr-choice-group"><legend>Cores a combinar</legend><div class="vr-subcategories">${catalog.colors.filter(row => ![3804,3742].includes(Number(row.id))).map(row =>
                `<label class="vr-choice"><input type="checkbox" data-combined-color value="${row.id}"${(component.colors || []).map(Number).includes(Number(row.id)) ? ' checked' : ''}><span>${esc(row.name)}</span></label>`).join('')}</div></fieldset></div>
            <label class="vr-label" data-pantone-label${pantone ? '' : ' hidden'}>Identifique o(s) Pantone(s) <small>obrigatório</small><input data-pantone maxlength="500" value="${esc(req.printing?.pantone || '')}"${pantone ? ' required' : ''} placeholder="Ex.: Pantone 186 C, Pantone 871 C"><small>Para indicar vários Pantones, separe as referências por vírgulas.</small></label>` : ''}
            <fieldset class="vr-choice-group"><legend>Acabamentos${draft.components.filter(c => c.kind !== 'assembly').length > 1 ? ' — '+esc(component.name) : ''}</legend>
            ${!finishingRows(component).length && !(req.finishing_operations || []).length && !req.lamination && die.status === 'none' && !finishingApplies(finishingRules.lamination_products) && !finishingApplies(catalog.finishing_operations.find(operation => Number(operation.id) === finishingRules.die_operation)?.product_types) ? '<small class="vr-help">Não existem acabamentos associados a este tipo de produto nas operações.</small>' : ''}
            ${component.kind === 'printed' ? (() => {
                const laminated = req.lamination ?? [6267,6268].some(field => catalog.choices.some(choice => Number(choice.fields_id) === field && String(choice.id) === String(fields[field]) && choice.value !== 'none'));
                if (!laminated && (!finishingRules.lamination_available || !finishingApplies(finishingRules.lamination_products))) return '';
                return `<div class="vr-lamination-row"><label class="vr-choice"><input type="checkbox" data-lamination${laminated ? ' checked' : ''}><span>Plastificação</span></label>
                <div data-lamination-faces class="vr-lamination-types"${laminated ? '' : ' hidden'}>${[{field:6267,label:'Frente'},{field:6268,label:'Verso'}].map(({field,label}) => {
                    const choices = catalog.choices.filter(choice => Number(choice.fields_id) === field);
                    return `<label class="vr-label">${label}<select data-field="${field}" data-lamination-face>${options(choices,fields[field] || choices.find(choice => choice.value === 'none').id)}</select></label>`;
                }).join('')}</div></div>`;
            })() : ''}
            <div data-component-finishing class="vr-subcategories vr-finishing-row">${finishingOptions(req.finishing_operations || [],component)}
            <div class="vr-die-column"${die.status !== 'none' || finishingApplies(catalog.finishing_operations.find(operation => Number(operation.id) === finishingRules.die_operation)?.product_types) ? '' : ' hidden'}><label class="vr-choice"><input type="checkbox" data-die-enabled${die.status !== 'none' ? ' checked' : ''}><span>Cortante</span></label>
            <div data-die-details${die.status !== 'none' ? '' : ' hidden'}><label class="vr-label">Cortante<select data-die-status>${options([{id:'new',name:'Novo — produzir cortante'},{id:'used',name:'Usado — cortante existente'}],die.status === 'used' ? 'used' : 'new')}</select></label>
            <label class="vr-label" data-die-number-label${die.status === 'used' ? '' : ' hidden'}>Número do cortante <small>obrigatório</small><input data-die-number maxlength="100" value="${esc(die.number)}"${die.status === 'used' ? ' required' : ''}></label></div></div></div>
            <small data-envelope-hint hidden>Envelopar / Ensacar: indique dimensões inferiores a 324 × 254 mm. A rotação da peça é considerada.</small></fieldset>`;
        root.querySelector('[data-components]').append(row);
        const preview=row.querySelector('[data-format-preview]');
        if(preview) {
            const layout=document.createElement('div');layout.className='vr-dimensions-layout';
            const measurements=document.createElement('div');measurements.className='vr-dimensions-fields';
            const drawing=document.createElement('div');drawing.className='vr-dimensions-drawing';
            const firstGrid=row.querySelector('.vr-grid');
            firstGrid.before(layout);layout.append(measurements,drawing);
            for(const input of row.querySelectorAll('[data-field="4218"],[data-field="4219"],[data-flap-left],[data-flap-right],[data-cover-spine]'))
                measurements.append(input.closest('label'));
            drawing.append(preview.closest('.vr-format-figure'));
            for(const grid of row.querySelectorAll('.vr-grid'))if(!grid.children.length)grid.remove();
        }
        if (isCorrugated()) {
            row.querySelector('[data-print-faces]').hidden = true;
            row.querySelector('[data-field="4229"]').required = false;
            row.querySelector('[data-combined-colors]').hidden = true;
            row.querySelector('[data-pantone-label]').hidden = true;
            row.querySelector('[data-pantone]').required = false;
        }
    }
    function renderFiles() {
        const list = root.querySelector('[data-files]'); list.replaceChildren();
        for (const file of (state.attachments || []).filter(file => !removed.includes(Number(file.id)))) {
            const item = document.createElement('li'), link = document.createElement('a');
            link.textContent = file.name; link.href = initial.url + '&' + new URLSearchParams({api:'download',config_id:state.id,attachment_id:file.id});
            item.append(link,document.createTextNode(' · guardado · ' + size(file.size) + ' '),button('Retirar',() => {
                if (!confirm('Retirar «' + file.name + '»? A remoção só é aplicada ao guardar.')) return;
                removed.push(Number(file.id));dirty = true;renderFiles();
            })); list.append(item);
        }
        uploads.forEach((file,index) => {
            const item = document.createElement('li');
            item.append(document.createTextNode(file.name + ' · por enviar · ' + size(file.size) + ' '),button('Retirar',() => {uploads.splice(index,1);dirty = true;renderFiles();})); list.append(item);
        });
        if (!list.children.length) list.innerHTML = '<li class="vr-help">Ainda não juntou documentos.</li>';
    }
    function size(bytes) { return Number(bytes) >= 1024*1024 ? (Number(bytes)/(1024*1024)).toFixed(1) + ' MB' : Math.max(1,Math.ceil(Number(bytes)/1024)) + ' KB'; }
    async function submit() {
        collect();
        if (!categoryOf(draft.product_type)) {
            root.querySelector('[data-category]').focus();throw new Error('Escolha o tipo de produto.');
        }
        if (!productReady()) {
            root.querySelector('[data-subtype]').focus();throw new Error('Escolha a subcategoria do produto para completar o pedido.');
        }
        if(isFoldedLeaflet()) {
            if(!Number.isSafeInteger(draft.request_details.folding.count)||draft.request_details.folding.count<1) {
                root.querySelector('[data-fold-count]').focus();throw new Error('Indique o número de dobras (inteiro positivo).');
            }
            if(!['open','closed'].includes(draft.request_details.folding.delivery)) {
                root.querySelector('[data-fold-delivery]').focus();throw new Error('Indique se o desdobrável é entregue aberto ou fechado.');
            }
        }
        if(isSticker()&&!stickerCuttingChoices.has(draft.request_details.cutting_operation)) {
            root.querySelector('[data-cutting-operation]').focus();throw new Error('Escolha a operação de corte pretendida para os autocolantes.');
        }
        if (Number(draft.product_type) === Number(initial.schema['config.other_type']) && !draft.request_details.other_product.trim()) {
            root.querySelector('[data-other-product]').focus();throw new Error('Escolheu Outro: preencha o campo Especifique.');
        }
        if (!identity.name) {root.querySelector('[data-title]').focus();throw new Error('Dê um nome ao trabalho para podermos identificar o pedido.');}
        if (!identity.customer) {
            window.jQuery(root.querySelector('[data-customer]')).select2('open');
            throw new Error('Escreva e selecione um cliente da tabela de entidades.');
        }
        for (const row of root.querySelectorAll('[data-component]')) {
            const component = draft.components.find(component => component.id === Number(row.dataset.component));
            if (component.kind !== 'printed') continue;
            if (!component.request.printing.mode || !component.colors.length) {
                row.querySelector('[data-print-color]').focus(); throw new Error(component.name + ': escolha a impressão e as cores pretendidas.');
            }
            if (component.request.printing.mode !== 'none' && ![1255,1256,1257].includes(Number(component.fields[4229]))) {
                row.querySelector('[data-field="4229"]').focus();
                throw new Error(component.name + ': indique as faces de impressão: frente, frente e verso ou verso.');
            }
            if (component.colors.some(id => [3732,3820].includes(id)) && !component.request.printing.pantone) {
                row.querySelector('[data-pantone]').focus(); throw new Error(component.name + ': identifique o(s) Pantone(s).');
            }
        }
        for (const [index,row] of [...root.querySelectorAll('[data-rubric]')].entries()) {
            const quantity = row.querySelector('[data-rubric-quantity]'), description = row.querySelector('[data-rubric-description]');
            if (!quantity.value.trim() && !description.value.trim() && !row.querySelector('[data-rubric-code]').value.trim()) continue;
            if (!Number.isInteger(Number(quantity.value)) || Number(quantity.value) < 1) {quantity.focus();throw new Error('Rubrica ' + (index+1) + ': indique uma quantidade inteira positiva.');}
            if (!description.value.trim()) {description.focus();throw new Error('Rubrica ' + (index+1) + ': indique a descrição.');}
        }
        clearTimeout(reviewTimer); ++reviewSequence;
        const review = await api('finishing_review',{config_id:state.id,payload:draft});
        showReview(review);
        if (review.errors.length) throw new Error('Reveja os acabamentos assinalados antes de guardar.');
        const submitted = {config_id:state.id,revision:state.revision,identity:{name:identity.name,customer:identity.customer,reference:identity.reference,notes:identity.notes},
            payload:draft,removed_attachments:removed};
        const signature = JSON.stringify([submitted,uploads.map(file => [file.name,file.size,file.lastModified])]);
        if (pending && pending !== signature) request = crypto.randomUUID();
        pending = signature;
        const form = root.querySelectorAll('input,select,textarea,button');
        const disabled = [...form].filter(element => !element.disabled);
        disabled.forEach(element => {element.disabled = true;});
        message('A guardar o pedido e os documentos. Aguarde…');
        let result;
        try {result = await api('submit',{...submitted,request_id:request},false,uploads);}
        finally {disabled.forEach(element => {element.disabled = false;});}
        state = result; draft = result.payload; uploads = []; removed = []; request = crypto.randomUUID();pending = null;dirty = false;
        render();location.hash = 'configuration=' + state.id;
        message('Pedido #' + state.id + ' guardado' + (state.attachments.length ? ', com ' + state.attachments.length + ' documento(s)' : '') + '. Pode voltar aqui para o completar.');
        root.querySelector('[data-message]').scrollIntoView({block:'center',behavior:'smooth'});
    }
    const markChanged = event => {
        if (!event.target.classList.contains('select2-search__field')) dirty = true;
    };
    root.addEventListener('input',markChanged);
    root.addEventListener('change',markChanged);
    window.addEventListener('beforeunload',event => {if (dirty || importQueue.length) {event.preventDefault();event.returnValue = '';}});
    render();
    const existing = location.hash.match(/^#(configuration|duplicate)=(\d+)$/);
    if (existing) open(Number(existing[2])).then(() => {
        if (existing[1] === 'duplicate') duplicateCurrentRequest();
    }).catch(error => message(error.message,true));
})();

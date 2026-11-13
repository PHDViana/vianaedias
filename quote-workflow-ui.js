(function () {
    'use strict';
    const root = document.querySelector('#vd-quote-workflow');
    if (!root) return;
    const modal = root.closest('.modal');
    if (modal && window.jQuery) {
        const instance = window.jQuery(modal).data('modal');
        if (instance) {
            instance.options.keyboard = true;
            instance.escape();
        }
    }
    if (window.jQuery) window.jQuery(document).off('keydown.vdQuoteWorkflow').on('keydown.vdQuoteWorkflow', event => {
        if (event.key !== 'Escape' || !root.isConnected || document.querySelector('dialog[open]')) return;
        const currentModal = root.closest('.modal');
        const visibleModals = [...document.querySelectorAll('.modal')].filter(node => node.offsetWidth > 0);
        if (!currentModal || visibleModals[visibleModals.length - 1] !== currentModal) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.jQuery(currentModal).modal('hide');
    });
    const content = root.querySelector('#vd-workflow-content');
    const message = root.querySelector('#vd-workflow-message');
    let state = JSON.parse(root.querySelector('#vd-workflow-state').textContent);
    const el = (tag, text) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const url = new URL('index.php', location.href);
    url.search = new URLSearchParams({ module: 'items/vd_quote_workflow', path: root.dataset.path });
    const money = value => value === null || value === undefined ? 'Por completar' :
        Number(value).toLocaleString('pt-PT', { style: 'currency', currency: 'EUR' });
    const unitMoney = (value, quantity) => value === null || value === undefined ? 'Por completar' :
        (value / Number(quantity)).toLocaleString('pt-PT', {
            style: 'currency', currency: 'EUR', minimumFractionDigits: 4, maximumFractionDigits: 4 });
    const smsStatus = value => ({
        not_started: 'Não iniciado', pending: 'A aguardar envio do email', sending: 'Em envio',
        sent: 'Aceite pelo operador', send_unknown: 'Não confirmado — não repetir',
        skipped_no_phone: 'Não enviado — sem telemóvel atribuído'
    })[value] || value;
    function table(headers, rows) {
        const result = el('table');
        result.className = 'table table-bordered';
        const head = result.createTHead().insertRow();
        headers.forEach(text => head.append(el('th', text)));
        const body = result.createTBody();
        rows.forEach(values => {
            const row = body.insertRow();
            values.forEach(value => row.append(el('td', value)));
        });
        return result;
    }
    async function showSnapshot(version) {
        const readingUrl = new URL(url);
        readingUrl.searchParams.set('api', 'snapshot');
        readingUrl.searchParams.set('version', version.id);
        const response = await fetch(readingUrl, { credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await response.json();
        if (!response.ok || result.ok !== true) throw new Error(result.error || 'Não foi possível consultar o instantâneo.');
        const snapshot = result.data;
        const calculation = snapshot.calculation;
        const ledger = calculation.allOperations;
        const dialog = el('dialog');
        dialog.className = 'vd-workflow-snapshot';
        dialog.setAttribute('aria-label', 'Detalhes da versão #' + snapshot.version_no);
        dialog.style.cssText = 'width:min(1200px,96vw);max-height:90vh;overflow:auto;border:1px solid #ccc;border-radius:6px;padding:20px;color:#333;background:#fff';
        const close = el('button', 'Fechar');
        close.type = 'button';
        close.className = 'btn btn-default';
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => dialog.remove(), { once: true });
        dialog.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                dialog.close();
            }
        });
        dialog.append(el('h2', 'Versão #' + snapshot.version_no + ' · componente #' + snapshot.component_id),
            el('p', snapshot.quantity + ' unidades · Custo ' + money(ledger.cost) +
                ' · Venda ' + money(ledger.sale) + ' · Venda/un. ' + unitMoney(ledger.sale, snapshot.quantity) +
                ' · sem IVA. Dados fixados nesta versão; consulta sem alterações.'));
        if (!ledger.complete) dialog.append(el('p', 'Rascunho incompleto · custo parcial ' +
            money(ledger.partialCost) + ' · venda parcial ' + money(ledger.partialSale)));
        const costing = window.vdOperationCosting;
        if (costing?.detail) {
            const details = costing.detail(ledger);
            const costDialog = details.querySelector('dialog');
            [...costDialog.querySelectorAll('button')].find(button => button.textContent === 'Fechar')?.remove();
            dialog.append(...costDialog.childNodes);
            dialog.querySelectorAll('.cb-missing-data').forEach(row => row.style.backgroundColor = '#fff0d6');
        } else {
            const lines = table(['Operação / material', 'Quantidade / base', 'Tempo (h)', 'Custo calculado',
                'Custo manual (€)', 'Custo utilizado', 'Acréscimo (%)', 'Venda calculada'],
            ledger.lines.map(line => [
                line.name, (line.quantity ?? 'Por completar') + ' ' + (line.unit || ''),
                line.hours ?? '—', money(Object.hasOwn(line, 'calculatedCost') ? line.calculatedCost : line.cost),
                ledger.inputs?.commercial?.costs?.[line.key] ?? '—', money(line.cost),
                line.markup ?? ledger.pricing?.markup ?? '—', money(line.sale)
            ]));
            [...lines.tBodies[0].rows].forEach((row, index) => {
                const line = ledger.lines[index];
                if (line.sale === 0 || line.sale === null || line.cost === null || line.errors?.length) {
                    row.style.backgroundColor = '#fff0d6';
                }
                if (line.source || line.errors?.length) {
                    row.cells[0].append(el('p', [line.source, ...(line.errors || [])].filter(Boolean).join(' · ')));
                }
                const source = ledger.sourceOperations?.find(operation =>
                    Number(operation.operation_id) === Number(line.operationId));
                if (line.parentOperationRow === undefined && source?.requirement_type === 'selected') {
                    row.cells[0].append(el('strong', ' · Extra'));
                }
            });
            dialog.append(lines);
            if (ledger.errors?.length) dialog.append(el('p', ledger.errors.join(' ')));
        }
        const parts = calculation.parts || (calculation.plan ? [{ plan: calculation.plan }] : []);
        if (parts.length) {
            dialog.append(el('h3', 'Geometria e montagens guardadas'),
                table(['Montagem', 'Formato do plano (mm)', 'Formato da folha (mm)', 'Poses', 'Colunas × filas', 'Planos / folha'],
                    parts.map((part, index) => {
                        const plan = part.plan || part;
                        return [index + 1, (plan.width ?? '—') + ' × ' + (plan.height ?? '—'),
                            (plan.sheet_width ?? '—') + ' × ' + (plan.sheet_height ?? '—'),
                            plan.poses ?? '—', (plan.columns ?? '—') + ' × ' + (plan.rows ?? '—'),
                            plan.per_sheet ?? '—'];
                    })));
        }
        dialog.append(close);
        root.append(dialog);
        dialog.showModal();
    }
    async function request(action, payload) {
        const attachment = payload.approvalFile;
        const data = { ...payload };
        delete data.approvalFile;
        const body = attachment ? new FormData() : new URLSearchParams();
        body.append('api', action);
        body.append('payload', JSON.stringify(data));
        body.append('form_session_token', root.dataset.token);
        if (attachment) body.append('approval_file', attachment);
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body
        });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Resposta inválida; estado não confirmado.'); }
        if (!response.ok || result.ok !== true) throw new Error(result.error || 'A ação não foi confirmada.');
        const refresh = new URL(url);
        refresh.searchParams.set('api', 'state');
        const reading = await fetch(refresh, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const current = await reading.json();
        if (!reading.ok || current.ok !== true) throw new Error('Ação processada; não foi possível reler o estado. Não repita automaticamente.');
        state = current.data;
        render();
        message.textContent = result.data.message || 'Estado persistido e verificado.';
        message.className = result.data.sms_state === 'send_unknown' ? 'alert alert-warning' : 'alert alert-success';
        if (action === 'accept' || action === 'restore_adjudication' || action === 'create_production_order') location.reload();
    }
    function button(text, action, payload) {
        const node = el('button', text);
        node.type = 'button';
        node.className = 'btn btn-default';
        node.addEventListener('click', async () => {
            node.disabled = true;
            message.textContent = '';
            try { await request(action, typeof payload === 'function' ? payload() : payload); }
            catch (error) { message.textContent = error.message; message.className = 'alert alert-danger'; }
            finally { node.disabled = false; }
        });
        return node;
    }
    function discountWarning(d) {
        const percent = values => values.map(value => String(value).replace('.', ',') + ' %').join(' + ');
        const warning = el('div');
        warning.className = 'alert alert-warning';
        warning.append(el('strong', 'Desconto alterado depois da aprovação. '),
            el('span', 'Proposta aprovada com ' + percent(d.discount_change.approved) + '; desconto atual ' +
                percent(d.discount_change.current) + '. Esta proposta não pode ser enviada; aprove novamente as mesmas versões para gerar a proposta com o desconto atual.'));
        if (state.approver) warning.append(el('br'), button('Aprovar novamente com o desconto atual', 'approve',
            () => ({ versions: d.version_ids })));
        return warning;
    }
    function deliveryForm(d) {
        const form = el('div');
        const email = el('input');
        email.type = 'email';
        email.value = d.deliveries[0]?.email || d.recipient_email;
        email.size = 40;
        const emailLabel = el('label', 'Email de destino ');
        emailLabel.append(email);
        const phone = el('input');
        phone.type = 'tel';
        phone.value = state.send_phone;
        phone.size = 25;
        const phoneLabel = el('label', 'Telemóvel para SMS ');
        phoneLabel.append(phone);
        const requestId = 'delivery-' + crypto.randomUUID();
        form.append(emailLabel, el('br'), phoneLabel,
            el('p', 'Pode alterar os destinatários para este envio, sem modificar a proposta aprovada. Sem telemóvel, envia apenas email; com telemóvel, envia sempre SMS depois do email.'));
        if (!state.control.production_document && state.approver && ['approved', 'sent'].includes(d.state) &&
            !d.discount_change?.changed && !['sending', 'send_unknown'].includes(d.sms_state)) {
            form.append(button(d.state === 'sent' ? 'Confirmar novo envio ao cliente' : 'Confirmar envio ao cliente', 'send', () => {
                if (!email.checkValidity() || !email.value.trim()) throw new Error('Introduza um email de destino válido.');
                const destination = email.value.trim();
                const mobile = phone.value.trim();
                if (!confirm('Enviar ' + (d.state === 'sent' ? 'novamente ' : '') + 'a proposta #' + d.id +
                    ' para ' + destination + (mobile ? ' e enviar SMS para ' + mobile + '?' : ', sem SMS?'))) {
                    throw new Error('Envio cancelado.');
                }
                return { document: d.id, email: destination, phone: mobile, request_id: requestId };
            }));
        } else form.append(el('p', !state.approver ? 'O seu perfil não permite enviar propostas ao cliente.' :
            d.discount_change?.changed ? 'Envio indisponível: desconto alterado depois da aprovação.' :
            'Envio indisponível: proposta substituída ou envio em curso/sem confirmação. Consulte o histórico e a auditoria.'));
        if (d.deliveries.length) form.append(el('h3', 'Histórico de envios'),
            table(['Data', 'Remetente', 'Email de destino', 'Email', 'Telemóvel', 'SMS'],
                d.deliveries.map(delivery => [
                    new Date(Number(delivery.created_at) * 1000).toLocaleString('pt-PT'),
                    delivery.sender_email, delivery.email, delivery.email_state,
                    delivery.phone || 'Sem telemóvel', smsStatus(delivery.sms_state)
                ])));
        return form;
    }
    function approvalFileLink(d) {
        const link = el('a', 'Documento de aprovação: ' + d.approval_file.name);
        const download = new URL(url);
        download.searchParams.set('approval_file', d.id);
        link.href = download.href;
        link.style.cssText = 'display:block;margin:12px 0';
        return link;
    }
    function deliveryInput() {
        const input = el('input');
        input.type = 'date';
        input.required = true;
        input.min = state.delivery_min;
        input.value = state.delivery_default;
        input.style.cssText = 'display:block;margin:6px 0';
        const label = el('label', 'Data prevista de entrega (obrigatória)');
        label.style.cssText = 'display:block;width:100%;margin-bottom:12px';
        label.append(input, el('small', 'Por defeito: 3 dias úteis após adjudicação, excluindo fins de semana e feriados nacionais portugueses. Pode alterar.'));
        return { input, label };
    }
    function appendProductionDelivery() {
        if (!state.production_order) return;
        if (state.production_order.delivery_date) {
            content.append(el('p', 'Data prevista de entrega: ' + state.production_order.delivery_date.split('-').reverse().join('/')));
        } else if (state.approver) {
            const delivery = deliveryInput();
            content.append(delivery.label, button('Guardar data prevista de entrega na OP', 'set_delivery_date', () => {
                if (!delivery.input.reportValidity()) throw new Error('Introduza uma data prevista de entrega válida.');
                return { delivery_date: delivery.input.value };
            }));
        }
    }
    function render() {
        content.replaceChildren();
        if (root.dataset.acceptOnly === '1') {
            if (state.control.production_document) {
                content.append(el('h2', 'Adjudicação registada'),
                    el('p', 'Proposta #' + state.control.production_document + ' · ' +
                        state.control.production_quantity + ' unidades selecionadas para produção.'));
                const accepted = state.documents.find(d => Number(d.id) === Number(state.control.production_document));
                if (accepted?.approval_file) content.append(approvalFileLink(accepted));
                if (state.production_order) {
                    const link = el('a', 'Abrir ' + state.production_order.number);
                    link.href = state.production_order.url;
                    link.className = 'btn btn-info';
                    content.append(link, el('p', 'Existe uma OP. Trate a OP primeiro; o restabelecimento da adjudicação está bloqueado.'));
                    appendProductionDelivery();
                    if (state.production_order.unassigned_materials) content.append(el('p',
                        'Existem ' + state.production_order.unassigned_materials + ' materiais sem artigo específico fixado. Reveja as referências e os consumos previstos antes de reservar stock.'));
                } else if (!state.production_order_exists && state.approver) {
                    content.append(button('Gerar ordem de produção da adjudicação existente', 'create_production_order', () => {
                        if (!confirm('Criar a OP da alternativa adjudicada, sem reenviar email/SMS nem movimentar stock?')) {
                            throw new Error('Criação da OP cancelada.');
                        }
                        return {};
                    }));
                }
                if (state.admin && !state.production_order_exists) content.append(button('Restabelecer envio e adjudicação', 'restore_adjudication', () => {
                    if (!confirm('Reabrir a adjudicação e retirar a seleção para produção? O histórico e os anexos serão preservados.')) {
                        throw new Error('Restabelecimento cancelado.');
                    }
                    return {};
                }));
                return;
            }
            const d = state.documents.find(document => document.can_accept);
            if (!d) {
                content.append(el('p', 'Não existe uma proposta enviada, válida e atual disponível para adjudicação. Consulte as versões e a proposta comercial.'));
                return;
            }
            content.append(el('h2', 'Proposta comercial #' + d.id),
                el('p', 'Selecione a quantidade efetivamente aceite pelo cliente. Valores sem IVA.'));
            const choices = [];
            const alternatives = table(['Adjudicar', 'Quantidade', 'P. unit. s/ IVA', 'Desc. cliente', 'Desc. extra', 'Desc. total', 'Total s/ IVA'], []);
            for (const alternative of d.alternatives) {
                const row = alternatives.tBodies[0].insertRow();
                const radio = el('input');
                radio.type = 'radio';
                radio.name = 'vd-adjudication-quantity';
                radio.value = alternative.quantity;
                radio.setAttribute('aria-label', 'Adjudicar ' + alternative.quantity + ' unidades');
                choices.push(radio);
                const cell = row.insertCell();
                cell.append(radio);
                row.append(el('td', alternative.quantity), el('td', unitMoney(alternative.grossSale ?? alternative.sale, alternative.quantity)),
                    el('td', String(alternative.discountPercent ?? 0).replace('.', ',') + ' %'),
                    el('td', String(alternative.extraDiscountPercent ?? 0).replace('.', ',') + ' %'),
                    el('td', String(alternative.totalDiscountPercent ?? alternative.discountPercent ?? 0).replace('.', ',') + ' %'),
                    el('td', money(alternative.sale)));
            }
            const evidence = el('textarea');
            evidence.rows = 3;
            evidence.style.width = '100%';
            evidence.placeholder = 'Ex.: aceite por email em 09/11/2026, referência de encomenda…';
            const evidenceLabel = el('label', 'Referência da aceitação do cliente (opcional)');
            evidenceLabel.style.cssText = 'display:block;width:100%;margin-bottom:12px';
            evidenceLabel.append(evidence);
            const attachment = el('input');
            attachment.type = 'file';
            attachment.accept = '.pdf,.jpg,.jpeg,.png';
            const attachmentLabel = el('label', 'Documento de aprovação (opcional — PDF, JPG ou PNG, até 5 MB)');
            attachmentLabel.style.cssText = 'display:block;width:100%;margin-bottom:12px';
            attachmentLabel.append(attachment);
            const delivery = deliveryInput();
            content.append(alternatives, delivery.label, evidenceLabel, attachmentLabel);
            const actions = el('div');
            content.append(actions);
            if (state.approver) actions.append(button('Confirmar adjudicação', 'accept', () => {
                const selected = choices.find(choice => choice.checked);
                if (!selected) throw new Error('Selecione a alternativa a adjudicar.');
                if (!delivery.input.reportValidity()) throw new Error('Introduza uma data prevista de entrega válida.');
                const file = attachment.files[0];
                if (file && (file.size === 0 || file.size > 5 * 1024 * 1024)) throw new Error('O anexo deve ter até 5 MB e não pode estar vazio.');
                if (!confirm('Registar a adjudicação de ' + selected.value + ' unidades da proposta #' + d.id +
                    ', com entrega prevista em ' + delivery.input.value.split('-').reverse().join('/') + '?')) {
                    throw new Error('Adjudicação cancelada.');
                }
                return { document: d.id, quantity: Number(selected.value), evidence: evidence.value.trim(), approvalFile: file,
                    delivery_date: delivery.input.value };
            }));
            else content.append(el('p', 'O seu perfil não permite registar adjudicações.'));
            return;
        }
        if (root.dataset.sendOnly === '1') {
            if (state.control.production_document) {
                content.append(el('p', state.production_order_exists ?
                    'Orçamento adjudicado com OP. O envio e o restabelecimento estão bloqueados; trate a OP primeiro.' :
                    'Orçamento adjudicado. O envio está bloqueado; o administrador pode restabelecer os botões.'));
                return;
            }
            const d = state.documents.find(document => document.state !== 'superseded');
            if (!d) {
                content.append(el('p', 'Ainda não existe uma proposta comercial aprovada. Aprove primeiro as versões do orçamento.'));
                return;
            }
            content.append(el('h2', 'Proposta comercial #' + d.id),
                el('p', 'Estado: ' + d.state + ' · SMS: ' + smsStatus(d.sms_state)),
                el('p', 'Remetente: ' + (state.sender_name || 'Nome por preencher') + ' <' +
                    (state.sender_email || 'Email por preencher no perfil') + '>'));
            if (d.discount_change?.changed) content.append(discountWarning(d));
            content.append(deliveryForm(d));
            return;
        }
        const versions = el('table');
        versions.className = 'table table-bordered';
        const head = el('tr');
        content.append(el('p', 'Desconto do cliente: ' + String(state.discount_percent ?? 0).replace('.', ',') +
            ' % + extra: ' + String(state.extra_discount_percent ?? 0).replace('.', ',') +
            ' %. As percentagens somam-se. Edite o desconto extra na identificação do orçamento. Após o primeiro envio ficam fixados. Valores das versões abaixo antes do desconto.'));
        ['Aprovar', 'Versão', 'Componente', 'Quantidade', 'Custo total', 'Venda antes desc.',
            'Custo/un.', 'P. unit. antes desc.', 'Estado', 'Ação'].forEach(text => head.append(el('th', text)));
        versions.append(head);
        const selected = [];
        for (const v of state.versions) {
            const tr = el('tr');
            const check = el('input');
            check.type = 'checkbox';
            check.value = v.id;
            check.disabled = v.state !== 'confirmed';
            selected.push(check);
            const cell = el('td');
            cell.append(check);
            tr.append(cell, el('td', '#' + v.version_no + ' · ID ' + v.id),
                el('td', '#' + v.component_id), el('td', v.quantity),
                el('td', money(v.totals.cost)), el('td', money(v.totals.sale)),
                el('td', unitMoney(v.totals.cost, v.quantity)), el('td', unitMoney(v.totals.sale, v.quantity)),
                el('td', v.state));
            if (!v.totals.complete) {
                tr.title = 'Rascunho incompleto · custo parcial ' + money(v.totals.partialCost) +
                    ' · venda parcial ' + money(v.totals.partialSale);
            }
            const actions = el('td');
            if (v.state === 'draft') actions.append(button('Confirmar versão e planos', 'confirm', { version: v.id }));
            if (state.admin && v.state === 'confirmed') actions.append(button('Reabrir versão', 'reopen', () => {
                if (!confirm('Reabrir esta versão confirmada? O histórico ficará preservado e será necessário gravar uma nova versão.')) {
                    throw new Error('Reabertura cancelada.');
                }
                return { version: v.id };
            }));
            const native = el('button', 'Consultar instantâneo nativo');
            native.type = 'button';
            native.className = 'btn btn-default';
            native.addEventListener('click', async () => {
                native.disabled = true;
                try { await showSnapshot(v); }
                catch (error) {
                    message.textContent = 'Não foi possível abrir os detalhes: ' + error.message;
                    message.className = 'alert alert-danger';
                    console.error('Consultar instantâneo:', error);
                } finally { native.disabled = false; }
            });
            actions.append(native);
            tr.append(actions);
            versions.append(tr);
        }
        const versionsScroll = el('div');
        versionsScroll.style.overflowX = 'auto';
        versionsScroll.append(versions);
        content.append(el('h2', 'Histórico imutável'),
            el('p', 'Idioma da próxima proposta: ' + (state.language === 'en' ? 'Inglês' : 'Português') +
                '. Para alterar, edite a identificação do orçamento. Textos em inglês exigem revisão antes da aprovação.'),
            el('p', 'Rascunhos podem guardar linhas por completar. Só versões com todas as linhas completas podem ser confirmadas, aprovadas e enviadas ao cliente.'),
            versionsScroll);
        if (state.approver) content.append(button('Aprovar seleção e fixar proposta comercial', 'approve',
            () => ({ versions: selected.filter(input => input.checked).map(input => Number(input.value)) })));
        if (state.legacy.length) {
            const details = el('details');
            details.append(el('summary', 'Histórico anterior preservado (' + state.legacy.length + ')'));
            for (const legacy of state.legacy) details.append(el('p',
                'Seleção #' + legacy.id + ' · componente #' + legacy.component_id + ' · ' + legacy.quantity +
                ' unidades. Não aprovada pelo novo fluxo; recalcular e guardar uma nova versão.'));
            content.append(details);
        }
        content.append(el('h2', 'Propostas comerciais aprovadas'));
        for (const d of state.documents) {
            const section = el('section');
            section.append(el('h3', 'Documento #' + d.id + ' · ' + d.state),
                el('p', 'Idioma fixado: ' + (d.language === 'en' ? 'Inglês' : 'Português')),
                el('p', 'Notificação SMS: ' + smsStatus(d.sms_state)),
                el('p', 'Validade: ' + new Date(Number(d.expires_at) * 1000).toLocaleDateString('pt-PT')));
            const link = el('a', 'Documento comercial / imprimir PDF');
            const documentUrl = new URL(url);
            documentUrl.searchParams.set('document', d.id);
            link.href = documentUrl.href;
            link.target = '_blank';
            section.append(link);
            if (d.approval_file) section.append(approvalFileLink(d));
            const pdfLink = el('a', 'Descarregar PDF');
            documentUrl.searchParams.set('pdf', '1');
            pdfLink.href = documentUrl.href;
            pdfLink.style.marginLeft = '12px';
            section.append(pdfLink);
            if (d.discount_change?.changed) section.append(discountWarning(d));
            section.append(deliveryForm(d));
            if (state.approver && d.state === 'sent') {
                const quantity = el('input');
                quantity.type = 'number';
                quantity.placeholder = 'Quantidade aceite';
                const evidence = el('input');
                evidence.placeholder = 'Referência/data da aceitação do cliente';
                evidence.size = 50;
                const delivery = deliveryInput();
                section.append(quantity, delivery.label, evidence, button('Registar aceitação e alternativa de produção', 'accept',
                    () => {
                        if (!delivery.input.reportValidity()) throw new Error('Introduza uma data prevista de entrega válida.');
                        return { document: d.id, quantity: quantity.value, evidence: evidence.value, delivery_date: delivery.input.value };
                    }));
            }
            content.append(section);
        }
        if (state.control.production_document) content.append(el('p',
            'Alternativa de produção: documento #' + state.control.production_document +
            ' · ' + state.control.production_quantity + ' unidades.'));
        if (state.production_order) {
            const link = el('a', 'Abrir ordem de produção ' + state.production_order.number);
            link.href = state.production_order.url;
            link.className = 'btn btn-info';
            content.append(link);
            appendProductionDelivery();
        }
        const audit = el('details');
        audit.append(el('summary', 'Auditoria interna (últimos 100 eventos)'));
        for (const event of state.audit || []) audit.append(el('p',
            new Date(Number(event.created_at) * 1000).toLocaleString('pt-PT') +
            ' · utilizador #' + event.actor_id + ' · ' + event.event + ' · ' + event.detail));
        content.append(audit);
        if (state.admin) {
            const input = el('input');
            input.value = state.approval_groups.join(',');
            input.setAttribute('aria-label', 'IDs dos perfis aprovadores');
            content.append(el('h2', 'Perfis aprovadores (configuração global)'), input,
                button('Guardar perfis', 'groups', () => ({ groups: input.value.split(',').map(Number) })));
        }
    }
    render();
})();

(() => {
    'use strict';
    const root = document.getElementById('vd-product');
    const config = document.getElementById('vd-product-data');
    if (!root || !config) return;
    let data = JSON.parse(config.textContent);
    const initial = data;
    const forbidden = [1,3,4,5,7,13,14,43,44];
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const options = (rows, selected) => rows.map(row => `<option value="${escape(row.id)}"${String(row.id)===String(selected)?' selected':''}>${escape(row.name)}</option>`).join('');
    const button = (text, fn) => {
        const element = document.createElement('button');
        element.type = 'button'; element.className = 'btn btn-default btn-sm'; element.textContent = text;
        element.addEventListener('click', async () => {
            element.disabled = true;
            try { await fn(); } catch (error) { notify(error.message, true); }
            finally { element.disabled = false; }
        });
        return element;
    };
    function notify(message, error = false) {
        const box = root.querySelector('[data-message]');
        box.className = 'alert ' + (error ? 'alert-danger' : 'alert-info'); box.textContent = message;
    }
    async function api(action, payload) {
        const body = new URLSearchParams({api:action,form_session_token:initial.token,payload:JSON.stringify(payload)});
        const response = await fetch(initial.url,{method:'POST',credentials:'same-origin',body});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível concluir a operação.');
        return result.data;
    }
    async function refresh(quantityId) {
        const response = await fetch(initial.url + '&api=product_state',{credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Falha ao atualizar o produto.');
        data = result.data; await render(quantityId);
    }
    async function render(quantityId) {
        if (initial.component_id) {
            root.innerHTML = '<div data-message></div><div data-editor></div>';
            const component = data.components.find(c => Number(c.id) === Number(initial.component_id));
            if (!component?.product || component.product.kind === 'printed') {
                notify('Componente sem configuração de operações e materiais. Reveja a estrutura do produto.', true);
                return;
            }
            await edit(component, quantityId);
            return;
        }
        root.innerHTML = `<div data-message></div><p>Um produto por orçamento. Os componentes impressos mantêm o configurador habitual. Materiais/estrutura e assemblagem não exigem papel, cores nem imposição.</p>
        <p>Caixas: indicar manualmente o formato aberto e selecionar o cortante existente na identificação do componente. Não é gerado desenho estrutural.</p>
        <label>Tipo de produto <select data-type>${options(Object.entries(data.types).map(([id,name])=>({id,name})),data.type)}</select></label>
        <div style="overflow:auto"><table class="table table-condensed"><thead><tr><th>Componente</th><th>Tipo</th><th>Un./produto</th><th>Entradas da assemblagem</th><th>Cálculo</th></tr></thead><tbody>
        ${data.components.map(c=>`<tr data-component="${c.id}"><td><a href="index.php?module=items/info&amp;path=146-${data.quote}/262-${c.id}">${escape(c.name)}</a>${c.product?.stage?`<br><small>Etapa: ${escape(c.product.stage)}<br>Requisitos: ${escape((c.product.requirements?.operations||[]).map(op=>op.name).join('; ')||'Rever preparação técnica')}</small>`:''}</td><td><select data-kind>${options([{id:'printed',name:'Impresso'},{id:'material',name:'Material / estrutura'},{id:'assembly',name:'Assemblagem / Encadernação'}],c.product?.kind||'printed')}</select></td>
        <td><input data-units type="number" min="0.0001" step="any" value="${escape(c.product?.units_per_product??1)}" style="width:70px"></td><td>${data.components.filter(other=>other.id!==c.id).map(other=>`<label style="display:block"><input type="checkbox" data-input="${other.id}"${c.product?.inputs.includes(Number(other.id))?' checked':''}> ${escape(other.name)}</label>`).join('')}</td><td data-calculate></td></tr>`).join('')}</tbody></table></div><div data-actions style="display:flex;gap:8px;flex-wrap:wrap"></div>
        <p style="margin-top:12px">A assemblagem recebe os componentes físicos. O embalamento individual recebe o produto terminado, após a assemblagem. Cada etapa é contabilizada uma vez por produto, não por capa/miolo. Escolha explicitamente a variante manual/máquina e os materiais. Quantidades, tempos (incluindo preparação) e consumos são totais para cada alternativa; reveja-os ao mudar a quantidade. As versões anteriores ficam preservadas.</p><div data-editor></div>`;
        async function configure(extra = {}) {
            const components = [...root.querySelectorAll('[data-component]')].map(row=>({
                id:Number(row.dataset.component),kind:row.querySelector('[data-kind]').value,
                units_per_product:Number(row.querySelector('[data-units]').value),
                inputs:row.querySelector('[data-kind]').value==='assembly'?[...row.querySelectorAll('[data-input]:checked')].map(c=>Number(c.dataset.input)):[]
            }));
            const result = await api('product_configure',{type:root.querySelector('[data-type]').value,revision:data.revision,components,create_template:!components.length,...extra});
            await refresh(); notify(result.message);
        }
        root.querySelector('[data-actions]').append(button(data.components.length?'Guardar estrutura':'Criar componentes do modelo',()=>configure()));
        if (data.components.length && !data.components.some(c=>c.product?.kind==='assembly')) {
            root.querySelector('[data-actions]').append(button('Adicionar assemblagem aos componentes existentes',()=>configure({add_assembly:true})));
        }
        if (data.revision) {
            const area = root.querySelector('[data-actions]');
            const name = document.createElement('input'); name.placeholder = 'Novo componente / acessório'; name.setAttribute('aria-label','Nome do novo componente');
            const kind = document.createElement('select'); kind.innerHTML=options([{id:'printed',name:'Impresso'},{id:'material',name:'Material / estrutura'}],'material');
            area.append(name,kind,button('Adicionar componente',async()=>{
                const result=await api('product_add',{name:name.value,kind:kind.value}); await refresh(); notify(result.message);
            }));
        }
        for (const component of data.components) {
            const row = root.querySelector(`[data-component="${component.id}"]`);
            if (component.product && component.product.kind!=='printed') {
                row.querySelector('[data-calculate]').append(button('Operações e materiais',()=>edit(component)));
            } else {
                row.querySelector('[data-calculate]').textContent = 'Configurador de impressão';
            }
        }
    }
    async function edit(component, selectedQuantityId) {
        const editor=root.querySelector('[data-editor]');
        const stateResponse=await fetch(initial.url+'&api=state',{credentials:'same-origin'});
        const stateResult=await stateResponse.json();
        if(!stateResponse.ok||!stateResult.ok)throw new Error(stateResult.error||'Falha ao consultar versões.');
        const versions=stateResult.data.versions;
        const quantityId=Number(selectedQuantityId||data.quantities[0]?.id);
        const saved=versions.find(v=>Number(v.component_id)===Number(component.id)&&Number(v.quantity_id)===quantityId);
        let calculation=null;
        if(saved) {
            const response=await fetch(initial.url+'&api=snapshot&version='+encodeURIComponent(saved.id),{credentials:'same-origin'});
            const result=await response.json();
            if(!response.ok||!result.ok)throw new Error(result.error||'Falha ao consultar valores guardados.');
            calculation=result.data.calculation;
        }
        const definition=calculation?.definition||{markup:0,operations:[],materials:[]};
        const stages={component:'Componente',assembly:'Assemblagem',packaging:'Embalamento individual',dispatch:'Expedição'};
        const eligible=data.catalog.filter(op=>!forbidden.includes(Number(op.id))&&(!component.product?.stage||op.stage===stages[component.product.stage]));
        const operationOptions=selected=>options([{id:'',name:'Escolha explicitamente a variante técnica'},...eligible.map(op=>({...op,name:op.name+(op.machines?' — máquina':'')}))],selected??'');
        const quantities=data.quantities.map(q=>({id:q.id,name:q.quantity+' produtos'}));
        if(!quantities.length)throw new Error('Adicione primeiro as quantidades do orçamento.');
        const money=(value,digits=2)=>value===null||value===undefined?'Por completar':Number(value).toLocaleString('pt-PT',{style:'currency',currency:'EUR',minimumFractionDigits:digits,maximumFractionDigits:digits});
        const states={draft:'Rascunho',confirmed:'Confirmada',approved:'Aprovada',superseded:'Substituída'};
        editor.innerHTML=`<h3>${escape(component.name)} — operações e materiais</h3>
        <h4>Valores guardados por alternativa</h4>
        <div style="overflow:auto"><table class="table table-bordered"><thead><tr><th>Quantidade</th><th>Versão</th><th>Estado</th><th>Custo total</th><th>Venda total</th><th>Venda/un.</th></tr></thead><tbody>
        ${data.quantities.map(q=>{
            const version=versions.find(v=>Number(v.component_id)===Number(component.id)&&Number(v.quantity_id)===Number(q.id));
            return `<tr><td>${escape(q.quantity)}</td><td>${version?'#'+escape(version.id):'Não guardada'}</td><td>${version?escape(states[version.state]||version.state):'—'}</td><td>${version?money(version.totals.cost):'—'}</td><td>${version?money(version.totals.sale):'—'}</td><td>${version?money(version.totals.unitSale,4):'—'}</td></tr>`;
        }).join('')}</tbody></table></div><div data-workflow></div>
        <p>Guarde uma versão para cada alternativa. Confirme as versões no painel habitual para construir a proposta com Capa, Miolo e restantes componentes.</p>
        <p>Sem impressão/imposição. Custos explicitamente indicativos: introduza minutos totais (incluindo preparação), custo/h e consumos totais; não há movimentação de stock.</p>
        <p>Requisitos: ${escape((component.product?.requirements?.operations||[]).map(op=>op.name+' (variantes: '+(op.variants||[op.id]).join(', ')+')').join('; ')||'Rever preparação técnica')}. Indique uma única variante por requisito. Materiais de embalagem têm consumos e preços próprios.</p>
        <label>Alternativa <select data-quantity>${options(quantities,quantityId)}</select></label>
        <label>Acréscimo sobre custo (%) <input data-markup type="number" min="0" step="any" value="${escape(definition.markup)}"></label>
        <h4>Operações (pela ordem de execução)</h4><div data-operations></div><div data-operation-add></div>
        <h4>Materiais (cola, arame, cartão, caixas, etc.)</h4><div data-materials></div><div data-material-add></div><hr><div data-save></div><pre data-result style="white-space:pre-wrap"></pre>`;
        editor.querySelector('[data-workflow]').append(button('Versões, confirmação, aprovação e proposta',()=>{
            const url=new URL(initial.url,location.href);
            url.searchParams.set('modal','1');
            window.open_dialog(url.pathname+url.search);
        }));
        editor.querySelector('[data-quantity]').addEventListener('change',async event=>{
            const select=event.target;
            select.disabled=true;
            try { await edit(component,select.value); } catch(error) { notify(error.message,true); }
            finally { select.disabled=false; }
        });
        function showCalculation(result) {
            editor.querySelector('[data-result]').textContent='Custo total: '+money(result.allOperations.cost)+'; venda: '+money(result.allOperations.sale)+'\n'+result.allOperations.lines.map(l=>l.name+': '+money(l.cost)).join('\n');
        }
        function operation(input={}) {
            const div=document.createElement('div');div.dataset.operation='';div.dataset.basis=input.basis||'h';div.style.cssText='display:flex;gap:6px;flex-wrap:wrap;margin:8px 0';
            div.innerHTML=`<label>Operação / variante <select data-id aria-label="Operação">${operationOptions(input.operation_id)}</select></label>
            <label>Min. totais <input data-min type="number" min="0" step="any" value="${escape(input.minutes??'')}" style="width:80px"></label>
            <label><span data-hour-label>${div.dataset.basis==='serviço'?'Preço/serviço €':'Custo/h total €'}</span> <input data-hour type="number" min="0" step="any" value="${escape(input.hourly??'')}" style="width:80px"></label>
            <label>Trabalho <input data-work type="number" min="0" step="any" value="${escape(input.work_quantity??'')}" style="width:85px"></label>
            <label>Unidade <input data-unit value="${escape(input.unit??'un.')}" style="width:70px"></label>
            <label>Equipamento <select data-equipment style="max-width:220px"></select></label>`;
            function equipment(selected=0) {
                const op=eligible.find(op=>Number(op.id)===Number(div.querySelector('[data-id]').value));
                const ids=(op?.machines||'').split(',').filter(Boolean);
                div.querySelector('[data-equipment]').innerHTML=options([{id:0,name:ids.length?'Escolha o equipamento':'Sem equipamento'},
                    ...ids.map(id=>({id,name:'#'+id+' — '+(data.machines.find(m=>String(m.id)===id)?.name||'Equipamento')}))],selected);
            }
            equipment(input.equipment||0);
            div.querySelector('[data-id]').addEventListener('change',()=>equipment());
            div.append(button('↑',()=>{if(div.previousElementSibling)div.parentNode.insertBefore(div,div.previousElementSibling);}),button('Remover',()=>div.remove()));
            if(input.source||input.notes?.length)div.append(Object.assign(document.createElement('small'),{textContent:[input.source,...(input.notes||[])].filter(Boolean).join(' · '),style:'flex-basis:100%;color:#666'}));
            editor.querySelector('[data-operations]').append(div);
            return div;
        }
        function material(input={}) {
            const div=document.createElement('div');div.dataset.material='';div.style.cssText='display:flex;gap:6px;flex-wrap:wrap;margin:8px 0';
            div.innerHTML=`<select data-article style="max-width:260px" aria-label="Artigo">${options(data.articles,input.article_id)}</select>
            <label>Operação <select data-parent>${operationOptions(input.operation_id)}</select></label>
            <label>Consumo total <input data-amount type="number" min="0" step="any" value="${escape(input.quantity??'')}" style="width:90px"></label>
            <label>Unidade <input data-unit value="${escape(input.unit??'')}" style="width:65px"></label>
            <label>Custo/un. € <input data-price type="number" min="0" step="any" value="${escape(input.unit_cost??'')}" style="width:85px"></label>`;
            div.append(button('Remover',()=>div.remove()));
            if(input.source)div.append(Object.assign(document.createElement('small'),{textContent:input.source,style:'flex-basis:100%;color:#666'}));
            editor.querySelector('[data-materials]').append(div);
        }
        definition.operations.forEach(operation);definition.materials.forEach(material);
        if(calculation)showCalculation(calculation);
        editor.querySelector('[data-operation-add]').append(button('Adicionar operação',()=>operation()));
        editor.querySelector('[data-material-add]').append(button('Adicionar material',()=>material()));
        function number(element) {
            if(element.value.trim()===''||!Number.isFinite(Number(element.value)))throw new Error('Preencha todos os valores numéricos; zero deve ser explícito.');
            return Number(element.value);
        }
        function payload() {
            const quantityId=Number(editor.querySelector('[data-quantity]').value);
            const quantity=Number(data.quantities.find(q=>Number(q.id)===quantityId).quantity);
            return {component:Number(component.id),quantity_id:quantityId,
                expected_version:Number(versions.find(v=>Number(v.component_id)===Number(component.id)&&Number(v.quantity_id)===quantityId)?.id||0),
                request_id:'product-'+crypto.randomUUID(),
                definition:{quantity,markup:number(editor.querySelector('[data-markup]')),
                    operations:[...editor.querySelectorAll('[data-operation]')].map(row=>({
                        operation_id:Number(row.querySelector('[data-id]').value),minutes:number(row.querySelector('[data-min]')),
                        hourly:number(row.querySelector('[data-hour]')),work_quantity:number(row.querySelector('[data-work]')),
                        unit:row.querySelector('[data-unit]').value,equipment:number(row.querySelector('[data-equipment]')),basis:row.dataset.basis||'h'
                    })),
                    materials:[...editor.querySelectorAll('[data-material]')].map(row=>({
                        article_id:Number(row.querySelector('[data-article]').value),operation_id:Number(row.querySelector('[data-parent]').value),
                        quantity:number(row.querySelector('[data-amount]')),unit:row.querySelector('[data-unit]').value,
                        unit_cost:number(row.querySelector('[data-price]'))
                    }))}};
        }
        editor.querySelector('[data-save]').append(button('Calcular automaticamente',async()=>{
            const quantityId=Number(editor.querySelector('[data-quantity]').value);
            if(!quantityId)throw new Error('Escolha a alternativa de quantidade.');
            const current=[...editor.querySelectorAll('[data-operation]')].map(row=>({operation_id:Number(row.querySelector('[data-id]').value),
                equipment:row.querySelector('[data-equipment]').value}));
            const suggestion=await api('assembly_suggest',{component:Number(component.id),quantity_id:quantityId,operations:current});
            editor.querySelector('[data-operations]').replaceChildren();
            suggestion.operations.forEach(operation);
            for(const suggested of suggestion.materials||[]) {
                for(const row of editor.querySelectorAll('[data-material]'))
                    if(Number(row.querySelector('[data-article]').value)===suggested.article_id&&Number(row.querySelector('[data-parent]').value)===suggested.operation_id)row.remove();
                material(suggested);
            }
            editor.querySelector('[data-result]').textContent='Valores sugeridos para '+suggestion.quantity+' unidades (editáveis). '+suggestion.notes.join(' ')+
                '\nReveja e use «Calcular» para ver custos ou «Guardar nova versão».';
        }),button('Calcular',async()=>{
            const result=await api('nonprint_preview',payload());
            showCalculation(result);
        }),button('Guardar nova versão',async()=>{
            const input=payload();
            const result=await api('nonprint_save',input);
            await refresh(input.quantity_id);
            if(!initial.component_id)await edit(data.components.find(c=>Number(c.id)===Number(component.id)),input.quantity_id);
            notify('Versão #'+result.workflow_version+' guardada para '+input.definition.quantity+' produtos. Confirme e aprove no painel de versões habitual.');
        }));
        editor.scrollIntoView({block:'nearest'});
    }
    render().catch(error=>notify(error.message,true));
})();

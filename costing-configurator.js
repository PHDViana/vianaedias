!async function () { const e = document.getElementById("component-budget"), a = Number(e.dataset.componentId), t = new DOMParser, o = e => String(e || "").replace(/\s+/g, " ").trim(), n = e => new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(e), r = (e, a, t) => { const o = document.createElement(e); return void 0 !== a && (o.textContent = a), t && (o.className = t), o; }; async function i(e, a) { const n = await fetch(e, a ? { method: "POST", headers: { "X-Requested-With": "XMLHttpRequest" }, body: new URLSearchParams(a) } : {}); if (!n.ok)
    throw new Error("Falha ao consultar dados: HTTP " + n.status); const r = await n.text(), i = t.parseFromString(r, "text/html"); if (i.querySelector('input[type="password"]'))
    throw new Error("Sessão expirada. Volta a iniciar sessão."); const s = i.querySelector(".alert-danger,.alert-error"); if (s)
    throw new Error(o(s.textContent)); const l = i.body.cloneNode(!0); if (l.querySelectorAll("script,style").forEach(e => e.remove()), /Fatal error|SQL syntax|Database Error/.test(l.textContent))
    throw new Error("A plataforma devolveu um erro ao consultar os dados."); return i; } function s(e, a, t) { const o = r("a", e, "btn btn-default"); return o.href = a, t && (o.target = t, o.rel = "noopener"), o; } function l(e, a) { const t = s(e, a); return t.addEventListener("click", e => { "function" == typeof open_dialog && (e.preventDefault(), open_dialog(a)); }), t; } function p(e, a, t) { const o = Array.from(e.querySelectorAll("a,button")).find(e => t.test(e.getAttribute("onclick") || "")), n = o && (o.getAttribute("onclick") || "").match(/open_dialog\('([^']+)'\)/); return n ? l(a, n[1]) : null; } function d(e) { if ("number" == typeof e)
    return Number.isFinite(e) ? e : null; const a = o(e).replace(/[€\s\u00a0]/g, ""); if (!/^-?\d+(?:[.,]\d+)*$/.test(a))
    return null; const t = a.lastIndexOf(",") > a.lastIndexOf(".") ? "," : ".", n = a.split(t), r = n.length > 1 ? n.slice(0, -1).join("").replace(/[.,]/g, "") + "." + n[n.length - 1] : a, i = Number(r); return Number.isFinite(i) ? i : null; } function c(e, a) { const t = e.querySelector("table"); if (!t)
    throw new Error("A listagem não devolveu a tabela esperada."); const n = Array.from(t.querySelectorAll("thead th,thead td")), r = n.length ? n : Array.from(t.rows[0].cells), i = r.findIndex(e => o(e.textContent) === a); if (i < 0)
    throw new Error("Coluna não encontrada: " + a); let s = 0, l = 0, p = 0; for (const e of t.querySelectorAll("tbody tr")) {
    if (e.cells.length !== r.length || /No Records Found/.test(e.textContent))
        continue;
    p++;
    const a = d(e.cells[i].textContent);
    null === a ? l++ : s += a;
} return { total: s, missing: l, count: p }; } function m(e) { return Array.from(e.querySelectorAll("table")).slice(0, 1).flatMap(e => Array.from(e.tBodies).flatMap(e => Array.from(e.rows))).filter(e => e.classList.contains("listing-table-tr")); } function u(e, a) { const t = Array.from(e.cells).find(e => e.classList.contains("field-" + a + "-td")); return t ? o(Array.from(t.childNodes).map(e => e.textContent).join(" ")) : ""; } function h({ materials: e, plans: a, hasOffset: t, hasPaper: o, hasColors: n }) { const r = [], i = { 5: { id: 985, name: "Chapas Offset 510x400 0.15" }, 6: { id: 984, name: "Chapas Offset 745x605 0.30" } }, s = e.filter(e => [984, 985].includes(e.id) || /\bchapas?\b/i.test(e.name)); s.length && (t || r.push("Existem chapas, mas falta uma operação de impressão Offset."), o || r.push("Existem chapas, mas falta papel: seleciona uma descrição interna associada a materiais do tipo Papel, com quantidade positiva."), n || r.push("Existem chapas, mas faltam cores de impressão preenchidas."), a.length || r.push("Existem chapas, mas falta um plano de impressão.")), t && !a.length && r.push("A impressão Offset exige um plano com a máquina selecionada."); const l = new Set, p = new Map; let d = a.length > 0; for (const e of a) {
    if (!t && !s.length && !e.machines.some(e => i[e.id]))
        continue;
    const a = "Plano " + e.id + ": ";
    if (e.width > 0 && e.height > 0 || r.push(a + "preenche a largura e a altura do plano, em mm."), 1 !== e.machines.length) {
        r.push(a + "seleciona uma única máquina de impressão."), d = !1;
        continue;
    }
    const o = e.machines[0], n = i[o.id];
    n && (l.add(n.id), p.set(n.id, n.name)), !t && !s.length || n || (r.push(a + "a máquina " + o.name + " não tem regra de chapas configurada. Não é possível validar a impressão Offset."), d = !1), n && !t && r.push(a + o.name + " exige uma operação de impressão Offset."), o.maxWidth > 0 && o.maxHeight > 0 ? (e.width > o.maxWidth || e.height > o.maxHeight) && r.push(a + e.width + " × " + e.height + " mm excede " + o.name + " (" + o.maxWidth + " × " + o.maxHeight + " mm). Não é permitida rotação.") : r.push(a + "faltam os limites máximos na ficha de " + o.name + ".");
} for (const [e, a] of p)
    s.some(a => a.id === e && a.quantity > 0) || r.push("Faltam " + a + " com quantidade positiva para a máquina selecionada."); for (const e of s)
    e.quantity > 0 || r.push("Chapa " + e.name + ": a quantidade tem de ser positiva."), d && !l.has(e.id) && r.push("Chapa incompatível com as máquinas dos planos: " + e.name + "."); return r; } function f(e) { const a = r("table"), t = r("thead"), o = r("tr"), n = r("tbody"); return e.forEach(e => { const a = r("th", e); a.scope = "col", o.append(a); }), t.append(o), a.append(t, n), { node: a, body: n }; } if (!window.componentSimulation)
    try {
        await new Promise((e, a) => { const t = () => { clearTimeout(o), e(); }, o = setTimeout(() => { document.removeEventListener("componentSimulationReady", t), a(new Error("Bloco de cálculo indisponível.")); }, 1e4); document.addEventListener("componentSimulationReady", t, { once: !0 }); });
    }
    catch (a) {
        throw e.textContent = a.message, e.classList.add("alert", "alert-danger"), a;
    } const { validOperations: g, offsetPasses: b, suggestedPlates: v, colorProcesses: y, whiteTariff: S, mixedProduction: w, productionPlates: P, priceChanges: x, simulationOrder: C, completeSimulations: q, rankSimulations: E, publicationLayout: N, bindingStatus: I, paperFamily: A, familyPapers: _, offsetPlanError: F, paperStock: O, publicationCompositions: R, calculatePublication: T, machineSettings: k, calculatePaper: M, digitalClickFactor: D, simulatePlan: j, sheetFormat: L, planMatch: U, eligiblePlans: V, largerPlanPossible: H, rubricPatterns: W, rubricSearch: J, rubricSearchPass: B, rubricOptions: G, rubricCombinationTable: z, checked: $, planCapacityLimits: Q, possiblePlans: X, usefulGeneratedPlans: K, generatedPlanKey: Y, generatorPaperFormats: Z, generatorPapers: ee, generatedPlanOrder: ae, selectedGeneratedPlans: te, generatedGeometry: oe, rubricPositionLabel: ne, rubricPositionColor: re, rubricPositionAssignments: ie, generatedPlanSvg: se, generatedPlanPreview: le, separateDigitalRubrics: pe, generatedCostComparison: de, rankGeneratedResults: ce, dynamicSelection: me, dynamicHistory: ue, renderGeneratedCosts: he, simulationCostNote: fe, planGeneratorPanel: ge, dynamicSimulationUI: be } = window.componentSimulation; function ve(e, a, t) { const o = f([...a.map(e => e[0]), ""]); for (const n of m(e)) {
    const e = r("tr");
    for (const [, t, o] of a) {
        const a = t.map(e => u(n, e)).filter(Boolean);
        e.append(r("td", a.join(" · ") || "—", o ? "cb-number" : void 0));
    }
    const i = r("td"), s = p(n, "Editar", /module=items\/form/), d = n.querySelector("[data_path]"), c = d && d.getAttribute("data_path").match(/^(\d+)-(\d+)$/);
    s ? i.append(s) : c && i.append(l("Editar", "index.php?" + new URLSearchParams({ module: "items/form", entity_id: c[1], id: c[2], path: t + "/" + c[0], redirect_to: "parent_item_info_page" }))), e.append(i), c && c[1] === "276" && (e.dataset.operationRow = c[2]), o.body.append(e);
} if (!o.body.rows.length) {
    const e = r("tr"), t = r("td", "Sem registos");
    t.colSpan = a.length + 1, e.append(t), o.body.append(e);
} return o.node; } function ye(e, t, o, i, l, p) { const c = r("section", void 0, "cb-card"); c.id = "cb-simulator", c.append(r("h2", "Simular impressão · Digital × Offset")); const m = e.component[0]; if (!m)
    throw new Error("Dados do componente indisponíveis para simulação."); const u = y(e.colors), h = e.machines.find(e => 56 === Number(e.id)); m.hasDie = $(m.has_die); const C = (["1545", "1546", "1547", "1548"].includes(String(m.binding)) && !(m.flat_folding && String(m.binding) === "1545")); C && c.append(r("p", "Paginação sem páginas em branco. Acabamento incluído nas linhas de cálculo; preencha os dados em falta.", "alert alert-warning")); const A = [1248, 1249].includes(Number(m.die_status)); const D = r("form"), W = r("div", void 0, "cb-sim-controls"); function B(e, a, t, o = "1") { const n = r("label", a), i = r("input", void 0, "form-control"); return i.type = "number", i.min = "0", i.step = o, i.value = t ?? "", n.append(i), e.append(n), i; } function Q(e, a, t) { const o = r("label", a), n = r("select", void 0, "form-control"); return t.forEach(([e, a]) => { const t = r("option", a); t.value = e, n.append(t); }), o.append(n), e.append(o), n; } const X = o.filter(e => e.paper && e.id), K = Q(W, "Papel (formato escolhido abaixo)", [["", "Selecionar papel"], ...X.map(e => [e.id, e.name])]); 1 === X.length && (K.value = X[0].id); const Y = Q(W, "Formato da folha", [["", "Automático · comparar todos"], ...[...new Set(e.machines.flatMap(a => V(e.plans, m, a.id).map(L)).filter(Boolean))].sort((e, a) => e.split("x").reduce((e, a) => e * a, 1) - a.split("x").reduce((e, a) => e * a, 1)).map(e => [e, e.replace("x", " × ") + " mm"])]), Z = B(W, "Formas/cadernos por exemplar", 1); Z.min = "1", Z.required = !0, C && (Z.readOnly = !0, Z.closest("label").hidden = !0); const ee = { 3747: "Frente", 3748: "Frente e Verso", 3749: "Tira e Retira", 3750: "Cambalhota" }, ae = Q(W, "Modo Offset", [["auto", "Automático · menor total"], ...[3747, 3748, 3749, 3750].map(e => [e, ee[e]])]), te = u.mixed && e.colors.some(e => "3820" === String(e.color)) ? Q(W, "Metálicos na produção mista", [["", "Selecionar processo"], ["offset", "Offset"], ["digital", "Digital"]]) : null; if ((u.pantone || u.white) && c.append(r("p", u.mixed ? "Offset → Digital (branco). Acerto único: maior valor das operações. Metálicos: escolha manual." : u.white ? "Branco: apenas Digital." : "Pantone não metálico: apenas Offset.", "alert alert-info")), u.white) {
    const e = r("p", ($(h?.white_test) ? "TESTE — " : "") + "Clique branco adicional: " + (d(h?.white_click) ?? "por configurar") + " €/face + clique normal. ", "alert alert-warning");
    e.append(s("Equipamento Digital", "index.php?module=items/info&path=78-56", "_blank")), c.append(e);
} ae.value = "auto"; const oe = B(W, "Offset: chapas por forma", null); oe.placeholder = "Automático pelas cores"; let ne = !1; function re() { if ("auto" === ae.value)
    return void (ne || (oe.value = "")); const a = v(e.colors, ae.value); ne || (oe.value = a ?? ""); } oe.addEventListener("input", () => { ne = "" !== oe.value, re(); }), ae.addEventListener("change", re), re(); const ie = e.price_operations.find(e => 3 === Number(e.id)), se = B(W, "Offset: passagens por plano (automático)", b(ae.value)); se.readOnly = !0, C && (se.closest("label").hidden = !0), ae.addEventListener("change", () => { se.value = b(ae.value) ?? ""; }); const le = [1255, 1257].includes(Number(m.faces)) ? 1 : 1256 === Number(m.faces) ? 2 : null, pe = new Map; if (e.rubrics.length) {
    let a = [];
    const t = l.get("rubrics");
    if (t)
        try {
            a = JSON.parse(t.details).calculation.rubrics;
        }
        catch (e) {
            console.error("Rubricas guardadas inválidas", e), c.append(r("p", "Não foi possível ler o histórico das rubricas.", "alert alert-danger"));
        }
    for (const t of e.rubrics) {
        const e = r("label"), o = r("input");
        o.type = "checkbox", o.dataset.isolateRubric = String(t.id), o.checked = Boolean(a?.find(e => String(e.id) === String(t.id))?.isolated), e.append(o, document.createTextNode(" Isolar #" + t.id + " · " + t.name + " · " + t.quantity + " un.")), W.append(e), pe.set(String(t.id), o);
    }
    Z.closest("label").hidden = !0;
} D.append(W); const de = r("button", e.rubrics.length ? "Comparar produção das rubricas" : "Comparar todas as quantidades", "btn btn-primary"); de.type = "submit"; const ce = r("div", void 0, "cb-actions"); ce.append(de), D.append(ce); const me = r("div", void 0, "cb-sim-results"); function ue() { me.replaceChildren(), i.replaceChildren(r("h2", "Resumo das simulações")); const c = e.rubrics.length ? [{ id: "rubrics", value: e.rubrics.reduce((e, a) => e + d(a.quantity), 0) }] : e.quantities.map(e => ({ id: e.id, value: d(e.quantity) })); if (!c.length)
    return me.append(r("p", "Adiciona as quantidades no orçamento-pai.", "alert alert-warning")), void i.append(r("p", "Sem quantidades a orçamentar.", "cb-note")); const u = Number(K.value); if (!X.some(e => Number(e.id) === u))
    return me.append(r("p", "Seleciona o papel do componente.", "alert alert-warning")), void i.append(r("p", "Papel por selecionar.", "cb-note")); const D = _(e, u); if (D.errors.length)
    return me.append(r("p", D.errors.join(" "), "alert alert-warning")), void i.append(r("p", D.errors.join(" "), "cb-note")); const W = y(e.colors, te?.value); if (e.rubrics.length)
    return C || W.mixed || m.hasDie || A ? void me.append(r("p", "Rubricas: a comparação conjunta ainda não suporta paginação, cortantes ou produção mista. Não foram aplicadas as quantidades alternativas do orçamento.", "alert alert-warning")) : W.errors.length ? void me.append(r("p", W.errors.join(" "), "alert alert-warning")) : void function (s, c) { const u = e.rubrics.map(e => ({ ...e, isolated: pe.get(String(e.id)).checked })), h = u.reduce((e, a) => e + d(a.quantity), 0), g = [], b = []; for (const t of e.machines) {
        if (56 === Number(t.id) ? !c.allowDigital : !c.allowOffset)
            continue;
        const n = G({ data: e, machine: t, component: m, papers: s.papers, format: Y.value, mode: ae.value, faces: le, colors: c, manualPlates: ne ? d(oe.value) : void 0, plateOperation: ie }), r = J(u, n.options);
        if (r.errors.length) {
            b.push(t.name + ": " + [...new Set([...r.errors, ...n.errors])].join(" "));
            continue;
        }
        n.errors.length && b.push(t.name + " — alternativas não avaliadas: " + n.errors.join(" "));
        const i = r.solutions[0], l = i.forms.map(e => { const a = n.options[e.optionIndex]; return { ...e, plan: a.plan, operation: a.operation, tariff: { ...a.tariff, operationId: Number(a.operation.id), operationSource: a.operation }, modeId: a.modeId, ...56 === Number(t.id) && c.white ? { whiteClick: d(t.white_click) } : {}, machine: a.machine }; }), p = e => l.reduce((a, t) => a + t.result[e], 0), f = 56 === Number(t.id) ? { hours: 0, total: 0, errors: [] } : P(p("plates"), ie);
        if (f.errors.length) {
            b.push(t.name + ": " + f.errors.join(" "));
            continue;
        }
        const v = i.cost - p("plateProduction") + f.total, y = new Map;
        for (const e of l) {
            const a = e.paperCalculation.paper;
            a.family = s.family;
            const t = L({ sheet_width: a.width, sheet_height: a.height }), o = y.get(t) || { paper: a, sheets: 0 };
            o.sheets += e.paperCalculation.sheets, y.set(t, o);
        }
        const S = [...y.values()].map(a => ({ ...a, stock: O(e.papers, a.paper, a.sheets) })), w = { version: 2, basis: "rubrics", componentId: a, component: { ...m }, quantity: h, rubrics: u, search: { method: "bounded-anchor-beam", beamWidth: 24, optimality: "not-guaranteed", excessRule: "cost-driven-no-percentage-cap", turnAllocation: "even-per-artwork" }, parts: l.map(({ optionIndex: e, remaining: a, ...t }) => t), paperId: Number(K.value), paperFamily: s.family, paperWeight: s.weight, machineId: Number(t.id), machine: { ...t }, plateProduction: { operation: ie, hours: f.hours, total: f.total }, paperStocks: S, materials: o.map(e => ({ ...e })), colors: e.colors.map(e => ({ ...e })), pricingStatus: "TESTE — A REVER", results: { total: v, unit: v / h, plates: p("plates"), sheets: p("sheets"), hours: p("hours"), impressions: p("impressions"), clicks: p("clicks"), paper: p("paper"), printing: p("printing"), plate: p("plate"), click: p("click"), plateProduction: f.total, machineCost: p("machineCost"), mountings: l.length } };
        g.push({ quantityId: "rubrics", quantity: h, machineId: Number(t.id), plan: l[0].plan, parameters: w, errors: [], total: v, parts: l, machine: t, stocks: S });
    } for (const row of g) window.vdOperationCosting.get(a).apply(row);
    g.sort((e, a) => Number(e.errors.length > 0) - Number(a.errors.length > 0) || (e.total ?? Infinity) - (a.total ?? Infinity)), me.append(r("p", "Pedido por rubricas: " + h + " unidades. Melhor solução encontrada entre as combinações avaliadas; pesquisa limitada, ótimo global não garantido. Inclui operações e materiais na listagem de cálculo. Valores incompletos exigem preencher os dados indicados. Sem IVA. TESTE — A REVER.", "alert alert-warning")), b.length && me.append(r("p", b.join(" "), "alert alert-warning")); const v = f(["Máquina", "Montagens", "Folhas", "Chapas", "Tempo (h)", "Total", "Escolha"]); for (const [e, a] of g.entries()) {
        const t = a.parameters, o = t.results, i = r("tr", void 0, 0 === e ? "cb-best" : void 0);
        for (const t of [a.machine.name + (0 === e && !a.errors.length ? " · melhor completa" : ""), o.mountings, o.sheets, o.plates, o.hours.toFixed(3), window.vdOperationCosting.money(o.total)])
            i.append(r("td", String(t)));
        const s = r("td"), c = r("button", "Guardar TESTE", "btn btn-default");
        c.type = "button";
        const m = l.get("rubrics");
        if (m)
            try {
                JSON.stringify(JSON.parse(m.details).calculation) === JSON.stringify(t) && (c.textContent = "Guardado", c.disabled = !0);
            }
            catch (e) {
                console.error("Comparar composição guardada", e), s.append(r("p", "Histórico inválido.", "text-danger"));
            }
        c.addEventListener("click", async () => { if (!l.has("rubrics") || confirm("Substituir a composição e os preços guardados das rubricas?")) {
            c.disabled = !0;
            try {
                await p(a), ue();
            }
            catch (e) {
                c.disabled = !1, s.append(r("p", "Falha ao guardar: " + e.message, "text-danger")), console.error("Guardar rubricas", e);
            }
        } }), s.append(c), s.append(window.vdOperationCosting.get(m.id).detail(a)), i.append(s), v.body.append(i);
        const h = r("details"), g = r("summary", a.machine.name + " · " + window.vdOperationCosting.money(o.total) + " · consultar montagens"), b = f(["Montagem / plano / modo", "Arte · posições · produzidos / pedidos", "Planos úteis + acerto", "Folhas", "Chapas", "Tempo (h)", "Custo sem acerto CTP"]);
        a.parts.forEach((e, a) => { const t = r("tr"), o = e.paperCalculation, i = e.allocation.map((a, t) => a ? "#" + u[t].id + " " + u[t].name + ": " + a + " posições · " + e.produced[t] + " produzidos (pedido " + u[t].quantity + ")" : "").filter(Boolean).join("; "); for (const s of [a + 1 + " · #" + e.plan.id + " · " + e.plan.width + " × " + e.plan.height + " mm · " + ee[e.modeId] + " · " + e.operation.name, i, e.runs + " + " + o.wastePlans, o.sheets + " de " + o.paper.width + " × " + o.paper.height + " mm", e.result.plates, e.result.hours.toFixed(3), n(e.result.total)])
            t.append(r("td", String(s))); b.body.append(t); });
        const y = r("div", void 0, "cb-scroll");
        y.append(b.node), h.append(g, y, r("p", "CTP total (um acerto): " + n(t.plateProduction.total) + ". " + a.stocks.map(e => e.paper.width + " × " + e.paper.height + " mm: " + e.sheets + " folhas necessárias; stock " + (e.stock.available ?? "por confirmar") + "; em falta " + (e.stock.shortage ?? "por confirmar")).join("; "), "cb-note"));
        const S = f(["Rubrica / arte", "Pedido", "Produzido", "Excedente", "Isolada"]);
        u.forEach((e, t) => { const o = a.parts.reduce((e, a) => e + a.produced[t], 0), n = r("tr"); for (const a of ["#" + e.id + " · " + e.name, e.quantity, o, o - d(e.quantity), e.isolated ? "Sim" : "Não"])
            n.append(r("td", String(a))); S.body.append(n); }), h.append(S.node), me.append(h);
    } me.prepend(v.node); const y = l.get("rubrics"); if (i.append(r("p", u.length + " rubricas · " + h + " unidades. " + (g.length && !g[0].errors.length ? "Melhor completa: " + g[0].machine.name + " · " + n(g[0].total) : "Sem composição completa."), "cb-note")), y) {
        const a = r("details"), o = r("summary", "Composição guardada · histórico fixado");
        a.append(o, r("pre", y.details)), i.append(a);
        try {
            const a = JSON.parse(y.details).calculation;
            JSON.stringify(a.rubrics) !== JSON.stringify(u) && i.append(r("p", "Rubricas/isolamento alterados desde a gravação. Histórico mantido; guardar para atualizar.", "alert alert-warning"));
            const o = [...new Set(a.parts.flatMap(o => x({ ...o, paperId: a.paperId, paperFamily: a.paperFamily, paperWeight: a.paperWeight, plate: 56 === Number(o.machine.id) ? null : { materialId: 5 === Number(o.machine.id) ? 985 : 984, unitPrice: o.result.plate / o.result.plates }, plateProduction: 56 === Number(o.machine.id) ? null : a.plateProduction }, e, t)))];
            o.length && i.append(r("p", "Preços alterados: " + o.join("; ") + ". Histórico mantido.", "alert alert-warning"));
        }
        catch (e) {
            i.append(r("p", "Histórico das rubricas inválido.", "alert alert-danger")), console.error("Ler histórico de rubricas", e);
        }
    } g.length && (me.append(r("h3", "Combinações por imposição")), me.append(z(g, u, ee, r, f)), me.append(r("p", "Posições = exemplares acabados por plano, por arte. As quantidades produzidas não incluem as folhas de acerto. Cada imposição corresponde a uma montagem distinta.", "cb-note"))), g.length || me.append(r("p", "Sem solução completa. Corrige os dados indicados antes de guardar.", "alert alert-danger")); }(D, W); const B = []; for (const n of c) {
    for (const r of e.machines) {
        const i = Number(r.id), s = 56 === i;
        if (s && !W.allowDigital || !s && !W.allowOffset)
            continue;
        const l = g(e.operations, i), p = V(e.plans, m, i, Y.value);
        if (p.length)
            if (l.length)
                for (const c of l)
                    for (const l of W.mixed ? g(e.operations, 56) : [null]) {
                        const f = k(e, i, c.id), g = W.mixed ? k(e, 56, l.id) : null, y = s ? [2 === le ? "3748" : "3747"] : "auto" === ae.value ? 2 === le ? ["3748", "3749", "3750"] : ["3747"] : [ae.value];
                        for (const P of y) {
                            const y = s ? [] : p.filter(e => F(e, P, C));
                            y.length && "auto" !== ae.value && B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name + " · " + ee[P], errors: [...new Set(y.map(e => "#" + e.id + ": " + F(e, P, C)))] });
                            const x = p.filter(e => !y.includes(e));
                            if (!x.length)
                                continue;
                            const q = C ? x.filter(e => "incompatible" !== I(e, m.binding)) : x, E = C ? q.filter(e => !N(e, P)) : [], _ = C ? R(q, d(m.pages), P) : null;
                            E.length && B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name + " · " + c.name + " · " + ee[P], settings: f, errors: ["Configura Páginas por face/Cópias (ou modo incompatível): planos #" + E.map(e => e.id).join(", #") + "."] }), _?.errors.length && B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name + " · " + c.name + " (#" + c.id + ") · " + ee[P], settings: f, errors: _.errors });
                            const k = C ? _.compositions.map(e => ({ originalPlan: e[0].plan, jobs: e })) : q.map(e => ({ originalPlan: e, jobs: null }));
                            for (const { originalPlan: p, jobs: y } of k) {
                                const x = p, C = y ? y.reduce((e, a) => e + a.forms, 0) : d(Z.value), q = s ? 0 : ne ? d(oe.value) : v(W.offsetColors, P), E = s ? le : b(P), N = [...f.missing, ...W.errors];
                                const _ = e => f.values[e], F = s ? null : 5 === i ? 985 : 984, R = e.plate_prices.find(e => Number(e.id) === F)?.price;
                                let k = { hourly: _("hourly"), costHourly: _("costHourly"), speed: _("speed"), setupMinutes: _("setupMinutes"), waste: _("waste"), clickPrice: s ? _("clickPrice") : null };
                                if (s && W.white) {
                                    const e = S(k, r, !0);
                                    k = e.tariff, N.push(...e.errors);
                                }
                                const L = { jobs: y, quantity: n.value, forms: d(Z.value), plan: x, machine: r, papers: D.papers, waste: _("waste"), tariff: k, platePrice: d(R), plateOperation: ie, platesPerForm: q, component: m, passes: E }, V = W.mixed ? w({ offset: { args: L }, digital: l, digitalSettings: g, digitalMachine: h, colors: W, plans: e.plans }) : null, H = V?.publication ?? (y ? T(L) : null), J = H?.paperCalculation ?? M({ quantity: n.value, forms: d(Z.value), plan: x, machine: r, component: m, papers: D.papers, waste: _("waste") }), G = J.paper?.price ?? null;
                                J.paper && (J.paper.family = D.family);
                                const z = O(e.papers, J.paper, J.sheets);
                                J.missing && N.push(...J.missing);
                                const Q = V?.result ?? H?.result ?? (J.errors.length ? { errors: J.errors } : j({ quantity: n.value, forms: d(Z.value), platesPerForm: q, passes: E, plan: { width: d(x.width), height: d(x.height), poses: d(x.poses), perSheet: J.perSheet }, machine: { id: i, minWidth: d(r.min_width), minHeight: d(r.min_height), maxWidth: d(r.max_width), maxHeight: d(r.max_height) }, paperPrice: G, paperCalculation: J, platePrice: null == R ? null : d(R), plateOperation: ie, tariff: k })), K = { version: 2, componentId: a, component: { ...m }, quantityId: Number(n.id), quantity: n.value, machineId: i, machineName: r.name, mode: (W.mixed ? "Offset → Digital · " : "") + (s ? "Digital" : "Offset (" + ee[P] + ")"), modeId: Number(P), colors: e.colors.map(e => ({ ...e })), mixed: V?.snapshot ?? null, ...s && W.white ? { whiteClick: d(r.white_click) } : {}, pagination: y ? { pages: d(m.pages), binding: m.binding, bindingName: m.bindingName, validation: y.every(e => "validated" === I(e.plan, m.binding)) ? "Acabamento validado no catálogo" : "Dobragens/cadernos por validar", parts: H.parts } : null, paperStock: { ...z }, paperId: u, paperName: X.find(e => Number(e.id) === u)?.name, paperFamily: D.family, paperWeight: D.weight, sheetFormatFilter: Y.value || null, sheetFormatRule: Y.value ? "catalogue-filter" : "catalogue", planMatches: (y ? y.map(e => e.plan) : [x]).map(e => ({ id: e.id, ...U(e, m) })), materials: o.map(e => ({ ...e })), materialPrices: o.filter(e => e.id).map(e => ({ id: e.id, price: t[e.id]?.latestPrice ?? null })), relatedOperations: e.relatedOperations, paperCalculation: J, paperUnitPrice: G, plate: { materialId: F, unitPrice: s ? null : d(R) }, plateProduction: s ? null : { operation: ie ? { ...ie } : null, hours: Q.plateProductionHours ?? null, total: Q.plateProduction ?? null }, machine: { ...r }, operations: e.operations.filter(e => String(e.machines || "").split(",").includes(String(i))).map(e => ({ ...e })), forms: C, platesPerForm: q, passes: E, pricingStatus: W.white && $(h?.white_test) ? "TESTE — A REVER" : "Sem marca de teste", tariff: { ...f.values, operationId: Number(c.id), operationSource: f.operation }, plan: { id: Number(x.id), width: d(x.width), height: d(x.height), unitsPerPlan: d(x.poses), plansPerSheet: J.perSheet, catalogue: { ...p } }, missingPrices: [...N, ...Q.errors], results: { goodPlans: J.goodPlans, wastePlans: J.wastePlans, sheets: J.sheets, plates: Q.plates, impressions: Q.impressions, clicks: Q.clicks, clickFactor: Q.clickFactor ?? null, hours: Q.hours, paper: Q.paper ?? (G > 0 ? J.sheets * G : null), plate: Q.plate ?? null, printing: Q.printing ?? null, machineCost: Q.machineCost ?? null, click: Q.click ?? null, plateProduction: Q.plateProduction ?? null, total: Q.total ?? null, unit: Q.unit ?? null } };
                                B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name, digital: s, plan: x, plateId: F, modeId: P, rowForms: C, jobs: y, stock: z, ...Q, paperCalculation: J, parameters: K, settings: f, paperErrors: [...f.operation ? [] : ["Seleciona uma operação de impressão válida."], ...J.errors], errors: [...N, ...Q.errors] });
                            }
                        }
                    }
            else
                B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name, errors: ["Sem operação de impressão válida associada à máquina."] });
        else
            for (const a of l.length ? l : [null]) {
                const t = k(e, i, a?.id);
                B.push({ quantityId: Number(n.id), quantity: n.value, machineId: i, machine: r.name + (a ? " · " + a.name + " (#" + a.id + ")" : ""), settings: t, errors: [Y.value ? "Sem plano neste papel (" + Y.value.replace("x", " × ") + " mm) para o componente/máquina." : "Sem plano compatível.", ...l.length ? [] : ["Sem operação de impressão válida associada à máquina."]] });
            }
    }
    W.mixed && !g(e.operations, 56).length && B.push({ quantityId: Number(n.id), quantity: n.value, machine: "Offset → Digital", errors: ["Sem operação Digital válida para o branco."] });
} for (const row of B) if (row.parameters) window.vdOperationCosting.get(a).apply(row);
if (E(B), !C && !W.mixed) {
    const a = new Map, t = new Set;
    for (const o of B) {
        if (o.errors.length || !Number.isFinite(o.total) || a.has(o.machineId))
            continue;
        const n = o.machineId + ":" + o.quantityId;
        if (t.has(n))
            continue;
        t.add(n);
        const r = e.machines.find(e => Number(e.id) === o.machineId);
        !B.some(e => e.machineId === o.machineId && e.quantityId === o.quantityId && !e.errors.length && Number.isFinite(e.total) && L(e.plan) === L(o.plan) && d(e.plan.poses) > d(o.plan.poses)) && r && H(o.plan, r, m) && a.set(o.machineId, r.name);
    }
    a.size && me.append(r("p", "Possível plano maior: " + [...a.values()].join("; ") + ". Poderá permitir mais exemplares por plano e menos passagens. Indicação geométrica com sangria de 3 mm por lado, não uma imposição validada. Validar pinças, margens, corte e viragens e criar os novos planos necessários. Cálculos e preços mantêm apenas os planos existentes.", "alert alert-info cb-larger-plan-notice"));
} const Q = f(["Quant.", "Máquina / plano", "Papel / aproveitamento", "Planos úteis + acerto", "Folhas úteis + acerto = total", "Desperdício geom. / com acerto", "Chapas", "Cliques", "Tempo (h)", "Papel", "Chapas €", "Máquina €", "Cliques €", "Produção de chapas €", "Total da simulação", "€/un.", "Resultado", "Escolher", "Stock disponível (folhas)", "Folhas em falta"]); for (const e of B) {
    const a = q(B, e.quantityId)[0] === e, t = r("tr", void 0, a ? "cb-best" : void 0), o = e.plan ? e.machine + " · " + e.settings.operation.name + " (#" + e.settings.operation.id + ") · " + (e.jobs ? e.jobs.map(e => e.forms + " × " + e.pages + " pág. · plano #" + e.plan.id).join(" + ") + " · " + e.rowForms + " formas · " + e.parameters.passes + " passagens/forma" : "plano #" + e.plan.id + " · " + e.plan.width + " × " + e.plan.height + " mm · " + e.plan.poses + " un./plano") + " · " + (e.digital ? "Digital " + le + " face(s)" : ee[e.modeId]) + (e.parameters.mixed ? " → " + e.parameters.mixed.digital.machine.name + " · " + e.parameters.mixed.digital.tariff.operationSource.name + " (branco)" : "") : e.machine;
    t.append(r("td", null === e.quantity ? "Inválida" : String(e.quantity)), r("td", o));
    for (const a of e.parameters?.planMatches || [])
        t.cells[1].append(r("p", "#" + a.id + " · " + (a.exact ? "Plano exato" : "Plano compatível — margem para corte: " + a.width + " × " + a.height + " mm"), "cb-note"));
    const i = e.paperCalculation, d = i && !e.paperErrors.length, c = B.filter(a => a.quantityId === e.quantityId && a.paperCalculation && !a.paperErrors.length), m = d && i.sheetArea === Math.min(...c.map(e => e.paperCalculation.sheetArea));
    t.append(r("td", d ? i.paper.width + " × " + i.paper.height + " mm" + (i.paper.id ? " · preço: material #" + i.paper.id : " · preço por preencher") + " · " + i.perSheet + " planos/folha" : "—"), r("td", d ? i.goodPlans + " + " + i.wastePlans : "—", "cb-number"), r("td", d ? i.usefulSheets + " + " + i.setupSheets + " = " + i.sheets : "—", "cb-number"), r("td", d && null !== i.productWaste ? (100 * i.productWaste).toFixed(2) + " % / " + (100 * i.totalWaste).toFixed(2) + " %" : "—", "cb-number"));
    const u = (e, a = String) => Number.isFinite(e) ? a(e) : "—";
    for (const a of [u(e.plates), u(e.clicks), u(e.hours, e => new Intl.NumberFormat("pt-PT", { maximumFractionDigits: 3 }).format(e)), ...[e.paper, e.plate, e.printing, e.click, e.plateProduction, e.total].map(e => u(e, n)), u(e.unit, e => new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(e))])
        t.append(r("td", String(a), "cb-number"));
    e.errors.length && d && Number.isFinite(i.paper.price) && i.paper.price > 0 && (t.cells[9].textContent = n(i.sheets * i.paper.price)), t.append(r("td", e.errors.length ? (d ? "Papel calculado. Dados por completar: " : "") + [...new Set(e.errors)].join(" ") : a ? "Melhor opção: menor total completo" : "Alternativa completa")), e.jobs && t.cells[16].append(r("p", e.parameters.pagination.validation + ". " + e.jobs.map(e => "Plano #" + e.plan.id + ": " + e.positions + " páginas/face, " + e.copies + " cópia(s)/plano, " + e.forms + " forma(s), " + e.pages + " páginas/forma.").join(" "), "cb-note")), e.parameters?.mixed && t.cells[16].append(r("p", "Offset → Digital; acerto único: " + e.parameters.mixed.waste + " planos/forma. Offset " + (Number.isFinite(e.parameters.mixed.offset.result.printing) ? n(e.parameters.mixed.offset.result.printing) : "—") + "; Digital " + (Number.isFinite(e.parameters.mixed.digital.result.printing) ? n(e.parameters.mixed.digital.result.printing) : "—") + ". Papel/chapas/CTP uma vez.", "cb-note"));
    const h = e.settings;
    if (h) {
        const a = e => null === e ? "Por preencher" : String(e);
        t.cells[16].append(r("p", "Custo €/h: " + a(h.values.costHourly) + " · " + " · produção/h: " + a(h.values.speed) + " · acerto min/forma: " + a(h.values.setupMinutes) + " · acerto planos/forma: " + a(h.values.waste) + (56 === e.machineId ? " · €/clique: " + a(h.values.clickPrice) : ""), "cb-note")), h.operation && t.cells[16].append(s("Operação #" + h.operation.id, "index.php?module=items/info&path=36-" + h.operation.id, "_blank")), Number.isFinite(e.machineCost) && t.cells[16].append(r("p", "Custo interno da impressão: " + n(e.machineCost) + " (incluído no custo total).", "cb-note")), ("TESTE — A REVER" === e.parameters?.pricingStatus || h.provisional) && t.cells[16].prepend(r("strong", "TESTE — A REVER. Não enviar ao cliente. "));
    }
    m && t.cells[16].prepend(r("span", "Menor área de papel (informativo; escolha pelo total). "));
    if (e.allOperations) t.cells[16].append(window.vdOperationCosting.get(m.id).detail(e));
    const f = r("td");
    if (d && Number.isInteger(e.quantityId)) {
        const a = l.get(String(e.quantityId));
        let t = null;
        if (a)
            try {
                t = JSON.parse(a.details);
            }
            catch (e) {
                console.error("Instantâneo guardado inválido", e);
            }
        const o = t && JSON.stringify(t.calculation) === JSON.stringify(e.parameters), n = r("button", o ? "Guardado" : a ? "Guardar nova versão" : e.errors.length ? "Guardar papel" : "TESTE — A REVER" === e.parameters.pricingStatus ? "Guardar TESTE" : "Selecionar", "btn " + (o ? "btn-success" : "btn-default"));
        n.type = "button", n.disabled = Boolean(o), n.addEventListener("click", async () => { if (!a || confirm("Guardar nova versão e preços guardados?")) {
            n.disabled = !0, n.textContent = "A guardar…";
            try {
                await p(e), ue();
            }
            catch (e) {
                n.disabled = !1, n.textContent = "Tentar novamente", f.append(r("p", "Falha ao guardar: " + e.message, "text-danger")), console.error("Guardar seleção de impressão", e);
            }
        } }), f.append(n);
    }
    t.append(f, r("td", Number.isFinite(e.stock?.available) ? String(e.stock.available) : e.stock?.reason || "—", "cb-number"), r("td", Number.isFinite(e.stock?.shortage) ? String(e.stock.shortage) : "Por confirmar", e.stock?.shortage > 0 ? "cb-number text-danger" : "cb-number")), e.stock?.withoutMovements?.length && (t.cells[18].title = "Sem movimentos de stock nos materiais #" + e.stock.withoutMovements.join(", #") + " (saldo zero)."), Q.body.append(t);
} const re = r("div", void 0, "cb-scroll"); re.append(Q.node), me.append(re); const se = f(["Quant.", "Papel · menor desperdício (informativo)", "Melhor opção · menor total completo", "Seleção guardada"]); for (const a of c) {
    const o = q(B, Number(a.id))[0], i = l.get(String(a.id)), s = r("tr"), p = B.filter(e => e.quantityId === Number(a.id) && e.paperCalculation && !e.paperErrors.length).sort((e, a) => e.paperCalculation.sheetArea - a.paperCalculation.sheetArea)[0], d = e.machines.find(e => Number(e.id) === Number(i?.machine_id));
    let c = "Não selecionada", m = [];
    if (i)
        try {
            const o = JSON.parse(i.details), r = o.calculation || o, s = r.paperCalculation;
            c = (r.machine?.name || r.machine || d?.name || "Máquina #" + i.machine_id) + (r.tariff?.operationSource ? " · " + r.tariff.operationSource.name + " (#" + r.tariff.operationSource.id + ")" : "") + " · plano #" + i.plan_id + " · " + (s && !s.errors.length ? s.sheets + " folhas de " + s.paper.width + " × " + s.paper.height + " mm · " : "") + ("number" == typeof r.results?.total ? n(r.results.total) : "Preço por completar") + (o.savedAt ? " · fixado em " + new Date(o.savedAt).toLocaleString("pt-PT") : " · instantâneo anterior"), Number(r.quantity) !== Number(a.value) && (c += " · Quantidade alterada; seleção histórica mantida."), "TESTE — A REVER" === r.pricingStatus && (c += " · TESTE — A REVER. Não enviar."), r.sheetFormatFilter && (c += " · Formato forçado: " + r.sheetFormatFilter.replace("x", " × ") + " mm"), r.pagination && (c += " · " + r.pagination.parts.map(e => e.forms + " × " + e.pages + " pág. (plano #" + e.plan.id + ")").join(" + ") + " · " + r.mode + " · " + r.pagination.validation), 2 !== o.version && (c += " · Seleção antiga: sem instantâneo completo dos materiais."), m = x(r, e, t), 56 === r.machineId || r.plateProduction || (c += " · Histórico anterior ao CTP; total preservado.");
        }
        catch (e) {
            c = "Erro: instantâneo guardado inválido. Não foi recalculado.", console.error("Ler seleção guardada", e);
        }
    if (s.append(r("td", null === a.value ? "Inválida" : String(a.value)), r("td", p ? p.machine + " · plano #" + p.plan.id + " · " + p.paperCalculation.sheets + " folhas de " + p.paperCalculation.paper.width + " × " + p.paperCalculation.paper.height + " mm" + (null !== p.paperCalculation.totalWaste ? " · desperdício total " + (100 * p.paperCalculation.totalWaste).toFixed(2) + " %" : "") : "Dados de papel por completar"), r("td", o ? o.machine + " · " + o.settings.operation.name + " (#" + o.settings.operation.id + ") · " + (o.jobs ? o.jobs.map(e => e.forms + " × " + e.pages + " pág. (#" + e.plan.id + ")").join(" + ") + " · " + o.parameters.mode : "plano #" + o.plan.id) + " · " + n(o.total) + ("TESTE — A REVER" === o.parameters.pricingStatus ? " · TESTE — A REVER" : "") : "Dados por completar"), r("td", c)), i) {
        const e = r("details"), a = r("summary", "Consultar dados e preços fixados"), t = r("pre", i.details);
        t.style.whiteSpace = "pre-wrap", e.append(a, t), s.cells[3].append(e);
    }
    m.length && s.lastElementChild.append(r("p", "ALERTA — Preços alterados desde a gravação: " + m.join("; ") + ". Histórico mantido. Guardar nova versão para atualizar.", "alert alert-warning")), se.body.append(s);
} i.append(se.node, r("p", "Histórico preservado. Totais incompletos não são finais.", "cb-note")), B.some(e => e.errors.length) && i.append(r("p", "Folhas calculadas; preços incompletos.", "cb-note text-danger")), B.some(e => e.errors.length) && me.prepend(r("p", B.some(e => !e.errors.length && Number.isFinite(e.total)) ? "Melhores opções completas no topo. Há outras alternativas incompletas; podem alterar a comparação quando preenchidas." : "Sem opção completa: consulta os dados em falta nas linhas.", "alert alert-warning")), me.append(r("p", fe(), "cb-note")); } if (me.setAttribute("aria-live", "polite"), !(C || m.hasDie || A || u.mixed))
    return be({ card: c, form: D, formatSelect: Y, forms: Z, passesInput: se, action: ce, results: me, summary: i, selectedByQuantity: l, element: r, table: f, data: e, component: m, paperSelect: K, metallicSelect: te, persistSelection: p, materials: o, platesInput: oe, modeSelect: ae, getManualPlates: () => ne }), c; D.addEventListener("submit", e => { e.preventDefault(); try {
    ue();
}
catch (e) {
    me.replaceChildren(r("p", "Falha na simulação: " + e.message, "alert alert-danger")), console.error("Simulador de planos", e);
} }); const he = () => { me.replaceChildren(r("p", "Valores alterados. Clica “Comparar todas as quantidades” para recalcular.", "alert alert-info")), i.replaceChildren(r("h2", "Resumo das simulações"), r("p", "Aguardando nova comparação.", "cb-note")); }; return D.addEventListener("input", he), D.addEventListener("change", e => { if (e.target === Y || e.target === ae)
    try {
        ue();
    }
    catch (e) {
        me.replaceChildren(r("p", "Falha na simulação: " + e.message, "alert alert-danger")), console.error("Simulador de planos", e);
    }
else
    he(); }), c.append(r("p", "Catálogo: cortantes, paginação ou produção mista.", "cb-note"), D, me), ue(), c; } function Se(a) { const t = r("div", "Não foi possível carregar o orçamento: " + a.message, "alert alert-danger"); e.replaceChildren(t), console.error("Configurar e orçamentar", a); } document.querySelectorAll(".page-title").forEach(title => {
    if (title.textContent.trim() === "Configurar e orçamentar") title.remove();
});
const initialReport = {
    catalog: document.querySelector("#component-material-catalog")?.textContent,
    simulation: document.querySelector("#component-simulation-data")?.textContent
};
await async function t(refresh = false) { if (e.replaceChildren(r("p", "A carregar o componente e os seus dados…")), !Number.isInteger(a) || a <= 0)
    throw new Error("Abre esta página a partir de um componente do orçamento."); let l = new URLSearchParams(location.search).get("path"); if (!l || !new RegExp("^(146-\\d+/)?262-" + a + "$").test(l))
    throw new Error("O contexto do componente não é válido. Abre a página pelo botão do componente."); const g = await i("index.php?module=items/info&path=" + encodeURIComponent(l)), b = g.querySelector("#subentity279_items_listing_path"); if (!b || !new RegExp("^146-\\d+/262-" + a + "/279$").test(b.value))
    throw new Error("Sem acesso ao componente ou ao plano de impressão."); l = b.value.replace(/\/279$/, ""); const v = "index.php?module=items/info&path=" + encodeURIComponent(l), y = [{ id: 279, title: "Plano de impressão", note: "Formatos em mm. Campos por preencher são apresentados com —." }, { id: 277, title: "Papel e matérias-primas", note: "Inclui as chapas e os consumíveis já registados, sem os duplicar." }, { id: 276, title: "Pré-impressão · Impressão · Acabamento", note: "Operações e requisitos técnicos do componente. Custos calculados no comparativo." }, { id: 278, title: "Cores de impressão", note: "Cores e referências do componente." }, { id: 280, title: "Rubricas", note: "Discriminação e referências adicionais." }], S = {}; for (const e of y) {
    const a = Array.from(g.querySelectorAll('[id^="entity_items_listing"]')).find(a => new RegExp("^entity_items_listing\\d+_" + e.id + "$").test(a.id));
    if (!a)
        throw new Error("Sem acesso ou listagem não configurada: " + e.title);
    S[e.id] = { container: a, report: a.id.match(/^entity_items_listing(\d+)_/)[1] };
} const w = await Promise.all(y.map(async (e) => { const a = S[e.id], t = { path: l + "/" + e.id, reports_entities_id: e.id, reports_id: a.report, listing_container: a.container.id, page: 1, redirect_to: "parent_item_info_page" }, o = await i("index.php?module=items/listing", t), n = e => Array.from(e.querySelectorAll("tbody tr.listing-table-tr")), r = o.querySelectorAll(".listing-split-page strong"), s = 3 === r.length ? Number(r[2].textContent) : 0; let p = n(o).length; if (p && (!Number.isInteger(s) || s < p))
    throw new Error("Não foi possível verificar o número de linhas de " + e.title); for (; p < s;) {
    t.page++;
    const e = n(await i("index.php?module=items/listing", t));
    if (!e.length || p + e.length > s)
        throw new Error("A listagem mudou durante a leitura. Atualiza o orçamento.");
    o.querySelector("tbody").append(...e), p += e.length;
} return { section: e, doc: o }; })), P = r("div", void 0, "cb-header"), x = r("div"); void 0; const q = r("button", "Atualizar orçamento", "btn btn-primary"); q.type = "button", q.addEventListener("click", () => t(true).catch(Se)); const E = r("div", void 0, "cb-actions"), N = p(g, "Editar características", /module=items\/form.*entity_id=262/); N && E.append(N), E.append(s("Abrir componente", v, "_blank"), s("Abrir orçamento", "index.php?module=items/info&path=" + encodeURIComponent(l.split("/")[0]), "_blank"), q); const I = r("nav", void 0, "cb-tabs"), A = r("div", void 0, "cb-grid"), _ = r("div"), F = r("div"), O = r("section", void 0, "cb-summary cb-card"), R = r("section", void 0, "cb-summary cb-card"), T = { 277: [["Material", [4280, 4278]], ["Quant.", [4277], !0], ["P.U. compra", [4323], !0], ["Compra", [4335], !0]], 276: [["Operação", [4287]], ["Equipamento", [4288]], ["Entrada (mm)", [6125], !0], ["Saída (mm)", [6126], !0], ["Entrada pelo lado estreito", [6127]]], 278: [["Cor", [4251, 4249]]], 280: [["Descrição", [4321]], ["Quant.", [4320], !0], ["Código", [4322]]] }, k = [], M = new Map, D = (e, a) => Array.from(e.querySelectorAll("select")).filter(e => e.name === "fields[" + a + "]" || e.name === "fields[" + a + "][]").flatMap(e => Array.from(e.selectedOptions)).filter(e => /^\d+$/.test(e.value)).map(e => ({ id: Number(e.value), name: o(e.textContent) })), j = async (e, a) => i("index.php?" + new URLSearchParams({ module: "items/form", entity_id: e, id: a, path: l + "/" + e + "-" + a })); for (const { section: e, doc: a } of w) {
    const t = r("section", void 0, "cb-card");
    t.id = "cb-section-" + e.id;
    const n = r("a", e.title);
    n.href = "#" + t.id, I.append(n), n.addEventListener("click", () => { const e = t.closest("details"); e && (e.open = !0); }), t.append(r("h2", e.title), r("p", e.note, "cb-note"));
    const c = r("div", void 0, "cb-actions"), u = p(g, "Adicionar", new RegExp("module=items/form&path=" + l + "/" + e.id + "&"));
    u && c.append(u), c.append(s("Gerir / editar", "index.php?module=items/items&path=" + encodeURIComponent(l + "/" + e.id), "_blank")), t.append(c);
    const h = r("div", void 0, "cb-scroll");
    if (279 === e.id) {
        const e = f(["Plano / máquina", "Folha (mm)", "Plano (mm)", "Un./plano", "Pl./folha", "Cadernos", ""]), t = await Promise.all(m(a).map(async (e) => { const a = p(e, "Editar", /module=items\/form/), t = e.querySelector("[data_path]"), n = t && t.getAttribute("data_path"); if (!n || !/^279-\d+$/.test(n))
            throw new Error("Plano sem referência válida."); const s = await i("index.php?module=items/info&path=" + encodeURIComponent(l + "/" + n)), c = await j(279, n.split("-")[1]), m = await Promise.all(D(c, 4272).map(async (e) => { M.has(e.id) || M.set(e.id, i("index.php?module=items/info&path=78-" + e.id)); const a = await M.get(e.id); return { ...e, maxWidth: d(a.querySelector(".form-group-1285 td")?.textContent), maxHeight: d(a.querySelector(".form-group-1284 td")?.textContent) }; })), u = e => o(s.querySelector(".form-group-" + e + " td")?.textContent); k.push({ id: Number(n.split("-")[1]), machines: m, width: d(u(4262)), height: d(u(4263)) }); const h = (e, a) => (u(e) || "—") + " × " + (u(a) || "—"), f = r("tr"); for (const e of [[u(4256), u(4272), u(4271)].filter(Boolean).join(" · ") || "—", h(4264, 4265), h(4262, 4263), u(4261) || "—", u(4266) || "—", u(4274) || "—"])
            f.append(r("td", e)); const g = r("td"); return a && g.append(a), f.append(g), f; }));
        if (e.body.append(...t), !e.body.rows.length) {
            const a = r("tr"), t = r("td", "Sem plano de impressão");
            t.colSpan = 7, a.append(t), e.body.append(a);
        }
        h.append(e.node);
    }
    else
        h.append(ve(a, T[e.id], l));
    if (t.append(h), [278, 280].includes(e.id)) {
        const o = r("details"), n = r("summary", e.title + " (" + m(a).length + ")");
        o.append(n, t), _.append(o);
    }
    else
        _.append(t);
} const L = w.find(e => 277 === e.section.id).doc, U = w.find(e => 276 === e.section.id).doc, V = new URLSearchParams(location.search).get("id"), H = !refresh && initialReport.catalog && initialReport.simulation ? null :
        await i(url_for("report_page/view", "action=load&id=" + encodeURIComponent(V) + "&path=" + encodeURIComponent(l)), {}),
    W = H ? H.querySelector("#component-material-catalog") : {textContent: initialReport.catalog}; if (!W)
    throw new Error("Não foi possível consultar os materiais associados às descrições internas."); const J = JSON.parse(W.textContent), B = H ? H.querySelector("#component-simulation-data") : {textContent: initialReport.simulation}; if (!B)
    throw new Error("Dados do simulador de planos indisponíveis."); const G = JSON.parse(B.textContent);
G.ctp_resources = { equipment: G.ctp_equipment, labor: G.ctp_labor, operators: G.ctp_operators };
G.imposition_resources = { operation: G.imposition_operation[0], equipment: G.imposition_equipment,
    labor: G.imposition_labor, operators: G.imposition_operators, papers: G.imposition_papers,
    machine: (() => { const m = G.machines.find(row => Number(row.id) === 56); return m ?
        { id: m.id, width: m.max_width, height: m.max_height, print_width: m.print_width, print_height: m.print_height } : null; })() };
G.operations = G.operations.filter(row => Number(row.id) !== 43);
G.price_operations = G.price_operations.filter(row => Number(row.id) !== 43);
G.component[0].setupWasteOperations = G.costing_operations.map(row => ({
    operation_id: row.operation_id, name: row.name, machines: row.machines,
    waste: Number(row.operation_id) === 13 && G.component[0].lamination_front === 'none' &&
        G.component[0].lamination_back === 'none' ? 0 : row.waste
}));
G.material_price_policy = { version: 1, method: "last-completed-purchase", states: [39, 3790],
    order: ["date_added DESC", "id DESC"], quantity: "positive", unit: "article-native" };
for (const operation of [...G.operations, ...G.price_operations]) {
    const tariff = window.vdOperationCosting.currentTariff(G, operation.id);
    // The simulation engine's hourly slot is a cost proxy; commercial pricing is applied by the full ledger.
    operation.hourly = !tariff.error && tariff.basis === "h" ? tariff.sale : null;
    operation.cost_hourly = !tariff.error && tariff.basis === "h" ? tariff.cost : null;
    operation.consolidatedTariff = tariff;
    if (Number(operation.id) === 3) operation.ctpResources = G.ctp_resources;
} G.component[0] && (G.component[0].bindingName = o(g.querySelector(".form-group-6110 td")?.textContent) || "Não indicado"); const z = await Promise.all(m(L).map(async (e) => {
    const record = G.material_records?.find(record =>
        Number(record.record_id) === Number(e.querySelector("[data_path]")?.getAttribute("data_path")?.split("-")[1]));
    if (record && [record.internal_id, record.article_id].every(value => !value || /^\d+$/.test(String(value)))) {
        const articleId = Number(record.article_id) || null;
        const internalId = Number(record.internal_id) || Number(G.papers.find(paper => Number(paper.id) === articleId)?.internal_id) || undefined;
        const purchaseReference = G.material_purchases.find(purchase => Number(purchase.record_id) === Number(record.record_id));
        const quantity = d(record.quantity), purchaseUnitPrice = d(purchaseReference?.price);
        return {id: internalId, recordId: Number(record.record_id), materialId: articleId,
            name: record.internal_name || record.article_name || "Material sem descrição",
            materialName: record.article_name || null, quantity,
            paper: record.internal_id ? Number(record.internal_paper) === 1 : Number(record.article_paper) === 1,
            purchaseReference, purchaseUnitPrice,
            purchaseTotal: quantity !== null && purchaseUnitPrice !== null ? quantity * purchaseUnitPrice : null,
            saleUnitPrice: d(record.sale_unit_price), saleTotal: d(u(e, 4325))};
    } const a = e.querySelector("[data_path]")?.getAttribute("data_path"); if (!a || !/^277-\d+$/.test(a))
    throw new Error("Material sem referência válida."); const t = await j(277, a.split("-")[1]), o = D(t, 4280)[0], n = D(t, 4278)[0]; let r = !!o && !0 === J[o.id]?.paper; if (!o && n) {
    const e = await i("index.php?module=items/form&entity_id=99&id=" + n.id + "&path=99-" + n.id);
    r = Array.from(e.querySelectorAll('select[name^="fields[1019]"]')).some(e => Array.from(e.selectedOptions).some(e => "190" === e.value));
} const s = G.papers.find(e => Number(e.id) === n?.id)?.internal_id; return { id: o?.id || (s ? Number(s) : void 0), recordId: Number(a.split("-")[1]), materialId: n?.id ?? null, name: o?.name || n?.name || "Material sem descrição", materialName: n?.name ?? null, quantity: d(t.querySelector('[name="fields[4277]"]')?.value), paper: r, purchaseReference: G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1])),
    purchaseUnitPrice: d(G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1]))?.price),
    purchaseTotal: (() => { const quantity = d(t.querySelector('[name="fields[4277]"]')?.value);
        const price = d(G.material_purchases.find(p => Number(p.record_id) === Number(a.split("-")[1]))?.price);
        return quantity !== null && price !== null ? quantity * price : null; })(), saleUnitPrice: d(t.querySelector('[name="fields[4324]"]')?.value), margin: d(t.querySelector('[name="fields[4336]"]')?.value), saleTotal: d(u(e, 4325)) }; })), $ = _.querySelector("#cb-section-277 table"), Q = $.tHead.rows[0]; Q.insertBefore(r("th", "Última compra"), Q.cells[3]); const X = $.tBodies[0].rows; z.forEach((e, a) => { const t = X[a], o = e.purchaseUnitPrice, i = null == o || "" === o ? null : Number(o), s = r("td", Number.isFinite(i) ? n(i) : "Sem preço", "cb-number"); t.insertBefore(s, t.cells[3]), e.id && null === J[e.id]?.latestPrice && (X[a].cells[2].textContent = "Sem preço", X[a].cells[2].classList.add("text-danger")); }); const K = e => { if ("" !== e.quantity_id && null !== e.quantity_id && Number(e.quantity_id) > 0)
    return String(e.quantity_id); try {
    return "rubrics" === JSON.parse(e.details).calculation?.basis ? "rubrics" : String(e.quantity_id);
}
catch (a) {
    return console.error("Identificar seleção inválida", a), "invalid:" + e.id;
} }, Y = new Map((G.selections || []).map(e => [K(e), e])); G.relatedOperations = m(U).map(e => ({ record: e.querySelector("[data_path]")?.getAttribute("data_path"), operation: u(e, 4287), machine: u(e, 4288), laminationEntry: d(u(e, 6125)), laminationExit: d(u(e, 6126)), laminationNarrowEdge: u(e, 6127) })); const operationPanel = r("div");
const operationCosting = window.vdOperationCosting.create(G, z, Y, operationPanel, _.querySelector("#cb-section-276 table"));
const Z = ye(G, J, z, R, Y, operationCosting.save = async function (e) {
operationCosting.check(e);
const versions = G.workflow_versions.filter(row => String(Number(row.quantity_id) > 0 ? row.quantity_id : "rubrics") === String(e.quantityId));
const expectedVersion = Number(versions.at(-1)?.id || 0);
e.workflowRequestId ||= crypto.randomUUID();
const workflowResponse = await fetch("index.php?" + new URLSearchParams({module:"items/vd_quote_workflow", path:l}), {
    method:"POST", credentials:"same-origin", headers:{"X-Requested-With":"XMLHttpRequest"},
    body:new URLSearchParams({api:"save",form_session_token:G.workflow_token,payload:JSON.stringify({
        request_id:e.workflowRequestId,expected_version:expectedVersion,quantity_id:String(e.quantityId),
        calculation:e.parameters})})});
let workflowResult;
try { workflowResult = await workflowResponse.json(); } catch (_) { throw new Error("Resposta inválida. Não repita a gravação automaticamente."); }
if (!workflowResponse.ok || workflowResult.ok !== true) throw new Error(workflowResult.error || "Gravação não confirmada.");
const savedVersion = workflowResult.data;
G.workflow_versions.push({id:savedVersion.workflow_version,quantity_id:savedVersion.quantity_id});
Y.set(String(e.quantityId), savedVersion);
operationCosting.renderSaved();
return savedVersion;
 const a = Y.get(String(e.quantityId)), t = l + "/388" + (a ? "-" + a.id : ""), o = new URLSearchParams({ module: "items/form", entity_id: "388", path: t, redirect_to: "parent_item_info_page" }); a && o.set("id", String(a.id)); const n = await i("index.php?" + o.toString()), r = Array.from(n.forms).find(e => e.querySelector('[name="fields[6101]"]')); if (!r)
    throw new Error("Não foi possível abrir o formulário da seleção de produção."); const s = new URLSearchParams(new FormData(r)); s.set("fields[6101]", "rubrics" === e.quantityId ? "" : String(e.quantityId)), s.set("fields[6102]", String(e.quantity)), s.set("fields[6103]", "dynamic" === e.parameters.source ? "" : String(e.plan.id)), s.set("fields[6104]", String(e.machineId)); const p = { version: 2, savedAt: (new Date).toISOString(), calculation: e.parameters }, d = JSON.stringify(p); s.set("fields[6105]", e.errors.length ? "" : String(e.total)), s.set("fields[6106]", d), await i(r.action, s); const c = url_for("report_page/view", "action=load&id=" + encodeURIComponent(V) + "&path=" + encodeURIComponent(l)), m = (await i(c, {})).querySelector("#component-simulation-data"); if (!m)
    throw new Error("A plataforma não devolveu a seleção guardada para validação."); const u = (JSON.parse(m.textContent).selections || []).find(a => K(a) === String(e.quantityId)); if (!u || Number(u.plan_id) !== Number(e.plan.id) || Number(u.machine_id) !== Number(e.machineId))
    throw new Error("A seleção submetida não foi encontrada após a gravação."); if (u.details !== d || Number(u.selected_quantity) !== e.quantity)
    throw new Error("Os parâmetros ou preços fixados não coincidem com os dados submetidos."); if (!e.errors.length && Math.abs(Number(u.base) - e.total) > .011)
    throw new Error("A base estimada guardada não coincide com o cálculo."); if (e.errors.length && "" !== u.base && null !== u.base)
    throw new Error("A seleção de papel incompleta não pode ter uma base estimada final."); Y.set(String(e.quantityId), u); operationCosting.renderSaved(); return u; }), ee = r("a", "Comparar planos"); ee.href = "#cb-simulator", I.prepend(ee); const ae = w.find(e => 278 === e.section.id).doc, te = h({ materials: z, plans: k, hasOffset: m(U).some(e => /\bimpress[aã]o\b.*\boffset\b/i.test(u(e, 4287))), hasPaper: z.some(e => e.paper && e.quantity > 0), hasColors: m(ae).some(e => u(e, 4251) || u(e, 4249)) }), oe = z.reduce((totals, material) => {
        const cost = d(material.purchaseTotal);
        if (cost === null) totals.missing++;
        else totals.total += cost;
        return totals;
    }, { total: 0, missing: 0 }),
    ne = (() => {
        const pricing = window.vdOperationCosting.commercialPricing(G);
        return { total: pricing.markup === null ? null : oe.total * (1 + pricing.markup / 100),
            missing: oe.missing + pricing.errors.length };
    })(), re = m(U).length, ie = oe.missing + ne.missing + re, se = f(["Rubrica", "Compra", "Venda"]); for (const [e, a, t] of [["Materiais", oe, ne], ["Operações", null, null]]) {
    const o = r("tr");
    o.append(r("th", e));
    for (const e of [a, t])
        o.append(r("td", e ? n(e.total) + (e.missing ? " *" : "") : "Não disponível", "cb-number"));
    se.body.append(o);
} O.append(r("h2", "Configuração guardada · Sem IVA"), se.node); const le = d(g.querySelector(".form-group-4224 td")?.textContent.replace(/\s*un\s*$/i, "")), pe = f(["Quantidade", "P. unitário", "Total (s/IVA)"]); pe.node.className = "cb-price"; const de = r("tr"), ce = ne.total + 0, me = z.filter(e => e.id && null === J[e.id]?.latestPrice), ue = !ie && !te.length && !me.length && null !== le && le > 0; for (const e of [null === le ? "Por preencher" : new Intl.NumberFormat("pt-PT").format(le), ue ? new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(ce / le) + " €" : "Por completar", ue ? n(ce) : "Por completar"])
    de.append(r("td", e)); pe.body.append(de), O.append(pe.node); const he = r("div", void 0, "cb-warnings"); for (const e of me)
    he.append(r("p", e.name + ": não existe preço de compra para esta descrição interna ou para os materiais associados.", "text-danger")); he.append(r("p", "Papel orçamentado por descrição interna, sem escolher marca. P.U. compra = preço da última compra concluída dessa descrição.", "cb-note")); const fe = r("div", void 0, te.length ? "alert alert-danger" : "alert alert-success"); if (fe.id = "cb-validation", fe.append(r("strong", te.length ? "CONFIGURAÇÃO INVÁLIDA" : "Regras técnicas verificadas")), te.length) {
    const e = r("ul");
    te.forEach(a => e.append(r("li", a))), fe.append(e);
} fe.append(r("p", "Validação do resumo: permite guardar trabalho incompleto. Não altera nem remove linhas automaticamente.", "cb-note")), he.append(fe), ie && he.append(r("strong", "ORÇAMENTO INCOMPLETO"), r("p", "* Existem valores em falta: os montantes de materiais são parciais.", "text-danger")), he.append(r("p", "Resumo dos valores registados, não uma proposta validada. A compra das operações não está disponível.", "cb-note"), r("p", "As operações do componente definem os requisitos do trabalho; não têm tarifas ou totais manuais. Consulta os custos calculados no comparativo. Os acabamentos ainda não estão incluídos nesse custo.", "cb-note"), r("p", "A gravação usa os formulários da plataforma e pode regressar ao componente. Nesse caso, reabre “Configurar e orçamentar”; para reler alterações, usa “Atualizar orçamento”.", "cb-note"), r("p", "Offset/Digital: nesta página ainda não há geração nem remoção automática de chapas. As linhas existentes são preservadas.", "cb-note")), O.append(he), R.style.gridColumn = "1 / -1", O.querySelectorAll("table,h2,.cb-warnings > p,.cb-warnings > strong").forEach(node => node.hidden = true), F.append(O), A.append(R, _, F), (() => {
const workflowUrl = "index.php?" + new URLSearchParams({
    module:"items/vd_quote_workflow",path:l.split("/")[0],modal:"1"});
const workflowLink = s("Versões, confirmação, aprovação e proposta", "#");
workflowLink.classList.add("link-to-modalbox");
workflowLink.addEventListener("click", event => {
    event.preventDefault();
    window.open_dialog(workflowUrl);
});
E.append(workflowLink);
const manualPlans = _.querySelector("#cb-section-279");
manualPlans.hidden = true;
I.querySelector('a[href="#cb-section-279"]').hidden = true;
const operationsSection = _.querySelector("#cb-section-276");
const operationsHeading = operationsSection.querySelector("h2");
operationsHeading.textContent = "Parâmetros das operações";
operationsSection.querySelector(".cb-note").textContent =
    "Operações usadas pelo cálculo dinâmico. Edite apenas os requisitos, equipamentos e parâmetros específicos do componente; os tempos e custos são calculados na simulação.";
const operationsDetails = r("details");
operationsDetails.id = "cb-operation-parameters";
const requirementLabels = {
    mandatory: "Obrigatória",
    inferred: "Inferida dos campos",
    dependency: "Dependência automática",
    selected: "Selecionada explicitamente",
    plan_alternative: "Alternativa decidida pelo plano"
};
const requirementSummaryLabels = {
    mandatory: "obrigatórias",
    inferred: "inferidas dos campos",
    dependency: "dependências automáticas",
    selected: "selecionadas explicitamente",
    plan_alternative: "alternativas decididas pelo plano"
};
const requirementCounts = G.costing_operations.reduce((counts, row) => {
    const type = row.requirement_type || (row.discovered ? "inferred" : "selected");
    counts[type] = (counts[type] || 0) + 1;
    return counts;
}, {});
const requirementSummary = Object.entries(requirementLabels)
    .filter(([type]) => requirementCounts[type])
    .map(([type]) => requirementCounts[type] + " " + requirementSummaryLabels[type])
    .join(" · ");
operationsDetails.append(r("summary", "Operações e parâmetros do componente (" + requirementSummary + ")"));
operationsDetails.append(r("p",
    "Obrigatórias e inferidas são derivadas da configuração; dependências vêm das regras do catálogo. " +
    "Acabamentos opcionais, como Verniz UV e BiAdesivo, só entram quando selecionados no componente. " +
    "As alternativas de impressão são escolhidas pelo plano, não são operações obrigatórias em conjunto.",
    "cb-note"));
operationsSection.before(operationsDetails);
operationsDetails.append(operationsSection);
for (const row of G.costing_operations) {
    const tableRow = operationsSection.querySelector('[data-operation-row="' + row.id + '"]');
    if (!tableRow) continue;
    const type = row.requirement_type || (row.discovered ? "inferred" : "selected");
    const status = r("small", " · " + (requirementLabels[type] || "Selecionada explicitamente") +
        (row.requirement_reason ? ": " + row.requirement_reason : ""), "cb-note");
    tableRow.cells[0].append(status);
}
I.querySelector('a[href="#cb-section-276"]').textContent = "Parâmetros das operações";
I.replaceChildren();
I.setAttribute("role", "tablist");
I.setAttribute("aria-label", "Secções do configurador");
const panel = () => {
    const node = r("section", undefined, "cb-tab-panel");
    node.setAttribute("role", "tabpanel");
    node.style.cssText = "padding-top:16px";
    return node;
};
const tabDefinitions = [
    ["Comparativo Planos Dinâmicos", panel()],
    ["Papel e matérias-primas", panel()],
    ["Simular impressão · Digital × Offset", panel()],
    ["Planos Possíveis", panel()],
    ["Escolhas guardadas", panel()]
];
let activeTab = 0;
const activateTab = index => {
    activeTab = index;
    for (const [position, [label, content, button]] of tabDefinitions.entries()) {
        const selected = position === index;
        button.setAttribute("aria-selected", String(selected));
        button.tabIndex = selected ? 0 : -1;
        content.hidden = !selected;
    }
};
for (const [index, [label, content]] of tabDefinitions.entries()) {
    const button = r("button", label, "btn " + (index === 0 ? "btn-primary" : "btn-default"));
    button.type = "button";
    button.id = "cb-tab-" + (index + 1);
    button.setAttribute("role", "tab");
    button.setAttribute("aria-controls", "cb-tab-panel-" + (index + 1));
    button.setAttribute("aria-selected", String(index === 0));
    button.tabIndex = index === 0 ? 0 : -1;
    content.id = "cb-tab-panel-" + (index + 1);
    content.setAttribute("aria-labelledby", button.id);
    button.style.cssText = "margin:0 6px 6px 0";
    button.addEventListener("click", () => activateTab(index));
    button.addEventListener("keydown", event => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? 0 : event.key === "End" ? tabDefinitions.length - 1 :
            (index + (event.key === "ArrowRight" ? 1 : tabDefinitions.length - 1)) % tabDefinitions.length;
        activateTab(next);
        tabDefinitions[next][2].focus();
    });
    tabDefinitions[index][2] = button;
    I.append(button);
}
const [comparisonTab, materialsTab, simulationTab, plansTab, savedTab] = tabDefinitions.map(item => item[1]);
const planSection = Z.querySelector(".cb-plan-generator");
const comparisonOutput = planSection?.querySelector("#cb-dynamic-plan-results");
const rubricTable = _.querySelector("#cb-section-280")?.parentElement;
materialsTab.append(_.querySelector("#cb-section-277"), operationsDetails,
    _.querySelector("#cb-section-278")?.parentElement, rubricTable);
simulationTab.append(Z);
if (planSection) plansTab.append(planSection);
if (comparisonOutput) comparisonTab.append(comparisonOutput);
comparisonTab.append(A);
savedTab.append(operationPanel.querySelector("#cb-final-cost-lines"));
activateTab(activeTab);
e.replaceChildren(fe, E, I, materialsTab, simulationTab, plansTab, comparisonTab,
    savedTab, operationPanel); })(); }().catch(Se); }();

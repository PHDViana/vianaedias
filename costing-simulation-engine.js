window.componentSimulation = (() => { const e = e => String(e || "").replace(/\s+/g, " ").trim(); function t(t) { if ("number" == typeof t)
    return Number.isFinite(t) ? t : null; const a = e(t).replace(/[€\s\u00a0]/g, ""); if (!/^-?\d+(?:[.,]\d+)*$/.test(a))
    return null; const r = a.lastIndexOf(",") > a.lastIndexOf(".") ? "," : ".", i = a.split(r), o = i.length > 1 ? i.slice(0, -1).join("").replace(/[.,]/g, "") + "." + i[i.length - 1] : a, n = Number(o); return Number.isFinite(n) ? n : null; } function a(e, t) { return e.filter(e => !["3647", "3655"].includes(String(e.status)) && String(e.machines || "").split(",").includes(String(t))); } function r(e) { return "3747" === String(e) ? 1 : ["3748", "3749", "3750"].includes(String(e)) ? 2 : null; } function i(e, t) { const a = e.map(e => String(e.color)); if (!a.length || a.some(e => !["3728", "3729", "3730", "3731", "3732", "3742", "3804", "3820", "3821"].includes(e)))
    return null; const r = a.filter(e => !["3804", "3821"].includes(e)).length; return r > 0 ? r * ("3748" === String(t) ? 2 : 1) : null; } function o(e, t = "") { const a = t => e.some(e => String(e.color) === t), r = a("3821"), i = a("3732"), o = r && i, n = e.some(e => !["3728", "3729", "3730", "3731", "3732", "3742", "3804", "3820", "3821"].includes(String(e.color))) ? ["Cor desconhecida: rever classificação."] : []; return o && a("3820") && !["offset", "digital"].includes(t) && n.push("Seleciona o processo dos metálicos."), { white: r, pantone: i, mixed: o, errors: n, allowDigital: !i, allowOffset: !r || o, offsetColors: e.filter(e => "3821" !== String(e.color) && (!o || "3820" !== String(e.color) || "offset" === t)), digitalColors: e.filter(e => !o || "3821" === String(e.color) || "3820" === String(e.color) && "digital" === t) }; } function n(e, a, r) { if (!r)
    return { tariff: e, errors: [] }; const i = t(a?.white_click); return { tariff: { ...e, clickPrice: null !== i && i >= 0 && null !== e.clickPrice ? e.clickPrice + i : null }, errors: null === i || i < 0 ? ["Clique branco em falta no equipamento Digital."] : [] }; } function s(e, a) {
if (a?.ctpResources) {
    const speed = t(a.speed), setup = t(a.setup_minutes);
    const hours = speed > 0 && setup !== null && setup >= 0 ? e / speed + setup / 60 : null;
    const execution = window.vdOperationCosting.ctpExecution(a.ctpResources, e, hours);
    if (["3647", "3655"].includes(String(a.status))) execution.errors.push("CTP: operação Obsoleta ou Anulada.");
    return { hours, total: execution.errors.length ? null : execution.cost, errors: execution.errors, execution };
}
const r = []; a && !["3647", "3655"].includes(String(a.status)) || r.push("Produção de Chapas: operação #3 inexistente, Obsoleta ou Anulada."); const i = t(a?.speed), o = t(a?.hourly), n = t(a?.setup_minutes); i > 0 || r.push("Produção de Chapas: produtividade chapas/h em falta."), o > 0 || r.push("Produção de Chapas: custo de referência €/h em falta."), (null === n || n < 0) && r.push("Produção de Chapas: tempo de acerto em falta (zero deve ser explícito)."); const s = Number.isSafeInteger(e) && e > 0 && i > 0 && null !== n && n >= 0 ? e / i + n / 60 : null, l = r.length || null === s ? null : s * o; return null === l || Number.isFinite(l) || r.push("Produção de Chapas: limite numérico excedido."), { hours: s, total: r.length ? null : l, errors: r }; } function l(e, a, r) { const i = []; function o(e, a, r) { const o = t(a), n = t(r); o !== n && i.push(e + ": " + (null === o ? "sem preço" : String(o)) + " → " + (null === n ? "sem preço" : String(n)) + " €"); } for (const t of [e.tariff?.operationSource, e.plateProduction?.operation].filter(Boolean)) {
    const e = a.price_operations.find(e => String(e.id) === String(t.id));
    if (e) {
        for (const [a, r] of [["hourly", "venda €/h"], ["cost_hourly", "custo €/h"], ["click_price", "€/clique"]])
            o("Operação #" + t.id + " · " + r, t[a], e[a]);
        ["3647", "3655"].includes(String(e.status)) && i.push("Operação #" + t.id + " agora Obsoleta/Anulada.");
    }
    else
        i.push("Operação #" + t.id + " já não disponível.");
} const n = e.paperCalculation?.paper; if (n) {
    const r = n.id ? a.papers.find(e => String(e.id) === String(n.id)) : a.papers.filter(a => (e.paperFamily ? d(a.internal_name) === e.paperFamily && t(a.weight) === e.paperWeight : Number(a.internal_id) === Number(e.paperId)) && 3648 === Number(a.unit) && y({ sheet_width: a.width, sheet_height: a.height }) === y({ sheet_width: n.width, sheet_height: n.height })).sort((e, a) => (Number(a.purchase_date) || 0) - (Number(e.purchase_date) || 0) || (Number(a.purchase_id) || 0) - (Number(e.purchase_id) || 0))[0];
    o(n.id ? "Papel #" + n.id : "Papel no formato guardado", n.price, r?.price);
} e.plate?.materialId && o("Chapas #" + e.plate.materialId, e.plate.unitPrice, a.plate_prices.find(t => Number(t.id) === Number(e.plate.materialId))?.price); for (const t of e.materialPrices || [])
    o("Material #" + t.id, t.price, r[t.id]?.latestPrice); return void 0 !== e.whiteClick && o("Clique branco", e.whiteClick, a.machines.find(e => 56 === Number(e.id))?.white_click), e.mixed && i.push(...l({ tariff: e.mixed.digital.tariff, whiteClick: e.mixed.digital.whiteClick }, a, r)), i; } function p(e, t) { const a = e => !e.errors.length && Number.isFinite(e.total); return Number(a(t)) - Number(a(e)) || (a(e) && a(t) ? Math.round(100 * e.total) - Math.round(100 * t.total) || (3748 === Number(t.modeId)) - (3748 === Number(e.modeId)) || e.total - t.total : 0); } function c(e, t) { return e.filter(e => e.quantityId === t && !e.errors.length && Number.isFinite(e.total)).sort(p); } function u(e, a) { const i = t(e.pages_per_face), o = t(e.imposition_copies), n = r(a), s = [3749, 3750].includes(Number(a)); return ![i, o].every(e => Number.isSafeInteger(e) && e > 0) || !n || s && i % 2 ? null : { plan: e, positions: i * o, copies: o * (s ? 2 : 1), passes: n, pages: s ? i : i * n }; } function d(t) { return e(t).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/\b\d+(?:[.,]\d+)?\s*[x×]\s*\d+(?:[.,]\d+)?\s*(?:mm|cm)?\b/g, " ").replace(/\b\d+(?:[.,]\d+)?\s*(?:g\/m[²2]|gsm|gr|g)\b/g, " ").replace(/\s+/g, " ").trim(); } function m(e, a) { const r = e.paper_sources.filter(e => String(e.internal_id) === String(a)), i = new Set(r.map(e => d(e.internal_name)).filter(Boolean)), o = new Set(r.map(e => t(e.weight))); if (1 !== i.size || 1 !== o.size || !([...o][0] > 0))
    return { papers: [], errors: ["Família/gramagem do papel ambígua ou em falta: rever catálogo."] }; const n = [...i][0], s = [...o][0]; return { family: n, weight: s, errors: [], papers: e.papers.filter(e => d(e.internal_name) === n && t(e.weight) === s) }; } function h(e, a, r) { const i = { 3749: "1553", 3750: "1554" }[a]; if (!i)
    return null; if (!String(e.validated_turns || "").split(",").includes(i))
    return "Viragem não validada"; const o = t(r ? e.pages_per_face : e.poses); return !Number.isSafeInteger(o) || o <= 0 || o % 2 ? "Posições por face devem ser pares" : null; } function f(e, a, r) { if (!a?.id || !Number.isFinite(r))
    return { available: null, shortage: null, ids: [], reason: "Papel/formato por identificar" }; if (!(t(a.weight) > 0 && a.internal_id))
    return { available: null, shortage: null, ids: [], reason: "Gramagem/papel em falta" }; const i = [...new Map(e.filter(e => (a.family ? d(e.internal_name) === a.family : String(e.internal_id) === String(a.internal_id)) && t(e.weight) === t(a.weight) && 3648 === Number(e.unit) && y({ sheet_width: e.width, sheet_height: e.height }) === y({ sheet_width: a.width, sheet_height: a.height })).map(e => [String(e.id), e])).values()], o = i.filter(e => null === t(e.available_stock) && "0" !== String(e.stock_records) || Number(e.invalid_stock) > 0); if (!i.length || o.length)
    return { available: null, shortage: null, ids: i.map(e => e.id), reason: "Stock por confirmar: dados inválidos" }; const n = i.reduce((e, a) => e + ("0" === String(a.stock_records) ? 0 : t(a.available_stock)), 0); if (!Number.isFinite(n))
    throw new Error("Stock: limite numérico excedido."); return { available: n, shortage: Math.max(0, r - n), ids: i.map(e => e.id), required: r, withoutMovements: i.filter(e => "0" === String(e.stock_records)).map(e => e.id) }; } function g({ jobs: e, quantity: a, machine: r, papers: i, waste: o, tariff: n, platePrice: l, plateOperation: p, platesPerForm: c, component: u }) { const d = e.map(e => { const s = w({ quantity: a, forms: e.forms, plan: e.plan, machine: r, papers: i, waste: o, component: u, copiesPerPlan: e.copies, positionsPerPlan: e.positions }), d = s.errors.length ? { errors: s.errors } : S({ quantity: a, forms: e.forms, platesPerForm: c, passes: e.passes, plan: { width: t(e.plan.width), height: t(e.plan.height), poses: e.copies, perSheet: t(e.plan.per_sheet) }, machine: { id: Number(r.id), minWidth: t(r.min_width), minHeight: t(r.min_height), maxWidth: t(r.max_width), maxHeight: t(r.max_height) }, paperPrice: s.paper?.price ?? null, platePrice: l, tariff: n, paperCalculation: s, plateOperation: p ? { ...p, setup_minutes: 0 } : null }); return { ...e, paperCalculation: s, result: d }; }), m = d.flatMap(e => e.paperCalculation.errors), h = d.flatMap(e => e.result.errors); if (m.length)
    return { parts: d, paperCalculation: { errors: m }, result: { errors: h } }; const f = (e, t) => e.every(e => Number.isFinite(e[t])) ? e.reduce((e, a) => e + a[t], 0) : null, g = d.map(e => e.paperCalculation), b = d.map(e => e.result), v = { ...g[0], errors: [], missing: [...new Set(g.flatMap(e => e.missing))] }; for (const e of ["goodPlans", "wastePlans", "sheets", "usefulSheets", "setupSheets", "sparePlans", "sheetArea"])
    v[e] = f(g, e); for (const e of ["cutWaste", "productWaste", "totalWaste"])
    v[e] = g.every(t => Number.isFinite(t[e])) ? g.reduce((t, a) => t + a[e] * a.sheetArea, 0) / v.sheetArea : null; v.perSheet = 1 === new Set(g.map(e => e.perSheet)).size ? g[0].perSheet : "misto"; const y = { errors: h }; for (const e of ["goodPlans", "wastePlans", "sheets", "plates", "impressions", "hours", "paper", "plate", "printing", "machineCost", "clicks", "click"])
    y[e] = f(b, e); const N = 56 === Number(r.id) ? { hours: 0, total: 0, errors: [] } : s(y.plates, p); if (y.errors = [...new Set([...h, ...N.errors])], y.plateProduction = N.total, y.plateProductionHours = N.hours, y.total = ["paper", "plate", "printing", "click", "plateProduction"].every(e => Number.isFinite(y[e])) ? y.paper + y.plate + y.printing + y.click + y.plateProduction : null, y.unit = null === y.total ? null : y.total / a, Object.entries(y).some(([e, t]) => "errors" !== e && null !== t && !Number.isFinite(t)))
    throw new Error("Paginação: limite numérico excedido."); return { parts: d, paperCalculation: v, result: y }; } function b(e, r, i) { const o = a(e.operations, r).find(e => String(e.id) === String(i)), n = { hourly: t(o?.hourly), costHourly: t(o?.cost_hourly), speed: t(o?.speed), setupMinutes: t(o?.setup_minutes), waste: t(o?.waste), clickPrice: 56 === r ? t(o?.click_price) : null }, s = []; o || s.push("Seleciona uma operação de impressão associada à máquina (entidade 36)."); for (const [e, t, a, i, o] of [["hourly", "Custo de referência €/h", "Custo unitário de referência", !1, !1], ["costHourly", "Custo €/h", "Custo horário", !0, !1], ["speed", 56 === r ? "Faces/h" : "Passagens/h", "Produtividade Média", !1, !1], ["setupMinutes", "Acerto min/forma", "Tempo Total", !0, !1], ["waste", "Acerto planos/forma", "Planos de Acerto", !0, !0], ...56 === r ? [["clickPrice", "€/face", "Preço por clique / face", !0, !1]] : []]) {
    const r = n[e];
    (!Number.isFinite(r) || (i ? r < 0 : r <= 0) || o && !Number.isSafeInteger(r)) && s.push(t + " inválido/em falta — Operação: " + a + ".");
} return { values: n, missing: s, operation: o || null, provisional: k(o?.provisional) }; } function w({ quantity: e, forms: a, plan: r, machine: i, papers: o, waste: n, component: s, copiesPerPlan: l = t(r.poses), positionsPerPlan: p = t(r.poses) }) {
const setupWaste = window.vdOperationCosting.setupWaste(s?.setupWasteOperations || [], i?.id, n);
n = setupWaste.plans;
const c = [...setupWaste.errors], u = t(r.per_sheet), d = t(r.sheet_width), m = t(r.sheet_height); for (const [i, o] of [["Quantidade", e], ["Formas/cadernos por exemplar", a], ["Unidades por plano no catálogo", t(r.poses)], ["Cópias por plano", l], ["Posições por plano", p], ["Planos por folha no catálogo", u]])
    (!Number.isSafeInteger(o) || o <= 0) && c.push(i + ": indica um inteiro positivo."); (!Number.isSafeInteger(n) || n < 0) && c.push("Acerto planos/forma em falta na operação: indica zero ou um inteiro positivo."); const h = t(r.width), f = t(r.height), g = [i.min_width, i.min_height, i.max_width, i.max_height].map(t); if ([h, f, d, m, ...g].every(e => Number.isFinite(e) && e > 0) ? h < g[0] || f < g[1] || h > g[2] || f > g[3] ? c.push("Plano fora dos limites da máquina.") : u * h * f > d * m + .001 && c.push("Planos por folha incompatíveis com a área da folha: verifica o catálogo.") : c.push("Dimensões da folha/plano ou limites da máquina em falta."), c.length)
    return { errors: c }; const b = Math.ceil(e / l), w = b * a, v = n * a, S = Math.ceil(b / u) * a, y = Math.ceil((b + n) / u) * a, N = y * u, x = N - w - v, P = d * m, I = y * P, _ = 1 - u * h * f / P, C = s ? t(s.width) * t(s.height) : t(r.component_width) * t(r.component_height), M = Number.isFinite(C) && C > 0 ? C : null; if (u * p * t(r.component_width) * t(r.component_height) > P + .001)
    return { errors: ["Páginas × cópias excedem a folha: verifica o plano."] }; const k = null === M ? null : 1 - u * p * M / P, F = null === M ? null : 1 - e * a * p / l * M / I; if (![w, v, y, S, N, x].every(Number.isSafeInteger) || ![I, _].every(Number.isFinite))
    return { errors: ["Papel: limite numérico excedido."] }; if (null !== k && (!Number.isFinite(k) || k < -1e-6 || !Number.isFinite(F) || F < -1e-6))
    return { errors: ["Poses/dimensões excedem a folha: verifica o catálogo."] }; const q = o.filter(e => 3648 === Number(e.unit) && (t(e.width) === d && t(e.height) === m || t(e.width) === m && t(e.height) === d)); q.sort((e, a) => (Number(a.purchase_date) || 0) - (Number(e.purchase_date) || 0) || (Number(a.purchase_id) || 0) - (Number(e.purchase_id) || 0) || Number(e.id) - Number(a.id)); const E = q[0], A = E ? { ...E, width: d, height: m, price: t(E.price) } : { id: null, width: d, height: m, price: null }; return { errors: [], missing: E ? [] : ["Preço do papel no formato sugerido " + d + " × " + m + " mm por preencher; não se aplica o preço de outra dimensão."], paper: A, setupWaste, perSheet: u, goodPlans: w, wastePlans: v, sheets: y, usefulSheets: S, setupSheets: y - S, sparePlans: x, cutWaste: _, productWaste: k, totalWaste: F, sheetArea: I }; } function v(e, t) { if (![e, t].every(e => Number.isFinite(e) && e > 0))
    return null; const a = Math.max(e, t); return a <= 487 ? 1 : a <= 700 ? 2 : a <= 920 ? 3 : a <= 1250 ? 4 : null; } function S({ quantity: e, forms: t, platesPerForm: a, passes: r, plan: i, machine: o, paperPrice: n, platePrice: l, tariff: p, paperCalculation: c, plateOperation: u }) { const d = [], m = 56 === o.id; for (const [a, o] of [["Quantidade", e], ["Formas/cadernos", t], ["Passagens por plano", r], ["Unidades por plano", i.poses], ["Planos por folha", i.perSheet]])
    (!Number.isSafeInteger(o) || o <= 0) && d.push(a + ": indica um inteiro positivo."); m || Number.isSafeInteger(a) && !(a <= 0) || d.push("Chapas por forma: indica um inteiro positivo."), m && ![1, 2].includes(r) && d.push("Digital: indica uma ou duas faces por plano."); for (const [e, t] of [["Preço da folha", n], ["Tarifa horária", p.hourly], ["Produtividade/h", p.speed]])
    (!Number.isFinite(t) || t <= 0) && d.push(e + ": valor em falta ou inválido."); m || Number.isFinite(l) && !(l <= 0) || d.push("Preço da chapa: valor em falta ou inválido."), m && (!Number.isFinite(p.clickPrice) || p.clickPrice < 0) && d.push("Preço por clique/face: valor em falta ou inválido (zero deve ser explícito)."), (!Number.isSafeInteger(p.waste) || p.waste < 0) && d.push("Planos de acerto: indica zero ou um inteiro positivo."), (!Number.isFinite(p.setupMinutes) || p.setupMinutes < 0) && d.push("Tempo de acerto: valor em falta ou inválido."), [i.width, i.height, o.minWidth, o.minHeight, o.maxWidth, o.maxHeight].every(e => Number.isFinite(e) && e > 0) ? (i.width < o.minWidth || i.height < o.minHeight || i.width > o.maxWidth || i.height > o.maxHeight) && d.push("Plano fora dos limites da máquina (sem rotação).") : d.push("Dimensões/limites de impressão em falta."); const h = e => Number.isFinite(e) && e > 0, f = e => Number.isFinite(e) && e >= 0, g = e => Number.isSafeInteger(e) && e > 0, { goodPlans: b, wastePlans: w, sheets: S } = c, y = m ? 0 : g(a) && g(t) ? a * t : null, N = Number.isSafeInteger(b) && Number.isSafeInteger(w) && g(r) && (!m || [1, 2].includes(r)) ? (b + w) * r : null, x = null !== N && h(p.speed) && f(p.setupMinutes) && g(t) ? N / p.speed + p.setupMinutes * t / 60 : null, P = Number.isSafeInteger(S) && h(n) ? S * n : null, I = m ? 0 : null !== y && h(l) ? y * l : null, _ = null !== x && h(p.hourly) ? x * p.hourly : null, C = m ? v(i.width, i.height) : 0; m && null === C && d.push("Digital: plano fora dos escalões de clique (máximo 1250 mm)."); const M = m ? null !== N && null !== C ? N * C : null : 0, k = m ? null !== M && f(p.clickPrice) ? M * p.clickPrice : null : 0, F = null !== x && f(p.costHourly) ? x * p.costHourly : null, q = m ? { hours: 0, total: 0, errors: [] } : s(y, u); d.push(...q.errors); const E = [P, I, _, k, q.total].every(e => null !== e) ? P + I + _ + k + q.total : null, A = { goodPlans: b, wastePlans: w, sheets: S, plates: y, impressions: N, hours: x, paper: P, plate: I, printing: _, machineCost: F, clickFactor: C, clicks: M, click: k, total: E, plateProduction: q.total, plateProductionHours: q.hours, unit: null !== E && g(e) ? E / e : null }; for (const [e, t] of Object.entries(A))
    null == t || Number.isFinite(t) && (!["sheets", "plates", "impressions", "clicks"].includes(e) || Number.isSafeInteger(t)) || (A[e] = null, d.push("Limite numérico excedido: " + e + ".")); return null !== A.total || d.length || d.push("Cálculo incompleto."), { ...A, errors: d }; } function y(e) { const a = [t(e.sheet_width), t(e.sheet_height)]; return a.every(e => null !== e && e > 0) ? a.sort((e, t) => e - t).join("x") : ""; } function N(e, a) { const r = [e.component_width, e.component_height, a.width, a.height].map(t); if (!r.every(e => e > 0))
    return null; const i = r[0] - r[2], o = r[1] - r[3], n = 0 === i && 0 === o; return i < 0 || o < 0 || i > 5 || o > 5 || a.hasDie && !n ? null : { width: i, height: o, exact: n }; } function x(e, t, a, r = "") { return [1255, 1256, 1257].includes(Number(t.faces)) ? e.filter(e => Number(e.machine_id) === Number(a) && N(e, t) && k(e.die) === t.hasDie && (!r || y(e) === r)) : []; } function P(e, t, a, r, i = !1) { const o = new Map, n = e.length; if (!Number.isSafeInteger(t) || t <= 0 || t > 500)
    throw new Error("Rubricas: capacidade do plano inválida ou superior a 500 posições."); for (let s = 0; s < n; s++)
    if (e[s])
        for (let l = a; l <= t; l += a) {
            const p = Math.ceil(e[s] / l);
            for (let c = -1; c < n; c++) {
                const u = Array(n).fill(0);
                u[s] = l;
                let d = t - l;
                if (c >= 0 && !r[s])
                    for (let t = 0; t < n; t++) {
                        const i = (c + t) % n;
                        if (i === s || r[i] || !e[i])
                            continue;
                        const o = Math.min(Math.floor(d / a) * a, Math.floor(e[i] / p / a) * a);
                        u[i] = o, d -= o;
                    }
                const m = u.map(e => e * p);
                if (!m.every(Number.isSafeInteger))
                    throw new Error("Rubricas: limite numérico excedido.");
                if (o.set(p + ":" + u.join(","), { runs: p, allocation: u, produced: m, remaining: e.map((e, t) => Math.max(0, e - m[t])) }), i && c >= 0 && !r[s]) {
                    const i = Array(n).fill(0);
                    i[s] = l;
                    let u = t - l;
                    for (let t = 0; t < n; t++) {
                        const l = (c + t) % n;
                        if (l === s || r[l] || !e[l])
                            continue;
                        const d = Math.ceil(e[l] / p / a) * a;
                        if (d > u)
                            continue;
                        i[l] = d, u -= d;
                        const m = Math.max(...i.map((t, a) => t ? Math.ceil(e[a] / t) : 0)), h = i.map(e => e * m);
                        if (!h.every(Number.isSafeInteger))
                            throw new Error("Rubricas: limite numérico excedido.");
                        o.set(m + ":" + i.join(","), { runs: m, allocation: [...i], produced: h, remaining: e.map((e, t) => Math.max(0, e - h[t])) });
                    }
                }
            }
        } return [...o.values()]; } function I(e, t) { const a = _(e, t, !1), r = _(e, t, !0), i = a.solutions[0], o = r.solutions[0]; return { ...o && (!i || o.cost < i.cost - 1e-6) ? r : a, visits: (a.visits || 0) + (r.visits || 0), excessRule: "cost-driven-no-percentage-cap" }; } function _(e, a, r) { if (!e.length || e.length > 8)
    return { errors: ["A pesquisa de rubricas suporta entre 1 e 8 artes distintas."], solutions: [] }; const i = e.map(e => t(e.quantity)); if (!i.every(e => Number.isSafeInteger(e) && e > 0) || !Number.isSafeInteger(i.reduce((e, t) => e + t, 0)))
    return { errors: ["Todas as rubricas precisam de quantidades inteiras positivas, dentro do limite numérico."], solutions: [] }; if (!a.length)
    return { errors: ["Sem plano/operação com preços completos para estas rubricas."], solutions: [] }; const o = e.map(e => Boolean(e.isolated)), n = new Map, s = new Set; let l = [{ remaining: i, cost: 0, forms: [] }], p = null, c = 0, u = !1; e: for (let t = 0; t < e.length && l.length; t++) {
    const e = new Map;
    for (const i of l)
        for (let l = 0; l < a.length; l++) {
            const d = a[l];
            for (const a of P(i.remaining, d.capacity, d.step, o, r)) {
                if (++c > 75e3) {
                    u = !0;
                    break e;
                }
                const r = a.allocation.reduce((e, t) => e + t, 0), o = l + ":" + a.runs + ":" + r + ":" + (0 === t);
                n.has(o) || n.set(o, d.calculate(a.runs, r, 0 === t));
                const m = n.get(o);
                if (m.result.errors.length || !Number.isFinite(m.result.total)) {
                    m.result.errors.forEach(e => s.add(e));
                    continue;
                }
                const h = i.cost + m.result.total;
                if (!Number.isFinite(h))
                    throw new Error("Rubricas: custo excede o limite numérico.");
                if (p && h > p.cost + 1e-6)
                    continue;
                const f = { remaining: a.remaining, cost: h, forms: [...i.forms, { optionIndex: l, ...a, ...m }] };
                if (a.remaining.every(e => 0 === e)) {
                    (!p || h < p.cost - 1e-6 || Math.abs(h - p.cost) < 1e-6 && f.forms.length < p.forms.length) && (p = f);
                    continue;
                }
                const g = a.remaining.join(",");
                (!e.has(g) || h < e.get(g).cost) && e.set(g, f);
            }
        }
    l = [...e.values()].sort((e, t) => { const a = e => e.cost / Math.max(1, i.reduce((t, a, r) => t + a - e.remaining[r], 0)); return a(e) - a(t) || e.cost - t.cost; }).slice(0, 24);
} return { errors: p ? [] : u ? ["Limite de pesquisa atingido sem solução completa: reduz rubricas/planos."] : [...s].length ? [...s] : ["Não foi encontrada uma composição completa."], solutions: p ? [p] : [], limited: !0, stopped: u, visits: c }; } function C({ data: e, machine: o, component: s, papers: l, format: p, mode: c, faces: u, colors: d, manualPlates: m, plateOperation: f }) { const g = 56 === Number(o.id), v = [], y = [], N = g ? [2 === u ? "3748" : "3747"] : "auto" === c ? 2 === u ? ["3748", "3749", "3750"] : ["3747"] : [c]; for (const c of a(e.operations, Number(o.id))) {
    const a = b(e, Number(o.id), c.id);
    if (a.missing.length) {
        y.push(...a.missing);
        continue;
    }
    let P = { ...a.values };
    if (g && d.white) {
        const e = n(P, o, !0);
        if (P = e.tariff, e.errors.length) {
            y.push(...e.errors);
            continue;
        }
    }
    for (const n of x(e.plans, s, o.id, p))
        for (const p of N) {
            if (!g && h(n, p, !1))
                continue;
            const b = t(n.poses), N = !g && ["3749", "3750"].includes(p) ? 2 : 1;
            if (!Number.isSafeInteger(b) || b <= 0 || b > 500) {
                y.push("Plano #" + n.id + ": capacidade inválida ou superior a 500 posições.");
                continue;
            }
            const x = g ? null : 5 === Number(o.id) ? 985 : 984, I = g ? null : t(e.plate_prices.find(e => Number(e.id) === x)?.price), _ = g ? 0 : void 0 === m ? i(d.offsetColors, p) : m, C = g ? u : r(p), M = (e, a, r = !0) => { const i = g && !r ? { ...P, waste: 0, setupMinutes: 0 } : P, p = w({ quantity: e * a, forms: 1, plan: { ...n, poses: a }, machine: o, papers: l, waste: i.waste, component: r ? s : { ...s, setupWasteOperations: [] } }); return { paperCalculation: p, result: p.errors.length ? { errors: p.errors } : S({ quantity: e * a, forms: 1, platesPerForm: _, passes: C, plan: { width: t(n.width), height: t(n.height), poses: a, perSheet: t(n.per_sheet) }, machine: { id: Number(o.id), minWidth: t(o.min_width), minHeight: t(o.min_height), maxWidth: t(o.max_width), maxHeight: t(o.max_height) }, paperPrice: p.paper?.price ?? null, paperCalculation: p, platePrice: I, tariff: i, plateOperation: f ? { ...f, setup_minutes: 0 } : null }), setupApplied: !g || r }; }, k = M(1, N);
            k.result.errors.length ? y.push(...k.result.errors) : v.push({ plan: n, machine: o, operation: c, settings: a, tariff: P, modeId: p, capacity: b, step: N, calculate: M });
        }
} return { options: v, errors: [...new Set(y)] }; } function M(e, a, r, i, o) { const n = o(["Máquina", "Imposição / plano / modo", ...a.map(e => "#" + e.id + " · " + e.name + " · " + e.quantity + " pedidas"), "Planos úteis + acerto", "Folhas", "Chapas"]); n.node.className = "cb-rubric-combinations"; for (const t of e)
    t.parts.forEach((e, a) => { const o = i("tr"), s = e.paperCalculation; o.append(i("td", t.machine.name), i("td", a + 1 + " · plano #" + e.plan.id + " · " + r[e.modeId])), e.allocation.forEach((t, a) => o.append(i("td", t ? t + " posições → " + e.produced[a] + " un." : "—"))); for (const t of [e.runs + " + " + s.wastePlans, s.sheets + " de " + s.paper.width + " × " + s.paper.height + " mm", e.result.plates])
        o.append(i("td", String(t))); n.body.append(o); }); for (const r of e) {
    const e = i("tr");
    e.append(i("th", r.machine.name), i("th", "Total produzido / excedente")), a.forEach((a, o) => { const n = r.parts.reduce((e, t) => e + t.produced[o], 0), s = t(a.quantity); if (n < s)
        throw new Error("Rubrica #" + a.id + ": produção inferior ao pedido."); e.append(i("td", n + " un. / +" + (n - s) + " un.")); });
    for (let t = 0; t < 3; t++)
        e.append(i("td", ""));
    n.body.append(e);
} const s = i("div", void 0, "cb-scroll"); s.append(n.node); const l = i("div"), p = i("div", void 0, "cb-rubric-graphics"); p.style.cssText = "display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px"; for (const t of e)
    t.parts.forEach((e, o) => { const n = e.plan.suggestion; if (!n)
        return; const s = z(n, e.allocation, a), l = i("article"); l.style.cssText = "border:1px solid #c8d0d8;padding:12px", l.append(i("h4", t.machine.name + (t.digitalSeparate ? " · tiragem " : " · montagem ") + (o + 1)), V({ ...n, machineName: t.machine.name }, t.component || { width: e.plan.component_width, height: e.plan.component_height }, r[e.modeId], i, s), i("p", e.runs + " planos úteis + " + e.paperCalculation.wastePlans + " de acerto · " + e.result.sheets + " folhas · " + e.result.plates + " chapas")), a.forEach((t, a) => { if (!e.allocation[a])
        return; const r = i("p", j(t) + " · " + t.name + " · " + e.allocation[a] + " posições → " + e.produced[a] + " un. (pedido " + t.quantity + ")"); r.style.borderLeft = "12px solid " + D(a), r.style.paddingLeft = "6px", l.append(r); }), p.append(l); }); if (p.children.length) {
    const e = i("details");
    e.append(i("summary", "Quantidades totais e excedentes · tabela"), s), l.append(i("p", "Distribuição esquemática por rubrica; nas viragens, pares simétricos representam as duas faces. Não substitui a validação técnica da arte e do registo.", "cb-note"), p, e);
}
else
    l.append(s); return l; } function k(e) { return !0 === e || 1 === e || "1" === e || "true" === e; } function F(e, a) { const r = e => null == e || "" === String(e).trim() ? null : t(e), i = r(e), o = r(a), n = []; for (const [t, r, s] of [["Mínimo", e, i], ["Máximo", a, o]])
    null != r && "" !== String(r).trim() && (!Number.isSafeInteger(s) || s <= 0) && n.push(t + ": indica um inteiro positivo ou deixa vazio."); return null !== i && null !== o && i > o && n.push("Mínimo de exemplares por plano superior ao máximo."), { min: i, max: o, errors: n }; } function q(e, a, r, i = { min: null, max: null, errors: [] }) { const o = [], n = []; if (i.errors.length)
    return { plans: n, errors: i.errors }; const s = t(e.width), l = t(e.height), p = t(e.bleed), c = t(e.spacing), u = t(a.gripper), [d, m, h, f, g, b] = [a.min_width, a.min_height, a.max_width, a.max_height, a.print_width, a.print_height].map(t); (e.hasDie || k(e.has_die) || [1248, 1249].includes(Number(e.die_status)) || ["1545", "1546", "1547", "1548"].includes(String(e.binding))) && o.push("Gerador inicial: apenas peças retangulares sem cortante nem paginação."), [s, l].every(e => Number.isFinite(e) && e > 0) || o.push("Dimensões do componente inválidas."), [p, c].every(e => Number.isFinite(e) && e >= 0) || o.push("Confirma sangria exterior e espaçamento no componente; zero deve ser explícito."), (![d, m, h, f, g, b].every(e => Number.isFinite(e) && e > 0) || !Number.isFinite(u) || u < 0 || d > h || m > f || g > h || b > f || u >= f) && o.push("Limites, área imprimível ou pinça da máquina inválidos/em falta."); const w = [1255, 1257].includes(Number(e.faces)) ? 1 : 1256 === Number(e.faces) ? 2 : null; if (null === w && o.push("Confirma as faces de impressão do componente."), o.length)
    return { plans: n, errors: o }; const v = k(e.reduce_internal) ? c : Math.max(c, 2 * p), S = new Map; for (const e of r) {
    if (3648 !== Number(e.unit))
        continue;
    const a = t(e.width), r = t(e.height);
    a > 0 && r > 0 ? S.set(y({ sheet_width: a, sheet_height: r }), [a, r]) : o.push("Material #" + e.id + ": formato de folha inválido.");
} if (!S.size)
    return { plans: n, errors: [...o, "Sem formato real de papel em folhas nesta família/gramagem."] }; const N = new Set, x = 56 === Number(a.id) ? [2 === w ? "3748" : "3747"] : 2 === w ? ["3748", "3749", "3750"] : ["3747"]; for (const [e, t] of S.values())
    for (const [r, c] of [[e, t], [t, e]]) {
        const e = Math.floor(r / d), t = Math.floor(c / m);
        if (e > 64 || t > 64)
            o.push("Formato excede o limite de 64 divisões por eixo.");
        else
            for (let w = 1; w <= e; w++)
                for (let e = 1; e <= t; e++) {
                    const t = Math.min(h, Math.floor(r / w * 1e3) / 1e3), S = Math.min(f, Math.floor(c / e * 1e3) / 1e3);
                    if (!(t < d || S < m || t > h || S > f) && (56 !== Number(a.id) || t >= 420 && S >= 297 || t >= 297 && S >= 420))
                        for (const d of x)
                            for (const m of [!1, !0]) {
                                const h = m ? l : s, f = m ? s : l, y = "3750" === d ? 2 : 1, x = Math.min(t, g), P = Math.min(S - y * u, b);
                                let I = Math.floor((x - 2 * p + v + 1e-8) / (h + v)), _ = Math.floor((P - 2 * p + v + 1e-8) / (f + v));
                                if ("3749" === d && (I -= I % 2), "3750" === d && (_ -= _ % 2), I <= 0 || _ <= 0)
                                    continue;
                                const C = I * h + (I - 1) * v + 2 * p, M = _ * f + (_ - 1) * v + 2 * p;
                                if (C > x + 1e-7 || M > P + 1e-7)
                                    throw new Error("Gerador: imposição excede a área disponível.");
                                const k = I * _, F = w * e;
                                if (!Number.isSafeInteger(k) || !Number.isSafeInteger(k * F))
                                    throw new Error("Gerador: capacidade excede o limite numérico.");
                                if (k > 500) {
                                    o.push("Sugestões com mais de 500 posições não são suportadas nesta fase.");
                                    continue;
                                }
                                if (null !== i.min && k < i.min || null !== i.max && k > i.max)
                                    continue;
                                const q = [r, c, t, S, w, e, d, m].join(":");
                                N.has(q) || (N.add(q), n.push({ machineId: a.id, sheetWidth: r, sheetHeight: c, planWidth: t, planHeight: S, cutsX: w, cutsY: e, trimWidth: r - w * t, trimHeight: c - e * S, columns: I, rows: _, poses: k, perSheet: F, modeId: d, rotated: m, gap: v, bleed: p, usableWidth: x, usableHeight: P, occupiedWidth: C, occupiedHeight: M, reservedGrippers: y, gripper: u, waste: 1 - k * F * s * l / (r * c), validation: "pending" }));
                            }
                }
    } return { plans: n.sort((e, t) => t.poses - e.poses || e.waste - t.waste || e.perSheet - t.perSheet), errors: [...new Set(o)] }; } function E(e) { return e.filter(t => !e.some(e => { if (e === t || String(e.machineId) !== String(t.machineId) || e.modeId !== t.modeId || y({ sheet_width: e.sheetWidth, sheet_height: e.sheetHeight }) !== y({ sheet_width: t.sheetWidth, sheet_height: t.sheetHeight }))
    return !1; const a = t.poses * t.perSheet, r = e.poses * e.perSheet; return !(56 === Number(t.machineId) && v(e.planWidth, e.planHeight) > v(t.planWidth, t.planHeight)) && e.poses >= t.poses && r >= a && (e.poses > t.poses || r > a); })); } function A(e) { return [e.machineId, e.sheetWidth, e.sheetHeight, e.planWidth, e.planHeight, e.cutsX, e.cutsY, e.modeId, e.rotated].join(":"); } function H(e, t) { const a = new Set, r = new Set; for (const i of e) {
    if (3648 !== Number(i.unit))
        continue;
    const e = y({ sheet_width: i.width, sheet_height: i.height });
    e && (a.add(e), String(i.internal_id) === String(t) && r.add(e));
} return { formats: [...a].sort((e, t) => e.localeCompare(t)), preferred: 1 === r.size ? [...r] : [] }; } function W(e, t) { const a = new Set(t); if (a.size < 1 || a.size > 3)
    return { papers: [], errors: ["Seleciona entre 1 e 3 formatos de papel para gerar planos."] }; const r = e.filter(e => 3648 === Number(e.unit) && a.has(y({ sheet_width: e.width, sheet_height: e.height }))), i = new Set(r.map(e => y({ sheet_width: e.width, sheet_height: e.height }))); return [...a].some(e => !i.has(e)) ? { papers: [], errors: ["Formato selecionado indisponível na família/gramagem atual."] } : { papers: r, errors: [] }; } function T(e) { const t = e => ({ 56: 0, 5: 1, 6: 2 }[Number(e)] ?? 3), a = [...e].sort((e, a) => t(e.machineId) - t(a.machineId) || a.poses - e.poses || e.waste - a.waste || Number(e.modeId) - Number(a.modeId)), r = new Set, i = new Set; for (const e of a)
    i.has(e.machineId) || (r.add(e), i.add(e.machineId)); return a.sort((e, a) => Number(r.has(a)) - Number(r.has(e)) || t(e.machineId) - t(a.machineId) || a.poses - e.poses || e.waste - a.waste); } function O(e, t) { const a = e.filter(e => t.has(A(e))); if (a.length)
    return a; const r = new Map; for (const t of T(e)) {
    const e = t.machineId + ":" + t.modeId;
    r.has(e) || r.set(e, t);
} return [...r.values()]; } function L(e, a) { const r = t(e.rotated ? a.height : a.width), i = t(e.rotated ? a.width : a.height), o = (e.planWidth - e.occupiedWidth) / 2 + e.bleed, n = e.gripper + (e.planHeight - e.reservedGrippers * e.gripper - e.usableHeight) / 2, s = n + (e.usableHeight - e.occupiedHeight) / 2 + e.bleed; return { area: { x: (e.planWidth - e.usableWidth) / 2, y: n, width: e.usableWidth, height: e.usableHeight }, pieces: Array.from({ length: e.poses }, (t, a) => ({ x: o + a % e.columns * (r + e.gap), y: s + Math.floor(a / e.columns) * (i + e.gap), width: r, height: i })) }; } function j(t) { return e(t.code).trim() || "#" + t.id; } function D(e) { return ["#b8d9f5", "#ffdc9e", "#bce3c1", "#e1c4ef", "#f5bfc4", "#b9e8e4", "#e5dfa2", "#d4c8bd"][e % 8]; } function z(e, t, a) { if (t.length !== a.length || t.some(e => !Number.isSafeInteger(e) || e < 0) || t.reduce((e, t) => e + t, 0) > e.poses)
    throw new Error("Distribuição de rubricas inválida."); const r = ["3749", "3750"].includes(String(e.modeId)), i = r ? 2 : 1, o = []; if (t.some(e => e % i))
    throw new Error("Viragem: posições pares por rubrica obrigatórias."); for (let t = 0; t < e.rows; t++)
    for (let a = 0; a < e.columns; a++) {
        const i = t * e.columns + a;
        if (r) {
            const r = "3749" === String(e.modeId) ? t * e.columns + e.columns - 1 - a : (e.rows - 1 - t) * e.columns + a;
            if (r === i)
                throw new Error("Viragem: grelha sem metades simétricas.");
            i < r && o.push([i, r]);
        }
        else
            o.push([i]);
    } const n = Array(e.poses).fill(null); let s = 0; return t.forEach((e, t) => { for (let r = 0; r < e; r += i)
    for (const e of o[s++])
        n[e] = { label: j(a[t]), id: String(a[t].id), color: D(t) }; }), n; } function R(e, t, a) { const r = "http://www.w3.org/2000/svg", i = document.createElementNS(r, "svg"), o = L(e, t); function n(e, t, a, o) { const n = document.createElementNS(r, "rect"); for (const [t, a] of Object.entries(e))
    n.setAttribute(t, String(a)); n.setAttribute("fill", t), n.setAttribute("stroke", a), n.setAttribute("stroke-width", "0.8"), n.setAttribute("data-kind", o), i.append(n); } if (i.setAttribute("viewBox", "-2 -2 " + (e.planWidth + 4) + " " + (e.planHeight + 4)), i.setAttribute("role", "img"), i.setAttribute("aria-label", "Plano " + e.planWidth + " por " + e.planHeight + " mm, " + e.columns + " colunas e " + e.rows + " filas, " + e.poses + " exemplares"), i.style.cssText = "display:block;width:100%;height:230px;margin:10px 0", n({ x: 0, y: 0, width: e.planWidth, height: e.planHeight }, "#fff", "#263238", "plan"), n({ x: 0, y: 0, width: e.planWidth, height: e.gripper }, "#f9ce87", "#ba8300", "gripper"), 2 === e.reservedGrippers && n({ x: 0, y: e.planHeight - e.gripper, width: e.planWidth, height: e.gripper }, "#f9ce87", "#ba8300", "gripper"), n(o.area, "#edf5f5", "#009688", "print-area"), o.pieces.forEach((e, t) => { const o = a?.[t]; if (n(e, a ? o?.color || "#eee" : "#c7e5ff", "#2865a2", "piece"), !a)
    return; const s = document.createElementNS(r, "text"), l = e.x + e.width / 2, p = e.y + e.height / 2, c = e.height > e.width, u = c ? e.height : e.width, d = o?.label || "Livre"; s.textContent = d; for (const [t, a] of Object.entries({ x: l, y: p, "text-anchor": "middle", "dominant-baseline": "central", "font-size": Math.min(14, .4 * (c ? e.width : e.height), (u - 6) / Math.max(1, d.length) * 1.5), fill: "#172b3a", "data-kind": "rubric-code", "data-rubric-id": o?.id || "" }))
    s.setAttribute(t, String(a)); c && s.setAttribute("transform", "rotate(-90 " + l + " " + p + ")"), i.append(s); }), ["3749", "3750"].includes(e.modeId)) {
    const t = document.createElementNS(r, "line"), a = "3749" === e.modeId;
    for (const [r, i] of Object.entries({ x1: a ? e.planWidth / 2 : 0, x2: a ? e.planWidth / 2 : e.planWidth, y1: a ? 0 : e.planHeight / 2, y2: a ? e.planHeight : e.planHeight / 2, stroke: "#b42318", "stroke-width": 1.5, "stroke-dasharray": "5 3" }))
        t.setAttribute(r, String(i));
    i.append(t);
} return i; } function V(e, t, a, r, i) { const o = r("button", void 0, "cb-plan-preview"); return o.type = "button", o.title = "Ampliar plano", o.setAttribute("aria-label", "Ampliar plano " + e.machineName + " " + e.planWidth + " × " + e.planHeight + " mm"), o.style.cssText = "display:block;width:100%;border:0;background:transparent;padding:0;cursor:zoom-in", o.append(R(e, t, i), r("span", "Ampliar plano", "cb-note")), o.addEventListener("click", () => { const n = r("dialog"), s = r("h3", e.machineName + " · " + e.planWidth + " × " + e.planHeight + " mm · " + a), l = r("button", "Fechar", "btn btn-default"), p = R(e, t, i); n.className = "cb-plan-zoom", n.setAttribute("aria-label", s.textContent), n.style.cssText = "width:94vw;max-width:1500px;max-height:94vh;overflow:auto;border:1px solid #596776;padding:20px", p.style.height = "65vh", l.type = "button", l.autofocus = !0, l.addEventListener("click", () => n.close()), n.append(l, s, p, r("p", e.poses + " exemplares · grelha " + e.columns + " × " + e.rows + " · intervalo " + e.gap + " mm · sangria exterior " + e.bleed + " mm. Folha " + e.sheetWidth + " × " + e.sheetHeight + " mm · " + e.perSheet + " planos/folha. Azul: peças ao corte; verde: área útil; laranja: pinça. Por validar.")), n.addEventListener("click", e => { if (e.target !== n)
    return; const t = n.getBoundingClientRect(); (e.clientX < t.left || e.clientX > t.right || e.clientY < t.top || e.clientY > t.bottom) && n.close(); }); const c = e => { "Escape" === e.key && (e.preventDefault(), e.stopImmediatePropagation(), n.close()); }; window.addEventListener("keydown", c, !0), n.addEventListener("close", () => { window.removeEventListener("keydown", c, !0), n.remove(), o.isConnected && o.focus(); }, { once: !0 }), document.body.append(n), n.showModal(); }), o; } function G(e, a) { const r = [], i = []; for (const [o, n] of e.entries()) {
    const s = t(n.quantity);
    if (!Number.isSafeInteger(s) || s <= 0)
        return { errors: ["Rubrica #" + n.id + ": quantidade inteira positiva obrigatória."], solutions: [] };
    let l = null;
    for (const [t, r] of a.entries()) {
        const a = Math.ceil(s / r.capacity), n = r.calculate(a, r.capacity, 0 === o);
        if (n.result.errors.length || !Number.isFinite(n.result.total)) {
            i.push(...n.result.errors);
            continue;
        }
        const p = e.map((e, t) => t === o ? r.capacity : 0), c = p.map(e => e * a);
        if (!c.every(Number.isSafeInteger))
            throw new Error("Rubricas: limite numérico excedido.");
        const u = { ...n, optionIndex: t, runs: a, allocation: p, produced: c };
        (!l || u.result.total < l.result.total) && (l = u);
    }
    if (!l)
        return { errors: i.length ? [...new Set(i)] : ["Sem plano digital com preços completos para a rubrica #" + n.id + "."], solutions: [] };
    r.push(l);
} return { errors: [], solutions: [{ forms: r, cost: r.reduce((e, t) => e + t.result.total, 0) }] }; } function Q({ plans: e, data: a, component: r, papers: i, colors: o, rubrics: n, manualPlates: l, digitalSeparate: p = !1 }) { const c = [], u = [], d = 1256 === Number(r.faces) ? 2 : 1, m = a.price_operations.find(e => 3 === Number(e.id)); for (const h of a.machines) {
    const f = e.filter(e => Number(e.machineId) === Number(h.id));
    if (!f.length)
        continue;
    const g = [];
    for (const [e, t] of f.entries()) {
        const n = { id: "suggestion-" + e, machine_id: h.id, component_width: r.width, component_height: r.height, width: t.planWidth, height: t.planHeight, poses: t.poses, per_sheet: t.perSheet, sheet_width: t.sheetWidth, sheet_height: t.sheetHeight, die: "false", validated_turns: "3749" === t.modeId ? "1553" : "3750" === t.modeId ? "1554" : "", suggestion: t }, s = C({ data: { ...a, plans: [n] }, machine: h, component: r, papers: i, format: "", mode: t.modeId, faces: d, colors: o, plateOperation: m, manualPlates: l });
        s.errors.forEach(e => u.push(h.name + ": " + e)), g.push(...s.options);
    }
    if (n.length) {
        const e = 56 === Number(h.id) && p, a = e ? G(n, g) : I(n, g);
        if (a.errors.length) {
            a.errors.forEach(e => u.push(h.name + ": " + e));
            continue;
        }
        const r = a.solutions[0], i = r.forms.map(e => ({ ...e, option: g[e.optionIndex] })), o = 56 === Number(h.id) ? { total: 0, errors: [] } : s(i.reduce((e, t) => e + t.result.plates, 0), m);
        if (o.errors.length) {
            u.push(...o.errors);
            continue;
        }
        const l = r.cost - i.reduce((e, t) => e + t.result.plateProduction, 0) + o.total;
        c.push({ machine: h, quantity: n.reduce((e, a) => e + t(a.quantity), 0), parts: i, total: l, ctp: o.total, basis: "rubrics", digitalSeparate: e });
    }
    else
        for (const e of a.quantities) {
            const a = t(e.quantity);
            if (!Number.isSafeInteger(a) || a <= 0)
                u.push("Quantidade inválida #" + e.id);
            else
                for (const t of g) {
                    const r = Math.ceil(a / t.capacity), i = t.calculate(r, t.capacity);
                    if (i.result.errors.length) {
                        u.push(...i.result.errors);
                        continue;
                    }
                    const o = 56 === Number(h.id) ? { total: 0, errors: [] } : s(i.result.plates, m);
                    o.errors.length ? u.push(...o.errors) : c.push({ machine: h, quantity: a, quantityId: e.id, parts: [{ ...i, option: t, runs: r, allocation: [t.capacity], produced: [r * t.capacity] }], total: i.result.total - i.result.plateProduction + o.total, ctp: o.total, basis: "quantity" });
                }
        }
}
const costing = window.vdOperationCosting.get(r.id);
for (const row of c) {
    const selection = U(row, { data: a, component: r, paperId: i[0]?.internal_id, rubrics: n, materials: [] });
    costing.apply(selection);
    row.allOperations = selection.allOperations;
    row.costingRevision = selection.parameters.costingRevision;
    row.total = selection.total;
    row.errors = selection.errors;
}
return { results: B(c), errors: [...new Set(u)] }; } function B(e) { const t = e => "rubrics" === e.basis ? "rubrics" : String(e.quantityId), a = (e, t) => Number(e.errors?.length > 0) - Number(t.errors?.length > 0) || Math.round(100 * (e.total ?? Infinity)) - Math.round(100 * (t.total ?? Infinity)) || Number(t.parts.every(e => "3748" === String(e.option.modeId))) - Number(e.parts.every(e => "3748" === String(e.option.modeId))) || e.total - t.total, r = [...e].sort((e, t) => e.quantity - t.quantity || a(e, t)), i = new Map; for (const e of r)
    i.has(t(e)) || i.set(t(e), e); return r.map(e => ({ ...e, bestForQuantity: i.get(t(e)) === e })).sort((e, t) => Number(t.bestForQuantity) - Number(e.bestForQuantity) || e.quantity - t.quantity || a(e, t)); } function U(e, { data: a, component: r, paperId: i, rubrics: o, materials: n }) { const s = e.parts.map(({ option: r, ...i }) => ({ ...i, plan: r.plan, modeId: r.modeId, operation: r.operation, tariff: { ...r.tariff, ...!1 === i.setupApplied ? { waste: 0, setupMinutes: 0 } : {}, operationId: Number(r.operation.id), operationSource: r.operation }, machine: r.machine, ...56 === Number(e.machine.id) ? {} : { plate: { materialId: 5 === Number(e.machine.id) ? 985 : 984, unitPrice: t(a.plate_prices.find(t => Number(t.id) === (5 === Number(e.machine.id) ? 985 : 984))?.price) } }, ...56 === Number(e.machine.id) && a.colors.some(e => "3821" === String(e.color)) ? { whiteClick: t(e.machine.white_click) } : {} })), l = e => s.reduce((t, a) => t + a.result[e], 0), p = new Map; for (const e of s) {
    const t = e.paperCalculation.paper, a = y({ sheet_width: t.width, sheet_height: t.height }), r = p.get(a) || { paper: t, sheets: 0 };
    r.sheets += e.result.sheets, p.set(a, r);
} return { quantityId: "rubrics" === e.basis ? "rubrics" : String(e.quantityId), quantity: e.quantity, machineId: Number(e.machine.id), plan: { id: null }, errors: e.errors || [], total: e.total, allOperations: e.allOperations, parameters: { version: 2, allOperations: e.allOperations, costingRevision: e.costingRevision, source: "dynamic", basis: e.basis, componentId: Number(r.id), component: { ...r }, quantity: e.quantity, machineId: Number(e.machine.id), machine: { ...e.machine }, paperId: i, rubrics: o, parts: s, materials: n, colors: a.colors, pricingStatus: "TESTE — A REVER", digitalSeparate: Boolean(e.digitalSeparate), digitalSetupRule: 56 === Number(e.machine.id) ? "first-run-only" : null, search: { method: e.digitalSeparate ? "independent-full-plan" : "bounded-anchor-beam", optimality: "not-guaranteed", excessRule: "cost-driven-no-percentage-cap", minimumRule: "each-artwork-at-least-requested", turnAllocation: "even-per-artwork" }, validation: { confirmed: !0, confirmedAt: (new Date).toISOString(), scope: "geometry-grippers-turns-cut", productionOrderApproved: !1 }, plateProduction: { operation: a.price_operations.find(e => 3 === Number(e.id)), total: e.ctp }, paperStocks: [...p.values()].map(e => ({ ...e, stock: f(a.papers, e.paper, e.sheets) })), results: { total: e.total, unit: e.total === null ? null : e.total / e.quantity, mountings: s.length, sheets: l("sheets"), plates: l("plates"), hours: l("hours"), impressions: l("impressions"), clicks: l("clicks"), paper: l("paper"), printing: l("printing"), plate: l("plate"), click: l("click"), machineCost: l("machineCost"), plateProduction: e.ctp } } }; } function X(e, a, r, i, o) { e.replaceChildren(r("h3", "Escolhas guardadas · preços fixados")); const n = i(["Quantidade", "Máquina", "Base guardada", "Dados fixados"]); for (const [e, s] of a) {
    const a=r("tr"),p=r("td"),dialog=r("dialog"),open=r("button","Consultar geometria, montagens e preços","btn btn-default");
dialog.className="cb-saved-quotation-dialog";
dialog.setAttribute("aria-label","Geometria, montagens e preços configurados");
dialog.style.cssText="width:min(1100px,94vw);max-height:90vh;overflow:auto;border:1px solid #8794a2;border-radius:6px;padding:20px;overscroll-behavior:contain";
const close=r("button","Fechar","btn btn-default");
close.type="button";close.style.cssText="float:right;margin:0 0 12px 12px";
close.addEventListener("click",()=>dialog.close());
open.type="button";open.setAttribute("aria-haspopup","dialog");
open.addEventListener("click",()=>{if(!dialog.open)dialog.showModal()});
dialog.addEventListener("click",event=>{if(event.target===dialog){const bounds=dialog.getBoundingClientRect();if(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom)dialog.close()}});
const money=value=>value===null||value===undefined||!Number.isFinite(Number(value))?"Por completar":new Intl.NumberFormat("pt-PT",{style:"currency",currency:"EUR"}).format(Number(value));
const amount=value=>value===null||value===undefined||!Number.isFinite(Number(value))?"—":new Intl.NumberFormat("pt-PT",{maximumFractionDigits:4}).format(Number(value));
const addTable=(heading,headers,rows)=>{dialog.append(r("h3",heading));const table=i(headers);table.node.className="table table-bordered cb-saved-data-table";for(const values of rows){const tr=r("tr");values.forEach(value=>tr.append(r("td",String(value??"—"))));table.body.append(tr)}dialog.append(table.node)};
dialog.append(close,r("h2","Geometria, montagens e preços · "+s.selected_quantity+" unidades"));
try{
const saved=JSON.parse(s.details).calculation;
if(!saved||typeof saved!=="object")throw new Error("Instantâneo sem dados de cálculo.");
const geometryRows=[["Produto",saved.component?.width&&saved.component?.height?saved.component.width+" × "+saved.component.height+" mm":"—"],["Máquina",saved.machine?.name||"—"],["Quantidade",amount(saved.quantity)],["Montagens",amount(saved.results?.mountings)],["Folhas",amount(saved.results?.sheets)],["Chapas",amount(saved.results?.plates)],["Tempo estimado",saved.results?.hours===null||saved.results?.hours===undefined?"—":amount(saved.results.hours)+" h"],["Custo total",money(saved.allOperations?.cost??saved.results?.total)],["Venda total",money(saved.allOperations?.sale)]];
addTable("Resumo configurado",["Parâmetro","Valor"],geometryRows);
if(saved.source==="dynamic"&&Array.isArray(saved.parts)){
for(const[index,part]of saved.parts.entries()){
dialog.append(r("h3","Montagem "+(index+1)));
const suggestion=part.plan?.suggestion;
if(suggestion)dialog.append(R({...suggestion,machineName:saved.machine?.name||""},saved.component));
const paper=part.paperCalculation||{},plan=part.plan||{},allocated=Array.isArray(part.allocation)?part.allocation.map((value,index)=>value?value+" posições → "+(part.produced?.[index]??"—")+" un.":"—").join(" · "):"—";
addTable("Geometria e produção",["Configuração","Valor"],[["Modo",({3747:"Frente",3748:"Frente e Verso",3749:"Tira e Retira",3750:"Cambalhota"})[part.modeId]||"—"],["Formato do produto",plan.component_width&&plan.component_height?plan.component_width+" × "+plan.component_height+" mm":"—"],["Formato do plano",suggestion?.planWidth&&suggestion?.planHeight?suggestion.planWidth+" × "+suggestion.planHeight+" mm":"—"],["Formato da folha",paper.paper?.width&&paper.paper?.height?paper.paper.width+" × "+paper.paper.height+" mm":"—"],["Exemplares no plano",amount(suggestion?.poses??plan.poses)],["Grelha",suggestion?.columns&&suggestion?.rows?suggestion.columns+" × "+suggestion.rows:"—"],["Intervalo / sangria",suggestion?.gap!==undefined?suggestion.gap+" mm / "+(suggestion.bleed??"—")+" mm":"—"],["Posições e produção",allocated],["Planos úteis + acerto",amount(part.runs)+" + "+amount(paper.wastePlans)],["Folhas",amount(paper.sheets)],["Chapas",amount(part.result?.plates)]]);
if(suggestion)dialog.append(r("p","Folha "+amount(suggestion.sheetWidth)+" × "+amount(suggestion.sheetHeight)+" mm · "+amount(suggestion.perSheet)+" plano(s)/folha · pinça "+amount(suggestion.gripper)+" mm.","cb-note"));
}
if(saved.basis==="rubrics"&&Array.isArray(saved.rubrics))dialog.append(M([{machine:saved.machine,parts:saved.parts,digitalSeparate:saved.digitalSeparate}],saved.rubrics,{3747:"Frente",3748:"Frente e Verso",3749:"Tira e Retira",3750:"Cambalhota"},r,i).node);
}
const ledger=saved.allOperations;
if(Array.isArray(ledger?.lines)){
const priceRows=ledger.lines.map(line=>[line.name,amount(line.quantity)+" "+(line.unit||""),line.hours===null||line.hours===undefined?"—":amount(line.hours)+" h",money(line.cost),money(line.sale)]);
addTable("Discriminação de custos e preços",["Operação / material","Quantidade","Tempo","Custo","Venda"],priceRows);
if(ledger.errors?.length)dialog.append(r("p",ledger.errors.join(" · "),"alert alert-warning"));
}
const changed=[...new Set([l(saved,o,{}),...(saved.parts||[]).map(part=>l(part,o,{}))].flat())];
if(changed.length)dialog.append(r("p","Preços alterados desde esta gravação: "+changed.join("; ")+". Histórico mantido; recalcule para atualizar.","alert alert-warning"));
if(!Array.isArray(ledger?.lines))dialog.append(r("p","Este instantâneo não contém discriminação de preços por linha.","alert alert-info"));
}catch(error){dialog.append(r("p","Não foi possível apresentar os dados configurados: "+error.message,"alert alert-danger"));console.error("Instantâneo de geometria e preços",error)}
p.append(open,dialog);
    a.append(r("td", s.selected_quantity + ("rubrics" === e ? " · rubricas" : "")), r("td", o.machines.find(e => String(e.id) === String(s.machine_id))?.name || String(s.machine_id)), r("td", null === t(s.base) ? "Incompleta" : new Intl.NumberFormat("pt-PT", { style: "currency", currency: "EUR" }).format(t(s.base))), p), n.body.append(a);
} e.append(n.node), a.size || e.append(r("p", "Ainda não há escolhas guardadas.", "cb-note")); } function Y(e, t, a, r, i, o, n) { if (e.replaceChildren(), t.errors.length && e.append(r("p", t.errors.join("; "), "alert alert-warning")), !t.results.length)
    return void e.append(r("p", "Sem custos completos para as sugestões usadas.", "alert alert-warning")); const s = i(["Quant.", "Máquina / plano / modo", "Montagens / tiragens", "Folhas", "Chapas", "Cliques faturados", "Tempo (h)", "Venda total estimada", "Custo/un. venda", "Venda/un. venda", "Estado"]); s.node.className = "cb-generated-costs"; for (const e of t.results) {
    const t = t => e.parts.reduce((e, a) => e + a.result[t], 0), l = r("tr", void 0, e.bestForQuantity ? "cb-best" : void 0);
    for (const a of [e.quantity, e.machine.name + (e.bestForQuantity && !e.errors.length ? " · Melhor completa" : "") + (e.digitalSeparate ? " · rubricas separadas" : "") + " · " + e.parts.map(e => e.option.plan.width + " × " + e.option.plan.height + " mm · " + o[e.option.modeId]).join(" + "), e.parts.length + (e.digitalSeparate ? " tiragens" : ""), t("sheets"), t("plates"), t("clicks"), t("hours").toFixed(3), window.vdOperationCosting.money(e.total)])
        l.append(r("td", String(a)));
    const unitPrices = window.vdOperationCosting.unitPrices(e.allOperations);
    l.append(r("td", window.vdOperationCosting.unitMoney(unitPrices.cost)),
        r("td", window.vdOperationCosting.unitMoney(unitPrices.sale)));
    const p = r("td"), c = r("button", "Confirmar e guardar", "btn btn-primary");
    c.type = "button", c.addEventListener("click", async () => { if (!confirm("Confirmas que verificaste TODAS as montagens desta solução: geometria, pinças, área imprimível, viragem e corte?"))
        return; const t = "rubrics" === e.basis ? "rubrics" : String(e.quantityId); if (!n.selectedByQuantity.has(t) || confirm("Substituir a escolha e os preços guardados para esta quantidade?")) {
        c.disabled = !0;
        try {
            await n.persistSelection(U(e, { ...n, rubrics: a })), c.textContent = "Guardado", X(n.summary, n.selectedByQuantity, r, i, n.data);
        }
        catch (e) {
            c.disabled = !1, p.append(r("p", "Falha ao guardar: " + e.message, "text-danger")), console.error("Guardar plano dinâmico", e);
        }
    } }), p.append(c, window.vdOperationCosting.get(n.component.id).detail(U(e, { ...n, rubrics: a }))), l.append(p), s.body.append(l);
} const l = r("div", void 0, "cb-scroll"); if (l.append(s.node), e.append(l), a.length) {
    const n = t.results.map(e => ({ machine: e.machine, digitalSeparate: e.digitalSeparate, parts: e.parts.map(e => ({ ...e, plan: e.option.plan, modeId: e.option.modeId })) }));
    e.append(r("h4", "Combinações por imposição · sugestões"), M(n, a, o, r, i));
} } function K({ data: e, component: a, paperSelect: r, metallicSelect: i, element: n, table: s, saveContext: l, platesInput: p, modeSelect: c, getManualPlates: u }) { const d = n("section"), h = n("button", "Calcular planos possíveis", "btn btn-info"), f = n("div"), g = n("div"), b = n("p", void 0, "cb-note"), w = n("div"), S = new Map, y = n("input"); y.type = "checkbox", y.className = "cb-digital-separate", y.addEventListener("change", () => h.click()), w.className = "cb-generator-limits"; for (const t of e.machines) {
    const e = n("div");
    e.append(n("strong", t.name + " · exemplares/plano"));
    const a = {};
    for (const [r, i] of [["min", "Mínimo"], ["max", "Máximo"]]) {
        const o = n("label", i), s = n("input", void 0, "form-control");
        o.style.cssText = "display:inline-block;margin:4px 10px", s.type = "number", s.min = "1", s.step = "1", s.placeholder = "Sem limite", s.dataset.machineId = String(t.id), s.dataset.bound = r, s.setAttribute("aria-label", t.name + " · " + i + " de exemplares por plano"), s.addEventListener("input", () => h.click()), o.append(s), e.append(o), a[r] = s;
    }
    if (S.set(String(t.id), a), w.append(e), 56 === Number(t.id)) {
        const t = n("label");
        t.append(y, document.createTextNode(" Não gerar montagens")), e.append(t, n("p", "Cada rubrica é impressa separadamente, repetida em todas as posições do plano. Acerto apenas na primeira tiragem; sem chapas. Apenas nesta simulação.", "cb-note"));
    }
} g.className = "cb-generator-formats"; const N = () => [...g.querySelectorAll("input:checked")].map(e => e.value), x = () => { g.replaceChildren(), f.replaceChildren(), b.textContent = ""; const t = m(e, Number(r.value)); if (t.errors.length)
    return void (b.textContent = t.errors.join(" ")); const a = H(t.papers, r.value); for (const e of a.formats) {
    const t = n("label"), r = n("input");
    t.style.cssText = "display:inline-block;margin:4px 14px 4px 0", r.type = "checkbox", r.value = e, r.checked = a.preferred.includes(e), r.addEventListener("change", () => { if (N().length > 3)
        return r.checked = !1, void (b.textContent = "Máximo de 3 formatos. Desmarca outro para mudar."); b.textContent = "", h.click(); }), t.append(r, document.createTextNode(" " + e.replace("x", " × ") + " mm")), g.append(t);
} a.preferred.length || (b.textContent = "Não foi possível identificar um único formato associado. Seleciona 1 a 3 formatos."); }; h.type = "button", d.className = "cb-plan-generator", d.append(n("h3", "Planos possíveis · por validar"), n("p", "Planos dinâmicos. Assinala os planos a comparar; sem piscos, usam-se os de maior capacidade por máquina e modo. Apenas grelhas uniformes; custos separados do catálogo. Versafire: mínimo A3 (420 × 297 mm). Cliques por face: até 487 mm = 1; até 700 = 2; até 920 = 3; até 1250 = 4.", "cb-note"), n("h4", "Papel a usar · escolhe entre 1 e 3 formatos"), g, b, n("h4", "Limites nesta simulação · vazio = sem limite"), w, h, f); r.addEventListener("change", () => { x(); h.click(); }), i?.addEventListener("change", () => h.click()), x(), h.hidden = !0, h.addEventListener("click", () => { f.replaceChildren(); document.querySelector("#cb-dynamic-plan-results")?.replaceChildren(); try {
    const d = m(e, Number(r.value));
    if (d.errors.length)
        return void f.append(n("p", d.errors.join(" "), "alert alert-warning"));
    const h = o(e.colors, i?.value);
    if (h.errors.length || h.mixed)
        return void f.append(n("p", h.mixed ? "Sugestões de produção mista ainda não suportadas." : h.errors.join(" "), "alert alert-warning"));
    const g = W(d.papers, N());
    if (g.errors.length)
        return void f.append(n("p", g.errors.join(" "), "alert alert-warning"));
    const b = g.papers, w = [], x = [];
    for (const t of e.machines) {
        if (56 === Number(t.id) ? !h.allowDigital : !h.allowOffset)
            continue;
        const e = S.get(String(t.id)), r = F(e.min.value, e.max.value), i = q(a, t, b, r);
        i.errors.forEach(e => x.push(t.name + ": " + e)), i.plans.length || i.errors.length || x.push(t.name + ": sem sugestão dentro dos formatos e limites indicados.");
        for (const e of i.plans)
            56 !== Number(t.id) && "auto" !== c.value && e.modeId !== c.value || w.push({ ...e, machineName: t.name });
    }
    if (x.length && f.append(n("p", [...new Set(x)].join(" "), "alert alert-warning")), f.append(n("p", "Sugestões ordenadas por exemplares/plano, não por custo. Eliminam-se opções geometricamente inferiores na mesma máquina, modo e formato: outra opção tem pelo menos tantos exemplares por plano e por folha, com melhoria num destes valores e sem aumentar o escalão de cliques digitais. Pinça descontada na altura: uma extremidade em F/V e Tira e Retira; duas em Cambalhota. Área útil = mínimo entre área máxima imprimível e plano após pinça, sem descontar a pinça duas vezes. Viragens exigem metades simétricas da grelha. Validar registo, posição da área imprimível, marcas e corte antes de criar o plano. Nenhuma sugestão está aprovada para produção.", "alert alert-info")), !w.length)
        return void f.append(n("p", "Sem sugestão com os dados e limites atuais.", "alert alert-warning"));
    const P = T(E(w)), I = new Set, _ = n("div"), C = n("p", void 0, "cb-note"), M = n("div");
    M.className = "cb-generated-plans", M.style.cssText = "display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px";
    const k = { 3747: "Frente", 3748: "Frente e Verso", 3749: "Tira e Retira", 3750: "Cambalhota" }, H = () => { _.replaceChildren(); const e = O(P, I); C.textContent = (I.size ? "Seleção manual: " : "Automático (maior capacidade por máquina e modo): ") + e.length + " planos para comparar. A gravação exige confirmação técnica."; for (const e of M.children)
        e.style.borderColor = I.has(e.dataset.planKey) ? "#007c91" : "#c8d0d8"; queueMicrotask(() => j.click()); }, L = () => { _.replaceChildren(n("p", "A recalcular comparativo…", "cb-note")); queueMicrotask(() => j.click()); };
    document.querySelectorAll("#cb-simulator [data-isolate-rubric]").forEach(e => e.onchange = L);
    for (const e of P) {
        const t = n("article"), r = n("label"), i = n("input"), o = A(e);
        t.dataset.planKey = o, t.dataset.machineId = String(e.machineId), t.dataset.poses = String(e.poses), t.style.cssText = "border:2px solid #c8d0d8;padding:10px;min-width:0", i.type = "checkbox", i.setAttribute("aria-label", "Usar " + e.machineName + " " + e.planWidth + " × " + e.planHeight + " " + k[e.modeId] + " " + e.poses + " exemplares"), r.append(i, document.createTextNode(" Usar · " + e.machineName)), i.addEventListener("change", () => { i.checked ? I.add(o) : I.delete(o), H(); }), t.append(r, n("p", e.poses + " exemplares/plano · " + k[e.modeId]), V(e, a, k[e.modeId], n), n("p", "Plano " + e.planWidth + " × " + e.planHeight + " mm · grelha " + e.columns + " × " + e.rows + (e.rotated ? " · peças rodadas" : "") + ". Folha " + e.sheetWidth + " × " + e.sheetHeight + " mm · corte " + e.cutsX + " × " + e.cutsY + " = " + e.perSheet + " planos/folha. Intervalo " + e.gap + " mm · aparas totais " + Number(e.trimWidth.toFixed(3)) + " × " + Number(e.trimHeight.toFixed(3)) + " mm · desperdício " + (100 * e.waste).toFixed(2) + " %." + (56 === Number(e.machineId) ? " " + v(e.planWidth, e.planHeight) + " clique(s) por face." : ""), "cb-note"), n("strong", "Por validar")), M.append(t);
    }
    const j = n("button", "Comparar custos das sugestões", "btn btn-primary");
    j.type = "button", j.hidden = !0, j.addEventListener("click", () => { try {
        const i = e.rubrics.map(e => ({ ...e, isolated: Boolean(document.querySelector('#cb-simulator [data-isolate-rubric="' + e.id + '"]')?.checked) })), o = Q({ plans: O(P, I), data: e, component: a, papers: b, colors: h, rubrics: i, manualPlates: u() ? t(p.value) : void 0, digitalSeparate: y.checked });
        Y(_, o, i, n, s, k, { ...l, data: e, component: a, paperId: Number(r.value) });
    }
    catch (e) {
        _.replaceChildren(n("p", "Falha na comparação: " + e.message, "alert alert-danger")), console.error("Comparar sugestões", e);
    } }), H(), _.id = "cb-dynamic-plan-results"; f.append(n("p", "Azul: peças ao corte. Verde: área útil assumida centrada. Laranja: pinça. Tracejado: divisão de viragem. Espaçamento e sangria ficam fora dos retângulos azuis. Primeiro, o plano com mais exemplares de cada máquina (Versafire, PrintMaster, SpeedMaster); depois as restantes opções.", "cb-note"), C, M, j, _);
    queueMicrotask(() => { if (j.isConnected) j.click(); });
}
catch (e) {
    f.replaceChildren(n("p", "Falha ao gerar planos: " + e.message, "alert alert-danger")), console.error("Gerar planos possíveis", e);
} }); for (const e of [p, c])
    for (const t of ["input", "change"])
        e.addEventListener(t, P); return l.form.addEventListener("change", e => { e.target.matches("[data-isolate-rubric]") && P(); }), h.click(), d; } return { validOperations: a, offsetPasses: r, suggestedPlates: i, colorProcesses: o, whiteTariff: n, mixedProduction: function ({ offset: e, digital: a, digitalSettings: r, digitalMachine: i, colors: o, plans: s }) { if (!i)
        throw new Error("Equipamento Digital indisponível para o branco."); const l = e.args, p = Math.max(
        window.vdOperationCosting.setupWaste(l.component?.setupWasteOperations || [], l.machine?.id, l.waste).plans ?? NaN,
        window.vdOperationCosting.setupWaste(l.component?.setupWasteOperations || [], i?.id, r.values.waste).plans ?? NaN), c = [...r.missing, ...o.errors], u = l.jobs || [{ plan: l.plan, forms: l.forms, copies: t(l.plan.poses), positions: t(l.plan.poses), pages: 1, passes: l.passes }]; u.some(e => !s.some(t => String(t.id) === String(e.plan.id) && 56 === Number(t.machine_id))) && c.push("Plano/imposição não associado à Digital; configura plano comum às duas máquinas."); const d = n(r.values, i, !0); c.push(...d.errors); const m = g({ ...l, jobs: u, waste: p, tariff: { ...l.tariff, waste: p } }), h = g({ ...l, jobs: u, waste: p, machine: i, tariff: { ...d.tariff, waste: p }, plateOperation: null, platesPerForm: 0 }); null !== h.result.total && (h.result.total -= h.result.paper), h.result.paper = 0, h.result.unit = null === h.result.total ? null : h.result.total / l.quantity, c.push(...m.result.errors, ...h.result.errors); const f = m.result, b = h.result, w = { ...f, errors: [...new Set(c)] }, v = e => Number.isFinite(f[e]) && Number.isFinite(b[e]) ? f[e] + b[e] : null; for (const e of ["hours", "printing", "machineCost", "clicks", "click", "impressions"])
        w[e] = v(e); return w.total = ["paper", "plate", "printing", "click", "plateProduction"].every(e => Number.isFinite(w[e])) && !w.errors.length ? w.paper + w.plate + w.printing + w.click + w.plateProduction : null, w.unit = null === w.total ? null : w.total / l.quantity, { publication: m, result: w, paperCalculation: m.paperCalculation, snapshot: { sequence: "Offset → Digital", wasteRule: "max", waste: p, offset: { machine: { ...l.machine }, tariff: { ...l.tariff }, colors: o.offsetColors, result: f }, digital: { machine: { ...i }, tariff: { ...r.values, operationId: Number(a.id), operationSource: a }, colors: o.digitalColors, whiteClick: t(i.white_click), result: b } } }; }, productionPlates: s, priceChanges: l, simulationOrder: p, completeSimulations: c, rankSimulations: function (e) { const t = new Set(e.map(t => c(e, t.quantityId)[0])); return e.sort((e, a) => Number(t.has(a)) - Number(t.has(e)) || e.quantity - a.quantity || e.quantityId - a.quantityId || p(e, a)); }, publicationLayout: u, bindingStatus: function (e, t) { const a = { 1545: "1549", 1546: "1550", 1547: "1551", 1548: "1552" }[t], r = String(e.bindings || "").split(",").filter(Boolean); return r.length ? r.includes(a) ? "validated" : "incompatible" : "pending"; }, paperFamily: d, familyPapers: m, offsetPlanError: h, paperStock: f, publicationCompositions: function (e, t, a) { if (!Number.isSafeInteger(t) || t <= 0 || t > 4096)
        return { errors: ["Paginação: indica entre 1 e 4096 páginas inteiras."], compositions: [] }; const r = e.map(e => u(e, a)).filter(Boolean), i = new Map; for (const e of r) {
        const t = y(e.plan);
        i.has(t) || i.set(t, []), i.get(t).push(e);
    } const o = []; let n = 0; function s(e, t, a, r) { if (++n > 2e4 || o.length >= 512)
        throw new Error("Demasiadas composições: restringe planos/formato."); if (!a)
        return void o.push(r); if (t === e.length)
        return; const i = e[t]; for (let o = Math.floor(a / i.pages); o >= 0; o--)
        s(e, t + 1, a - o * i.pages, o ? [...r, { ...i, forms: o }] : r); } for (const e of i.values()) {
        if (e.length > 64)
            throw new Error("Paginação: restringe a comparação a 64 planos por formato.");
        e.sort((e, t) => t.pages - e.pages || Number(e.plan.id) - Number(t.plan.id)), s(e, 0, t, []);
    } return { compositions: o, errors: o.length ? [] : ["Sem composição exata para " + t + " páginas. Configura paginação e planos para o resto, sem páginas em branco."] }; }, calculatePublication: g, machineSettings: b, calculatePaper: w, digitalClickFactor: v, simulatePlan: S, sheetFormat: y, planMatch: N, eligiblePlans: x, largerPlanPossible: function (e, a, r) { if (r.hasDie)
        return !1; const [i, o, n, s, l, p, c, u, d] = [e.width, e.height, e.sheet_width, e.sheet_height, a.max_width, a.max_height, e.poses, r.width, r.height].map(t); return !![i, o, n, s, l, p, c, u, d].every(e => Number.isFinite(e) && e > 0) && !(i > l || o > p) && [[n, s], [s, n]].some(([e, t]) => { const a = Math.min(e, l), r = Math.min(t, p); return !(a < i || r < o || a === i && r === o) && [[u, d], [d, u]].some(([e, t]) => Math.floor(a / (e + 6)) * Math.floor(r / (t + 6)) > c); }); }, rubricPatterns: P, rubricSearch: I, rubricSearchPass: _, rubricOptions: C, rubricCombinationTable: M, checked: k, planCapacityLimits: F, possiblePlans: q, usefulGeneratedPlans: E, generatedPlanKey: A, generatorPaperFormats: H, generatorPapers: W, generatedPlanOrder: T, selectedGeneratedPlans: O, generatedGeometry: L, rubricPositionLabel: j, rubricPositionColor: D, rubricPositionAssignments: z, generatedPlanSvg: R, generatedPlanPreview: V, separateDigitalRubrics: G, generatedCostComparison: Q, rankGeneratedResults: B, dynamicSelection: U, dynamicHistory: X, renderGeneratedCosts: Y, simulationCostNote: function () { return "Total = papel + impressão + chapas + CTP + cliques. Stock por família/gramagem/formato, sem reservar. Menor total completo; empate: F/V. Operações e materiais discriminados; sem IVA. Folhas/forma: teto de (quantidade ÷ cópias + acerto) ÷ planos/folha. Acerto = maior número de planos das operações usadas (não soma), por forma/montagem; inclui F/V. Digital: cliques por face × escalão do lado maior: até 487 mm = 1; até 700 = 2; até 920 = 3; até 1250 = 4. Inclui suplemento branco. Não multiplica o tempo. Maior preço exato do papel."; }, planGeneratorPanel: K, dynamicSimulationUI: function (e) { const { card: t, form: a, formatSelect: r, forms: i, passesInput: o, action: n, results: s, summary: l, selectedByQuantity: p, element: c, table: u } = e; for (const e of [r, i, o])
        e.closest("label").hidden = !0; n.hidden = !0, a.addEventListener("submit", e => e.preventDefault()); const d = { summary: l, selectedByQuantity: p, persistSelection: e.persistSelection, materials: e.materials, form: a }; t.append(a, K({ ...e, saveContext: d })), X(l, p, c, u, e.data), s.replaceChildren(); } }; })(), document.dispatchEvent(new Event("componentSimulationReady"));

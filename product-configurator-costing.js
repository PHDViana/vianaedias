(function (root) {
    'use strict';
    const number = value => {
        if (value === null || value === undefined || String(value).trim() === '') return null;
        const parsed = Number(String(value).replace(',', '.'));
        return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
    };
    const sum = (rows, key) => rows.reduce((total, row) => total + (row[key] ?? 0), 0);
    const money = value => value === null || !Number.isFinite(value) ? 'Por completar' :
        new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(value);
    const contexts = new Map();
    if (root.addEventListener && root.document) {
        root.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const dialogs = [...root.document.querySelectorAll('dialog[open]')];
            const dialog = dialogs.at(-1);
            if (dialog) {
                event.preventDefault();
                event.stopImmediatePropagation();
                if (dialog.dispatchEvent(new Event('cancel', { cancelable: true }))) dialog.close();
                return;
            }
            const modal = root.document.querySelector('#ajax-modal');
            if (modal && root.jQuery?.(modal).is(':visible')) {
                event.preventDefault();
                event.stopImmediatePropagation();
                root.jQuery(modal).modal('hide');
            }
        }, true);
    }
    function unitPrices(ledger) {
        const quantity = number(ledger?.quantity);
        const unit = key => quantity > 0 && number(ledger?.[key]) !== null ? number(ledger[key]) / quantity : null;
        return { cost: unit('cost'), sale: unit('sale') };
    }
    const unitMoney = value => value === null || !Number.isFinite(value) ? 'Por completar' :
        new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR',
            minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(value);
    const isPrinting = row => Number(row.operation_id) !== 43 && ([4, 5, 7].includes(Number(row.operation_id)) ||
        String(row.machines || '').split(',').some(id => ['5', '6', '56'].includes(id)));
    const effectiveMargin = value => value === undefined || value === null || String(value).trim() === '' ? 30 : number(value);
    function commercialPricing(data, overrides = {}) {
        if (!Array.isArray(data.commercial_pricing)) return null;
        const quote = data.commercial_pricing[0];
        if (!quote) return { method: 'cost-plus', errors: ['Orçamento em falta para calcular o acréscimo comercial.'] };
        const quoteMarkup = quote.markup === undefined || quote.markup === null || String(quote.markup).trim() === '' ? 50 : number(quote.markup);
        const markup = overrides.commercial?.markup !== undefined ? number(overrides.commercial.markup) : quoteMarkup;
        const errors = markup === null ? ['Acréscimo sobre o custo: indique uma percentagem não negativa.'] : [];
        return { version: 1, method: 'cost-plus', quoteId: quote.id, quoteMarkup, markup, errors };
    }
    function materialSale(quantity, purchaseUnitPrice, manualUnitPrice, margin) {
        margin = effectiveMargin(margin);
        if (quantity === null || manualUnitPrice === null || margin === null) return null;
        if (manualUnitPrice > 0 && margin === 0) return quantity * manualUnitPrice;
        if (purchaseUnitPrice === null) return null;
        if (manualUnitPrice === 0) return quantity * purchaseUnitPrice * (1 + margin / 100);
        return quantity * (manualUnitPrice + purchaseUnitPrice * (1 + margin / 100));
    }
    function customerPrice(data, sale, quantity) {
        const discount = number(data.commercial_pricing?.[0]?.discount ?? 0);
        const extraDiscount = number(data.commercial_pricing?.[0]?.extra_discount ?? 0);
        if (discount === null || discount > 100) {
            throw new Error('Desconto do cliente inválido; indique uma percentagem entre 0 e 100.');
        }
        if (extraDiscount === null || discount + extraDiscount > 100) {
            throw new Error('Desconto extra inválido; a soma dos descontos deve estar entre 0 e 100%.');
        }
        const totalDiscount = discount + extraDiscount;
        if (sale === null) {
            return { discountPercent: discount, extraDiscountPercent: extraDiscount,
                totalDiscountPercent: totalDiscount, sale: null };
        }
        if (!Number.isFinite(sale) || sale < 0 || !Number.isFinite(Number(quantity)) || Number(quantity) <= 0) {
            throw new Error('Valor ou quantidade inválida para aplicar o desconto.');
        }
        const grossSale = Math.round(100 * (sale + Number.EPSILON)) / 100;
        const discountedSale = Math.round(100 * (grossSale * (1 - totalDiscount / 100) + Number.EPSILON)) / 100;
        return { discountPercent: discount, extraDiscountPercent: extraDiscount, totalDiscountPercent: totalDiscount,
            grossSale, discountAmount: Math.round(100 * (grossSale - discountedSale)) / 100,
            sale: discountedSale, unitSale: discountedSale / Number(quantity) };
    }

    function currentTariff(data, operation) {
        const rows = data.costing_tariffs.filter(row => Number(row.operation_id) === Number(operation));
        if (rows.length !== 1) return { error: rows.length ? 'Mais de uma tarifa vigente.' : 'Sem tarifa vigente.' };
        const row = rows[0];
        if (!['h', 'serviço'].includes(row.basis)) return { error: 'Base da tarifa não suportada: ' + row.basis };
        return { id: row.id, version: row.version, basis: row.basis,
            cost: number(row.cost), sale: Array.isArray(data.commercial_pricing) ? number(row.cost) : number(row.sale),
            start: row.starts_at, end: row.ends_at };
    }

    function production(data, row) {
        const selected = String(row.equipment || '').split(',').filter(id => id && id !== '0');
        const options = data.costing_equipment.filter(item => Number(item.operation_id) === Number(row.operation_id) &&
            (!selected.length || String(item.machine_id).split(',').some(id => selected.includes(id))));
        const equipment = options.length === 1 ? options[0] : null;
        const sharedParameters = options.length > 1 && options.every(item =>
            String(item.unit || '') === String(options[0].unit || '') &&
            number(item.speed) === number(options[0].speed) &&
            number(item.setup) === number(options[0].setup));
        const commonEquipment = equipment || (sharedParameters ? options[0] : null);
        return {
            unit: commonEquipment?.unit || row.unit || '',
            speed: number(commonEquipment?.speed) ?? number(row.speed),
            setup: number(commonEquipment?.setup) ?? number(row.setup),
            equipment: equipment ? { id: equipment.id, machineId: equipment.machine_id } : null,
            ambiguous: (options.length > 1 || selected.length > 1) && !sharedParameters
        };
    }

    function spiralArticleSpec(article) {
        const name = String(article?.name || '');
        const diameter = name.match(/N[º°o]\s*(\d+)/i);
        const turns = name.match(/(\d+)\s*(?:v\b|voltas?\b)/i);
        return { diameter: diameter ? Number(diameter[1]) : null, turns: turns ? Number(turns[1]) : null };
    }

    function totalPlans(snapshot) {
        const parts = snapshot.parts || snapshot.pagination?.parts;
        const papers = parts?.length ? parts.map(part => part.paperCalculation) : [snapshot.paperCalculation];
        if (!papers.every(paper => number(paper?.goodPlans) !== null && number(paper?.wastePlans) !== null &&
            number(paper.goodPlans) >= 0 && number(paper.wastePlans) >= 0)) return null;
        return papers.reduce((total, paper) => total + number(paper.goodPlans) + number(paper.wastePlans), 0);
    }

    function automaticQuantity(unit, snapshot, operationId) {
        const result = snapshot.results || {};
        if (Number(operationId) === 30) return totalPlans(snapshot);
        if (unit === 'folhas/h' && Number(operationId) === 48) {
            return totalPlans(snapshot);
        }
        if (unit === 'entregas/h') {
            const deliveries = number(snapshot.component?.operation_quantities?.[12]);
            return Number.isSafeInteger(deliveries) && deliveries > 0 ? deliveries : null;
        }
        if (unit === 'vincos/h') {
            const folds = number(snapshot.component?.folding_count), quantity = number(snapshot.quantity);
            return Number.isSafeInteger(folds) && folds > 0 && quantity > 0 &&
                Number.isSafeInteger(folds * quantity) ? folds * quantity : null;
        }
        if (unit === 'folhas/h') {
            if (number(result.goodPlans) !== null) return number(result.goodPlans);
            const parts = snapshot.parts || snapshot.pagination?.parts;
            if (parts?.length && parts.every(part => number(part.paperCalculation?.goodPlans) !== null)) {
                return parts.reduce((total, part) => total + number(part.paperCalculation.goodPlans), 0);
            }
            return null;
        }
        if (unit === 'chapas/h') return number(result.plates);
        if (unit === 'ficheiros/h') return 1;
        if (['peças/h', 'jogos/h', 'revistas/h', 'livros/h', 'cadernos/h'].includes(unit)) {
            return number(snapshot.quantity);
        }
        return null;
    }

    function inkImpressions(snapshot) {
        const raw = snapshot.mixed?.offset?.result?.impressions ?? snapshot.results?.impressions;
        if (raw === null || raw === undefined || String(raw).trim() === '') return null;
        const value = Number(raw);
        return Number.isSafeInteger(value) && value >= 0 ? value : null;
    }

    function inkColor(color) {
        const ids = { 3728: 'Cyan', 3729: 'Magenta', 3730: 'Yellow', 3731: 'Black', 3732: 'Pantone' };
        const value = String(color.color_name || color.color_label || '').trim().toLowerCase();
        const names = { cyan: 'Cyan', magenta: 'Magenta', yellow: 'Yellow', black: 'Black',
            pantone: 'Pantone', 'sem impressão': null };
        return ids[Number(color.color)] || names[value] || null;
    }

    function inkCalibration(data) {
        const generalErrors = [];
        const colorErrors = Object.fromEntries(['Cyan', 'Magenta', 'Yellow', 'Black', 'Pantone']
            .map(color => [color, []]));
        const machineRows = Array.isArray(data.ink_counter_machines) ? data.ink_counter_machines : [];
        const counterRows = Array.isArray(data.ink_counter_periods) ? data.ink_counter_periods : [];
        const purchases = Array.isArray(data.ink_purchase_totals) ? data.ink_purchase_totals : [];
        const latestPrices = Array.isArray(data.ink_latest_prices) ? data.ink_latest_prices : [];
        const knownMachineIds = [5, 6, 7, 9, 56];
        const machinesById = new Map(machineRows.map(row => [Number(row.machine_id), row]));
        for (const machineId of knownMachineIds) {
            if (!machinesById.has(machineId)) generalErrors.push('Falta o registo de auditoria da máquina offset/digital #' + machineId + '.');
        }
        const meta = machineRows[0];
        const expectedPeriods = number(meta?.expected_periods);
        const windowStart = String(meta?.window_start || '');
        const windowEnd = String(meta?.window_end || '');
        if (expectedPeriods !== 36 || !/^\d{4}-\d{2}-01$/.test(windowStart) ||
            !/^\d{4}-\d{2}-\d{2}$/.test(windowEnd)) {
            generalErrors.push('Período de calibração incompleto: são necessários 36 meses civis fechados.');
        }
        const expectedMonths = [];
        if (!generalErrors.some(error => error.startsWith('Período de calibração incompleto'))) {
            const [year, month] = windowStart.slice(0, 7).split('-').map(Number);
            for (let index = 0; index < expectedPeriods; index++) {
                const date = new Date(Date.UTC(year, month - 1 + index, 1));
                expectedMonths.push(date.toISOString().slice(0, 7));
            }
            if (expectedMonths.at(-1) !== windowEnd.slice(0, 7)) {
                generalErrors.push('Os limites do período não correspondem a 36 meses completos.');
            }
        }
        const activeMachineIds = [];
        const machineSummaries = [];
        for (const machineId of [5, 6, 7]) {
            const machine = machinesById.get(machineId);
            const rows = counterRows.filter(row => Number(row.machine_id) === machineId);
            const status = String(machine?.status ?? '');
            const active = status === '39';
            const retired = ['3647', '3655'].includes(status);
            if (!machine || (!active && !retired)) {
                generalErrors.push('Estado da máquina offset #' + machineId + ' não é reconhecido; calibração suspensa.');
                continue;
            }
            if (retired && rows.length === 0) {
                machineSummaries.push({ id: machineId, name: machine.name, status, included: false, periods: 0, impressions: 0 });
                continue;
            }
            if (active || rows.length) activeMachineIds.push(machineId);
            const byMonth = new Map();
            for (const row of rows) {
                const key = String(row.period_month || '');
                byMonth.set(key, (byMonth.get(key) || 0) + 1);
                const records = Number(row.records);
                const invalid = Number(row.invalid_records);
                const negative = Number(row.negative_records);
                const impressions = Number(row.impressions);
                if (records !== 1 || invalid !== 0 || negative !== 0 ||
                    !Number.isSafeInteger(impressions) || impressions < 0) {
                    generalErrors.push('Contador offset #' + machineId + ' contém registos duplicados, nulos, negativos ou inválidos.');
                }
            }
            const missingMonths = expectedMonths.filter(month => byMonth.get(month) !== 1);
            if (missingMonths.length) {
                generalErrors.push('Contador offset #' + machineId + ' não tem exatamente um registo válido em cada um dos 36 meses (' +
                    missingMonths.slice(0, 3).join(', ') + (missingMonths.length > 3 ? ', …' : '') + ').');
            }
            const periods = expectedMonths.map(month => {
                const row = rows.find(candidate => String(candidate.period_month) === month);
                return { month, records: row ? Number(row.records) : 0,
                    invalidRecords: row ? Number(row.invalid_records) : null,
                    negativeRecords: row ? Number(row.negative_records) : null,
                    impressions: row && Number.isSafeInteger(Number(row.impressions)) ?
                        Number(row.impressions) : null };
            });
            const totalImpressions = rows.reduce((total, row) => {
                const value = Number(row.impressions);
                return Number.isSafeInteger(value) && value >= 0 ? total + value : total;
            }, 0);
            machineSummaries.push({ id: machineId, name: machine?.name || 'Máquina #' + machineId,
                status, included: active || rows.length > 0, periods: rows.length, monthly: periods,
                impressions: totalImpressions });
        }
        const totalImpressions = machineSummaries.filter(row => row.included)
            .reduce((total, row) => total + row.impressions, 0);
        if (!activeMachineIds.length || !(totalImpressions > 0)) {
            generalErrors.push('Não há contadores completos de máquinas offset ativas para estimar tinta.');
        }
        const byColor = {};
        for (const color of ['Cyan', 'Magenta', 'Yellow', 'Black', 'Pantone']) {
            const unitRows = purchases.filter(row => String(row.color) === color && String(row.unit) === '3650');
            const purchase = unitRows.length === 1 ? unitRows[0] : null;
            const quantity = purchase ? Number(purchase.quantity) : NaN;
            if (!purchase || !Number.isFinite(quantity) || quantity <= 0) {
                colorErrors[color].push(color + ': compras positivas em kg não estão disponíveis no período.');
            }
            const articleIds = purchase?.article_ids ? String(purchase.article_ids).split(',').map(Number).filter(Number.isSafeInteger) : [];
            let pricePerKg = null;
            let priceSource = null;
            let latest = null;
            if (color === 'Pantone') {
                const unpriced = Number(purchase?.unpriced_quantity);
                const weighted = purchase?.weighted_price === null || purchase?.weighted_price === undefined ?
                    null : Number(purchase.weighted_price);
                if (!Number.isFinite(unpriced) || unpriced > 0) {
                    colorErrors[color].push('Pantone: há compras em kg sem preço; a média ponderada não é completa.');
                } else if (Number.isFinite(weighted) && weighted >= 0) {
                    pricePerKg = weighted;
                    priceSource = { method: 'weighted-average-by-kg', purchases: Number(purchase.purchases),
                        kilograms: quantity, amount: Number(purchase.quantity) * weighted, articleIds };
                } else {
                    colorErrors[color].push('Pantone: não foi possível calcular o preço médio ponderado por kg.');
                }
            } else {
                const candidates = latestPrices.filter(row => String(row.color) === color);
                latest = candidates.length === 1 ? candidates[0] : null;
                if (!latest) {
                    colorErrors[color].push(color + ': não existe uma última compra concluída identificável.');
                } else if (String(latest.unit) !== '3650') {
                    colorErrors[color].push(color + ': a última compra não está em kg; não existe conversão aprovada.');
                } else if (latest.price === null || latest.price === undefined || String(latest.price).trim() === '') {
                    colorErrors[color].push(color + ': o preço da última compra em kg está em falta.');
                } else if (Number.isFinite(Number(latest.price)) && Number(latest.price) >= 0) {
                    pricePerKg = Number(latest.price);
                    priceSource = { method: 'latest-completed-purchase', purchaseId: Number(latest.purchase_id),
                        purchaseDate: latest.purchase_date, articleId: Number(latest.article_id),
                        articleName: latest.article_name, quantity: Number(latest.quantity), unit: String(latest.unit) };
                } else {
                    colorErrors[color].push(color + ': preço da última compra inválido.');
                }
            }
            const kgPerImpression = purchase && Number.isFinite(quantity) && quantity > 0 &&
                totalImpressions > 0 && !generalErrors.length ? quantity / totalImpressions : null;
            byColor[color] = {
                color, kilograms: Number.isFinite(quantity) ? quantity : null,
                purchases: purchase ? Number(purchase.purchases) : null, articleIds,
                categories: purchase?.categories || null, totalImpressions: totalImpressions || null,
                kgPerImpression, kgPerThousandImpressions: kgPerImpression === null ? null : kgPerImpression * 1000,
                pricePerKg, priceSource, errors: [...colorErrors[color]]
            };
        }
        const excludedPurchases = [];
        for (const row of purchases) {
            const quantity = Number(row.quantity);
            if (String(row.unit) !== '3650' || String(row.color) === 'excluded') {
                excludedPurchases.push({ color: String(row.color), unit: String(row.unit),
                    unitName: row.unit_name || null,
                    quantity: Number.isFinite(quantity) ? quantity : null, purchases: Number(row.purchases),
                    articleIds: row.article_ids || '', categories: row.categories || '' });
            }
            if (String(row.color) === 'excluded') {
                const categories = String(row.categories || '').split(',').map(value => value.trim());
                const subcategories = categories.filter(value => value && value !== '191');
                const articleIds = String(row.article_ids || '').split(',').map(value => value.trim()).filter(Boolean);
                const knownNonInk = articleIds.length > 0 && articleIds.every(id => id === '2177');
                if (!knownNonInk && subcategories.some(value => !['219', '220'].includes(value))) {
                    generalErrors.push('Há compras de tinta sem classificação CMYK/Pantone confirmada (categorias ' +
                        subcategories.join(', ') + '); calibração não pode omiti-las.');
                }
            }
        }
        const auditMachines = machineRows.map(row => ({
            id: Number(row.machine_id), name: row.name, status: String(row.status),
            role: [5, 6, 7].includes(Number(row.machine_id)) ? 'offset' : 'digital-excluded'
        }));
        return {
            version: 1, method: '36 complete calendar months; kg purchased treated as estimated consumption; no stock adjustment',
            window: { start: windowStart, end: windowEnd, calibratedAt: meta?.calibrated_at || null,
                expectedPeriods: expectedPeriods || null },
            machineIds: machineSummaries.filter(row => row.included).map(row => row.id),
            machines: machineSummaries, auditMachines, expectedMonths, totalImpressions: totalImpressions || null,
            colors: byColor, excludedPurchases, generalErrors: [...new Set(generalErrors)],
            errorsByColor: colorErrors, complete: !generalErrors.length &&
                !Object.values(colorErrors).some(errors => errors.length)
        };
    }

    function administrativeCalibration(data) {
        const row = Array.isArray(data.administrative_calibration) ? data.administrative_calibration[0] : null;
        const errors = [];
        const janineRate = number(row?.janine_rate);
        const carlaRate = number(row?.carla_rate);
        const invoiceCount = number(row?.invoice_count);
        const opCount = number(row?.op_count);
        const workingDaysPerYear = 220;
        const hoursPerDay = 8;
        const parseDate = value => {
            if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
            const date = new Date(value + 'T00:00:00Z');
            return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
        };
        const start = parseDate(row?.period_start), end = parseDate(row?.period_end);
        let periodYears = null;
        if (start && end && end >= start && end - start <= 3660 * 86400000) {
            periodYears = 0;
            const exclusiveEnd = end.getTime() + 86400000;
            for (let year = start.getUTCFullYear(); year <= end.getUTCFullYear(); year++) {
                const yearStart = Date.UTC(year, 0, 1), yearEnd = Date.UTC(year + 1, 0, 1);
                periodYears += (Math.min(exclusiveEnd, yearEnd) - Math.max(start.getTime(), yearStart)) /
                    (yearEnd - yearStart);
            }
        }
        if (janineRate === null || carlaRate === null) errors.push('Custos administrativos: custo/h de Janine ou Carla em falta.');
        if (!Number.isSafeInteger(invoiceCount) || invoiceCount < 0) errors.push('Custos administrativos: número de faturas inválido.');
        if (!Number.isSafeInteger(opCount) || opCount < 1) errors.push('Custos administrativos: número de OP inválido.');
        if (!(periodYears > 0)) errors.push('Custos administrativos: período de referência das faturas inválido.');
        const annualInvoices = Number.isSafeInteger(invoiceCount) && periodYears > 0 ? invoiceCount / periodYears : null;
        const annualDocuments = annualInvoices !== null && Number.isSafeInteger(opCount) ? opCount + annualInvoices : null;
        const annualWorkingHours = workingDaysPerYear * hoursPerDay;
        const hourlyTotal = janineRate !== null && carlaRate !== null ? janineRate + carlaRate : null;
        const documentsPerHour = annualDocuments !== null && annualWorkingHours > 0 ?
            annualDocuments / annualWorkingHours : null;
        const costPerComponent = documentsPerHour > 0 && hourlyTotal !== null ? hourlyTotal / documentsPerHour : null;
        if (!(costPerComponent >= 0 && Number.isFinite(costPerComponent))) {
            errors.push('Custos administrativos: não foi possível calcular o custo unitário por componente.');
        }
        return {
            version: 2, method: 'hourly-cost-over-documents-per-hour',
            periodStart: String(row?.period_start || ''), periodEnd: String(row?.period_end || ''),
            excludedType: String(row?.excluded_type || ''), invoiceCount, periodYears, annualInvoices,
            opCount, annualDocuments, workingDaysPerYear, hoursPerDay, annualWorkingHours, documentsPerHour,
            janineRate, carlaRate, hourlyTotal, costPerComponent, errors: [...new Set(errors)]
        };
    }

    function setupWaste(operations, machineId, supplied) {
        const requested = number(supplied);
        const sources = [];
        const errors = [];
        if (!Number.isSafeInteger(requested)) errors.push('Acerto: indique um número inteiro de planos não negativo.');
        for (const row of operations) {
            if (isPrinting(row) && !String(row.machines || '').split(',').includes(String(machineId))) continue;
            if (row.waste === undefined || row.waste === null || String(row.waste).trim() === '') continue;
            const plans = number(row.waste);
            if (!Number.isSafeInteger(plans)) {
                errors.push(row.name + ': planos de acerto inválidos.');
                continue;
            }
            sources.push({ operationId: Number(row.operation_id), name: row.name, plans });
        }
        const plans = errors.length ? null : Math.max(requested, ...sources.map(source => source.plans));
        return { version: 1, rule: 'max', plans, requested, sources, errors,
            governing: plans === null ? [] : sources.filter(source => source.plans === plans).map(source => source.name) };
    }

    function adhesiveLayout(sheetWidth, sheetHeight, width, height) {
        const values = [sheetWidth, sheetHeight, width, height].map(number);
        if (values.some(value => value === null || value <= 0)) {
            return { errors: ['Indique largura e altura positivas, em mm.'] };
        }
        // Integer micrometres avoid fitting an extra piece through floating-point rounding.
        const scaled = values.map(value => Math.round(value * 1000));
        if (scaled.some((value, index) => !Number.isSafeInteger(value) || value <= 0 ||
            Math.abs(value / 1000 - values[index]) > 1e-8)) {
            return { errors: ['Dimensões: use no máximo três casas decimais em mm.'] };
        }
        const [sw, sh, pw, ph] = scaled;
        let best = { piecesPerSheet: 0, strips: [], axis: 'horizontal' };
        for (const [availableWidth, availableHeight, axis] of [[sw, sh, 'horizontal'], [sh, sw, 'vertical']]) {
            for (const [w, h, rotated] of [[pw, ph, false], [ph, pw, true]]) {
                const limit = Math.floor(availableHeight / h);
                if (limit > 10000) return { errors: ['Dimensão demasiado pequena para validar o plano de corte.'] };
                const perRow = Math.floor(availableWidth / w);
                const rotatedPerRow = Math.floor(availableWidth / h);
                for (let rows = 0; rows <= limit; rows++) {
                    if (rows && !perRow) continue;
                    const otherRows = Math.floor((availableHeight - rows * h) / w);
                    const piecesPerSheet = rows * perRow + otherRows * rotatedPerRow;
                    if (!Number.isSafeInteger(piecesPerSheet)) return { errors: ['Aproveitamento fora dos limites numéricos.'] };
                    if (piecesPerSheet > best.piecesPerSheet) {
                        best = { piecesPerSheet, axis, strips: [
                            { rows, perRow, rotated },
                            { rows: otherRows, perRow: rotatedPerRow, rotated: !rotated }
                        ].filter(strip => strip.rows > 0 && strip.perRow > 0) };
                    }
                }
            }
        }
        return best.piecesPerSheet ? { ...best, errors: [], method: 'faixas horizontais/verticais com rotação de 90°',
            sheetWidth, sheetHeight, width, height, wasteFraction: 1 - best.piecesPerSheet * pw * ph / (sw * sh) } :
            { errors: ['A peça de BiAdesivo não cabe na folha, mesmo rodada.'] };
    }

    function adhesiveCalculation(article, input, quantity) {
        const errors = [];
        if (!article || Number(article.unit) !== 3648) errors.push('Artigo de BiAdesivo em folhas não disponível.');
        const perCopy = number(input.perCopy);
        const copies = number(quantity);
        if (!Number.isSafeInteger(perCopy) || perCopy <= 0) errors.push('Indique um número inteiro positivo de peças por exemplar.');
        if (!Number.isSafeInteger(copies) || copies <= 0) errors.push('Quantidade de exemplares inválida.');
        const layout = adhesiveLayout(article?.width, article?.height, input.width, input.height);
        errors.push(...layout.errors);
        const pieces = errors.length ? null : perCopy * copies;
        if (pieces !== null && !Number.isSafeInteger(pieces)) errors.push('Quantidade de peças fora dos limites numéricos.');
        const sheets = errors.length ? null : Math.ceil(pieces / layout.piecesPerSheet);
        const manualPrice = input.purchasePrice !== undefined && input.purchasePrice !== '';
        const purchasePrice = number(manualPrice ? input.purchasePrice : article?.price);
        if (purchasePrice === null || purchasePrice <= 0) errors.push('Preço de compra por folha de BiAdesivo em falta ou inválido.');
        const cost = sheets !== null && purchasePrice > 0 ? sheets * purchasePrice : null;
        if (cost !== null && !Number.isFinite(cost)) errors.push('Custo do BiAdesivo fora dos limites numéricos.');
        return { perCopy, copies, pieces, sheets, layout, purchasePrice, manualPrice,
            cost: errors.length ? null : cost, errors };
    }

    function adhesiveInputs(row, overrides = {}) {
        const fields = { perCopy: 'adhesive_per_copy', width: 'adhesive_width', height: 'adhesive_height',
            margin: 'adhesive_margin', purchasePrice: 'adhesive_purchase_price' };
        const inputs = {};
        for (const [key, field] of Object.entries(fields)) {
            if (Object.prototype.hasOwnProperty.call(row, field)) inputs[key] = row[field] ?? '';
        }
        const result = { ...inputs, ...overrides };
        if (result.margin === undefined || result.margin === null || String(result.margin).trim() === '') {
            result.margin = '30';
        }
        return result;
    }

    function guillotineGrid({ width, height, pieceWidth, pieceHeight, columns, rows, x = 0, y = 0,
        gap = 0, sheets, capacity = 250 }) {
        const values = [width, height, pieceWidth, pieceHeight, columns, rows, x, y, gap, sheets, capacity].map(number);
        if (values.some(value => value === null) ||
            [width, height, pieceWidth, pieceHeight].some(value => value <= 0) ||
            ![columns, rows, sheets, capacity].every(value => Number.isSafeInteger(value) && value > 0) ||
            columns * rows > 1000000) return { errors: ['Dimensões, grelha, quantidade ou folhas por pilha inválidas/em falta.'] };
        const occupiedWidth = columns * pieceWidth + (columns - 1) * gap;
        const occupiedHeight = rows * pieceHeight + (rows - 1) * gap;
        const epsilon = 1e-6;
        if (x + occupiedWidth > width + epsilon || y + occupiedHeight > height + epsilon) {
            return { errors: ['A grelha de corte não cabe no plano.'] };
        }
        const batches = Math.ceil(sheets / capacity);
        const outerCutsPerBatch = Number(x > epsilon) + Number(y > epsilon) +
            Number(width - x - occupiedWidth > epsilon) + Number(height - y - occupiedHeight > epsilon);
        const boundaryCutsX = (columns - 1) * (gap > epsilon ? 2 : 1);
        const boundaryCutsY = (rows - 1) * (gap > epsilon ? 2 : 1);
        // After separating strips, equal strips can be restacked within the chosen capacity.
        const vertical = boundaryCutsX * batches + boundaryCutsY * Math.ceil(columns * sheets / capacity);
        const horizontal = boundaryCutsY * batches + boundaryCutsX * Math.ceil(rows * sheets / capacity);
        const cuts = outerCutsPerBatch * batches + Math.min(vertical, horizontal);
        if (!Number.isSafeInteger(cuts)) return { errors: ['Número de cortes fora dos limites numéricos.'] };
        return { errors: [], cuts, capacity, sheets, batches, columns, rows, gap,
            outerCutsPerBatch, boundaryCutsX, boundaryCutsY,
            direction: vertical <= horizontal ? 'colunas primeiro' : 'filas primeiro',
            method: 'aparas exteriores, separação em tiras e reempilhamento até ' + capacity };
    }

    function cuttingPile(thickness, override) {
        if (override !== undefined && override !== null && String(override).trim() !== '') {
            const capacity = number(override);
            return Number.isSafeInteger(capacity) && capacity > 0 ?
                { capacity, source: 'capacidade manual' } : { errors: ['Folhas por pilha: indique um inteiro positivo.'] };
        }
        if (thickness === undefined || thickness === null || String(thickness).trim() === '' || number(thickness) === 0) {
            return { capacity: 250, source: 'sem espessura: 250 folhas por defeito' };
        }
        const mm = number(thickness);
        if (mm === null || mm > 80) return { errors: ['Espessura do papel inválida para pilha de 80 mm: reveja o catálogo ou indique capacidade manual.'] };
        const capacity = Math.floor((80 + 1e-10) / mm);
        if (!Number.isSafeInteger(capacity) || capacity < 1) return { errors: ['Capacidade calculada fora dos limites: reveja a espessura.'] };
        return { capacity, thickness: mm, height: 80, source: '80 mm ÷ ' + mm + ' mm = ' + capacity + ' folhas/pilha' };
    }

    function paperThickness(paper, overrides = {}) {
        const catalogue = number(paper?.thickness);
        if (catalogue > 0 && catalogue <= 80) return paper.thickness;
        const manual = overrides['paper:' + paper?.id]?.thickness;
        return manual === undefined || manual === null || String(manual).trim() === '' ? paper?.thickness : manual;
    }

    function cuttingCalculation(snapshot, operationId, capacity, overrides = {}) {
        const parts = snapshot.parts?.length ? snapshot.parts : snapshot.pagination?.parts?.length ?
            snapshot.pagination.parts : [{ plan: snapshot.plan?.catalogue || snapshot.plan,
                paperCalculation: snapshot.paperCalculation, result: snapshot.results }];
        const details = [];
        for (const [index, part] of parts.entries()) {
            const plan = part.plan;
            const paper = part.paperCalculation;
            const suggestion = plan?.suggestion;
            if (!plan || !paper) return { errors: ['Sem plano e consumo de papel para calcular os cortes.'] };
            const thickness = paperThickness(paper.paper, overrides);
            const pile = cuttingPile(thickness, capacity);
            if (pile.errors) return pile;
            const planWidth = number(suggestion?.planWidth ?? plan.width);
            const planHeight = number(suggestion?.planHeight ?? plan.height);
            let grid;
            if (Number(operationId) === 2) {
                const sheetWidth = number(suggestion?.sheetWidth ?? plan.sheet_width);
                const sheetHeight = number(suggestion?.sheetHeight ?? plan.sheet_height);
                const perSheet = number(plan.per_sheet ?? snapshot.plan?.plansPerSheet);
                const sheets = number(paper.sheets);
                if (![sheetWidth, sheetHeight, planWidth, planHeight].every(value => value > 0) ||
                    !Number.isSafeInteger(perSheet) || perSheet <= 0) {
                    return { errors: ['Formato da folha, do plano ou planos/folha em falta para o corte inicial.'] };
                }
                const candidates = [];
                for (let columns = 1; columns <= perSheet && columns <= 10000; columns++) {
                    if (perSheet % columns) continue;
                    const rows = perSheet / columns;
                    if (suggestion && (columns !== Number(suggestion.cutsX) || rows !== Number(suggestion.cutsY))) continue;
                    const candidate = guillotineGrid({ width: sheetWidth, height: sheetHeight,
                        pieceWidth: planWidth, pieceHeight: planHeight, columns, rows, sheets, capacity: pile.capacity });
                    if (!candidate.errors.length) candidates.push(candidate);
                }
                if (!candidates.length) return { errors: ['Não foi possível validar a divisão da folha em planos para o corte inicial.'] };
                grid = candidates.sort((a, b) => a.cuts - b.cuts)[0];
            } else {
                if (snapshot.pagination || ([1545, 1546, 1547, 1548].includes(Number(snapshot.component?.binding)) &&
                    !(snapshot.component?.flat_folding && Number(snapshot.component?.binding) === 1545)) ||
                    ['true', '1'].includes(String(snapshot.component?.has_die))) {
                    return { errors: ['Corte final de cadernos/cortantes exige sequência específica: indique cortes ou tempo total.'] };
                }
                if (!suggestion) {
                    return { errors: ['Plano de catálogo sem grelha/posição de corte: use um plano dinâmico ou indique cortes/tempo.'] };
                }
                const component = snapshot.component || {};
                const pieceWidth = number(suggestion.rotated ? component.height : component.width);
                const pieceHeight = number(suggestion.rotated ? component.width : component.height);
                const gap = number(suggestion.gap);
                const bleed = number(suggestion.bleed);
                const gripper = number(suggestion.gripper);
                const usableHeight = number(suggestion.usableHeight);
                const reserved = number(suggestion.reservedGrippers);
                if ([bleed, gripper, usableHeight, reserved, number(suggestion.occupiedWidth),
                    number(suggestion.occupiedHeight)].some(value => value === null)) {
                    return { errors: ['Geometria de sangrias/pinças incompleta para o corte final.'] };
                }
                const x = (planWidth - Number(suggestion.occupiedWidth)) / 2 + bleed;
                const y = gripper + (planHeight - reserved * gripper - usableHeight) / 2 +
                    (usableHeight - Number(suggestion.occupiedHeight)) / 2 + bleed;
                grid = guillotineGrid({ width: planWidth, height: planHeight, pieceWidth, pieceHeight,
                    columns: Number(suggestion.columns), rows: Number(suggestion.rows), x, y, gap,
                    sheets: number(paper.goodPlans), capacity: pile.capacity });
            }
            if (grid.errors.length) return grid;
            details.push({ mounting: index + 1, planId: plan.id, paperId: paper.paper?.id,
                paperThickness: thickness, ...grid, pile });
        }
        const cuts = details.reduce((total, detail) => total + detail.cuts, 0);
        return { errors: [], cuts, capacity: details.every(d => d.capacity === details[0].capacity) ? details[0].capacity : null,
            details, version: 2,
            scope: Number(operationId) === 2 ? 'todas as folhas, incluindo acerto' : 'planos úteis, sem folhas de acerto' };
    }

    function paperWeight(snapshot, overrides = {}) {
        const parts = snapshot.parts?.length ? snapshot.parts : snapshot.pagination?.parts?.length ?
            snapshot.pagination.parts : [{ paperCalculation: snapshot.paperCalculation }];
        let kg = 0;
        const details = [];
        for (const part of parts) {
            const paper = part.paperCalculation;
            const gsm = number(paper?.paper?.weight);
            const area = number(paper?.sheetArea);
            const waste = number(paper?.totalWaste);
            if (!(gsm > 0) || !(area > 0) || waste === null || waste < 0 || waste >= 1) {
                return { errors: ['Gramagem (g/m²) ou área líquida do papel em falta: recalcule os planos do componente.'] };
            }
            const netAreaM2 = area * (1 - waste) / 1000000;
            const weightKg = netAreaM2 * gsm / 1000;
            if (!Number.isFinite(weightKg) || weightKg <= 0) return { errors: ['Peso de papel inválido.'] };
            kg += weightKg;
            details.push({ articleId: paper.paper.id, name: paper.paper.name, gsm, thickness: paperThickness(paper.paper, overrides),
                netAreaM2, kg: weightKg });
        }
        return { errors: [], kg, details };
    }

    function packagingState(data) {
        return { components: data.quote_components, owners: data.quote_packaging,
            selections: data.quote_selections?.map(selection => {
                try {
                    const snapshot = JSON.parse(selection.details).calculation;
                    return { id: selection.id, componentId: selection.component_id, quantityId: selection.quantity_id,
                        quantity: selection.quantity, component: snapshot?.component, weight: paperWeight(snapshot || {}) };
                } catch (error) {
                    return { id: selection.id, error: 'Simulação inválida: ' + error.message };
                }
            }) };
    }

    function packagingCalculation(data, snapshot, row, maxKg, overrides = {}) {
        const owner = data.quote_packaging?.[0] || { id: row.id, component_id: snapshot.component?.id };
        const ownerRow = String(owner.id) === String(row.id);
        const limit = number(ownerRow ? maxKg : owner.packaging_kg || 10);
        if (!(limit > 0)) return { errors: ['Peso por embalagem: indique kg positivos.'] };
        if (!ownerRow) return { errors: [], packages: 0, owner, maxKg: limit, version: 1,
            source: 'Embalamento conjunto contabilizado apenas no componente #' + owner.component_id +
                ', operação #' + owner.id + '; não duplicado neste componente.' };
        const quantity = number(snapshot.quantity);
        if (!Number.isSafeInteger(quantity) || quantity <= 0) return { errors: ['Tiragem inválida para embalamento.'] };
        const components = data.quote_components || [{ id: snapshot.component?.id }];
        const weights = [];
        for (const component of components) {
            let source = snapshot;
            let selectionId = null;
            if (String(component.id) !== String(snapshot.component?.id)) {
                const selections = (data.quote_selections || []).filter(s =>
                    String(s.component_id) === String(component.id) &&
                    String(s.quantity_id) === String(snapshot.quantityId) && number(s.quantity) === quantity);
                if (selections.length !== 1) return { errors: ['Componente #' + component.id +
                    ': guarde uma única simulação para esta tiragem antes de calcular o embalamento conjunto.'] };
                const selection = selections[0];
                try {
                    source = JSON.parse(selection.details).calculation;
                } catch (error) {
                    return { errors: ['Simulação do componente #' + component.id + ' inválida: ' + error.message] };
                }
                if (!source || number(source.quantity) !== quantity) return { errors: ['Tiragem guardada incoerente no componente #' + component.id + '.'] };
                for (const key of ['width', 'height', 'pages', 'binding']) {
                    if (String(source.component?.[key] ?? '') !== String(component[key] ?? '')) {
                        return { errors: ['Dimensões/paginação do componente #' + component.id +
                            ' alteradas: recalcule esse componente antes do embalamento.'] };
                    }
                }
                selectionId = selection.id;
            }
            const weight = paperWeight(source, overrides);
            if (weight.errors.length) return { errors: weight.errors.map(e => 'Componente #' + component.id + ': ' + e) };
            weights.push({ componentId: component.id, selectionId, component: source.component, ...weight });
        }
        const kg = weights.reduce((sum, w) => sum + w.kg, 0);
        const kgPerCopy = kg / quantity;
        const copiesPerPackage = Math.floor(limit / kgPerCopy + 1e-10);
        if (copiesPerPackage < 1) return { errors: ['Um exemplar completo excede ' + limit +
            ' kg de papel. Reveja o limite ou a divisão física das embalagens.'] };
        const packages = Math.ceil(quantity / copiesPerPackage);
        return { errors: [], version: 2, owner, maxKg: limit, kg, kgPerCopy, copiesPerPackage, packages, weights,
            quantity,
            source: 'Embalamento conjunto · ' + kg.toFixed(3) + ' kg de papel líquido · ' + limit +
                ' kg/embalagem · até ' + copiesPerPackage + ' exemplares completos/embalagem · ' +
                packages + ' embalagens · gramagem em g/m² · sem caixa, aparas ou acerto' };
    }

    function packagingInputs(row, overrides = {}) {
        const values = { type: row.packaging_type || '1', box: row.packaging_box || '',
            width: row.packaging_width ?? '', length: row.packaging_length ?? '', height: row.packaging_height ?? '',
            overlap: row.packaging_overlap === undefined || row.packaging_overlap === null ||
                row.packaging_overlap === '' ? 30 : row.packaging_overlap };
        return { ...values, ...overrides };
    }

    function packagingDimensions(packaging) {
        if (!packaging || packaging.errors?.length || !packaging.weights?.length) {
            return { errors: ['Calcule primeiro o peso e as embalagens de todos os componentes.'] };
        }
        let width = 0, length = 0, heightPerCopy = 0;
        const details = [];
        for (const weight of packaging.weights) {
            const w = number(weight.component?.width), l = number(weight.component?.height);
            if (!(w > 0) || !(l > 0)) return { errors: ['Formato final em falta no componente #' + weight.componentId + '.'] };
            width = Math.max(width, Math.min(w, l));
            length = Math.max(length, Math.max(w, l));
            for (const paper of weight.details) {
                const thickness = number(paper.thickness);
                if (!(thickness > 0) || thickness > 80) return { errors: ['Embrulho automático: espessura real do papel #' +
                    paper.articleId + ' em falta ou inválida (mm). Introduza a espessura no painel “Espessura do papel em falta” e volte a calcular; não é estimada pela gramagem.'] };
                const layers = paper.netAreaM2 * 1000000 / (w * l * packaging.quantity);
                heightPerCopy += layers * thickness;
                details.push({ articleId: paper.articleId, thickness, layersPerCopy: layers });
            }
        }
        const copies = Math.min(packaging.quantity, packaging.copiesPerPackage);
        const height = heightPerCopy * copies;
        if (![width, length, height].every(v => Number.isFinite(v) && v > 0)) {
            return { errors: ['Dimensões automáticas da embalagem inválidas.'] };
        }
        return { errors: [], width, length, height, copies, details, source: 'formato final e camadas de papel × espessura real × exemplares por embalagem' };
    }

    function packagingMaterial(data, input, packages, packaging) {
        if (!Number.isSafeInteger(packages) || packages < 0) return { errors: ['Número de embalagens em falta para calcular o material.'] };
        if (packages === 0) return { errors: [], quantity: 0, cost: 0, sale: 0, source: 'Material contabilizado na operação de embalamento conjunto.' };
        const type = String(input.type);
        if (!['1', '2'].includes(type)) return { errors: ['Selecione Embrulho ou Caixa.'] };
        const article = data.packaging_articles.find(a => String(a.packaging_type) === type &&
            String(a.id) === (type === '1' ? '1328' : String(input.box)));
        if (!article) return { errors: [type === '1' ? 'Kraft 90 g/m² em falta no catálogo.' : 'Selecione o artigo de caixa.'] };
        const price = number(article.price);
        const roll = type === '1';
        const expectedUnit = roll ? 3650 : 3649;
        if (Number(article.unit) !== expectedUnit || !(price > 0)) {
            return { article, errors: ['Preço máximo de compra do artigo por ' +
                (roll ? 'kg' : 'caixa') + ' em falta ou unidade incompatível.'] };
        }
        let quantity = packages;
        let layout = null;
        let dimensions = null;
        if (type === '1') {
            if (number(article.weight) !== 90) return { article, errors: ['A gramagem do kraft deve ser 90 g/m².'] };
            const keys = ['width', 'length', 'height'];
            const missing = keys.filter(key => input[key] === undefined || input[key] === null || String(input[key]).trim() === '');
            if (missing.length) {
                dimensions = packagingDimensions(packaging);
                if (dimensions.errors.length) return { article, errors: dimensions.errors };
                input = { ...input };
                for (const key of missing) input[key] = Math.ceil(dimensions[key] * 1000) / 1000;
            }
            const [w, l, h] = [input.width, input.length, input.height].map(number);
            const overlap = number(input.overlap);
            if (![w, l, h].every(v => v > 0) || overlap === null) {
                return { article, errors: ['Embrulho: indique largura, comprimento e altura positivos do volume (mm), e sobreposição não negativa.'] };
            }
            const panels = [[w, l], [l, w]].map(([width, length]) => ({
                panelWidth: 2 * (width + h) + overlap, panelHeight: length + 2 * h + overlap
            }));
            const rollWidth = number(article.width);
            if (!(rollWidth > 0)) return { article, errors: ['Largura do rolo em falta ou inválida (mm).'] };
            const candidates = panels.flatMap(panel => [
                { ...panel, across: panel.panelWidth, along: panel.panelHeight },
                { ...panel, across: panel.panelHeight, along: panel.panelWidth }
            ]).filter(panel => panel.across <= rollWidth && panel.along > 0)
                .map(panel => ({ ...panel, piecesAcross: Math.floor(rollWidth / panel.across) }));
            for (const candidate of candidates) {
                candidate.lengthMm = Math.ceil(packages / candidate.piecesAcross) * candidate.along;
            }
            layout = candidates.sort((a, b) => a.lengthMm - b.lengthMm)[0];
            if (!layout) return { article, errors: ['O painel do embrulho excede a largura do rolo de ' +
                rollWidth + ' mm em ambos os sentidos. Reveja o volume ou o formato.'] };
            layout.rollWidth = rollWidth;
            layout.linearMetres = layout.lengthMm / 1000;
            layout.areaM2 = rollWidth / 1000 * layout.linearMetres;
            quantity = layout.areaM2 * number(article.weight) / 1000;
        }
        const cost = quantity * price;
        if (!(quantity > 0) || (!roll && !Number.isSafeInteger(quantity)) || !Number.isFinite(cost)) return { article, errors: ['Material de embalagem fora dos limites numéricos.'] };
        return { errors: [], version: 4, type, article: { ...article }, packages, quantity, layout,
            dimensions: type === '1' ? { width: number(input.width), length: number(input.length),
                height: number(input.height), automatic: dimensions } : null, purchaseUnitPrice: price, cost,
            source: (type === '1' ? packages + ' embrulhos · volume ' + input.width + ' × ' + input.length +
                ' × ' + input.height + ' mm · sobreposição ' + input.overlap + ' mm · painel ' +
                layout.panelWidth + ' × ' + layout.panelHeight + ' mm · ' +
                'rolo de ' + layout.rollWidth + ' mm · ' + layout.piecesAcross + ' embrulhos na largura · ' +
                    layout.linearMetres.toFixed(3) + ' m lineares × largura total = ' + layout.areaM2.toFixed(4) +
                    ' m² × 90 g/m² = ' + quantity.toFixed(4) + ' kg (inclui sobra lateral)' +
                ' (mesmo volume máximo para todas as embalagens)' :
                packages + ' caixas · ' + article.name + ' · validar dimensões/capacidade da caixa') +
                ' · última compra concluída do artigo: ' + money(price) + (roll ? '/kg' : '/caixa') };
    }

    const laminationFinishes = { none: 'Sem plastificação', gloss: 'Brilho', matt: 'Mate', 'anti-scuff': 'Antirrisco' };
    function laminationMargin(row, side, input = {}) {
        const override = input[side];
        const value = override === undefined || override === '' ? row?.['lamination_' + side] : override;
        return number(value === undefined || value === null || value === '' ? 10 : value);
    }
    function laminationCalculation(data, snapshot, row, input = {}) {
        const component = snapshot.component || {};
        const faces = ['front', 'back'].map(face => ({ face, finish: component['lamination_' + face] }));
        const errors = faces.filter(face => !Object.hasOwn(laminationFinishes, face.finish))
            .map(face => 'Defina a plastificação — ' + (face.face === 'front' ? 'frente' : 'verso') + ' no componente.');
        if (errors.length) return { errors };
        const active = faces.filter(face => face.finish !== 'none');
        if (!active.length) return { errors: [], version: 1, quantity: 0, setups: 0, materials: [],
            faces, source: 'Componente sem plastificação nas duas faces; sem tempo nem filme.' };
        const waste = number(row.waste);
        const entry = number(input.entry === undefined || input.entry === '' ? row.lamination_entry : input.entry);
        const exit = number(input.exit === undefined || input.exit === '' ? row.lamination_exit : input.exit);
        if (!Number.isSafeInteger(waste) || entry === null || exit === null ||
            !['true', '1'].includes(String(input.narrow === undefined || input.narrow === '' ? row.lamination_narrow : input.narrow))) {
            return { errors: ['Confirme os planos de acerto, margens de entrada/saída e entrada pelo lado mais estreito na operação de Plastificação.'] };
        }
        const parts = snapshot.parts?.length ? snapshot.parts : snapshot.pagination?.parts?.length ?
            snapshot.pagination.parts : [{ plan: snapshot.plan?.catalogue || snapshot.plan, paperCalculation: snapshot.paperCalculation }];
        const materials = [];
        for (const [index, part] of parts.entries()) {
            const plan = part.plan, paper = part.paperCalculation, grid = plan?.suggestion;
            const pw = number(grid?.planWidth ?? plan?.width), ph = number(grid?.planHeight ?? plan?.height);
            const good = number(paper?.goodPlans), bleed = number(grid?.bleed);
            if (!grid || ![pw, ph].every(value => value > 0) || !Number.isSafeInteger(good) || good <= 0 ||
                bleed === null || number(grid.occupiedWidth) === null || number(grid.occupiedHeight) === null) {
                return { errors: ['Montagem ' + (index + 1) + ': geometria das peças em falta para calcular o filme. Use um plano dinâmico validável.'] };
            }
            const across = Math.min(pw, ph), travel = Math.max(pw, ph);
            const requiredWidth = (pw <= ph ? number(grid.occupiedWidth) : number(grid.occupiedHeight)) - 2 * bleed;
            const requiredLength = (pw <= ph ? number(grid.occupiedHeight) : number(grid.occupiedWidth)) - 2 * bleed;
            const gripper = number(grid.gripper), reserved = number(grid.reservedGrippers);
            const usable = number(grid.usableHeight);
            if (!(requiredWidth > 0) || requiredWidth > across || !(requiredLength > 0) ||
                gripper === null || reserved === null || usable === null) {
                return { errors: ['Montagem ' + (index + 1) + ': posição das peças inválida para plastificação.'] };
            }
            const x = (pw - number(grid.occupiedWidth)) / 2 + bleed;
            const y = gripper + (ph - reserved * gripper - usable) / 2 +
                (usable - number(grid.occupiedHeight)) / 2 + bleed;
            const start = pw <= ph ? y : x;
            const crossStart = pw <= ph ? x : y;
            if (crossStart < -1e-8 || crossStart + requiredWidth > across + 1e-8) {
                return { errors: ['Montagem ' + (index + 1) + ': as peças finais excedem a largura do plano.'] };
            }
            if (start + 1e-8 < entry || start + requiredLength > travel - exit + 1e-8) {
                const available = travel - entry - exit;
                return { errors: ['Montagem ' + (index + 1) + ': as peças finais precisam de ' +
                    requiredLength.toFixed(2) + ' mm no sentido de passagem, mas o plano ' + travel +
                    ' mm deixa ' + available.toFixed(2) + ' mm após as margens de entrada (' + entry +
                    ' mm) e saída (' + exit + ' mm). Escolha um plano com mais comprimento útil ou reduza as margens.'] };
            }
            const forms = number(part.forms ?? snapshot.forms) ??
                (number(paper.setupWaste?.plans) > 0 ? number(paper.wastePlans) / number(paper.setupWaste.plans) : 1);
            if (!Number.isSafeInteger(forms) || forms <= 0) return { errors: ['Número de formas inválido para o acerto da plastificação.'] };
            const setupPlans = forms * waste;
            const sheets = good + setupPlans;
            for (const face of active) {
                const selected = input[face.face + 'Article'];
                const films = (data.lamination_articles || []).filter(article => article.finish === face.finish &&
                    (selected === undefined || selected === '' || String(article.id) === String(selected)));
                const compatible = films.filter(article => number(article.width) >= requiredWidth &&
                    number(article.width) <= across &&
                    Number(article.unit) === 3771 && number(article.price) > 0);
                compatible.sort((a, b) => number(a.price) - number(b.price) || number(a.width) - number(b.width) || Number(a.id) - Number(b.id));
                const article = compatible[0];
                if (!article) return { errors: [(face.face === 'front' ? 'Frente' : 'Verso') + ' — ' +
                    laminationFinishes[face.finish] + ': filme compatível em falta (largura mínima ' +
                    requiredWidth + ' a ' + across + ' mm, compra por metro linear e preço positivo). ' +
                    (selected ? 'Reveja o artigo escolhido e o preço de compra.' : 'Preencha o catálogo ou selecione um filme compatível.')] };
                const metres = sheets * travel / 1000;
                materials.push({ face: face.face, finish: face.finish, mounting: index + 1, goodPlans: good,
                    setupPlans, sheets, forms, requiredWidth, travel, entry, exit, article: { ...article },
                    metres, cost: metres * number(article.price) });
            }
        }
        const quantity = sum(materials, 'sheets');
        const setups = new Set(materials.map(material => String(material.article.id))).size;
        return { errors: [], version: 1, quantity, setups, materials, faces,
            source: active.map(face => (face.face === 'front' ? 'Frente' : 'Verso') + ': ' +
                laminationFinishes[face.finish]).join(' · ') + ' · ' + quantity +
                ' passagens de folhas (úteis + acerto próprio por face) · ' + setups + ' acertos de máquina por filme distinto' };
    }

    function ctpExecution(resources, quantity, hours) {
        const errors = [];
        const equipment = resources?.equipment || [];
        const labor = resources?.labor || [];
        const operators = resources?.operators || [];
        const selected = equipment.length === 1 ? equipment[0] : null;
        const equipmentRate = number(selected?.cost);
        if (!selected || equipmentRate === null) errors.push('CTP: equipamento único e custo/h válidos em falta.');
        if (hours === null || !Number.isFinite(hours) || hours < 0) errors.push('CTP: tempo de equipamento inválido.');
        if (!Number.isSafeInteger(quantity) || quantity <= 0) errors.push('CTP: número de chapas inválido.');
        if (!labor.length) errors.push('CTP: associe RH e os tempos de intervenção.');
        const rates = [];
        const settings = [];
        for (const row of labor) {
            const hourlyMode = row.per_hour !== undefined && row.per_hour !== null && String(row.per_hour).trim() !== '';
            const setup = number(row.setup);
            const perUnit = number(row.per_unit);
            const perHour = hourlyMode ? number(row.per_hour) : null;
            const people = number(row.people);
            if ((hourlyMode ? perHour === null || perHour > 60 : setup === null || perUnit === null) ||
                !Number.isSafeInteger(people) || people < 1) {
                errors.push('CTP: preencha preparação RH, intervenção por chapa e pessoas em simultâneo.');
            } else settings.push(hourlyMode ? { mode: 'hourly', perHour, people } :
                { mode: 'per-unit', setup, perUnit, people });
            const ids = String(row.operators || '').split(',').filter(Boolean);
            if (!ids.length) errors.push('CTP: operador associado em falta.');
            for (const id of ids) {
                const rate = number(operators.find(operator => String(operator.id) === id)?.cost);
                if (rate === null) errors.push('CTP: custo/h do operador #' + id + ' em falta.');
                else rates.push(rate);
            }
        }
        if (new Set(settings.map(setting => JSON.stringify(setting))).size > 1) {
            errors.push('CTP: os RH alternativos devem ter tempos e equipa consistentes.');
        }
        const setting = settings[0];
        const laborRate = rates.length ? Math.max(...rates) : null;
        const laborHours = setting?.mode === 'hourly' ? hours !== null ? hours * setting.perHour / 60 : null :
            setting && Number.isSafeInteger(quantity) ? (setting.setup + quantity * setting.perUnit) / 60 : null;
        const machineCost = hours !== null && equipmentRate !== null ? hours * equipmentRate : null;
        const laborCost = laborHours !== null && laborRate !== null ? laborHours * laborRate * setting.people : null;
        const cost = machineCost !== null && laborCost !== null ? machineCost + laborCost : null;
        if (cost !== null && !Number.isFinite(cost)) errors.push('CTP: custo fora dos limites numéricos.');
        return { version: 1, equipmentRate, laborRate, laborHours, machineCost, laborCost, setting,
            cost: errors.length ? null : cost, errors: [...new Set(errors)], resources };
    }

    function impositionCalculation(resources, snapshot) {
        const errors = [];
        const parts = snapshot.parts?.length ? snapshot.parts : snapshot.pagination?.parts?.length ?
            snapshot.pagination.parts : [{ forms: snapshot.forms, modeId: snapshot.modeId }];
        let plans = 0;
        let faces = 0;
        for (const part of parts) {
            const forms = number(part.forms ?? snapshot.forms ??
                (snapshot.basis === 'rubrics' || snapshot.source === 'dynamic' ? 1 : undefined));
            const mode = Number(part.modeId ?? snapshot.modeId);
            const sides = mode === 3747 ? 1 : [3748, 3749, 3750].includes(mode) ? 2 : null;
            if (!Number.isSafeInteger(forms) || forms <= 0 || sides === null) {
                errors.push('Imposição: número de planos ou faces por montagem em falta.');
                continue;
            }
            plans += forms;
            faces += forms * sides;
        }
        const station = resources?.equipment?.filter(row => String(row.machine_id) === '11') || [];
        const proof = resources?.equipment?.filter(row => String(row.machine_id) === '56') || [];
        const labor = resources?.labor || [];
        const operators = resources?.operators || [];
        const rates = [];
        const people = [];
        if (!labor.length) errors.push('Imposição: associe a Carla Rocha nos Recursos Humanos.');
        for (const row of labor) {
            const count = number(row.people);
            if (!Number.isSafeInteger(count) || count < 1) errors.push('Imposição: equipa de RH inválida.');
            else people.push(count);
            const ids = String(row.operators || '').split(',').filter(Boolean);
            if (!ids.length) errors.push('Imposição: operador em falta.');
            for (const id of ids) {
                const rate = number(operators.find(operator => String(operator.id) === id)?.cost);
                if (rate === null) errors.push('Imposição: custo/h do operador #' + id + ' em falta.');
                else rates.push(rate);
            }
        }
        if (new Set(people).size !== 1) errors.push('Imposição: equipa inconsistente entre RH alternativos.');
        const workstation = station.length === 1 ? station[0] : null;
        const printer = proof.length === 1 ? proof[0] : null;
        const speed = number(workstation?.speed);
        const setup = number(workstation?.setup);
        const stationRate = number(workstation?.cost);
        const printerRate = number(printer?.cost);
        const printerSpeed = number(printer?.speed);
        const printerSetup = number(printer?.setup);
        const clickRate = number(resources?.operation?.proof_click);
        if (!(speed > 0) || setup === null || stationRate === null) errors.push('Imposição: produtividade, acerto ou custo da estação em falta.');
        if (!(printerSpeed > 0) || printerSetup === null || printerRate === null) errors.push('Prova: produtividade, acerto ou custo da Versafire em falta.');
        if (clickRate === null) errors.push('Prova: custo por clique/face em falta.');
        const papers = resources?.papers || [];
        const paper = papers.length === 1 ? papers[0] : null;
        const width = number(paper?.width), height = number(paper?.height);
        const price = number(paper?.price);
        const perSheet = width > 0 && height > 0 ? Math.max(Math.floor(width / 297) * Math.floor(height / 420),
            Math.floor(width / 420) * Math.floor(height / 297)) : 0;
        if (!paper || Number(paper.unit) !== 3648 || Number(paper.weight) !== 100 || !(price > 0) || perSheet < 1) {
            errors.push('Prova: papel de 100 g compatível com A3 e preço por folha em falta.');
        }
        const machine = resources?.machine;
        if (!machine || !(number(machine.print_width) > 0) || !(number(machine.print_height) > 0) ||
            !(number(machine.width) >= 297 && number(machine.height) >= 420 ||
                number(machine.width) >= 420 && number(machine.height) >= 297)) {
            errors.push('Prova: formato A3 ou área útil da Versafire indisponível.');
        }
        const hours = speed > 0 && setup !== null ? plans / speed + setup / 60 : null;
        const laborRate = rates.length ? Math.max(...rates) * people[0] : null;
        const proofHours = printerSpeed > 0 && printerSetup !== null ? faces / printerSpeed + printerSetup / 60 : null;
        const sheets = perSheet > 0 ? Math.ceil(plans / perSheet) : null;
        const cost = hours !== null && stationRate !== null && laborRate !== null ? hours * (stationRate + laborRate) : null;
        const proofCost = proofHours !== null && printerRate !== null ? proofHours * printerRate : null;
        const paperCost = sheets !== null && price !== null ? sheets * price : null;
        const clickCost = clickRate !== null ? faces * clickRate : null;
        if ([cost, proofCost, paperCost, clickCost].some(value => value !== null && !Number.isFinite(value))) {
            errors.push('Imposição/prova: resultado fora dos limites numéricos.');
        }
        return { version: 1, resources, plans, faces, hours, stationRate, laborRate, proofHours, sheets,
            perSheet, paper, clickRate, printerRate, cost, proofCost, paperCost, clickCost, errors: [...new Set(errors)] };
    }

    function calculate(data, snapshot, overrides = {}, materials = []) {
        const pricing = commercialPricing(data, overrides);
        if (pricing) {
            overrides = Object.fromEntries(Object.entries(overrides).map(([key, values]) => [key,
                Object.fromEntries(Object.entries(values).filter(([parameter]) => !['sale', 'margin'].includes(parameter)))]));
        }
        const lines = [];
        const packagingMaterialRecords = new Set();
        const laminationMaterialRecords = new Set();
        const laminationEnabled = Array.isArray(data.lamination_articles);
        const laminationRows = data.costing_operations.filter(row => Number(row.operation_id) === 13);
        const errors = [];
        if (pricing) errors.push(...pricing.errors);
        if (laminationEnabled && !laminationRows.length && ['front', 'back'].some(face =>
            ['gloss', 'matt', 'anti-scuff'].includes(snapshot.component?.['lamination_' + face]))) {
            errors.push('O componente tem plastificação definida, mas falta a operação Plastificação na sequência.');
        }
        if (laminationEnabled && laminationRows.length > 1) errors.push('Plastificação repetida: mantenha uma única operação por componente.');
        for (const [key, values] of Object.entries(overrides)) {
            if (pricing && key === 'commercial') continue;
            for (const [parameter, value] of Object.entries(values)) {
                if (value !== '' && number(value) === null) errors.push(key + ' · ' + parameter + ': valor inválido.');
                if (key.startsWith('paper:') && parameter === 'thickness' && value !== '' &&
                    (!(number(value) > 0) || number(value) > 80)) {
                    errors.push(key + ': introduza uma espessura real superior a 0 e até 80 mm.');
                }
            }
        }
        const result = snapshot.results || {};
        const adhesiveRows = data.adhesive_articles ? data.costing_operations.filter(row => Number(row.operation_id) === 42) : [];
        const adhesiveArticle = data.adhesive_articles?.find(article => Number(article.id) === 201);
        const adhesiveMaterials = adhesiveRows.length ? materials.filter(material =>
            Number(material.materialId) === 201 || (Number(material.id) === Number(adhesiveArticle?.internal_id) &&
                (!material.materialId || Number(material.materialId) === 201))) : [];
        const adhesiveResults = new Map(adhesiveRows.map(row => [row.id,
            adhesiveCalculation(adhesiveArticle, adhesiveInputs(row, overrides['adhesive:' + row.id]), snapshot.quantity)]));
        const candidateParts = snapshot.parts || snapshot.pagination?.parts || [];
        const operationIds = new Set([
            Number(snapshot.tariff?.operationId),
            ...candidateParts.map(part => Number(part.operation?.id || part.tariff?.operationId)),
            Number(snapshot.mixed?.digital?.tariff?.operationId)
        ].filter(Boolean));
        const printingRows = data.costing_operations.filter(row => operationIds.has(Number(row.operation_id)));
        const inkReference = inkCalibration(data);
        const offsetImpressionCount = inkImpressions(snapshot);
        const add = line => {
            line.errors = line.errors || [];
            const manualCost = overrides[line.key]?.cost;
            if (manualCost !== undefined && manualCost !== '') {
                line.cost = number(manualCost);
                line.costManual = true;
            }
            if (pricing) {
                line.sale = line.cost !== null && pricing.markup !== null && !pricing.errors.length ?
                    line.cost * (1 + pricing.markup / 100) : null;
                delete line.saleManual;
            }
            if (line.cost === null) line.errors.push('Custo interno em falta.');
            if (!pricing && line.sale === null) line.errors.push('Valor de venda em falta.');
            if ([line.cost, line.sale].some(value => value !== null && (!Number.isFinite(value) || value < 0))) {
                line.cost = null;
                line.sale = null;
                line.errors.push('Resultado numérico inválido.');
            }
            line.errors = [...new Set(line.errors)];
            errors.push(...line.errors.map(error => line.name + ': ' + error));
            lines.push(line);
        };
        const manualAmounts = (key, line) => {
            const input = overrides[key] || {};
            if (input.cost !== '' && input.cost !== undefined) {
                line.cost = number(input.cost);
                line.costManual = true;
            }
            if (!pricing && input.sale !== '' && input.sale !== undefined) {
                line.sale = number(input.sale);
                line.saleManual = true;
            }
            return line;
        };
        const baseLine = (key, name, amount, quantity, unit, reference) => manualAmounts(key, {
            key, name, quantity: number(quantity), unit, cost: number(amount), sale: number(amount),
            source: 'Simulação de impressão', reference
        });
        const pricedMaterial = (line, material) => {
            if (pricing) return line;
            if (!material) return line;
            line.materialPricing = { recordId: material.recordId, margin: effectiveMargin(material.margin),
                manualUnitPrice: number(material.saleUnitPrice) ?? 0 };
            if (!line.saleManual) {
                line.sale = materialSale(line.quantity, line.quantity > 0 && line.cost !== null ?
                    line.cost / line.quantity : line.quantity === 0 ? 0 : null,
                line.materialPricing.manualUnitPrice, line.materialPricing.margin);
            }
            line.source += ' · Regra de venda do material (% s/compra: ' + (line.materialPricing.margin ?? '?') + ')';
            return line;
        };
        const papers = materials.filter(material => material.paper && !adhesiveMaterials.includes(material));
        const paperMaterial = papers.find(material => Number(material.id) === Number(snapshot.paperId)) ||
            (papers.length === 1 ? papers[0] : null);
        const paperLine = pricedMaterial(baseLine('paper', 'Papel', result.paper, result.sheets, 'folhas',
            snapshot.paperCalculation?.paper || snapshot.paperStocks?.map(stock => stock.paper)), paperMaterial);
        const paperCalculations = candidateParts.length ? candidateParts.map(part => part.paperCalculation) :
            [snapshot.paperCalculation];
        const breakdown = paperCalculations.filter(Boolean).map((paper, index) => {
            const rule = paper.setupWaste;
            return 'montagem ' + (index + 1) + ': ' + paper.goodPlans + ' planos úteis + ' + paper.wastePlans +
                ' planos de acerto; ' + paper.perSheet + ' planos/folha → ' + paper.usefulSheets +
                ' folhas úteis + ' + paper.setupSheets + ' folhas de acerto = ' + paper.sheets + ' folhas' +
                (rule ? ' · acerto máximo ' + rule.plans + ' planos/forma (' +
                    (rule.governing.length ? rule.governing.join(', ') : 'valor da simulação') + ')' : '');
        });
        if (breakdown.length) {
            paperLine.source += ' · ' + breakdown.join('; ');
            paperLine.paperCalculations = paperCalculations;
        }
        if (!pricing && papers.length > 1 && !paperMaterial) paperLine.errors = ['Não foi possível identificar a regra de venda do papel escolhido.'];
        add(paperLine);
        if (number(result.plates) > 0) {
            const plateMaterial = materials.find(material => Number(material.id) === Number(snapshot.plate?.materialId ||
                candidateParts.find(part => part.plate?.materialId)?.plate.materialId));
            add(pricedMaterial(baseLine('plates', 'Chapas — material', result.plate, result.plates, 'chapas', snapshot.plate),
                plateMaterial));
        }
        const printingCost = number(result.machineCost);
        const printNames = snapshot.mixed ? 'Impressão Offset + Digital' :
            snapshot.tariff?.operationSource?.name || candidateParts[0]?.operation?.name ||
            data.costing_catalog.find(row => operationIds.has(Number(row.id)))?.name || 'Impressão';
        const printingLine = manualAmounts('printing', {
            key: 'printing', name: printNames, quantity: number(result.hours), hours: number(result.hours), unit: 'h',
            cost: printingCost, sale: number(result.printing), source: 'Simulação de impressão',
            operationIds: [...operationIds], reference: snapshot.mixed || snapshot.tariff || candidateParts.map(part => part.tariff),
            operationRows: printingRows.map(row => row.id)
        });
        if (operationIds.size === 1) {
            printingLine.operationId = [...operationIds][0];
            printingLine.tariff = currentTariff(data, printingLine.operationId);
        }
        printingLine.tariffs = [...operationIds].map(id => ({ operationId: id, tariff: currentTariff(data, id) }));
        const duplicatePrinting = printingRows.length !== new Set(printingRows.map(row => row.operation_id)).size;
        printingLine.errors = [];
        if (duplicatePrinting) printingLine.errors.push('Operações de impressão repetidas: reveja a sequência antes de calcular.');
        if (printingRows.some(row => String(row.active) !== 'true')) printingLine.errors.push('Operação de impressão inativa no catálogo.');
        if (snapshot.mixed && !printingLine.costManual && !printingLine.saleManual) {
            for (const [phase, step] of Object.entries({ offset: snapshot.mixed.offset, digital: snapshot.mixed.digital })) {
                const id = Number(step.tariff?.operationId || (phase === 'offset' ? snapshot.tariff?.operationId : 4));
                add({
                    ...printingLine, key: 'printing:' + phase, operationId: id, operationIds: [id],
                    name: step.tariff?.operationSource?.name || (phase === 'offset' ? 'Impressão Offset' : 'Impressão Digital'),
                    quantity: number(step.result?.hours), hours: number(step.result?.hours),
                    cost: number(step.result?.machineCost), sale: number(step.result?.printing),
                    tariff: currentTariff(data, id), tariffs: [{ operationId: id, tariff: currentTariff(data, id) }],
                    operationRows: printingRows.filter(row => Number(row.operation_id) === id).map(row => row.id),
                    reference: step, errors: [...printingLine.errors]
                });
            }
        } else {
            add(printingLine);
        }
        if (number(result.clicks) > 0 || number(result.click) > 0) {
            const clickQuantity = number(result.clicks);
            const clickSale = number(result.click);
            const clickSaleRate = clickQuantity > 0 && clickSale !== null ? clickSale / clickQuantity : null;
            add(manualAmounts('clicks', {
                key: 'clicks', name: 'Cliques de impressão', quantity: clickQuantity, unit: 'cliques',
                cost: null, sale: clickSale, saleReference: clickSale, saleReferenceRate: clickSaleRate,
                source: 'Simulação de impressão' + (clickSale !== null ?
                    ' · tarifa de venda calculada ' + unitMoney(clickSaleRate) + '/clique (' +
                        unitMoney(clickSale) + ' no total)' : ''),
                reference: { tariff: snapshot.tariff, whiteClick: snapshot.whiteClick,
                    note: 'O preço por clique disponível é de venda; indicar o custo de compra dos cliques.' }
            }));
        }
        let ctpIncluded = false;
        let impositionIncluded = false;
        if (data.imposition_resources && number(result.plates) > 0 &&
            !data.costing_operations.some(row => Number(row.operation_id) === 43)) {
            errors.push('Falta a operação Imposição antes da Produção de Chapas. Atualize as operações do componente.');
        }
        for (const row of data.costing_operations) {
            const id = Number(row.operation_id);
            if (id === 44) {
                const calibration = administrativeCalibration(data);
                const line = manualAmounts('operation:' + row.id, {
                    key: 'operation:' + row.id, name: row.name, operationId: id, operationRow: row.id,
                    quantity: 1, unit: 'componente', hours: null, parameters: production(data, row), tariff: null,
                    cost: calibration.costPerComponent, sale: null, adminCalibration: calibration,
                    source: 'Rateio administrativo: (' + unitMoney(calibration.janineRate) + '/h Janine + ' +
                        unitMoney(calibration.carlaRate) + '/h Carla) ÷ ((' + calibration.opCount +
                        ' OP/ano + ' + (calibration.annualInvoices?.toFixed(2) ?? '?') + ' faturas/ano) ÷ ' +
                        calibration.annualWorkingHours + ' h/ano = ' +
                        (calibration.documentsPerHour?.toFixed(4) ?? '?') + ' documentos/h) = ' +
                        unitMoney(calibration.costPerComponent) + '/componente', errors: [...calibration.errors]
                });
                if (String(row.active) !== 'true') line.errors.push('Operação inativa no catálogo.');
                add(line);
                continue;
            }
            if (id === 43 && row.discovered && !(number(result.plates) > 0)) continue;
            if (id === 43 && data.imposition_resources) {
                if (impositionIncluded) {
                    errors.push('Imposição repetida: reveja as operações do componente.');
                    continue;
                }
                impositionIncluded = true;
                const work = impositionCalculation(data.imposition_resources, snapshot);
                const common = { operationId: 43, imposition: work, errors: [...work.errors] };
                const laborLine = manualAmounts('operation:' + row.id, {
                    ...common, key: 'operation:' + row.id, operationRow: row.id, name: row.name,
                    quantity: work.plans, unit: 'planos de imposição', hours: work.hours,
                    cost: work.errors.length ? null : work.cost, sale: null,
                    source: 'Planos/montagens × tempo da estação (' +
                        (data.imposition_resources.equipment.find(e => String(e.machine_id) === '11')?.speed ?? '?') +
                        ' planos/h) · estação ' + unitMoney(work.stationRate) + '/h + RH ' + unitMoney(work.laborRate) +
                        '/h · não multiplica pela tiragem nem pelo número de chapas'
                });
                add(laborLine);
                for (const item of [
                    { suffix: 'equipment', name: 'Prova — Versafire', quantity: work.faces, unit: 'faces', hours: work.proofHours,
                        cost: work.proofCost, source: 'Uma prova A3 reduzida por montagem; frente/verso conforme o plano. Tempo do equipamento = acerto por trabalho + faces/velocidade; não inclui novamente RH de imposição.' },
                    { suffix: 'paper', name: 'Prova — papel A3 100 g', quantity: work.sheets, unit: 'folhas de origem',
                        cost: work.paperCost, source: (work.paper?.name || 'Papel por definir') + ' · ' + work.perSheet +
                            ' A3 por folha; ceil(' + work.plans + '/' + work.perSheet + ') folhas inteiras, com sobras incluídas.' },
                    { suffix: 'clicks', name: 'Prova — cliques Versafire', quantity: work.faces, unit: 'cliques/face',
                        cost: work.clickCost, source: 'Custo indicativo ' + unitMoney(work.clickRate) + '/face · separado dos cliques da tiragem.' }
                ]) {
                    add({ ...common, ...item, key: 'imposition:' + row.id + ':' + item.suffix,
                        parentOperationRow: row.id, sale: null, cost: work.errors.length ? null : item.cost,
                        errors: [...work.errors] });
                }
                continue;
            }
            if (isPrinting(row)) {
                continue;
            }
            if (id === 3 && !(number(result.plates) > 0)) continue;
            if (id === 3 && number(result.plates) > 0) {
                if (ctpIncluded) {
                    add({ key: 'operation:' + row.id, name: row.name, operationRow: row.id, operationId: id,
                        cost: null, sale: null, quantity: null, unit: 'chapas',
                        errors: ['Produção de Chapas repetida: reveja as operações do componente.'] });
                    continue;
                }
                ctpIncluded = true;
                const tariff = currentTariff(data, id);
                const input = overrides['operation:' + row.id] || {};
                const manualHours = input.hours !== undefined && input.hours !== '';
                const hours = manualHours ? number(input.hours) : number(snapshot.plateProduction?.hours) ??
                    (number(snapshot.plateProduction?.operation?.speed) > 0 ?
                        number(result.plates) / number(snapshot.plateProduction.operation.speed) +
                        (number(snapshot.plateProduction.operation.setup_minutes) ?? 0) / 60 : null);
                const ctp = data.ctp_resources ? ctpExecution(data.ctp_resources, number(result.plates), hours) : null;
                const line = manualAmounts('operation:' + row.id, {
                    key: 'operation:' + row.id, name: row.name, operationId: id, operationRow: row.id,
                    quantity: number(result.plates), unit: 'chapas', hours, tariff,
                    cost: ctp ? ctp.cost : hours !== null && tariff.cost !== null && tariff.cost !== undefined ? hours * tariff.cost : null,
                    sale: manualHours ? hours !== null && tariff.sale !== null && tariff.sale !== undefined ?
                        hours * tariff.sale : null : number(result.plateProduction), source: 'CTP já incluído na simulação',
                    ctp,
                    errors: ctp ? [...ctp.errors] : tariff.error ? [tariff.error] : []
                });
                if (ctp) {
                    delete line.tariff;
                    line.source = 'CTP autónomo · equipamento ' + (hours ?? '?') + ' h × ' + unitMoney(ctp.equipmentRate) +
                        '/h + RH ' + (ctp.laborHours === null ? '?' : (ctp.laborHours * 60).toFixed(2)) +
                        ' min × ' + unitMoney(ctp.laborRate) + '/h × ' + (ctp.setting?.people ?? '?') +
                        ' pessoa(s) · ' + (ctp.setting?.mode === 'hourly' ? ctp.setting.perHour + ' min RH por hora de CTP' :
                        'preparação ' + (ctp.setting?.setup ?? '?') + ' min + ' + (ctp.setting?.perUnit ?? '?') +
                        ' min/chapa') + ' · material de chapas à parte';
                }
                if (line.costManual && (pricing || line.saleManual)) line.errors = [];
                if (String(row.active) !== 'true') line.errors.push('Operação inativa no catálogo.');
                add(line);
                continue;
            }
            const tariff = currentTariff(data, id);
            const input = overrides['operation:' + row.id] || {};
            const selectedEquipment = input.equipment ? data.costing_equipment.find(item =>
                Number(item.operation_id) === id && String(item.machine_id) === String(input.equipment)) : null;
            const parameters = production(data, input.equipment ? { ...row, equipment: input.equipment } : row);
            if (id === 30) parameters.unit = 'planos/h';
            const manualHours = input.hours !== undefined && input.hours !== '' ? number(input.hours) : null;
            const manualQuantity = input.quantity !== undefined && input.quantity !== '';
            const spiralInput = overrides['spiral:' + row.id] || {};
            const spiralDiameter = id === 32 ? number(spiralInput.diameter) : null;
            const spiralTurns = id === 32 ? number(spiralInput.turns) : null;
            const spiralArticle = id === 32 ? data.spiral_articles?.find(article =>
                String(article.id) === String(spiralInput.articleId)) : null;
            const parsedSpiralSpec = spiralArticleSpec(spiralArticle);
            const spiralMaterialQuantity = id === 32 ? number(spiralInput.materialQuantity) : null;
            const pileSize = input.pileSize !== undefined && input.pileSize !== '' ? input.pileSize :
                row.cut_pile_size !== undefined && row.cut_pile_size !== '' && Number(row.cut_pile_size) !== 250 ? row.cut_pile_size : undefined;
            const cutting = [2, 9].includes(id) && !manualQuantity && manualHours === null ?
                cuttingCalculation(snapshot, id, pileSize, overrides) : null;
            const packaging = id === 10 && !manualQuantity && manualHours === null ?
                packagingCalculation(data, snapshot, row, input.maxKg ?? row.packaging_kg ?? 10, overrides) : null;
            const lamination = id === 13 && laminationEnabled ?
                laminationCalculation(data, snapshot, row, overrides['lamination:' + row.id]) : null;
            const quantity = input.quantity !== undefined && input.quantity !== '' ? number(input.quantity) :
                lamination ? lamination.errors.length ? null : lamination.quantity :
                packaging ? packaging.errors.length ? null : packaging.packages :
                cutting ? cutting.errors.length ? null : cutting.cuts :
                tariff.basis === 'serviço' ? 1 : adhesiveResults.has(row.id) ? adhesiveResults.get(row.id).pieces :
                    automaticQuantity(parameters.unit, snapshot, id);
            const hours = manualHours !== null ? manualHours : quantity !== null && parameters.speed > 0 &&
                parameters.setup !== null ? (cutting || packaging || lamination) && quantity === 0 ? 0 :
                    parameters.setup / 60 * (lamination?.setups ?? 1) + quantity / parameters.speed : null;
            const units = tariff.basis === 'serviço' ? quantity : hours;
            const line = manualAmounts('operation:' + row.id, {
                key: 'operation:' + row.id, name: row.name, operationId: id, operationRow: row.id,
                quantity, unit: tariff.basis === 'serviço' ? 'serviço' : parameters.unit.replace(/\/h$/, ''),
                hours, parameters, tariff, cutting, packaging, lamination, source: lamination && !lamination.errors.length ?
                    lamination.source : packaging && !packaging.errors.length ?
                    packaging.source : cutting && !cutting.errors.length ?
                    'Cortes automáticos · ' + cutting.scope + ' · ' +
                    cutting.details.map(detail => 'montagem ' + detail.mounting + ': ' + detail.sheets +
                        (id === 2 ? ' folhas' : ' planos') + ', ' + detail.batches + ' pilhas, ' +
                        detail.cuts + ' cortes (' + detail.direction + ') · ' + detail.pile.source).join('; ') +
                    ' · produtividade ' + parameters.speed + ' cortes/h · acerto ' +
                    (quantity === 0 ? 0 : parameters.setup) + ' min' :
                    id === 1 && parameters.unit === 'ficheiros/h' && quantity !== null ?
                        'Estimativa: 1 ficheiro de arte final por componente · produtividade ' + parameters.speed +
                        ' ficheiros/h · preparação ' + parameters.setup + ' min' :
                    id === 32 && spiralDiameter !== null && spiralTurns !== null ?
                        'Espiral Nº ' + spiralDiameter + ' · ' + spiralTurns + ' voltas · ' +
                        (parameters.speed ?? '?') + ' cadernos/h · acerto ' + (parameters.setup ?? '?') + ' min' :
                        'Tarifa consolidada vigente',
                cost: units !== null && tariff.cost !== null && tariff.cost !== undefined ? units * tariff.cost : null,
                sale: units !== null && tariff.sale !== null && tariff.sale !== undefined ? units * tariff.sale : null,
                errors: []
            });
            if (id === 32) {
                line.spiral = { diameter: spiralDiameter, turns: spiralTurns };
                if (!(spiralDiameter > 0)) line.errors.push('Indique o diâmetro do espiral.');
                if (!(spiralTurns > 0) || !Number.isInteger(spiralTurns)) {
                    line.errors.push('Indique o número inteiro de voltas do espiral.');
                }
            }
            if (!(line.costManual && (pricing || line.saleManual))) {
                if (tariff.error) line.errors.push(tariff.error);
                if (parameters.ambiguous && manualHours === null && tariff.basis === 'h') {
                    line.errors.push('Selecione um único equipamento ou indique o tempo total.');
                }
                if (units === null) line.errors.push('Indique quantidade e produtividade/acerto, tempo total ou valores manuais.');
                if (cutting?.errors.length) line.errors.push(...cutting.errors);
                if (packaging?.errors.length) line.errors.push(...packaging.errors);
            }
            if (String(row.active) !== 'true') line.errors.push('Operação inativa no catálogo.');
            if (input.equipment && !selectedEquipment) line.errors.push('Equipamento selecionado inválido para esta operação.');
            if (lamination?.errors.length) line.errors.push(...lamination.errors);
            add(line);
            if (id === 32) {
                const articleMatches = spiralArticle && spiralDiameter !== null && spiralTurns !== null &&
                    parsedSpiralSpec.diameter === spiralDiameter && parsedSpiralSpec.turns === spiralTurns;
                const articlePrice = articleMatches ? number(spiralArticle.price) : null;
                const materialErrors = [];
                if (!articleMatches) materialErrors.push('Selecione um artigo de compra compatível com o diâmetro e o número de voltas.');
                if (!(spiralMaterialQuantity > 0)) materialErrors.push('Indique a quantidade total de espirais a comprar/utilizar.');
                if (articleMatches && articlePrice === null) materialErrors.push('O artigo selecionado não tem preço de compra concluída.');
                const materialCost = spiralMaterialQuantity !== null && articlePrice !== null ?
                    spiralMaterialQuantity * articlePrice : null;
                add({ key: 'spiral-material:' + row.id,
                    name: 'Material espiral' + (articleMatches ? ' — ' + spiralArticle.name : ''),
                    operationId: id, parentOperationRow: row.id, quantity: spiralMaterialQuantity,
                    unit: spiralArticle?.unit_name || 'Un.', cost: materialCost,
                    sale: materialCost, source: articleMatches ?
                        'Última compra concluída #' + spiralArticle.purchase_id + ' · ' +
                            unitMoney(articlePrice) + '/' + (spiralArticle.unit_name || 'unidade') +
                            ' · quantidade do material introduzida manualmente' :
                        'Selecione um artigo de compra com diâmetro e voltas compatíveis',
                    article: articleMatches ? { id: Number(spiralArticle.id), name: spiralArticle.name,
                        unit: spiralArticle.unit, unitName: spiralArticle.unit_name,
                        purchaseId: Number(spiralArticle.purchase_id), purchaseDate: spiralArticle.purchase_date,
                        price: articlePrice } : null,
                    spiral: { diameter: spiralDiameter, turns: spiralTurns },
                    errors: materialErrors });
            }
            if (lamination) {
                if (lamination.errors.length) {
                    add({ key: 'lamination:' + row.id, name: 'Filme de plastificação', parentOperationRow: row.id,
                        quantity: null, unit: 'm', cost: null, sale: null, laminationMaterial: { version: 1 },
                        source: 'Material separado da execução', errors: [...lamination.errors] });
                }
                for (const material of lamination.materials || []) {
                    const matches = materials.filter(m => Number(m.materialId) === Number(material.article.id) ||
                        (!m.materialId && Number(m.id) === Number(material.article.internal_id)));
                    matches.forEach(m => laminationMaterialRecords.add(m.recordId));
                    const filmLine = pricedMaterial({
                        key: 'lamination:' + row.id + ':' + material.face + ':' + material.mounting,
                        parentOperationRow: row.id, name: 'Filme — ' + laminationFinishes[material.finish] + ' · ' +
                            (material.face === 'front' ? 'frente' : 'verso') + ' · montagem ' + material.mounting,
                        quantity: material.metres, unit: 'm', cost: material.cost,
                        sale: material.cost * 1.3, article: material.article, laminationMaterial: material,
                        source: material.goodPlans + ' planos úteis + ' + material.setupPlans + ' de acerto da plastificação × ' +
                            material.travel + ' mm = ' + material.metres.toFixed(3) + ' m · ' + material.article.name +
                            ' · largura mínima das peças ' + material.requiredWidth + ' mm · consumo pelo comprimento total do plano, incluindo bordos · última compra concluída ' +
                            unitMoney(number(material.article.price)) + '/m' +
                            (pricing ? '' : ' · venda sobre compra 30% por defeito'), errors: []
                    }, matches[0]);
                    if (matches.length > 1) filmLine.errors.push('Filme duplicado nos materiais do componente.');
                    add(filmLine);
                }
            }
            if (id === 10 && data.packaging_articles) {
                const packageInput = packagingInputs(row, overrides['packaging:' + row.id]);
                const count = manualQuantity ? number(input.quantity) : packaging?.packages ??
                    packagingCalculation(data, snapshot, row, input.maxKg ?? row.packaging_kg ?? 10, overrides).packages;
                const material = packagingMaterial(data, packageInput, count, packaging ??
                    packagingCalculation(data, snapshot, row, input.maxKg ?? row.packaging_kg ?? 10, overrides));
                const matches = materials.filter(m => material.article && (Number(m.materialId) === Number(material.article.id) ||
                    (!m.materialId && Number(m.id) === Number(material.article.internal_id))));
                matches.forEach(m => packagingMaterialRecords.add(m.recordId));
                const margin = pricing ? 0 : effectiveMargin(matches[0]?.margin);
                add({
                    key: 'packaging:' + row.id, parentOperationRow: row.id,
                    name: String(packageInput.type) === '2' ? 'Embalagem — caixas' : 'Embalagem — papel kraft 90 g/m²',
                    quantity: material.quantity ?? null, unit: String(packageInput.type) === '2' ? 'caixas' : 'kg',
                    cost: material.cost ?? null, sale: material.cost === undefined ? null : material.cost * (1 + margin / 100),
                    article: material.article, packagingMaterial: material, margin,
                    source: (material.source || 'Material de embalagem por preencher') +
                        (pricing ? '' : ' · venda sobre compra ' + margin + '%'),
                    errors: [...material.errors, ...(matches.length > 1 ? ['Material de embalagem duplicado nos materiais do componente.'] : [])]
                });
            }
            if (adhesiveResults.has(row.id)) {
                const adhesive = adhesiveResults.get(row.id);
                const values = adhesiveInputs(row, overrides['adhesive:' + row.id]);
                const material = adhesiveMaterials.length === 1 ? adhesiveMaterials[0] : null;
                const margin = pricing ? 0 : number(values.margin);
                const sale = materialSale(adhesive.sheets, adhesive.purchasePrice,
                    number(material?.saleUnitPrice) ?? 0, margin);
                add({
                    key: 'adhesive:' + row.id, name: 'BiAdesivo — folhas para ' + row.name,
                    parentOperationRow: row.id, quantity: adhesive.sheets, unit: 'folhas',
                    cost: adhesive.cost, sale: adhesive.errors.length ? null : sale,
                    adhesive, article: adhesiveArticle ? { ...adhesiveArticle } : null,
                    materialPricing: { margin, recordId: material?.recordId, manualUnitPrice: number(material?.saleUnitPrice) ?? 0 },
                    source: adhesive.layout.piecesPerSheet ?
                        adhesive.perCopy + ' peças/exemplar × ' + adhesive.copies + ' = ' + adhesive.pieces +
                        ' peças · ' + values.width + ' × ' + values.height + ' mm · ' +
                        adhesive.layout.piecesPerSheet + ' peças/folha · ' + adhesive.sheets + ' folhas inteiras · ' +
                        money(adhesive.purchasePrice) + '/folha' +
                        (pricing ? '' : ' · s/compra ' + (margin ?? '?') + '%') + ' · corte por faixas, validar' :
                        'Biadesivo 70×100 · peças por exemplar e dimensões obrigatórias',
                    errors: [...adhesive.errors, ...(margin === null ? ['Indique a percentagem de venda sobre a compra do BiAdesivo (zero é permitido).'] : []),
                        ...(adhesiveMaterials.length > 1 ? ['Existem várias linhas deste BiAdesivo nos materiais. Consolide numa única linha.'] : [])]
                });
            }
        }
        if (!ctpIncluded && number(result.plates) > 0) {
            errors.push('A simulação produz chapas mas falta Produção de Chapas nas operações do componente.');
            add({ key: 'ctp-missing', name: 'Produção de Chapas', quantity: number(result.plates), unit: 'chapas',
                cost: null, sale: number(result.plateProduction), errors: ['Adicionar operação ao componente.'] });
        }
        for (const material of materials) {
            if (laminationMaterialRecords.has(material.recordId)) continue;
            if (packagingMaterialRecords.has(material.recordId)) continue;
            if (material.paper || adhesiveMaterials.includes(material) || [984, 985].includes(Number(material.id))) continue;
            const input = overrides['material:' + material.recordId] || {};
            const quantity = input.quantity !== undefined && input.quantity !== '' ? number(input.quantity) :
                number(material.quantity) > 0 ? number(material.quantity) : null;
            const line = manualAmounts('material:' + material.recordId, {
                key: 'material:' + material.recordId, name: material.name, quantity, unit: 'un.',
                reference: { recordId: material.recordId, articleId: material.materialId, descriptionId: material.id,
                    purchase: material.purchaseReference },
                cost: quantity !== null && number(material.purchaseUnitPrice) !== null ? quantity * number(material.purchaseUnitPrice) : null,
                sale: material.margin !== undefined ? materialSale(quantity, number(material.purchaseUnitPrice),
                    number(material.saleUnitPrice) ?? 0, effectiveMargin(material.margin)) :
                    quantity === number(material.quantity) ? number(material.saleTotal) : null,
                source: 'Materiais do componente · última compra concluída', errors: []
            });
            add(line);
        }
        if (Array.isArray(data.color_materials) && [...operationIds].some(id => [5, 7].includes(id))) {
            for (const color of data.color_materials.filter(color => Number(color.color) !== 3804)) {
                const key = 'color:' + color.record_id;
                const input = overrides[key] || {};
                const colorClass = inkColor(color);
                const reference = colorClass ? inkReference.colors[colorClass] : null;
                const manualQuantity = input.quantity !== undefined && input.quantity !== '';
                const manualCost = input.cost !== undefined && input.cost !== '';
                const automaticQuantity = colorClass && reference?.kgPerImpression !== null &&
                    reference?.kgPerImpression !== undefined && offsetImpressionCount !== null &&
                    !inkReference.generalErrors.length ?
                    offsetImpressionCount * reference.kgPerImpression : null;
                const quantity = manualQuantity ? number(input.quantity) : automaticQuantity;
                const unit = colorClass ? 'kg' : color.unit_name || 'unidade de compra #' + (color.unit || '?');
                const price = reference?.pricePerKg ?? null;
                const errorsForLine = [];
                if (!manualCost) {
                    if (!colorClass) {
                        errorsForLine.push('Cor de tinta não classificada; consumo automático indisponível.');
                    } else if (!manualQuantity) {
                        errorsForLine.push(...inkReference.generalErrors);
                        if (offsetImpressionCount === null) {
                            errorsForLine.push('A simulação não contém um número válido de impressões offset.');
                        }
                        if (reference?.kgPerImpression === null || reference?.kgPerImpression === undefined) {
                            errorsForLine.push(...(reference?.errors || []));
                            if (!reference?.errors?.length && !inkReference.generalErrors.length) {
                                errorsForLine.push(colorClass + ': não há uma taxa de consumo por impressão calculável.');
                            }
                        }
                    }
                    if (price === null) {
                        const priceErrors = (reference?.errors || []).filter(error =>
                            /preço|última compra|média ponderada|compras positivas|sem preço/i.test(error));
                        errorsForLine.push(...(priceErrors.length ? priceErrors :
                            [colorClass ? colorClass + ': preço de referência em €/kg indisponível.' :
                                'Preço de referência em €/kg indisponível.']));
                    }
                }
                const machineNames = inkReference.machines.filter(machine => machine.included)
                    .map(machine => '#' + machine.id + ' ' + machine.name).join(', ');
                const autoSource = automaticQuantity === null || !reference ? null :
                    'Estimativa calibrada · ' + reference.kilograms + ' kg comprados (' +
                    inkReference.window.start + ' a ' + inkReference.window.end + ') ÷ ' +
                    reference.totalImpressions + ' impressões offset (' + machineNames + ') = ' +
                    reference.kgPerThousandImpressions.toPrecision(6) + ' kg/1 000 impressões · ' +
                    offsetImpressionCount + ' impressões da simulação × ' +
                    reference.kgPerImpression.toPrecision(9) + ' kg/impressão = ' +
                    automaticQuantity.toPrecision(9) + ' kg · ' + price + ' €/kg (' +
                    (colorClass === 'Pantone' ? 'média ponderada por kg de compra' : 'última compra concluída por cor') +
                    '). O número de impressões já inclui acerto, passagens e frente/verso do plano.';
                const manualSource = manualQuantity ?
                    'Consumo manual em ' + unit + (price === null ? '' : ' · preço referência ' +
                        price + ' €/kg · ' + (reference?.priceSource?.method || 'proveniência em falta')) :
                    null;
                const displayName = color.color_name || colorClass || color.name || 'cor #' + color.color;
                const articleSuffix = color.name && color.name !== displayName ? ' · ' + color.name : '';
                const line = manualAmounts(key, {
                    key, name: 'Tinta — ' + displayName + articleSuffix + (color.pantone ? ' · ' + color.pantone : ''),
                    quantity, unit, cost: quantity !== null && price !== null ? quantity * price : null, sale: null,
                    article: { id: color.article_id, price: color.price, unit: color.unit },
                    color: { ...color, classification: colorClass, calibration: reference || null },
                    inkCalibration: reference || null,
                    source: manualSource || autoSource || 'Estimativa automática indisponível; indique consumo e custo manual total.',
                    errors: [...new Set(errorsForLine)]
                });
                add(line);
            }
        }
        const uniqueErrors = [...new Set(errors)];
        const costComplete = lines.every(line => line.cost !== null) && !lines.some(line => line.errors?.some(error =>
            error !== 'Valor de venda em falta.')) && !uniqueErrors.some(error =>
                /simulação não inclui|mas esta alternativa|Plastificação repetida|falta a operação Plastificação|Orçamento em falta|Falta a operação Imposição|Imposição repetida/.test(error));
        const saleComplete = lines.every(line => line.sale !== null) &&
            !uniqueErrors.some(error => !error.endsWith('Custo interno em falta.'));
        const cost = costComplete && !uniqueErrors.some(error => /valor inválido/.test(error)) ? sum(lines, 'cost') : null;
        const sale = pricing ? cost !== null && !pricing.errors.length ? cost * (1 + pricing.markup / 100) : null :
            saleComplete ? sum(lines, 'sale') : null;
        if (sale !== null && !Number.isFinite(sale)) uniqueErrors.push('Acréscimo comercial: resultado fora dos limites numéricos.');
        const ledger = {
            version: 2, lines, errors: uniqueErrors, inputs: JSON.parse(JSON.stringify(overrides)),
            cost, sale: sale !== null && Number.isFinite(sale) ? sale : null,
            pricing: pricing ? { ...pricing, cost, increase: sale !== null && cost !== null && Number.isFinite(sale) ? sale - cost : null,
                sale: sale !== null && Number.isFinite(sale) ? sale : null } : null,
            partialCost: sum(lines, 'cost'), partialSale: sum(lines, 'sale'),
            complete: uniqueErrors.length === 0,
            sourceOperations: data.costing_operations,
            administrativeCalibration: data.administrative_calibration,
            materialPricePolicy: data.material_price_policy,
            inkCalibration: inkReference,
            packagingSources: packagingState(data),
            laminationSources: laminationEnabled ? { front: snapshot.component?.lamination_front,
                back: snapshot.component?.lamination_back, articles: data.lamination_articles } : null,
            quantity: number(snapshot.quantity)
        };
        return pricing && (overrides.commercial?.lines || overrides.commercial?.costs) ?
            repriceLines(ledger, pricing, overrides.commercial.lines || {}, overrides.commercial.costs || {}) : ledger;
    }

    function repriceLines(ledger, pricing, adjustments = {}, costs = ledger.inputs?.commercial?.costs || {}) {
        const result = JSON.parse(JSON.stringify(ledger));
        const previousLineErrors = new Set(ledger.lines.flatMap(line =>
            (line.errors || []).map(error => line.name + ': ' + error)));
        const errors = ledger.errors.filter(error => !previousLineErrors.has(error));
        const globalErrors = [...errors];
        let costComplete = !globalErrors.length;
        for (const line of result.lines) {
            if (!Object.hasOwn(line, 'calculatedCost')) line.calculatedCost = line.cost;
            if (!Object.hasOwn(line, 'calculatedErrors')) line.calculatedErrors = [...(line.errors || [])];
            const rawCost = costs[line.key];
            const manual = rawCost !== undefined && rawCost !== null && String(rawCost).trim() !== '';
            line.cost = manual ? number(rawCost) : line.calculatedCost;
            line.costOverride = manual ? rawCost : '';
            line.errors = line.calculatedErrors.filter(error => !manual || error !== 'Custo interno em falta.');
            if (manual && line.cost === null) line.errors.push('Custo manual inválido; indique um valor não negativo.');
            if (line.cost === null && !line.errors.includes('Custo interno em falta.')) line.errors.push('Custo interno em falta.');
            if (line.errors.length || line.cost === null) costComplete = false;
            const raw = adjustments[line.key];
            const markup = raw === undefined || raw === '' ? pricing.markup : number(raw);
            line.markup = markup;
            delete line.saleManual;
            line.sale = line.cost !== null && markup !== null ? line.cost * (1 + markup / 100) : null;
            if (markup === null) line.errors.push('acréscimo inválido; indique uma percentagem não negativa.');
            if (line.sale !== null && !Number.isFinite(line.sale)) {
                line.sale = null;
                line.errors.push('venda fora dos limites numéricos.');
            }
            errors.push(...line.errors.map(error => line.name + ': ' + error));
        }
        const totalCost = sum(result.lines, 'cost');
        if (!Number.isFinite(totalCost)) errors.push('Custo total fora dos limites numéricos.');
        result.cost = costComplete && Number.isFinite(totalCost) ? totalCost : null;
        result.partialCost = Number.isFinite(totalCost) ? totalCost : null;
        result.sale = result.cost !== null && !errors.length && result.lines.every(line => line.sale !== null) ?
            sum(result.lines, 'sale') : null;
        if (result.sale !== null && !Number.isFinite(result.sale)) {
            result.sale = null;
            errors.push('Venda total fora dos limites numéricos.');
        }
        result.errors = [...new Set(errors)];
        result.complete = !result.errors.length && result.cost !== null && result.sale !== null;
        const partialSale = sum(result.lines, 'sale');
        result.partialSale = Number.isFinite(partialSale) ? partialSale : null;
        result.pricing = { ...pricing, method: 'cost-plus', version: 2, cost: result.cost, sale: result.sale,
            lineOverrides: { ...adjustments }, costOverrides: { ...costs },
            increase: result.sale === null ? null : result.sale - result.cost };
        result.inputs = { ...result.inputs, commercial: { markup: pricing.markup, lines: { ...adjustments }, costs: { ...costs } } };
        return result;
    }

    function isMaterialLine(line) {
        return /^(paper$|plates$|material:|color:|spiral-material:|lamination:|packaging:|adhesive:)/.test(line.key) ||
            /^imposition:.*:paper$/.test(line.key);
    }

    function detailLineGroup(ledger, line) {
        if (isMaterialLine(line)) return 0;
        const parent = line.parentOperationRow === undefined ? null :
            ledger.lines.find(candidate => String(candidate.operationRow) === String(line.parentOperationRow));
        const ids = [...(line.operationIds || [line.operationId || (line.key === 'ctp-missing' ? 3 : 0)]),
            ...(parent?.operationIds || [parent?.operationId])].map(Number);
        if (ids.some(id => [3, 43].includes(id))) return 0;
        if (ids.includes(12)) return 3;
        return ids.includes(44) ? 2 : 1;
    }

    function appendDetailSubtotals(body, ledger, costPlus) {
        const linesByKey = new Map(ledger.lines.map(line => [line.key, line]));
        const labels = ['Sub-Total — Materiais + Produção de chapas + Imposição', 'Sub-Total — Operações'];
        for (const group of [0, 1]) {
            const lines = ledger.lines.filter(line => detailLineGroup(ledger, line) === group);
            const total = field => {
                const values = lines.map(line => field === 'calculatedCost' && !Object.hasOwn(line, field) ?
                    line.cost : line[field]);
                return values.every(Number.isFinite) ? values.reduce((sum, value) => sum + value, 0) : null;
            };
            const index = [...body.rows].findIndex(row => {
                const line = linesByKey.get(row.dataset.costLine);
                return line && detailLineGroup(ledger, line) > group;
            });
            const row = body.insertRow(index);
            row.dataset.costSubtotal = String(group);
            row.className = 'cb-cost-subtotal';
            row.style.fontWeight = 'bold';
            row.style.borderTop = '2px solid #ccc';
            const values = costPlus ?
                [labels[group], '', '', '', '', money(total('calculatedCost')), money(total('cost')), '', money(total('sale'))] :
                [labels[group], '', '', '', '', money(total('cost')), money(total('sale'))];
            for (const value of values) row.insertCell().textContent = value;
            if (costPlus) row.cells[6].title = 'Custo total efetivo, incluindo ajustes manuais.';
        }
    }

    function groupedLines(ledger) {
        const material = isMaterialLine;
        const sequence = [[1], [43], [3], [2], [4,5,7], [6,13,14,15,22], [45],
            [8,11,30,40,41,37], [17,18,34,48], [21,33,35], [19,20],
            [23,25,26,32], [24,38,42], [9], [27,28,36], [31,39,47], [29,46], [10], [12], [44]];
        const rank = line => {
            if (material(line)) return -1;
            const ids = line.operationIds || [line.operationId || (line.key === 'ctp-missing' ? 3 : 0)];
            const index = sequence.findIndex(group => ids.some(id => group.includes(Number(id))));
            if (index >= 0) return index;
            if (line.key === 'clicks' || line.key.startsWith('printing')) return 4;
            const source = ledger.sourceOperations?.find(row => Number(row.operation_id) === Number(line.operationId));
            return { 'Componente':7, 'Assemblagem':12, 'Embalamento individual':16, 'Expedição':17, 'Suporte':19 }[source?.stage] ?? 12;
        };
        const lines = [...ledger.lines].sort((a, b) => rank(a) - rank(b));
        if (lines.some(line => line.key === 'plates')) {
            const plateOperations = lines.filter(line => !material(line) &&
                (line.operationIds || [line.operationId || (line.key === 'ctp-missing' ? 3 : 0)])
                    .some(id => [3, 43].includes(Number(id))))
                .sort((a, b) => rank(b) - rank(a));
            for (const line of plateOperations) lines.splice(lines.indexOf(line), 1);
            lines.splice(lines.findIndex(line => line.key === 'plates') + 1, 0, ...plateOperations);
        }
        const parents = new Map();
        for (const line of lines) {
            if (material(line)) continue;
            let parent = line.parentOperationRow !== undefined ?
                lines.find(candidate => String(candidate.operationRow) === String(line.parentOperationRow)) : null;
            if (!parent && line.key === 'clicks') parent = lines.find(candidate => candidate.key === 'printing');
            if (parent && parent !== line) parents.set(line.key, parent.key);
        }
        const result = [];
        const visited = new Set();
        const visit = (line, ancestors) => {
            if (visited.has(line.key)) return;
            visited.add(line.key);
            result.push({ line, ancestors });
            for (const child of lines.filter(child => parents.get(child.key) === line.key)) {
                visit(child, [...ancestors, line.key]);
            }
        };
        for (const line of lines.filter(line => !parents.has(line.key))) visit(line, []);
        for (const line of lines) if (!visited.has(line.key)) visit(line, []);
        return result.sort((a, b) => detailLineGroup(ledger, a.line) - detailLineGroup(ledger, b.line));
    }

    function isExtraOperation(ledger, line) {
        if (line.parentOperationRow !== undefined || line.operationId === undefined) return false;
        const source = ledger.sourceOperations?.find(row =>
            Number(row.operation_id) === Number(line.operationId));
        return source?.requirement_type === 'selected';
    }

    function formatHours(hours) {
        if (typeof hours !== 'number' || !Number.isFinite(hours) || hours < 0) return '—';
        const seconds = Math.round(hours * 3600);
        if (!Number.isSafeInteger(seconds)) return '—';
        return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
            .map(value => String(value).padStart(2, '0')).join(':');
    }

    function styleDetailTable(table, ledger, costPlus) {
        const body = table.tBodies[0];
        const inks = ledger.lines.filter(line => line.key.startsWith('color:'));
        const inkRows = [...body.rows].filter(row => inks.some(line => line.key === row.dataset.costLine));
        if (inkRows.length > 1) {
            const total = field => {
                const values = inks.map(line => field === 'calculatedCost' && !Object.hasOwn(line, field) ?
                    line.cost : line[field]);
                return values.every(Number.isFinite) ? values.reduce((sum, value) => sum + value, 0) : null;
            };
            const row = body.insertRow([...body.rows].indexOf(inkRows[0]));
            row.dataset.inkGroup = 'true';
            const values = costPlus ? ['Tintas', '', '', '', '', money(total('calculatedCost')),
                money(total('cost')), '', money(total('sale'))] :
                ['Tintas', '', '', '', '', money(total('cost')), money(total('sale'))];
            for (const value of values) row.insertCell().textContent = value;
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'btn btn-default';
            toggle.style.marginRight = '8px';
            let expanded = false;
            const update = () => {
                toggle.textContent = expanded ? '−' : '+';
                toggle.setAttribute('aria-expanded', String(expanded));
                toggle.setAttribute('aria-label', (expanded ? 'Recolher' : 'Expandir') + ' tintas');
                for (const inkRow of inkRows) inkRow.style.display = expanded ? '' : 'none';
            };
            toggle.addEventListener('click', () => { expanded = !expanded; update(); });
            row.cells[0].prepend(toggle);
            row.cells[0].style.fontWeight = 'bold';
            update();
        }
        for (const row of table.rows) {
            if (costPlus) {
                for (const index of [5, 6, 8]) row.cells[index].style.textAlign = 'right';
            }
            for (const [index, width] of costPlus ? [[3, 86], [6, 90], [7, 80]] : [[3, 86]]) {
                const cell = row.cells[index];
                cell.style.width = width + 'px';
                cell.style.minWidth = '0';
                cell.style.paddingLeft = '4px';
                cell.style.paddingRight = '4px';
                cell.style.whiteSpace = index === 3 && row !== table.tHead.rows[0] ? 'nowrap' : 'normal';
                for (const input of cell.querySelectorAll('input')) {
                    input.style.width = (width - 10) + 'px';
                    input.style.minWidth = '0';
                    input.style.paddingLeft = '4px';
                    input.style.paddingRight = '4px';
                }
            }
        }
        for (const row of table.tFoot.rows) {
            for (const cell of row.cells) {
                cell.style.fontWeight = 'bold';
                cell.style.color = '#17365d';
            }
        }
    }

    function detail(ledger, edit) {
        const costPlus = ledger.pricing?.method === 'cost-plus';
        const section = document.createElement('div');
        section.className = 'cb-operation-cost-detail';
        const summary = document.createElement('button');
        summary.type = 'button';
        const pricesIncomplete = ledger.cost === null || ledger.sale === null ||
            ledger.lines.some(line => line.cost === null || line.sale === null);
        const units = unitPrices(ledger);
        summary.className = 'btn ' + (pricesIncomplete ? 'btn-warning' : 'btn-default');
        summary.setAttribute('aria-haspopup', 'dialog');
        summary.textContent = 'Detalhes';
        summary.title = (pricesIncomplete ? 'Há preços por completar. ' : '') +
            'Clique para abrir os detalhes; ou mantenha o cursor sobre este botão durante 2 segundos.';
        const dialog = document.createElement('dialog');
        dialog.className = 'cb-operation-cost-dialog';
        dialog.setAttribute('aria-label', 'Detalhe das linhas do cálculo');
        dialog.style.cssText = 'width:min(1200px,96vw);max-height:90vh;overflow:auto;border:1px solid #ccc;border-radius:6px;padding:20px;overscroll-behavior:contain';
        const dialogHeading = document.createElement('h2');
        dialogHeading.textContent = 'Linhas do cálculo';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-default';
        close.textContent = 'Fechar';
        close.style.cssText = 'display:block;margin:16px 0 0 auto';
        close.addEventListener('click', () => dialog.close());
        const openDialog = () => {
            if (!dialog.open) dialog.showModal();
        };
        let hoverTimer;
        const clearHoverTimer = () => {
            if (hoverTimer !== undefined) clearTimeout(hoverTimer);
            hoverTimer = undefined;
        };
        summary.addEventListener('click', openDialog);
        summary.addEventListener('pointerenter', event => {
            if (event.pointerType === 'touch') return;
            clearHoverTimer();
            hoverTimer = setTimeout(openDialog, 2000);
        });
        summary.addEventListener('pointerleave', clearHoverTimer);
        summary.addEventListener('pointerdown', clearHoverTimer);
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
        dialog.append(dialogHeading);
        if (edit?.openManual && !ledger.complete) {
            const note = document.createElement('p');
            note.className = 'alert alert-warning';
            note.textContent = 'Há linhas por completar. Clique no nome de uma operação/material para introduzir os dados em falta. A escolha pode ser guardada como rascunho, mas não pode ser confirmada nem enviada ao cliente enquanto estiver incompleta.';
            dialog.append(note);
        }
        section.append(summary);
        const scroll = document.createElement('div');
        scroll.className = 'cb-scroll';
        const table = document.createElement('table');
        const head = table.createTHead().insertRow();
        const columns = costPlus ? ['Operação / material', 'Quantidade / base', 'Tempo (h)', 'Custo de referência',
            'Custo calculado', 'Custo manual (€)', 'Acréscimo (%)', 'Venda calculada'] :
            ['Operação / material', 'Quantidade / base', 'Tempo (h)', 'Tarifa custo / venda', 'Custo interno', 'Valor de venda'];
        columns.splice(3, 0, 'Tempo (hh:mm:ss)');
        for (const label of columns) {
            const th = document.createElement('th');
            th.textContent = label;
            head.append(th);
        }
        const body = table.createTBody();
        const grouped = groupedLines(ledger);
        const expanded = new Set(edit?.expanded || []);
        const rowsByKey = new Map();
        const updateGroups = () => {
            for (const { line, ancestors } of grouped) {
                rowsByKey.get(line.key).hidden = ancestors.some(key => !expanded.has(key));
            }
        };
        for (const { line, ancestors } of grouped) {
            const row = body.insertRow();
            rowsByKey.set(line.key, row);
            row.dataset.costLine = line.key;
            const basis = line.quantity === null ? 'Por preencher' :
                (line.unit === 'kg' ? line.quantity.toLocaleString('pt-PT', { maximumFractionDigits: 4 }) :
                    String(line.quantity)) + ' ' + (line.unit || '');
            const rate = costPlus ? line.costManual ? 'Custo total manual' :
                line.ctp ? 'Equipamento ' + unitMoney(line.ctp.equipmentRate) + '/h · RH ' + unitMoney(line.ctp.laborRate) + '/h por intervenção' :
                line.imposition && line.operationRow ? 'Estação ' + unitMoney(line.imposition.stationRate) +
                    '/h + RH ' + unitMoney(line.imposition.laborRate) + '/h' :
                line.tariff ? unitMoney(line.tariff.cost) + ' por ' + (line.tariff.basis || '?') + ' · v' + (line.tariff.version || '?') :
                line.key === 'clicks' && line.saleReferenceRate !== null && line.saleReferenceRate !== undefined ?
                    unitMoney(line.saleReferenceRate) + ' por clique · tarifa de venda; custo de compra em falta' :
                line.quantity > 0 ? unitMoney(line.cost === null ? null : line.cost / line.quantity) + ' por ' + (line.unit || 'un.') :
                    'Valor total' : line.costManual && line.saleManual ? 'Valores totais manuais' :
                line.tariff ? money(line.tariff.cost) + ' / ' + money(line.tariff.sale) +
                ' por ' + (line.tariff.basis || '?') + ' · v' + (line.tariff.version || '?') :
                line.laminationMaterial && line.quantity > 0 ?
                    unitMoney(line.cost === null ? null : line.cost / line.quantity) + ' / ' +
                    unitMoney(line.sale === null ? null : line.sale / line.quantity) + ' por m' :
                line.key === 'clicks' && line.saleReferenceRate !== null && line.saleReferenceRate !== undefined ?
                    unitMoney(line.saleReferenceRate) + ' por clique · tarifa de venda; custo de compra em falta' :
                line.quantity > 0 ? money(line.cost === null ? null : line.cost / line.quantity) + ' / ' +
                    money(line.sale === null ? null : line.sale / line.quantity) : 'Valor total';
            const origin = [line.source, line.costManual ? 'Custo manual' : '', line.saleManual ? 'Venda manual' : '',
                ...(line.errors || [])].filter(Boolean).join(' · ');
            const values = [line.name, basis, line.hours === null || line.hours === undefined ? '—' :
                line.hours.toFixed(4), rate, money(line.cost),
                line.saleReference !== null && line.saleReference !== undefined ?
                    money(line.saleReference) + ' (referência)' : money(line.sale)];
            if (costPlus) values.splice(5, 1, String(line.markup ?? ledger.pricing.markup),
                line.saleReference !== null && line.saleReference !== undefined ?
                    money(line.saleReference) + ' (referência)' : money(line.sale));
            if (costPlus) {
                values[4] = money(Object.hasOwn(line, 'calculatedCost') ? line.calculatedCost : line.cost);
                values.splice(5, 0, '');
            }
            values.splice(3, 0, formatHours(line.hours));
            for (const value of values) row.insertCell().textContent = value;
            if (costPlus) {
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0';
                input.step = 'any';
                input.className = 'form-control';
                input.style.width = '110px';
                input.placeholder = 'Calculado';
                input.value = ledger.inputs?.commercial?.costs?.[line.key] ?? '';
                input.dataset.lineCost = line.key;
                input.setAttribute('aria-label', line.name + ' · custo manual (€)');
                input.readOnly = !edit?.changeCost;
                input.addEventListener('change', () => edit?.changeCost?.(line.key,
                    input.validity.badInput ? 'inválido' : input.value, section));
                row.cells[6].append(input);
            }
            if (line.saleReference !== null && line.saleReference !== undefined) {
                row.cells[row.cells.length - 1].title =
                    'Tarifa de venda do clique na simulação; não é custo de compra nem integra a venda calculada por acréscimo.';
            }
            if (line.cost === null || line.sale === null || line.sale === 0 || line.errors?.length) {
                row.classList.add('cb-missing-data');
            }
            if (origin) row.cells[0].title = 'Origem / dados em falta: ' + origin;
            if (edit?.openManual) {
                const link = document.createElement('button');
                link.type = 'button';
                link.className = 'btn btn-link';
                link.style.padding = '0';
                link.textContent = line.name;
                link.dataset.manualLine = line.key;
                link.title = (line.cost === null || line.sale === null || line.errors?.length ?
                    'Clique para preencher os dados em falta. ' : 'Clique para ajustar esta linha. ') +
                    (origin ? 'Origem / dados em falta: ' + origin : '');
                link.addEventListener('click', () => {
                    dialog.close();
                    edit.openManual(line);
                });
                row.cells[0].replaceChildren(link);
            }
            if (costPlus && edit?.change) {
                const cell = row.cells[7];
                cell.textContent = '';
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0';
                input.step = 'any';
                input.className = 'form-control';
                input.style.width = '110px';
                input.value = edit.overrides[line.key] === undefined || edit.overrides[line.key] === '' ?
                    ledger.pricing.markup : edit.overrides[line.key];
                input.placeholder = String(ledger.pricing.markup);
                input.dataset.lineMarkup = line.key;
                input.setAttribute('aria-label', line.name + ' · acréscimo (%)');
                input.addEventListener('change', () => edit.change(line.key,
                    input.validity.badInput ? 'inválido' : input.value, section));
                cell.append(input);
            }
            if (isExtraOperation(ledger, line)) {
                const badge = document.createElement('span');
                badge.className = 'label label-info cb-operation-extra';
                badge.textContent = 'Extra';
                badge.style.marginLeft = '8px';
                badge.title = 'Operação opcional selecionada no componente';
                row.cells[0].append(badge);
            }
            row.cells[0].style.paddingLeft = (8 + ancestors.length * 20) + 'px';
            if (grouped.some(item => item.ancestors.at(-1) === line.key)) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'btn btn-default';
                toggle.dataset.dependencyGroup = line.key;
                const initiallyOpen = expanded.has(line.key);
                toggle.textContent = initiallyOpen ? '−' : '+';
                toggle.style.marginRight = '8px';
                toggle.setAttribute('aria-label', 'Expandir dependências de ' + line.name);
                toggle.setAttribute('aria-expanded', String(initiallyOpen));
                toggle.addEventListener('click', () => {
                    if (expanded.has(line.key)) expanded.delete(line.key);
                    else expanded.add(line.key);
                    const open = expanded.has(line.key);
                    toggle.textContent = open ? '−' : '+';
                    toggle.setAttribute('aria-expanded', String(open));
                    toggle.setAttribute('aria-label', (open ? 'Recolher' : 'Expandir') + ' dependências de ' + line.name);
                    updateGroups();
                });
                row.cells[0].prepend(toggle);
            }
        }
        updateGroups();
        appendDetailSubtotals(body, ledger, costPlus);
        const totals = table.createTFoot().insertRow();
        const totalValues = costPlus ? ['TOTAL (s/IVA)', '', '', '', money(sum(ledger.lines.map(line => ({
            cost: Object.hasOwn(line, 'calculatedCost') ? line.calculatedCost : line.cost })), 'cost')),
            money(ledger.cost), '', money(ledger.sale)] :
            ['TOTAL (s/IVA)', '', '', '', money(ledger.cost), money(ledger.sale)];
        totalValues.splice(3, 0, '');
        for (const value of totalValues) {
            totals.insertCell().textContent = value;
        }
        if (costPlus) {
            for (const row of table.rows) {
                for (const index of [5, 6, 8]) row.cells[index].style.textAlign = 'right';
            }
            for (const input of table.querySelectorAll('input[data-line-cost]')) input.style.textAlign = 'right';
        }
        styleDetailTable(table, ledger, costPlus);
        scroll.append(table);
        dialog.append(scroll);
        if (costPlus) {
            const commercial = document.createElement('p');
            commercial.className = 'cb-commercial-total';
            commercial.textContent = 'Custo total ' + money(ledger.cost) + ' + acréscimo ' +
                (ledger.pricing.version === 2 ? 'por linha; padrão ' : '') +
                (ledger.pricing.markup === null ? 'Por completar' : ledger.pricing.markup + '%') + ' (' +
                money(ledger.pricing.increase) + ') = venda ' + money(ledger.sale) +
                ' · venda/un. ' + unitMoney(units.sale) + ' · sem IVA. Não acumula margens ou vendas dos materiais e operações.';
            dialog.append(commercial);
        }
        if (ledger.errors.length) {
            const warning = document.createElement('p');
            warning.className = 'alert alert-warning';
            warning.textContent = ledger.errors.join(' ');
            dialog.append(warning);
        }
        if (edit?.save) {
            const save = document.createElement('button');
            save.type = 'button';
            save.className = 'btn btn-primary';
            save.textContent = 'Gravar ajustes';
            save.style.marginRight = '8px';
            save.addEventListener('click', () => {
                if (edit.save() !== false) dialog.close();
            });
            dialog.append(save);
        }
        dialog.append(close);
        section.append(dialog);
        return section;
    }

    function create(data, materials, selections, target, operationTable) {
        const costPlus = Array.isArray(data.commercial_pricing);
        const id = Number(data.component[0].id);
        const inputs = costPlus ? { commercial: { markup: commercialPricing(data).markup ?? '' } } : {};
        const savedOperationSources = new Map();
        const savedLineKeys = new Set();
        let savedPricing;
        const saved = [...selections.values()].sort((a, b) => Number(a.id) - Number(b.id));
        for (const selection of saved) {
            try {
                const ledger = JSON.parse(selection.details).calculation?.allOperations;
                if (ledger?.manualAdjustmentScope !== 'selection') Object.assign(inputs, ledger?.inputs || {});
                else if (ledger.inputs?.commercial) inputs.commercial = { markup: ledger.inputs.commercial.markup };
                savedPricing = ledger?.pricing;
                for (const row of ledger?.sourceOperations || []) savedOperationSources.set(String(row.id), row);
                for (const line of ledger?.lines || []) savedLineKeys.add(line.key);
            } catch (error) {
                console.error('Ler parâmetros de operações guardados:', error);
                throw new Error('Uma seleção guardada tem parâmetros inválidos. Corrija antes de calcular.');
            }
            if (costPlus) {
                const current = commercialPricing(data);
                if (!savedPricing || savedPricing.quoteMarkup !== current.quoteMarkup) inputs.commercial = { markup: current.markup ?? '' };
                else inputs.commercial = { ...inputs.commercial, markup: inputs.commercial?.markup ?? current.markup ?? '' };
                for (const values of Object.values(inputs)) {
                    delete values.sale;
                    delete values.margin;
                }
            }
        }
        for (const row of data.costing_operations.filter(row => Number(row.operation_id) === 42)) {
            const native = adhesiveInputs(row);
            if (Object.keys(native).length) inputs['adhesive:' + row.id] = native;
        }
        for (const row of data.costing_operations.filter(row => [2, 9].includes(Number(row.operation_id)))) {
            inputs['operation:' + row.id] = { ...inputs['operation:' + row.id],
                pileSize: row.cut_pile_size === undefined || row.cut_pile_size === null || row.cut_pile_size === '' ?
                    inputs['operation:' + row.id]?.pileSize ?? '' : Number(row.cut_pile_size) === 250 ?
                        inputs['operation:' + row.id]?.pileSize ?? '' : row.cut_pile_size };
        }
        for (const row of data.costing_operations.filter(row => Number(row.operation_id) === 10)) {
            const previous = inputs['operation:' + row.id];
            inputs['operation:' + row.id] = { ...previous, maxKg: row.packaging_kg || previous?.maxKg || 10 };
            inputs['packaging:' + row.id] = packagingInputs(row, inputs['packaging:' + row.id]);
            const old = savedOperationSources.get(String(row.id));
            const native = packagingInputs(row);
            for (const key of ['type', 'box', 'width', 'length', 'height', 'overlap']) {
                if (old && String(old['packaging_' + key] ?? '') !== String(row['packaging_' + key] ?? '')) {
                    inputs['packaging:' + row.id][key] = native[key];
                }
            }
        }
        const section = document.createElement('section');
        section.className = 'cb-card';
        section.id = 'cb-all-operation-inputs';
        section.hidden = true;
        const title = document.createElement('h2');
        title.textContent = 'Cálculo de todas as operações · dados em falta / ajustes';
        section.append(title);
        if (costPlus) {
            const label = document.createElement('label');
            label.textContent = 'Acréscimo sobre o custo (%)';
            const input = document.createElement('input');
            input.type = 'number';
            input.min = '0';
            input.step = 'any';
            input.className = 'form-control';
            input.value = inputs.commercial.markup;
            input.dataset.costKey = 'commercial';
            input.dataset.costParameter = 'markup';
            input.setAttribute('aria-label', label.textContent);
            let timer;
            input.addEventListener('input', () => {
                inputs.commercial.markup = input.validity.badInput ? 'inválido' : input.value;
                context.revision++;
                context.invalidate();
                status.textContent = 'Percentagem alterada: a atualizar o cálculo. Confirme e guarde para fixar esta percentagem na simulação.';
                clearTimeout(timer);
                timer = setTimeout(() => {
                    if (!section.isConnected) return;
                    for (const button of document.querySelectorAll('button')) {
                        if (['Comparar custos das sugestões', 'Comparar todas as quantidades',
                            'Comparar produção das rubricas'].includes(button.textContent.trim())) button.click();
                    }
                }, 300);
            });
            label.append(input);
            const help = document.createElement('p');
            help.className = 'cb-note';
            help.textContent = 'Sem ajustes por linha: venda = custo total × (1 + percentagem/100). 30% sobre 100 € de custo = 130 € de venda; não é margem de 30% sobre a venda. Campo editável nesta simulação; o padrão para todos os componentes está em Editar orçamento → Descrição do Produto. Vazio no orçamento usa 30%; zero explícito é válido. Na listagem final, edite diretamente a coluna Acréscimo (%) e clique em Gravar para substituir o padrão em operações ou materiais específicos; a venda total passa a ser a soma das vendas das linhas. As vendas e margens antigas por operação/material são ignoradas. Simulações antigas só mudam ao confirmar e guardar.';
            section.append(label, help);
            const quoteId = commercialPricing(data).quoteId;
            if (quoteId) {
                const edit = document.createElement('a');
                edit.href = 'index.php?' + new URLSearchParams({ module: 'items/form', entity_id: '146',
                    id: quoteId, path: '146-' + quoteId });
                edit.target = '_blank';
                edit.rel = 'noopener';
                edit.textContent = 'Editar percentagem padrão no orçamento';
                section.append(edit);
            }
        }
        const note = document.createElement('p');
        note.className = 'cb-note';
        note.textContent = 'Corte inicial/final: pilha útil de 80 mm dividida pela espessura do papel escolhido (mm); sem espessura, 250 folhas/planos. O valor nativo padrão 250 permite este cálculo automático; outros valores nativos são manuais. Pode fixar qualquer capacidade abaixo, incluindo 250. Campo vazio = automático. Contagem pelo plano, com aparas e separação. Pode indicar cortes ou tempo total, incluindo acerto. Planos sem geometria e cadernos exigem confirmação manual.';
        section.append(note);
        const packagingNote = document.createElement('p');
        packagingNote.className = 'cb-note';
        packagingNote.textContent = 'Embalamento: peso líquido do papel de todos os componentes da mesma tiragem, até 10 kg por defeito, sem caixa. Limite editável; referência ergonómica, não garantia de segurança. Embrulho sempre em rolo kraft de 1,20 m, 90 g/m², artigo #1328, compra por kg. Painel: 2 × (largura + altura) + sobreposição por comprimento + 2 × altura + sobreposição; 30 mm por defeito. Testa ambos os sentidos e peças lado a lado para minimizar comprimento. Consumo = comprimento utilizado × largura total do rolo × 90 g/m², incluindo sobra lateral; não exige o comprimento total do rolo. Última compra concluída do artigo, na unidade de consumo; venda sobre compra 30% por defeito. Caixa: uma por embalagem; validar capacidade. Custo contado uma vez no primeiro componente com Embalamento. Recarregue após guardar simulações noutros componentes.';
        section.append(packagingNote);
        if (costPlus) packagingNote.textContent = packagingNote.textContent.replace('venda sobre compra 30% por defeito.',
            'acréscimo aplicado apenas ao custo total do orçamento.');
        if (data.lamination_articles) {
            const note = document.createElement('p');
            note.className = 'cb-note';
            note.textContent = 'Plastificação: acabamento por face definido no componente. Seleção automática do filme compatível com menor custo por metro, usando o preço da última compra concluída de cada artigo na unidade m. Pode fixar o filme por face abaixo. Bordos sem plástico permitidos se todas as peças finais ficarem cobertas. Filme: (planos úteis + acerto próprio da plastificação por forma) × comprimento total do plano por face; inclui sobras laterais e bordos. Tempo: passagens/velocidade + acerto por filme distinto. Material separado da execução; venda sobre compra 30% por defeito ou regra do material associado. Geometria em falta ou filme incompatível bloqueiam o total.';
            section.append(note);
            if (costPlus) note.textContent = note.textContent.replace('venda sobre compra 30% por defeito ou regra do material associado.',
                'venda calculada apenas no resumo comercial do orçamento.');
        }
        if (data.costing_operations.some(row => Number(row.operation_id) === 32)) {
            const note = document.createElement('p');
            note.className = 'alert alert-info';
            note.textContent = 'Espiral: indique o diâmetro (Nº) e as voltas, selecione um artigo compatível das compras com unidade Un. e introduza a quantidade total do material. O custo usa o preço da última compra concluída; a quantidade do material é manual.';
            section.append(note);
        }
        const thicknessFields = document.createElement('fieldset');
        thicknessFields.className = 'cb-card alert alert-warning';
        thicknessFields.hidden = true;
        const thicknessLegend = document.createElement('legend');
        thicknessLegend.textContent = 'Espessura do papel em falta';
        thicknessFields.append(thicknessLegend);
        const thicknessNote = document.createElement('p');
        thicknessNote.textContent = 'Introduza a espessura real em mm para calcular a altura do embrulho e a pilha dos cortes. Depois volte a comparar/calcular. O valor fica guardado nesta simulação, sem alterar o artigo nem os orçamentos anteriores; não é estimado pela gramagem.';
        thicknessFields.append(thicknessNote);
        section.append(thicknessFields);
        const thicknessControls = new Map();
        const table = document.createElement('table');
        const head = table.createTHead().insertRow();
        for (const label of ['Operação / material', 'Quantidade de trabalho', 'Tempo total (h)', 'Custo total manual (€)',
            ...costPlus ? [] : ['Venda total manual (€)'], 'Dados do catálogo']) {
            const th = document.createElement('th');
            th.textContent = label;
            head.append(th);
        }
        const body = table.createTBody();
        const rows = data.costing_operations.filter(row => !isPrinting(row))
            .map(row => ({ key: 'operation:' + row.id, name: row.name +
                (Number(row.operation_id) === 42 ? ' — aplicação (sem material)' : ''), operation: row }));
        rows.push({ key: 'printing', name: 'Impressão · ajuste de totais' },
            { key: 'clicks', name: 'Cliques · custo total de compra' });
        for (const color of data.color_materials || []) {
            if (Number(color.color) !== 3804) rows.push({ key: 'color:' + color.record_id,
                name: 'Tinta — ' + (color.color_name || inkColor(color) || color.name || 'cor #' + color.color) +
                    (inkColor(color) ? ' · consumo estimado em kg' :
                        ' · consumo em ' + (color.unit_name || 'unidade de compra #' + (color.unit || '?'))) });
        }
        for (const material of materials.filter(row => !row.paper && ![984, 985].includes(Number(row.id)))) {
            rows.push({ key: 'material:' + material.recordId, name: material.name });
        }
        for (const operation of data.costing_operations.filter(row => Number(row.operation_id) === 42)) {
            rows.push({ key: 'adhesive:' + operation.id, name: 'BiAdesivo — material', adhesive: true });
        }
        for (const key of Object.keys(inputs)) {
            if (!(costPlus && key === 'commercial') && !savedLineKeys.has(key) && !key.startsWith('paper:') && !rows.some(row => row.key === key ||
                (Number(row.operation?.operation_id) === 10 && key === 'packaging:' + row.operation.id) ||
                (Number(row.operation?.operation_id) === 13 && key === 'lamination:' + row.operation.id) ||
                (Number(row.operation?.operation_id) === 32 && key === 'spiral:' + row.operation.id))) delete inputs[key];
        }
        for (const row of rows) {
            if (row.adhesive) continue;
            const tr = body.insertRow();
            tr.insertCell().textContent = row.name;
            if (row.operation && ![3, 43, 44].includes(Number(row.operation.operation_id))) {
                const machines = data.costing_equipment.filter(item =>
                    Number(item.operation_id) === Number(row.operation.operation_id));
                if (machines.length) {
                    const label = document.createElement('label');
                    label.textContent = 'Operação / máquina';
                    label.style.display = 'block';
                    const select = document.createElement('select');
                    select.className = 'form-control';
                    select.dataset.costKey = row.key;
                    select.dataset.costParameter = 'equipment';
                    select.setAttribute('aria-label', row.name + ' · operação / máquina');
                    select.append(new Option('Usar equipamento configurado', ''));
                    for (const machine of machines) {
                        select.append(new Option(machine.machine_name || 'Equipamento #' + machine.machine_id,
                            String(machine.machine_id)));
                    }
                    select.value = inputs[row.key]?.equipment ?? '';
                    select.addEventListener('change', () => {
                        inputs[row.key] = { ...inputs[row.key], equipment: select.value };
                        context.revision++;
                        context.invalidate();
                        status.textContent = 'Equipamento alterado: volte a calcular antes de guardar.';
                    });
                    tr.cells[0].append(label);
                    label.append(select);
                }
            }
            for (const key of costPlus ? ['quantity', 'hours', 'cost'] : ['quantity', 'hours', 'cost', 'sale']) {
                const cell = tr.insertCell();
                if (((row.key === 'printing' || row.key === 'clicks') && ['quantity', 'hours'].includes(key)) ||
                    (Number(row.operation?.operation_id) === 3 && key === 'quantity') ||
                    (Number(row.operation?.operation_id) === 43 && ['quantity', 'hours'].includes(key))) continue;
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0';
                input.step = 'any';
                input.className = 'form-control';
                input.style.width = '125px';
                input.value = inputs[row.key]?.[key] ?? '';
                input.placeholder = 'Automático';
                input.dataset.costKey = row.key;
                input.dataset.costParameter = key;
                input.setAttribute('aria-label', row.name + ' · ' + key);
                input.addEventListener('input', () => {
                    inputs[row.key] = inputs[row.key] || {};
                    inputs[row.key][key] = input.validity.badInput ? 'inválido' : input.value;
                    context.revision++;
                    context.invalidate();
                    status.textContent = 'Parâmetros alterados: volte a comparar/calcular antes de guardar uma escolha.';
                });
                cell.append(input);
            }
            if (Number(row.operation?.operation_id) === 32) {
                const key = 'spiral:' + row.operation.id;
                for (const [parameter, labelText] of [
                    ['diameter', 'Diâmetro do espiral (Nº)'],
                    ['turns', 'Número de voltas'],
                    ['materialQuantity', 'Quantidade total de material espiral']
                ]) {
                    const label = document.createElement('label');
                    label.textContent = labelText;
                    label.style.display = 'block';
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = parameter === 'materialQuantity' ? '0.000001' : '1';
                    input.step = parameter === 'materialQuantity' ? 'any' : '1';
                    input.className = 'form-control';
                    input.value = inputs[key]?.[parameter] ?? '';
                    input.placeholder = 'Por preencher';
                    input.dataset.costKey = key;
                    input.dataset.costParameter = parameter;
                    input.setAttribute('aria-label', labelText);
                    input.addEventListener('input', () => {
                        inputs[key] = { ...inputs[key], [parameter]: input.validity.badInput ? 'inválido' : input.value };
                        context.revision++;
                        context.invalidate();
                        status.textContent = 'Parâmetros do espiral alterados: volte a calcular antes de guardar.';
                    });
                    label.append(input);
                    tr.cells[0].append(label);
                }
                const articleLabel = document.createElement('label');
                articleLabel.textContent = 'Artigo espiral comprado (unidade Un.)';
                articleLabel.style.display = 'block';
                const articleSelect = document.createElement('select');
                articleSelect.className = 'form-control';
                articleSelect.dataset.costKey = key;
                articleSelect.dataset.costParameter = 'articleId';
                articleSelect.setAttribute('aria-label', articleLabel.textContent);
                articleSelect.append(new Option('Selecione diâmetro, voltas e artigo', ''));
                for (const article of data.spiral_articles || []) {
                    const spec = spiralArticleSpec(article);
                    if (!spec.diameter || !spec.turns) continue;
                    articleSelect.append(new Option('Nº ' + spec.diameter + ' · ' + spec.turns +
                        ' voltas · ' + article.name + ' · ' + unitMoney(number(article.price)) + '/Un.',
                    String(article.id)));
                }
                articleSelect.value = inputs[key]?.articleId ?? '';
                articleSelect.addEventListener('change', () => {
                    inputs[key] = { ...inputs[key], articleId: articleSelect.value };
                    context.revision++;
                    context.invalidate();
                    status.textContent = 'Artigo espiral alterado: volte a calcular antes de guardar.';
                });
                articleLabel.append(articleSelect);
                tr.cells[0].append(articleLabel);
            }
            const tariff = row.operation ? currentTariff(data, row.operation.operation_id) : null;
            const parameters = row.operation ? production(data, row.operation) : null;
            tr.insertCell().textContent = tariff ? tariff.error ||
                'Custo ' + money(tariff.cost) + (costPlus ? '' : ' / venda ' + money(tariff.sale)) + '/' + tariff.basis +
                ' · ' + (parameters.speed ?? '?') + ' ' + parameters.unit + ' · acerto ' + (parameters.setup ?? '?') + ' min' :
                'Mantém a simulação; preencha apenas se necessário.';
            if (Number(row.operation?.operation_id) === 13 && data.lamination_articles) {
                const materialKey = 'lamination:' + row.operation.id;
                for (const [parameter, labelText] of [['entry', 'Margem de entrada da plastificação (mm)'],
                    ['exit', 'Margem de saída da plastificação (mm)'], ['narrow', 'Confirmar entrada pelo lado estreito']]) {
                    const label = document.createElement('label');
                    label.textContent = labelText;
                    label.style.display = 'block';
                    const input = document.createElement(parameter === 'narrow' ? 'select' : 'input');
                    if (parameter === 'narrow') {
                        for (const [value, text] of [['', 'Usar parâmetro nativo'], ['1', 'Confirmado'], ['0', 'Não confirmado']]) {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = text;
                            input.append(option);
                        }
                    } else {
                        input.type = 'number';
                        input.min = '0';
                        input.step = 'any';
                        input.placeholder = 'Parâmetro nativo / por definir';
                    }
                    input.className = 'form-control';
                    input.value = inputs[materialKey]?.[parameter] ?? '';
                    input.dataset.costKey = materialKey;
                    input.dataset.costParameter = parameter;
                    input.setAttribute('aria-label', labelText);
                    input.addEventListener(parameter === 'narrow' ? 'change' : 'input', () => {
                        inputs[materialKey] = { ...inputs[materialKey], [parameter]: input.value };
                        context.revision++;
                        context.invalidate();
                        status.textContent = 'Parâmetros da plastificação alterados: volte a calcular antes de gravar.';
                    });
                    label.append(input);
                    tr.cells[0].append(label);
                }
                for (const face of ['front', 'back']) {
                    const finish = data.component[0]['lamination_' + face];
                    const label = document.createElement('label');
                    label.style.display = 'block';
                    label.textContent = (face === 'front' ? 'Frente' : 'Verso') + ' — ' +
                        (laminationFinishes[finish] || 'Por definir no componente');
                    tr.cells[0].append(label);
                    if (!finish || finish === 'none') continue;
                    const select = document.createElement('select');
                    select.className = 'form-control';
                    const choices = [['', 'Automático · filme compatível com menor custo/m'],
                        ...data.lamination_articles.filter(article => article.finish === finish)
                            .map(article => [String(article.id), article.name + ' · ' + article.width + ' mm · ' +
                                unitMoney(number(article.price)) + '/m'])];
                    for (const [value, text] of choices) {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = text;
                        select.append(option);
                    }
                    const parameter = face + 'Article';
                    select.value = inputs[materialKey]?.[parameter] ?? '';
                    select.dataset.costKey = materialKey;
                    select.dataset.costParameter = parameter;
                    select.setAttribute('aria-label', label.textContent + ' · filme');
                    select.addEventListener('change', () => {
                        inputs[materialKey] = { ...inputs[materialKey], [parameter]: select.value };
                        context.revision++;
                        context.invalidate();
                        status.textContent = 'Filme de plastificação alterado: volte a calcular antes de guardar.';
                    });
                    label.append(select);
                }
            }
            if (Number(row.operation?.operation_id) === 10) {
                const label = document.createElement('label');
                label.textContent = 'Peso máximo de papel por embalagem (kg)';
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '0.01';
                input.step = 'any';
                input.className = 'form-control';
                input.value = inputs[row.key]?.maxKg ?? 10;
                input.dataset.costKey = row.key;
                input.dataset.costParameter = 'maxKg';
                const owner = data.quote_packaging?.[0];
                input.disabled = Boolean(owner && String(owner.id) !== String(row.operation.id));
                if (input.disabled) label.textContent += ' · definir na operação #' + owner.id + ' do componente #' + owner.component_id;
                input.addEventListener('input', () => {
                    inputs[row.key] = inputs[row.key] || {};
                    inputs[row.key].maxKg = input.validity.badInput ? 'inválido' : input.value;
                    context.revision++;
                    context.invalidate();
                    status.textContent = 'Peso por embalagem alterado: volte a calcular antes de guardar.';
                });
                label.append(input);
                tr.cells[0].append(label);
                const materialKey = 'packaging:' + row.operation.id;
                const controls = {};
                const addControl = (key, title, options) => {
                    const label = document.createElement('label');
                    label.textContent = title;
                    label.style.display = 'block';
                    const control = document.createElement(options ? 'select' : 'input');
                    if (options) {
                        for (const [value, text] of options) {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = text;
                            control.append(option);
                        }
                    } else {
                        control.type = 'number';
                        control.min = key === 'overlap' ? '0' : '0.01';
                        control.step = 'any';
                    }
                    control.className = 'form-control';
                    if (['width', 'length', 'height'].includes(key)) {
                        control.placeholder = 'Automático · espessura real';
                        control.title = 'Opcional: substitui a dimensão calculada pelo formato e espessura real do papel.';
                    }
                    control.value = inputs[materialKey]?.[key] ?? '';
                    control.disabled = input.disabled;
                    control.dataset.costKey = materialKey;
                    control.dataset.costParameter = key;
                    control.addEventListener(options ? 'change' : 'input', () => {
                        inputs[materialKey][key] = control.validity.badInput ? 'inválido' : control.value;
                        showPackaging();
                        context.revision++;
                        context.invalidate();
                        status.textContent = 'Material de embalagem alterado: volte a calcular antes de guardar.';
                    });
                    label.append(control);
                    tr.cells[0].append(label);
                    controls[key] = { label, control };
                };
                addControl('type', 'Material da embalagem', [['1', 'Embrulho — kraft 90 g/m²'], ['2', 'Caixa']]);
                addControl('box', 'Artigo de caixa · última compra concluída',
                    [['', 'Selecione a caixa'], ...(data.packaging_articles || []).filter(a => String(a.packaging_type) === '2')
                        .map(a => [String(a.id), a.name + ' · ' + money(number(a.price)) + '/caixa'])]);
                for (const [key, title] of [['width', 'Largura do volume (mm)'], ['length', 'Comprimento do volume (mm)'],
                    ['height', 'Altura do volume (mm)'], ['overlap', 'Sobreposição do kraft (mm)']]) addControl(key, title);
                function showPackaging() {
                    const wrapping = controls.type.control.value === '1';
                    controls.box.label.hidden = wrapping;
                    for (const key of ['width', 'length', 'height', 'overlap']) controls[key].label.hidden = !wrapping;
                }
                showPackaging();
            }
            if ([2, 9].includes(Number(row.operation?.operation_id))) {
                const label = document.createElement('label');
                label.textContent = 'Folhas/planos por pilha';
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '1';
                input.step = '1';
                input.className = 'form-control';
                input.value = inputs[row.key]?.pileSize ?? '';
                input.placeholder = 'Auto: 80 mm / espessura; sem espessura: 250';
                input.title = input.placeholder;
                input.dataset.costKey = row.key;
                input.dataset.costParameter = 'pileSize';
                input.addEventListener('input', () => {
                    inputs[row.key] = inputs[row.key] || {};
                    inputs[row.key].pileSize = input.validity.badInput ? 'inválido' : input.value;
                    context.revision++;
                    context.invalidate();
                    status.textContent = 'Capacidade da pilha alterada: volte a calcular antes de guardar.';
                });
                label.append(input);
                tr.cells[0].append(label);
            }
        }
        const scroll = document.createElement('div');
        scroll.className = 'cb-scroll';
        scroll.append(table);
        section.append(scroll);
        const adhesiveArticle = data.adhesive_articles?.find(article => Number(article.id) === 201);
        for (const row of rows.filter(row => row.adhesive)) {
            const fieldset = document.createElement('fieldset');
            fieldset.className = 'cb-card';
            const legend = document.createElement('legend');
            legend.textContent = 'Aplicação de BiAdesivo · parâmetros da operação / ajustes nesta simulação';
            fieldset.append(legend);
            const description = document.createElement('p');
            description.textContent = adhesiveArticle ? adhesiveArticle.name + ' · ' + adhesiveArticle.width + ' × ' +
                adhesiveArticle.height + ' mm · compra ' + money(number(adhesiveArticle.price)) +
                '/folha (última compra concluída deste artigo). Rotação de 90° permitida, sem intervalo entre cortes.' :
                'Artigo Biadesivo 70×100 em falta no catálogo.';
            description.textContent += ' Os valores guardados em “Editar” na tabela das operações são carregados aqui. ' +
                'Alterações neste painel só ficam na escolha de simulação; para alterar os dados da operação use “Editar”.';
            fieldset.append(description);
            const preview = document.createElement('p');
            preview.className = 'cb-adhesive-preview';
            const updatePreview = () => {
                const quantities = data.rubrics?.length ? [data.rubrics.reduce((total, rubric) =>
                    total + Number(rubric.quantity), 0)] : (data.quantities || []).map(item => item.quantity);
                preview.textContent = quantities.length ? quantities.map(quantity => {
                    const result = adhesiveCalculation(adhesiveArticle, inputs[row.key] || {}, quantity);
                    return quantity + ' exemplares: ' + (result.errors.length ? result.errors.join(' ') :
                        result.pieces + ' peças ÷ ' + result.layout.piecesPerSheet + ' peças/folha → ' +
                        result.sheets + ' folhas · custo material ' + money(result.cost));
                }).join(' | ') : 'Adicione as quantidades do orçamento para calcular as folhas.';
            };
            for (const [parameter, label] of [['perCopy', 'Peças por exemplar'], ['width', 'Largura da peça (mm)'],
                ['height', 'Altura da peça (mm)'], ['margin', 'Venda: % sobre compra do material'],
                ['purchasePrice', 'Compra €/folha (apenas para substituir o catálogo)']]) {
                if (costPlus && parameter === 'margin') continue;
                const wrapper = document.createElement('label');
                wrapper.textContent = label;
                wrapper.style.cssText = 'display:inline-block;margin:4px 12px 4px 0';
                const input = document.createElement('input');
                input.type = 'number';
                input.min = parameter === 'margin' ? '0' : parameter === 'perCopy' ? '1' : '0.001';
                input.step = parameter === 'perCopy' ? '1' : 'any';
                input.className = 'form-control';
                input.value = inputs[row.key]?.[parameter] ?? (parameter === 'margin' ? '30' : '');
                input.placeholder = parameter === 'purchasePrice' ? 'Catálogo' : 'Por preencher';
                input.dataset.costKey = row.key;
                input.dataset.costParameter = parameter;
                input.setAttribute('aria-label', label);
                input.addEventListener('input', () => {
                    inputs[row.key] = inputs[row.key] || {};
                    inputs[row.key][parameter] = input.validity.badInput ? 'inválido' : input.value;
                    context.revision++;
                    context.invalidate();
                    status.textContent = 'BiAdesivo alterado: volte a calcular para atualizar as folhas e os preços.';
                    updatePreview();
                });
                wrapper.append(input);
                fieldset.append(wrapper);
            }
            updatePreview();
            fieldset.append(preview);
            section.append(fieldset);
        }
        const status = document.createElement('p');
        status.className = 'cb-note';
        section.append(status);
        const configure = document.createElement('button');
        configure.type = 'button';
        configure.className = 'btn btn-default';
        configure.textContent = 'Completar custos / ajustar parâmetros antes de gravar';
        configure.addEventListener('click', () => { section.hidden = !section.hidden; });
        target.append(configure, section);
        const history = document.createElement('section');
        history.className = 'cb-card';
        history.id = 'cb-final-cost-lines';
        target.append(history);
        const context = {
            revision: 0,
            laminationMargins() {
                const row = data.costing_operations.find(row => Number(row.operation_id) === 13) || {};
                const input = inputs['lamination:' + row.id];
                return {
                    entry: laminationMargin(row, 'entry', input),
                    exit: laminationMargin(row, 'exit', input)
                };
            },
            priceOptions: new Map(),
            priceRevision: 0,
            invalidate() {
                if (operationTable) {
                    for (const cell of operationTable.querySelectorAll('[data-operation-price-total]')) {
                        cell.textContent = 'Recalcular';
                    }
                }
            },
            apply(selection) {
                const snapshot = selection.parameters;
                if (!snapshot) return selection;
                const papers = [...(paperWeight(snapshot).details || []),
                    ...(packagingState(data).selections || []).flatMap(item => item.weight?.details || [])];
                for (const paper of papers) {
                    if (number(paper.thickness) > 0 && number(paper.thickness) <= 80) continue;
                    const key = 'paper:' + paper.articleId;
                    if (thicknessControls.has(key)) continue;
                    const label = document.createElement('label');
                    label.textContent = 'Espessura real (mm) · papel #' + paper.articleId +
                        (paper.name ? ' · ' + paper.name : '');
                    label.style.display = 'block';
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0.001';
                    input.max = '80';
                    input.step = 'any';
                    input.className = 'form-control';
                    input.placeholder = 'Introduza a espessura real em mm';
                    input.setAttribute('aria-required', 'true');
                    input.setAttribute('aria-label', label.textContent);
                    input.dataset.costKey = key;
                    input.dataset.costParameter = 'thickness';
                    input.value = inputs[key]?.thickness ?? '';
                    input.addEventListener('input', () => {
                        inputs[key] = { thickness: input.validity.badInput ? 'inválido' : input.value };
                        this.revision++;
                        this.invalidate();
                        status.textContent = 'Espessura alterada: volte a comparar/calcular antes de guardar.';
                    });
                    label.append(input);
                    thicknessFields.append(label);
                    thicknessControls.set(key, input);
                    thicknessFields.hidden = false;
                }
                const ledger = calculate(data, snapshot, inputs, materials);
                snapshot.allOperations = ledger;
                snapshot.unitPrices = unitPrices(ledger);
                snapshot.costingRevision = this.revision;
                selection.allOperations = ledger;
                selection.total = ledger.sale;
                selection.unit = ledger.sale === null ? null : ledger.sale / Number(selection.quantity);
                selection.errors = [...new Set([...(selection.errors || []), ...ledger.errors])];
                snapshot.results.total = selection.total;
                snapshot.results.unit = selection.unit;
                this.offerPrices(ledger, snapshot, false);
                return selection;
            },
            offerPrices(ledger, snapshot, historic) {
                if (!this.priceSelect) return;
                if (this.priceRevision !== this.revision) {
                    this.priceOptions.clear();
                    this.priceSelect.replaceChildren();
                    this.priceRevision = this.revision;
                }
                const key = JSON.stringify([historic, snapshot.source, snapshot.basis, snapshot.quantity,
                    snapshot.machineId, snapshot.paperId, snapshot.forms, snapshot.tariff?.operationId,
                    snapshot.platesPerForm, snapshot.passes, snapshot.plan?.id, snapshot.modeId, snapshot.parts?.map(part =>
                        [part.plan?.id, part.plan?.width, part.plan?.height, part.modeId, part.runs])]);
                const label = (historic ? 'Guardado · ' : 'Simulação · ') + snapshot.quantity + ' un. · ' +
                    (snapshot.machineName || snapshot.machine?.name || snapshot.machineId) +
                    (snapshot.plan?.id ? ' · plano #' + snapshot.plan.id : ' · plano dinâmico') +
                    (snapshot.modeId ? ' · modo ' + snapshot.modeId : '') +
                    ' · venda ' + money(ledger.sale);
                if (!this.priceOptions.has(key)) {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = label;
                    this.priceSelect.append(option);
                }
                this.priceOptions.set(key, ledger);
                this.showPrices(this.priceOptions.get(this.priceSelect.value));
            },
            showPrices(ledger) {
                if (!operationTable || !ledger) return;
                for (const tr of operationTable.tBodies[0].rows) {
                    const id = Number(tr.dataset.operationRow);
                    const lines = ledger.lines.filter(line => Number(line.operationRow) === id ||
                        Number(line.parentOperationRow) === id || line.operationRows?.some(row => Number(row) === id));
                    for (const [key, field] of [['cost', 'cost'], ['sale', 'sale']]) {
                        const cell = tr.querySelector('[data-operation-price-total="' + key + '"]');
                        if (!cell) continue;
                        cell.textContent = lines.length && lines.every(line => number(line[field]) !== null) ?
                            money(sum(lines, field)) : 'Por completar';
                        cell.title = lines.map(line => line.name + ': ' + money(line[field]) +
                            (line.adhesive ? ' · ' + line.adhesive.sheets + ' folhas' : '')).join('\n');
                    }
                }
            },
            check(selection) {
                if (selection.parameters?.costingRevision !== this.revision) {
                    throw new Error('Os parâmetros das operações mudaram. Recalcule antes de guardar.');
                }
            },
            detail(selection) {
                const identity = JSON.stringify([selection.quantityId || selection.quantity_id, selection.quantity,
                    selection.machineId || selection.machine_id, selection.plan]);
                const node = detail(selection.allOperations, {
                    changeCost: (key, value, previous) => {
                        const expanded = [...previous.querySelectorAll('[data-dependency-group][aria-expanded="true"]')]
                            .map(button => button.dataset.dependencyGroup);
                        inputs.commercial = { ...inputs.commercial,
                            costs: { ...inputs.commercial?.costs, [key]: value } };
                        this.revision++;
                        this.invalidate();
                        this.apply(selection);
                        for (const button of document.querySelectorAll('button')) {
                            if (['Comparar custos das sugestões', 'Comparar todas as quantidades',
                                'Comparar produção das rubricas'].includes(button.textContent.trim())) button.click();
                        }
                        const current = [...document.querySelectorAll('[data-simulation-detail]')]
                            .find(element => element.dataset.simulationDetail === identity);
                        if (current) {
                            const next = this.detail(selection);
                            current.replaceWith(next);
                            for (const button of next.querySelectorAll('[data-dependency-group]')) {
                                if (expanded.includes(button.dataset.dependencyGroup)) button.click();
                            }
                            next.querySelector('dialog').showModal();
                            [...next.querySelectorAll('[data-line-cost]')].find(input =>
                                input.dataset.lineCost === key)?.focus();
                        }
                    },
                    openManual: line => this.openManual({
                        selected_quantity: selection.quantity,
                        quantity_id: selection.quantityId,
                        machine_id: selection.machineId,
                        plan_id: selection.plan?.id
                    }, selection.parameters, selection.allOperations, line, false)
                });
                node.dataset.simulationDetail = identity;
                return node;
            },
            openManual(selection, snapshot, ledger, line, saved = true) {
                const dialog = document.createElement('dialog');
                dialog.id = 'cb-manual-operation-dialog';
                dialog.style.cssText = 'width: min(900px, 95vw);max-height:90vh;overflow:auto;border:1px solid #ccc;border-radius:6px';
                const title = document.createElement('h2');
                title.textContent = 'Preencher dados · ' + line.name;
                const help = document.createElement('p');
                help.className = 'alert alert-info';
                help.textContent = saved ? 'Apenas esta escolha de ' + selection.selected_quantity +
                    ' unidades. Vazio = cálculo automático, exceto os dados indicados como obrigatórios. Gravar cria uma nova versão desta escolha, mesmo que ainda fique incompleta; não altera versões anteriores, catálogo nem outras escolhas.'
                    : 'Introduza os dados desta linha. Guardar cria uma nova versão em rascunho, conserva os parâmetros e recalcula as simulações. Não confirma nem aprova o orçamento. Vazio = cálculo automático, exceto os dados obrigatórios.';
                const form = document.createElement('form');
                const values = JSON.parse(JSON.stringify(ledger.inputs || {}));
                const oldOperationId = line.operationRow ?? line.parentOperationRow;
                const oldOperation = ledger.sourceOperations?.find(row => String(row.id) === String(oldOperationId));
                const operation = data.costing_operations.find(row => String(row.id) === String(oldOperationId)) ||
                    data.costing_operations.find(row => Number(row.operation_id) ===
                        Number(line.operationId ?? oldOperation?.operation_id));
                const operationId = operation?.id ?? oldOperationId;
                const prefixes = ['operation:', 'adhesive:', 'lamination:', 'packaging:', 'spiral:', 'spiral-material:', 'imposition:'];
                for (const source of ledger.sourceOperations || []) {
                    const current = data.costing_operations.find(row =>
                        Number(row.operation_id) === Number(source.operation_id));
                    if (!current || String(current.id) === String(source.id)) continue;
                    for (const map of [values, values.commercial?.costs, values.commercial?.lines].filter(Boolean)) {
                        for (const key of Object.keys(map)) {
                            const prefix = prefixes.find(prefix => key.startsWith(prefix + source.id) &&
                                ['', ':'].includes(key.charAt((prefix + source.id).length)));
                            if (!prefix) continue;
                            const next = prefix + current.id + key.slice((prefix + source.id).length);
                            if (!Object.hasOwn(map, next)) map[next] = map[key];
                            delete map[key];
                        }
                    }
                }
                const editableKey = operation && oldOperationId !== undefined ?
                    line.key.replace(':' + oldOperationId, ':' + operationId) : line.key;
                const keys = new Set([editableKey]);
                if (operationId !== undefined) {
                    for (const prefix of ['operation:', 'adhesive:', 'lamination:', 'packaging:', 'spiral:']) keys.add(prefix + operationId);
                }
                if (line.key === 'paper' || [2, 9, 10].includes(Number(operation?.operation_id))) {
                    for (const control of section.querySelectorAll('[data-cost-key^="paper:"]')) keys.add(control.dataset.costKey);
                }
                const controls = [...section.querySelectorAll('[data-cost-key]')].filter(control =>
                    keys.has(control.dataset.costKey));
                const added = new Set();
                const groups = new Map();
                if (Number(operation?.operation_id) === 32) {
                    for (const [key, heading] of [['operation', 'Operação / máquina'],
                        ['material', 'Material espiral · compras']]) {
                        const group = document.createElement('fieldset');
                        const legend = document.createElement('legend');
                        legend.textContent = heading;
                        group.append(legend);
                        groups.set(key, group);
                        form.append(group);
                    }
                }
                const addControl = (original, key, parameter, text) => {
                    const identity = key + ':' + parameter;
                    if (added.has(identity)) return;
                    added.add(identity);
                    const label = document.createElement('label');
                    label.style.cssText = 'display:block;margin:10px 0';
                    label.textContent = text;
                    const input = original.cloneNode(true);
                    input.removeAttribute('id');
                    input.style.width = '100%';
                    input.value = values[key]?.[parameter] ?? original.value;
                    input.setAttribute('aria-label', text);
                    input.dataset.costKey = key;
                    input.dataset.costParameter = parameter;
                    label.append(input);
                    const group = groups.get(key.startsWith('spiral:') &&
                        ['articleId', 'materialQuantity'].includes(parameter) ? 'material' : 'operation');
                    (group || form).append(label);
                    input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => {
                        values[key] = { ...values[key], [parameter]: input.validity.badInput ? 'inválido' : input.value };
                        update();
                    });
                };
                for (const control of controls) {
                    const names = { quantity: 'Quantidade de trabalho', hours: 'Tempo total (h), incluindo acerto',
                        cost: 'Custo total manual (€)', sale: 'Venda total manual (€)' };
                    const text = control.closest('label')?.childNodes[0]?.textContent ||
                        names[control.dataset.costParameter] || control.getAttribute('aria-label');
                    addControl(control, control.dataset.costKey, control.dataset.costParameter, text);
                }
                if (!editableKey.startsWith('spiral-material:') && !added.has(editableKey + ':cost')) {
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.step = 'any';
                    input.placeholder = 'Automático';
                    input.className = 'form-control';
                    addControl(input, editableKey, 'cost', 'Custo total manual desta linha (€)');
                }
                if (line.color && !added.has(editableKey + ':quantity')) {
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.step = 'any';
                    addControl(input, editableKey, 'quantity', line.unit === 'kg' ?
                        'Consumo de tinta (kg) — vazio = estimativa automática' :
                        'Consumo de tinta na unidade nativa de compra');
                }
                const preview = document.createElement('div');
                const pending = document.createElement('p');
                pending.className = 'alert alert-warning';
                pending.setAttribute('role', 'status');
                const status = document.createElement('p');
                status.setAttribute('role', 'status');
                const save = document.createElement('button');
                save.type = 'submit';
                save.className = 'btn btn-primary';
                save.textContent = saved ? 'Guardar alterações numa nova versão' : 'Guardar dados e recalcular';
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'btn btn-default';
                cancel.textContent = 'Cancelar';
                let draft;
                const close = () => { dialog.close(); dialog.remove(); };
                let saving = false;
                cancel.addEventListener('click', close);
                dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
                dialog.addEventListener('close', () => dialog.remove());
                function update() {
                    const result = calculate(data, JSON.parse(JSON.stringify(snapshot)), values, snapshot.materials || materials);
                    draft = result.pricing ? repriceLines(result, ledger.pricing || commercialPricing(data),
                        ledger.pricing?.lineOverrides || {}) : result;
                    draft.manualAdjustmentScope = saved ? 'selection' : 'component';
                    const lineErrors = draft.lines.filter(item => item.key === editableKey ||
                        (operationId !== undefined && String(item.operationRow ?? item.parentOperationRow) === String(operationId)))
                        .flatMap(item => (item.errors || []).map(error => item.name + ': ' + error));
                    pending.textContent = [...new Set(lineErrors)].join(' ');
                    pending.hidden = !lineErrors.length;
                    preview.replaceChildren(detail(draft));
                    preview.firstChild.open = true;
                    save.disabled = !form.checkValidity();
                }
                form.append(pending, preview, save, cancel, status);
                dialog.append(title, help, form);
                document.body.append(dialog);
                update();
                dialog.showModal();
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    update();
                    if (save.disabled) return;
                    save.disabled = true;
                    cancel.disabled = true;
                    saving = true;
                    form.querySelectorAll('input,select').forEach(input => input.disabled = true);
                    try {
                        const parameters = JSON.parse(JSON.stringify(snapshot));
                        parameters.allOperations = draft;
                        parameters.unitPrices = unitPrices(draft);
                        parameters.costingRevision = this.revision;
                        parameters.results.total = draft.sale;
                        parameters.results.unit = draft.sale === null ? null : draft.sale / Number(parameters.quantity);
                        if (!this.save) throw new Error('Gravação indisponível. Atualize a página.');
                        await this.save({ parameters, quantityId: snapshot.basis === 'rubrics' ? 'rubrics' : selection.quantity_id,
                            quantity: Number(parameters.quantity), machineId: selection.machine_id,
                            plan: { id: selection.plan_id || 0 }, total: draft.sale, errors: [],
                            expectedDetails: selection.details });
                        if (!saved) {
                            for (const key of keys) {
                                if (values[key]) inputs[key] = { ...values[key] };
                            }
                            for (const control of controls) {
                                control.value = inputs[control.dataset.costKey]?.[control.dataset.costParameter] ?? '';
                            }
                        }
                        this.revision++;
                        this.invalidate();
                        close();
                        const confirmation = document.createElement('p');
                        confirmation.className = 'alert alert-success';
                        confirmation.textContent = draft.complete ?
                            'Nova versão guardada e completa.' :
                            'Nova versão guardada como rascunho incompleto. Preencha as linhas destacadas nos detalhes antes de confirmar ou enviar ao cliente.';
                        if (!saved) {
                            for (const button of document.querySelectorAll('button')) {
                                if (['Comparar custos das sugestões', 'Comparar todas as quantidades',
                                    'Comparar produção das rubricas'].includes(button.textContent.trim())) button.click();
                            }
                            confirmation.textContent += ' Simulações recalculadas com os dados guardados.';
                        }
                        history.prepend(confirmation);
                    } catch (error) {
                        saving = false;
                        console.error('Guardar ajustes manuais:', error);
                        status.className = 'alert alert-danger';
                        status.textContent = 'Não foi possível guardar os ajustes: ' + error.message;
                        cancel.disabled = false;
                        form.querySelectorAll('input,select').forEach(input => {
                            const original = controls.find(control => control.dataset.costKey === input.dataset.costKey &&
                                control.dataset.costParameter === input.dataset.costParameter);
                            input.disabled = Boolean(original?.disabled);
                        });
                        save.disabled = !form.checkValidity();
                    }
                });
            },
            renderSaved() {
                history.replaceChildren();
                const heading = document.createElement('h2');
                heading.textContent = 'Listagem final do cálculo · escolhas guardadas · sem IVA';
                history.append(heading);
                for (const selection of selections.values()) {
                    const snapshot = JSON.parse(selection.details).calculation;
                    const label = document.createElement('h3');
                    label.textContent = selection.selected_quantity + ' unidades';
                    history.append(label);
                    if (snapshot?.allOperations) {
                        const ledger = snapshot.allOperations;
                        const openManual = line => this.openManual(selection, snapshot, ledger, line);
                        const node = detail(ledger, { openManual });
                        history.append(node);
                        if (costPlus) {
                            {
                                const pricing = ledger.pricing || commercialPricing(data);
                                const overrides = { ...ledger.pricing?.lineOverrides };
                                const costs = { ...ledger.inputs?.commercial?.costs };
                                const panel = document.createElement('div');
                                const note = document.createElement('p');
                                note.className = 'alert alert-info';
                                note.textContent = 'Clique na operação/material para abrir os ajustes manuais desta escolha. A coluna Acréscimo (%) ajusta apenas a venda sobre os custos guardados: vazio usa o padrão de ' +
                                    pricing.markup + '%. Custo manual preenchido substitui o custo calculado, incluindo zero; vazio restaura o cálculo. Clique em Gravar para guardar os ajustes numa nova versão. Sem gravar, a escolha guardada não é alterada.';
                                const save = document.createElement('button');
                                save.type = 'button';
                                save.className = 'btn btn-primary';
                                save.textContent = 'Gravar';
                                const cancel = document.createElement('button');
                                cancel.type = 'button';
                                cancel.className = 'btn btn-default';
                                cancel.textContent = 'Cancelar';
                                const status = document.createElement('p');
                                status.setAttribute('role', 'status');
                                let draft;
                                const refresh = (oldNode) => {
                                    draft = repriceLines(ledger, pricing, overrides, costs);
                                    draft.manualAdjustmentScope = 'selection';
                                    const expanded = oldNode ?
                                        [...oldNode.querySelectorAll('[data-dependency-group][aria-expanded="true"]')]
                                            .map(button => button.dataset.dependencyGroup) : [];
                                    const next = detail(draft, { overrides, expanded,
                                        save: () => {
                                            if (save.disabled) return false;
                                            save.click();
                                            return true;
                                        },
                                        changeCost: (key, value, previous) => {
                                            costs[key] = value;
                                            refresh(previous);
                                        },
                                        openManual: line => this.openManual(selection, snapshot, draft, line),
                                        change: (key, value, previous) => {
                                        overrides[key] = value;
                                        refresh(previous);
                                    } });
                                    if (oldNode) {
                                        const focusedElement = oldNode.ownerDocument.activeElement;
                                        const focused = focusedElement?.dataset.lineMarkup;
                                        const focusedCost = focusedElement?.dataset.lineCost;
                                        const wasOpen = Boolean(oldNode.querySelector('dialog')?.open);
                                        oldNode.replaceWith(next);
                                        if (wasOpen) next.querySelector('dialog').showModal();
                                        if (focused) [...next.querySelectorAll('input')].find(input =>
                                            input.dataset.lineMarkup === focused)?.focus();
                                        if (focusedCost) [...next.querySelectorAll('input')].find(input =>
                                            input.dataset.lineCost === focusedCost)?.focus();
                                    } else panel.append(next);
                                    save.disabled = [...next.querySelectorAll('input')].some(input => !input.checkValidity()) ||
                                        Object.values(costs).some(value => value !== undefined && value !== null &&
                                            String(value).trim() !== '' && number(value) === null) ||
                                        Object.values(overrides).some(value => value !== '' && number(value) === null);
                                };
                                panel.append(note);
                                refresh();
                                panel.append(save, cancel, status);
                                node.hidden = true;
                                node.replaceWith(panel);
                                cancel.addEventListener('click', () => {
                                    this.renderSaved();
                                });
                                save.addEventListener('click', async () => {
                                    save.disabled = true;
                                    cancel.disabled = true;
                                    panel.querySelectorAll('input').forEach(input => input.disabled = true);
                                    try {
                                        const parameters = JSON.parse(JSON.stringify(snapshot));
                                        parameters.allOperations = draft;
                                        parameters.unitPrices = unitPrices(draft);
                                        parameters.costingRevision = this.revision;
                                        parameters.results.total = draft.sale;
                                        parameters.results.unit = draft.sale === null ? null : draft.sale / Number(parameters.quantity);
                                        if (!this.save) throw new Error('Gravação indisponível. Atualize a página.');
                                        await this.save({ parameters, quantityId: snapshot.basis === 'rubrics' ? 'rubrics' : selection.quantity_id,
                                            quantity: Number(parameters.quantity), machineId: selection.machine_id,
                                            plan: { id: selection.plan_id || 0 }, total: draft.sale, errors: [],
                                            expectedDetails: selection.details });
                                        inputs.commercial = { ...JSON.parse(JSON.stringify(draft.inputs.commercial)),
                                            costs: { ...inputs.commercial?.costs } };
                                        this.revision++;
                                        this.invalidate();
                                        const confirmation = document.createElement('p');
                                        confirmation.className = 'alert alert-success';
                                        confirmation.setAttribute('role', 'status');
                                        confirmation.textContent = 'Custos manuais e acréscimos por linha guardados numa nova versão. Recalcule as outras simulações antes de guardar.';
                                        history.prepend(confirmation);
                                    } catch (error) {
                                        status.className = 'alert alert-danger';
                                        status.textContent = 'Não foi possível guardar os ajustes: ' + error.message;
                                        console.error('Guardar acréscimos por linha:', error);
                                        save.disabled = false;
                                        cancel.disabled = false;
                                        panel.querySelectorAll('input').forEach(input => input.disabled = false);
                                    }
                                });
                            }
                        }
                        this.offerPrices(ledger, snapshot, true);
                        const currentRows = data.costing_operations.map(row => [String(row.id), String(row.operation_id)]);
                        const oldRows = ledger.sourceOperations.map(row => [String(row.id), String(row.operation_id)]);
                        const changed = (data.material_price_policy &&
                            JSON.stringify(ledger.materialPricePolicy) !== JSON.stringify(data.material_price_policy)) ||
                            JSON.stringify(ledger.inkCalibration) !== JSON.stringify(inkCalibration(data)) ||
                            (data.imposition_resources && (ledger.lines.some(line => Number(line.operationId) === 3) &&
                            !ledger.lines.some(line => line.imposition) || ledger.lines.some(line => line.imposition &&
                                JSON.stringify(line.imposition.resources) !== JSON.stringify(data.imposition_resources)))) ||
                            (data.ctp_resources && ledger.lines.some(line => Number(line.operationId) === 3 &&
                            JSON.stringify(line.ctp?.resources) !== JSON.stringify(data.ctp_resources))) ||
                            (data.administrative_calibration &&
                            JSON.stringify(ledger.administrativeCalibration) !== JSON.stringify(data.administrative_calibration)) ||
                            (costPlus && (!ledger.pricing ||
                            ledger.pricing.quoteMarkup !== commercialPricing(data).quoteMarkup)) ||
                            (data.costing_operations.some(row => Number(row.operation_id) === 42) &&
                            !ledger.lines.some(line => line.adhesive)) ||
                            JSON.stringify(materials) !== JSON.stringify(snapshot.materials || []) ||
                            JSON.stringify(data.costing_operations) !== JSON.stringify(ledger.sourceOperations) ||
                            JSON.stringify(currentRows) !== JSON.stringify(oldRows) ||
                            ((snapshot.parts || snapshot.pagination?.parts || [{ paperCalculation: snapshot.paperCalculation }])
                                .some(part => part.paperCalculation && !part.paperCalculation.setupWaste)) ||
                            (data.costing_operations.some(row => Number(row.operation_id) === 10) &&
                                JSON.stringify(ledger.packagingSources) !== JSON.stringify(packagingState(data))) ||
                            (data.lamination_articles && JSON.stringify(ledger.laminationSources) !== JSON.stringify({
                                front: data.component[0].lamination_front, back: data.component[0].lamination_back,
                                articles: data.lamination_articles })) ||
                            ledger.lines.some(line => line.cutting && (line.cutting.version !== 2 ||
                                line.cutting.details.some(detail => {
                                    const paper = data.papers.find(paper => String(paper.id) === String(detail.paperId));
                                    return paper && String(paperThickness(paper, ledger.inputs) ?? '') !== String(detail.paperThickness ?? '');
                                }))) ||
                            (data.costing_operations.some(row => Number(row.operation_id) === 10) &&
                                !ledger.lines.some(line => line.packagingMaterial)) ||
                            ledger.lines.some(line => line.packagingMaterial && line.packagingMaterial.version !== 4 &&
                                String(line.packagingMaterial.type) === '1') ||
                            ledger.lines.some(line => (line.article && JSON.stringify(line.article) !== JSON.stringify(
                                line.packagingMaterial ? data.packaging_articles?.find(a => String(a.id) === String(line.article.id)) :
                                    line.laminationMaterial ? data.lamination_articles?.find(a => String(a.id) === String(line.article.id)) :
                                    adhesiveArticle)) ||
                                (line.tariff?.id && JSON.stringify(currentTariff(data, line.operationId)) !== JSON.stringify(line.tariff)) ||
                                line.tariffs?.some(item => JSON.stringify(currentTariff(data, item.operationId)) !== JSON.stringify(item.tariff)));
                        if (changed) {
                            const warning = document.createElement('p');
                            warning.className = 'alert alert-warning';
                            warning.textContent = 'Regra comercial, operações, materiais, tarifas, calibração de tintas ou cálculo de acerto/plastificação/BiAdesivo/cortes/embalamento alterados desde a gravação. Histórico mantido; recalcule para atualizar.';
                            history.append(warning);
                        }
                    } else {
                        const warning = document.createElement('p');
                        warning.className = 'alert alert-warning';
                        warning.textContent = 'Seleção anterior: não inclui o detalhe de todas as operações. Recalcule e guarde para obter um total completo. O histórico não foi alterado.';
                        history.append(warning);
                    }
                }
                if (!selections.size) {
                    const message = document.createElement('p');
                    message.className = 'cb-note';
                    message.textContent = 'Calcule e guarde uma escolha para fixar aqui as linhas, quantidades, tempos, tarifas e totais usados.';
                    history.append(message);
                }
            }
        };
        if (operationTable) {
            for (const label of costPlus ? ['Custo / base', 'Custo trabalho (€)'] :
                ['Custo / base', 'Venda / base', 'Custo trabalho (€)', 'Venda trabalho (€)']) {
                const th = document.createElement('th');
                th.textContent = label;
                operationTable.tHead.rows[0].append(th);
            }
            for (const tr of operationTable.tBodies[0].rows) {
                const operation = data.costing_operations.find(row => Number(row.id) === Number(tr.dataset.operationRow));
                if (!operation) {
                    tr.cells[0].colSpan += costPlus ? 2 : 4;
                    continue;
                }
                const tariff = currentTariff(data, operation.operation_id);
                for (const key of costPlus ? ['cost'] : ['cost', 'sale']) {
                    tr.insertCell().textContent = tariff.error || money(tariff[key]) + '/' + tariff.basis;
                }
                for (const key of costPlus ? ['cost'] : ['cost', 'sale']) {
                    const td = tr.insertCell();
                    td.dataset.operationPriceTotal = key;
                    td.textContent = 'Calcular / escolher simulação';
                }
            }
            const label = document.createElement('label');
            label.textContent = 'Preços do trabalho na tabela das operações: ';
            const select = document.createElement('select');
            select.className = 'form-control';
            select.setAttribute('aria-label', 'Simulação dos preços das operações');
            select.addEventListener('change', () => context.showPrices(context.priceOptions.get(select.value)));
            label.append(select);
            operationTable.closest('.cb-scroll').before(label);
            context.priceSelect = select;
        }
        contexts.set(id, context);
        context.renderSaved();
        return context;
    }
    const api = { number, calculate, currentTariff, production, automaticQuantity, inkImpressions, inkColor,
        inkCalibration, materialSale, customerPrice,
        adhesiveLayout, adhesiveCalculation, adhesiveInputs, guillotineGrid, cuttingPile, cuttingCalculation,
        paperWeight, packagingCalculation, packagingInputs, packagingMaterial, packagingDimensions, laminationMargin, laminationCalculation, commercialPricing, repriceLines, groupedLines, isExtraOperation, ctpExecution, impositionCalculation, setupWaste, unitPrices, unitMoney, detail, create,
        get: id => contexts.get(Number(id)), money, formatHours };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.vdOperationCosting = api;
})(typeof window === 'undefined' ? globalThis : window);

(function () {
    const config = __PACKAGING_CONFIG__;
    const form = document.getElementById('items_form');
    const operation = form.querySelector('[name="fields[4287]"]');
    const field = id => form.querySelector('[name="fields[' + id + ']"]');
    const type = field(6261);
    const box = field(6262);
    const dimensions = [6263, 6264, 6265].map(field);
    const overlap = field(6266);
    if (![type, box, ...dimensions, overlap].every(Boolean)) throw new Error('Campos de material de embalagem em falta.');
    const message = document.createElement('p');
    message.className = 'alert alert-danger';
    message.hidden = true;
    overlap.closest('.form-group').after(message);
    const note = document.createElement('p');
    note.className = 'help-block';
    note.textContent = 'Dimensões vazias = automáticas na simulação de cada tiragem: formato final dos componentes e camadas de papel × espessura real × exemplares por embalagem. Não se estima espessura pela gramagem. Estes campos permitem substituir dimensões. Embrulho sempre em kraft em rolo de 1,20 m, 90 g/m², compra por kg. Consumo = comprimento utilizado × largura total × gramagem; inclui sobras laterais. Não é necessário saber o comprimento total do rolo. Sobreposição 30 mm por defeito. Caixa: uma por embalagem; validar dimensões/capacidade.';
    overlap.closest('.form-group').append(note);
    function display() {
        const active = String(operation.value) === '10';
        if (active && !type.value) {
            type.value = String(config.wrap);
            $(type).trigger('change.select2');
        }
        const wrapping = String(type.value) === String(config.wrap);
        for (const [control, visible] of [[type, active], [box, active && !wrapping],
            ...dimensions.map(control => [control, active && wrapping]), [overlap, active && wrapping]]) {
            control.closest('.form-group').hidden = !visible;
            const required = visible && !dimensions.includes(control);
            control.required = required;
            control.classList.toggle('required', required);
            if (dimensions.includes(control)) control.placeholder = 'Automático no cálculo; substituição opcional';
        }
        if (active && wrapping && !overlap.value.trim()) overlap.value = '30';
        message.hidden = true;
    }
    form.validatePackagingMaterial = function () {
        if (String(operation.value) !== '10') return true;
        const wrapping = String(type.value) === String(config.wrap);
        let error = '';
        if (![String(config.wrap), String(config.box)].includes(String(type.value))) error = 'Selecione Embrulho ou Caixa.';
        else if (!wrapping && !config.boxChoices.includes(Number(box.value))) error = 'Selecione um artigo de caixa.';
        else if (wrapping) {
            if (!overlap.value.trim() || !Number.isFinite(Number(overlap.value.replace(',', '.'))) ||
                Number(overlap.value.replace(',', '.')) < 0) {
                message.hidden = false;
                message.textContent = 'Indique sobreposição não negativa em mm.';
                return false;
            }
            const supplied = dimensions.filter(c => c.value.trim() !== '');
            if (supplied.some(c => !Number.isFinite(Number(c.value.replace(',', '.'))) || Number(c.value.replace(',', '.')) <= 0)) {
                message.hidden = false;
                message.textContent = 'As dimensões manuais devem ser positivas; deixe vazio para cálculo automático.';
                return false;
            }
            if (supplied.length < 3) return true;
            const [w, l, h] = dimensions.map(c => Number(c.value.replace(',', '.')));
            const o = Number(overlap.value.replace(',', '.'));
            if (![w, l, h].every(v => Number.isFinite(v) && v > 0) || !overlap.value.trim() || !Number.isFinite(o) || o < 0) {
                error = 'Indique dimensões positivas do volume e sobreposição não negativa, em mm.';
            } else {
                const fits = [[w, l], [l, w]].some(([width, length]) => {
                    const a = 2 * (width + h) + o, b = length + 2 * h + o;
                    return Math.min(a, b) <= 1200;
                });
                if (!fits) error = 'O painel do embrulho excede a largura do rolo de 1,20 m em ambos os sentidos. Reveja o volume ou o formato.';
            }
        }
        message.hidden = !error;
        if (error) {
            message.textContent = error;
            message.scrollIntoView({ block: 'center' });
        }
        return !error;
    };
    $(operation).on('change.packagingMaterial', display);
    $(type).on('change.packagingMaterial', display);
    display();
})();

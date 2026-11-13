(function () {
    jQuery(function ($) {
        var form = $('#items_form');
        if (!form.length) return;
        var action = form.find('[name="fields[6606]"]');
        var original = form.find('[name="fields[6607]"]');
        var entry = form.find('[name="fields[6608]"]');
        var exit = form.find('[name="fields[6609]"]');
        $.validator.addMethod('vdCorrectionRange', function () {
            if (action.val() === '1635') return true;
            var start = Date.parse(entry.val().replace(' ', 'T'));
            var end = Date.parse(exit.val().replace(' ', 'T'));
            return Number.isFinite(start) && Number.isFinite(end) && end > start && end - start <= 86400000;
        }, 'Indique uma saída posterior à entrada, com duração máxima de 24 horas.');
        $.validator.addMethod('vdCorrectionOriginal', function (value) {
            if (action.val() === '1633') return value === '' || value === '0';
            return /^[1-9][0-9]*$/.test(value);
        }, 'Ao acrescentar, deixe o original vazio; ao substituir/anular, indique o ID do original.');
        original.rules('add', {vdCorrectionOriginal: true});
        entry.rules('add', {required: function () { return action.val() !== '1635'; }});
        exit.rules('add', {
            required: function () { return action.val() !== '1635'; },
            vdCorrectionRange: true
        });
        function update() {
            var cancel = action.val() === '1635';
            entry.closest('.form-group').toggle(!cancel);
            exit.closest('.form-group').toggle(!cancel);
            original.closest('.form-group').toggle(action.val() !== '1633');
        }
        action.on('change.vdAttendance', update);
        update();
    });
})();

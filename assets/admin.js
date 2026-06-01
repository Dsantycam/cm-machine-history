/**
 * CM Machine History — admin JS
 * Tabs de hoja de vida + validación de horómetro.
 */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------
    var $tabs   = $('.cmh-tab');
    var $panels = $('.cmh-tab-panel');

    function activateTab(tabId) {
        $tabs.removeClass('active').filter('[data-tab="' + tabId + '"]').addClass('active');
        $panels.hide().filter('#tab-' + tabId).show();
    }

    if ($tabs.length && $panels.length) {
        // Ocultar todos al inicio; mostrar según hash o primer tab
        var initial = (window.location.hash || '').replace('#tab-', '').replace('#', '');
        var firstTab = $tabs.first().data('tab');
        activateTab(initial || firstTab);

        $tabs.on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            activateTab(tab);
            history.replaceState(null, '', '#tab-' + tab);
        });
    }

    // -------------------------------------------------------------------------
    // Validación de horómetro
    // -------------------------------------------------------------------------
    var lastHourmeter = (typeof CMH !== 'undefined' && CMH.lastHourmeter) ? parseFloat(CMH.lastHourmeter) : 0;

    function checkHourmeter($input, $warnDiv) {
        var val = parseFloat($input.val());
        if (val > 0 && lastHourmeter > 0 && val < lastHourmeter) {
            $warnDiv.text(
                'Advertencia: el horómetro ingresado (' + val.toLocaleString('es-CO', {minimumFractionDigits: 2}) +
                ' h) es menor al último registrado (' + lastHourmeter.toLocaleString('es-CO', {minimumFractionDigits: 2}) +
                ' h). Verifica que sea correcto antes de guardar.'
            ).show();
        } else {
            $warnDiv.hide();
        }
    }

    // Input de intervención
    var $hmInput = $('#cmh-hourmeter-input');
    var $hmWarn  = $('#cmh-hourmeter-warn');
    if ($hmInput.length) {
        $hmInput.on('input change', function () {
            checkHourmeter($hmInput, $hmWarn);
        });
    }

    // Input de edición de máquina
    var $editHm = $('[name="current_hourmeter"][data-prev-hourmeter]');
    if ($editHm.length) {
        var prevHm = parseFloat($editHm.data('prev-hourmeter')) || 0;
        $editHm.on('input change', function () {
            var val = parseFloat($(this).val());
            var $warn = $('#cmh-edit-hm-warn');
            if ( !$warn.length ) {
                $warn = $('<div id="cmh-edit-hm-warn" class="cmh-field-warning"></div>').insertAfter($editHm);
            }
            if (val > 0 && prevHm > 0 && val < prevHm) {
                $warn.text(
                    'El horómetro ingresado (' + val + ' h) es menor al anterior (' + prevHm + ' h).'
                ).show();
            } else {
                $warn.hide();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Auto-comportamiento del campo "afecta disponibilidad"
    // según tipo de mantenimiento
    // -------------------------------------------------------------------------
    var $mtype   = $('#cmh-mtype');
    var $avCheck = $('[name="affects_availability"]');
    var $avLabel = $('#cmh-av-label');
    var $dtFields = $('#cmh-downtime-fields');

    function syncMtype() {
        var val = $mtype.val();
        if (val === 'averia') {
            $avCheck.prop('checked', true).prop('disabled', true);
            $avLabel.addClass('cmh-auto-set');
            $dtFields.show();
        } else if (val === 'preventivo' || val === 'evaluacion') {
            $avCheck.prop('checked', false).prop('disabled', true);
            $avLabel.addClass('cmh-auto-set');
            $dtFields.toggle(false);
        } else {
            // correctivo — el usuario decide
            $avCheck.prop('disabled', false);
            $avLabel.removeClass('cmh-auto-set');
            $dtFields.show();
        }
    }

    if ($mtype.length) {
        $mtype.on('change', syncMtype);
        syncMtype();
    }

})(jQuery);

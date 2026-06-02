/**
 * CM Machine History — admin JS v0.8.0
 */
(function ($) {
    'use strict';

    var CMHData = (typeof CMH !== 'undefined') ? CMH : {};

    // ─── Tabs ──────────────────────────────────────────────────────────────────
    var $tabsWrapper = $('.cmh-tabs-wrapper');
    var $tabs        = $tabsWrapper.find('.cmh-tab');
    var $panels      = $tabsWrapper.find('.cmh-tab-panel');

    function activateTab(tabId) {
        $tabs.removeClass('active').filter('[data-tab="' + tabId + '"]').addClass('active');
        $panels.removeClass('active').filter('#tab-' + tabId).addClass('active');
    }

    if ($tabs.length && $panels.length) {
        $tabsWrapper.addClass('cmh-tabs-active');
        var hash    = (window.location.hash || '').replace('#tab-', '');
        var firstId = $tabs.first().data('tab');
        activateTab(hash || firstId);

        $tabs.on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            activateTab(tab);
            history.replaceState(null, '', '#tab-' + tab);
        });
    }

    // ─── Horómetro — validación ────────────────────────────────────────────────
    var lastHourmeter = parseFloat(CMHData.lastHourmeter) || 0;

    function warnHourmeter($input, $warn, prevValue) {
        var val = parseFloat($input.val());
        if (val > 0 && prevValue > 0 && val < prevValue) {
            $warn.html(
                '<strong>Advertencia:</strong> el horómetro ingresado (<strong>' +
                val.toFixed(2) + ' h</strong>) es menor al último registrado (<strong>' +
                prevValue.toFixed(2) + ' h</strong>). Confirma que sea correcto antes de guardar.'
            ).show();
        } else {
            $warn.hide().empty();
        }
    }

    var $hmInput = $('#cmh-hourmeter-input');
    var $hmWarn  = $('#cmh-hourmeter-warn');
    if ($hmInput.length) {
        $hmInput.on('input change', function () {
            warnHourmeter($hmInput, $hmWarn, lastHourmeter);
        });
    }

    $('[data-prev-hourmeter]').each(function () {
        var $el   = $(this);
        var prev  = parseFloat($el.data('prev-hourmeter')) || 0;
        var $warn = $('<div class="cmh-field-warning" style="display:none"></div>').insertAfter($el);
        $el.on('input change', function () {
            warnHourmeter($el, $warn, prev);
        });
    });

    // ─── Tipo de mantenimiento — comportamiento dinámico ───────────────────────
    var $mtype     = $('#cmh-mtype');
    var $avCheck   = $('[name="affects_availability"]');
    var $avRow     = $('#cmh-av-row');
    var $dtFields  = $('#cmh-downtime-fields');
    var $statusRow = $('#cmh-status-row');

    var statusSuggestions = {
        averia:     'mantenimiento',
        correctivo: 'activa',
        preventivo: '',
        evaluacion: ''
    };

    function syncMtype() {
        var val = $mtype.val();

        // Afecta disponibilidad
        if (val === 'averia') {
            $avCheck.prop('checked', true).prop('disabled', true);
            $avRow.find('.cmh-auto-note').show();
        } else if (val === 'preventivo' || val === 'evaluacion') {
            $avCheck.prop('checked', false).prop('disabled', true);
            $avRow.find('.cmh-auto-note').show();
        } else {
            $avCheck.prop('disabled', false);
            $avRow.find('.cmh-auto-note').hide();
        }

        // Campos de parada
        $dtFields.toggle(val === 'averia' || val === 'correctivo');

        // Sugerencia de estado
        var suggested = statusSuggestions[val] || '';
        if (suggested && $statusRow.length) {
            $statusRow.show();
            $statusRow.find('select[name="new_machine_status"]').val(suggested);
        } else if ($statusRow.length) {
            $statusRow.show();
            $statusRow.find('select[name="new_machine_status"]').val('');
        }
    }

    if ($mtype.length) {
        $mtype.on('change', syncMtype);
        syncMtype();
    }

    // ─── Máquinas — fila completa clickable ───────────────────────────────────
    $(document).on('click', '.cmh-machine-table tbody tr', function (e) {
        if ($(e.target).is('a, button, input')) return;
        var $link = $(this).find('a.button');
        if ($link.length) window.location = $link.attr('href');
    });

    // ─── Imprimir hoja de vida ─────────────────────────────────────────────────
    $(document).on('click', '.cmh-btn-print', function (e) {
        e.preventDefault();
        window.print();
    });

    // ─── Editar intervención — toggle inline ──────────────────────────────────
    $(document).on('click', '.cmh-btn-toggle-edit', function () {
        var target = $(this).data('target');
        if (target) $('#' + target).slideToggle(200);
    });

    // ─── Filtros del timeline de intervenciones ───────────────────────────────
    $(document).on('click', '.cmh-tl-filter', function () {
        var $btn    = $(this);
        var filter  = $btn.data('filter');
        var $panel  = $btn.closest('.cmh-tab-panel, .cmh-panel');
        $panel.find('.cmh-tl-filter').removeClass('active');
        $btn.addClass('active');
        var $items = $panel.find('.cmh-timeline-item');
        if ( !filter ) {
            $items.show();
        } else {
            $items.each(function () {
                $(this).toggle( $(this).data('mtype') === filter );
            });
        }
    });

    // ─── Forzar mayúsculas en campos marcados ─────────────────────────────────
    $(document).on('input', '.cmh-uppercase', function () {
        var pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        if (this.setSelectionRange) this.setSelectionRange(pos, pos);
    });

})(jQuery);

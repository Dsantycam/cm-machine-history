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

    // ─── Pago — saldo y conciliación estado ⇄ abonado ─────────────────────────
    // v1.0.1 — El estado que elige el usuario manda: «Pagado» pone el abonado en
    // el costo y «Pendiente» lo pone en cero. Antes el estado se guardaba pero el
    // abonado quedaba en 0, y los KPIs (que restan cost − paid_amount) seguían
    // contando la intervención como por cobrar. El servidor hace lo mismo en
    // CMH_Admin::normalize_payment(), así que esto es solo comodidad visual.
    //
    // Delegado y por scope de formulario para cubrir tanto el formulario de alta
    // como los formularios de edición inline del timeline, que no tienen IDs.
    function payScope($el) {
        var $form = $el.closest('form');
        var $s = $form.find('select[name="payment_status"]');
        if (!$s.length) return null;
        return {
            form:  $form,
            stat:  $s,
            cost:  $form.find('input[name="cost"]'),
            paid:  $form.find('input[name="paid_amount"]'),
            hint:  $form.find('#cmh-saldo-hint'),
        };
    }

    function paySaldoHint(sc) {
        if (!sc.hint.length) return;
        var cost = parseFloat(sc.cost.val()) || 0;
        var paid = parseFloat(sc.paid.val()) || 0;
        sc.hint.text(cost > 0
            ? 'Saldo: $' + Math.max(0, cost - paid).toLocaleString('es-CO') + ' (costo $' + cost.toLocaleString('es-CO') + ' − abonado $' + paid.toLocaleString('es-CO') + ')'
            : 'Saldo = costo − abonado.');
    }

    // El estado manda sobre el monto, y elegirlo lo marca como decisión del usuario.
    $(document).on('change', 'select[name="payment_status"]', function () {
        var sc = payScope($(this));
        if (!sc) return;
        sc.form.data('cmhPayTouched', true);
        if (!sc.paid.length) return;
        var status = sc.stat.val();
        if (status === 'pagado')         sc.paid.val(parseFloat(sc.cost.val()) || 0);
        else if (status === 'pendiente') sc.paid.val(0);
        paySaldoHint(sc);
    });

    // El monto solo sugiere el estado mientras el usuario no lo haya fijado a mano.
    $(document).on('input change', 'input[name="cost"], input[name="paid_amount"]', function () {
        var sc = payScope($(this));
        if (!sc) return;
        var cost = parseFloat(sc.cost.val()) || 0;
        var paid = parseFloat(sc.paid.val()) || 0;

        // Si ya está en «Pagado», mover el costo arrastra el abonado.
        if (sc.stat.val() === 'pagado' && $(this).is('input[name="cost"]')) {
            sc.paid.val(cost);
        } else if (!sc.form.data('cmhPayTouched')) {
            sc.stat.val(cost <= 0 ? (paid > 0 ? 'pagado' : 'pendiente')
                                  : (paid >= cost ? 'pagado' : (paid > 0 ? 'parcial' : 'pendiente')));
        }
        paySaldoHint(sc);
    });

    $('select[name="payment_status"]').each(function () {
        var sc = payScope($(this));
        if (!sc) return;
        // En edición inline el estado viene de la BD: es una decisión ya tomada,
        // no la pisamos al recalcular por cambio de costo.
        if (sc.form.find('input[name="intervention_id"]').length) sc.form.data('cmhPayTouched', true);
        paySaldoHint(sc);
    });

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

    // ─── Recurrencia de mantenimiento — opción «Otro (días)» ──────────────────
    // El <select> lleva los presets; al elegir "custom" se muestra el campo numérico
    // hermano para escribir un intervalo libre en días.
    $(document).on('change', '.cmh-interval-select', function () {
        var $sel    = $(this);
        var $custom = $sel.siblings('.cmh-interval-custom');
        if (!$custom.length) return;
        if ($sel.val() === 'custom') {
            $custom.show().attr('required', true).focus();
        } else {
            $custom.hide().removeAttr('required').val('');
        }
    });

    // ─── Forzar mayúsculas en campos marcados ─────────────────────────────────
    $(document).on('input', '.cmh-uppercase', function () {
        var pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        if (this.setSelectionRange) this.setSelectionRange(pos, pos);
    });

    // ─── Formatos: reglas del tipo de mantenimiento ───────────────────────────
    // Los operadores «está vacío» / «no está vacío» no comparan contra nada, así
    // que su casilla de valor se apaga para no dar a entender que hace falta.
    function syncRuleRow($op) {
        var unary = $op.find('option:selected').data('unary') === 1;
        var $val  = $op.closest('tr').find('.cmh-rule-value');
        $val.prop('disabled', unary).attr('placeholder', unary ? 'no aplica' : 'valor a comparar');
        if (unary) $val.val('');
    }

    // ─── Formatos: autorrelleno con texto fijo ───────────────────────────────
    function syncPrefillRow($sel) {
        var literal = $sel.val() === 'literal';
        var $txt    = $sel.closest('tr').find('.cmh-prefill-literal');
        $txt.prop('disabled', !literal)
            .attr('placeholder', literal ? 'texto que se escribirá siempre' : 'solo si eliges «Texto fijo»');
        if (!literal) $txt.val('');
    }

    $(document).on('change', '.cmh-rule-op', function () { syncRuleRow($(this)); });
    $(document).on('change', '.cmh-prefill-source', function () { syncPrefillRow($(this)); });

    $(function () {
        $('.cmh-rule-op').each(function () { syncRuleRow($(this)); });
        $('.cmh-prefill-source').each(function () { syncPrefillRow($(this)); });
    });

})(jQuery);

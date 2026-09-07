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
        var val     = $mtype.val();
        // v2.3 — Los tipos son configurables, así que la regla ya no se decide por
        // el nombre: cada opción trae marcado si descuenta disponibilidad.
        var affects = $mtype.find('option:selected').data('affects') === 1;
        var known   = $mtype.find('option:selected').length > 0;

        // Afecta disponibilidad
        if (affects) {
            $avCheck.prop('checked', true).prop('disabled', true);
            $avRow.find('.cmh-auto-note').show();
        } else if (val === 'correctivo') {
            // «Correctivo» conserva su matiz histórico: lo decide el usuario.
            $avCheck.prop('disabled', false);
            $avRow.find('.cmh-auto-note').hide();
        } else if (known) {
            $avCheck.prop('checked', false).prop('disabled', true);
            $avRow.find('.cmh-auto-note').show();
        } else {
            $avCheck.prop('disabled', false);
            $avRow.find('.cmh-auto-note').hide();
        }

        // Campos de parada: se piden cuando descuenta disponibilidad, y en correctivo.
        $dtFields.toggle(affects || val === 'correctivo');

        // Sugerencia de estado de la máquina
        var suggested = statusSuggestions[val];
        if (suggested === undefined) suggested = affects ? 'mantenimiento' : '';
        if ($statusRow.length) {
            $statusRow.show();
            $statusRow.find('select[name="new_machine_status"]').val(suggested || '');
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

    // ─── Conmutador Tabla / Línea de tiempo ───────────────────────────────────
    $(document).on('click', '.cmh-view-btn', function () {
        var $btn  = $(this);
        var view  = $btn.data('view');
        var $wrap = $btn.closest('.cmh-tab-panel, .cmh-panel');

        $wrap.find('.cmh-view-btn').removeClass('active');
        $btn.addClass('active');
        $wrap.find('.cmh-view').hide();
        $wrap.find('.cmh-view-' + view).show();
    });

    /* ═══════════════════════════════════════════════════════════════════
       v2.4 — Comportamiento de la interfaz
       ═══════════════════════════════════════════════════════════════════ */

    // ─── Ventana modal ────────────────────────────────────────────────────
    // Los formularios de alta ya no viven en la columna estrecha de la
    // derecha: el HTML está en la página, oculto, y se trae aquí al abrir.
    // Se devuelve a su sitio al cerrar para no perder lo escrito ni romper
    // los formularios que dependen de su posición en el DOM.
    var $modalHost = null;

    function closeModal() {
        if (!$modalHost) return;
        var $backdrop = $('.cmh-modal-backdrop');
        var $content  = $backdrop.find('.cmh-modal-body').children().first();
        if ($content.length) $content.appendTo($modalHost).hide();
        $backdrop.remove();
        $('body').removeClass('cmh-modal-open');
        $modalHost = null;
    }

    function openModal($source, title, subtitle) {
        closeModal();
        $modalHost = $source.parent();

        // La clase `cmh` va aquí a propósito: la ventana se cuelga de <body>,
        // fuera de .wrap.cmh, y TODO el estilo de formularios está apuntado a
        // `.cmh`. Sin ella los campos salen con el estilo por defecto del
        // navegador —etiquetas en línea, anchos sueltos, sin separación—, que es
        // exactamente el desorden que se veía al abrir.
        var $backdrop = $(
            '<div class="cmh-modal-backdrop cmh"><div class="cmh-modal" role="dialog" aria-modal="true">' +
            '<div class="cmh-modal-head"><div>' +
            '<h2></h2><p></p>' +
            '</div><button type="button" class="cmh-modal-close" aria-label="Cerrar">&times;</button></div>' +
            '<div class="cmh-modal-body"></div></div></div>'
        );
        $backdrop.find('h2').text(title || '');
        $backdrop.find('.cmh-modal-head p').text(subtitle || '');
        if (!subtitle) $backdrop.find('.cmh-modal-head p').remove();

        $backdrop.find('.cmh-modal-body').append($source.show());
        $('body').addClass('cmh-modal-open').append($backdrop);

        // El primer campo enfocado: se puede empezar a escribir de una.
        $backdrop.find('input:visible, select:visible, textarea:visible').first().trigger('focus');
    }

    $(document).on('click', '.cmh-open-modal', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var $source = $('#' + $btn.data('target'));
        if (!$source.length) return;
        openModal($source, $btn.data('title') || $btn.text(), $btn.data('subtitle') || '');
    });

    $(document).on('click', '.cmh-modal-close', function () { closeModal(); });
    $(document).on('click', '.cmh-modal-backdrop', function (e) {
        if (e.target === this) closeModal();
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    // ─── Menú «Más acciones» ──────────────────────────────────────────────
    $(document).on('click', '.cmh-menu-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $list = $(this).siblings('.cmh-menu-list');
        $('.cmh-menu-list').not($list).hide();
        $list.toggle();
    });
    $(document).on('click', function () { $('.cmh-menu-list').hide(); });
    $(document).on('click', '.cmh-menu-list', function (e) { e.stopPropagation(); });

    // ─── Buscador y ordenamiento de tablas ───────────────────────────────
    // Trabaja sobre las filas ya cargadas: no consulta al servidor, así que
    // responde al instante y no cambia de página al escribir.
    $(document).on('input', '.cmh-table-search', function () {
        var term  = $.trim($(this).val()).toLowerCase();
        var $tbl  = $('#' + $(this).data('table'));
        var shown = 0;

        $tbl.find('tbody tr').each(function () {
            var $tr = $(this);
            // Una subfila de detalle sigue la suerte de la fila de arriba.
            if ($tr.hasClass('cmh-subrow')) {
                $tr.toggle($tr.prev('tr').is(':visible'));
                return;
            }
            var match = term === '' || $tr.text().toLowerCase().indexOf(term) !== -1;
            $tr.toggle(match);
            if (match) shown++;
        });

        $(this).closest('.cmh-tablebar').find('.cmh-count')
            .text(term === '' ? '' : shown + ' de ' + $tbl.find('tbody tr:not(.cmh-subrow)').length);
    });

    $(document).on('click', '.cmh-sortable th[data-sort]', function () {
        var $th    = $(this);
        var $table = $th.closest('table');
        var idx    = $th.index();
        var numeric = $th.data('sort') === 'num';
        var asc    = !$th.hasClass('cmh-asc');

        var $rows = $table.find('tbody tr:not(.cmh-subrow)').get();
        $rows.sort(function (a, b) {
            var av = $(a).children().eq(idx).text().trim();
            var bv = $(b).children().eq(idx).text().trim();
            if (numeric) {
                // Se limpian separadores de miles, moneda y unidades.
                var an = parseFloat(av.replace(/[^0-9,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
                var bn = parseFloat(bv.replace(/[^0-9,.-]/g, '').replace(/\./g, '').replace(',', '.')) || 0;
                return asc ? an - bn : bn - an;
            }
            return asc ? av.localeCompare(bv, 'es') : bv.localeCompare(av, 'es');
        });

        var $tbody = $table.find('tbody');
        $.each($rows, function (i, tr) {
            var $tr  = $(tr);
            var $sub = $tr.next('.cmh-subrow');
            $tbody.append($tr);
            if ($sub.length) $tbody.append($sub);
        });

        $table.find('th').removeClass('cmh-asc cmh-desc');
        $th.addClass(asc ? 'cmh-asc' : 'cmh-desc');
    });

    // ─── Formularios: no perder lo escrito, no enviar dos veces ──────────
    $(document).on('change input', 'form.cmh-guard :input', function () {
        $(this).closest('form').addClass('cmh-dirty');
    });
    $(document).on('submit', 'form.cmh-guard', function () {
        $(this).removeClass('cmh-dirty');
    });
    window.addEventListener('beforeunload', function (e) {
        if (!$('form.cmh-dirty').length) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // Un doble clic en «Guardar» registraba la intervención dos veces.
    $(document).on('submit', 'form', function () {
        var $form = $(this);
        if ($form.data('cmhSubmitting')) return false;
        $form.data('cmhSubmitting', true);

        var $btn = $form.find('button[type="submit"], button:not([type]), input[type="submit"]').first();
        if ($btn.length) {
            var original = $btn.is('input') ? $btn.val() : $btn.html();
            $btn.prop('disabled', true);
            if ($btn.is('input')) $btn.val('Guardando…'); else $btn.html('Guardando…');

            // Si el navegador vuelve atrás con la página cacheada, el botón
            // debe quedar utilizable otra vez.
            setTimeout(function () {
                $btn.prop('disabled', false);
                if ($btn.is('input')) $btn.val(original); else $btn.html(original);
                $form.data('cmhSubmitting', false);
            }, 8000);
        }
    });

    // ─── Recordar la pestaña y los filtros ───────────────────────────────
    // Solo comodidad local del navegador: nada de esto viaja al servidor.
    function storageKey(suffix) {
        return 'cmh:' + (window.location.search.replace(/[&?]cmh_(msg|warn)=[^&]*/g, '')) + ':' + suffix;
    }
    function remember(key, value) {
        try { window.localStorage.setItem(key, value); } catch (err) { /* modo privado */ }
    }
    function recall(key) {
        try { return window.localStorage.getItem(key); } catch (err) { return null; }
    }
    function forget(key) {
        try { window.localStorage.removeItem(key); } catch (err) { /* modo privado */ }
    }

    $(document).on('click', '.cmh-tab', function () {
        remember(storageKey('tab'), $(this).data('tab'));
    });

    // Los filtros del servidor viajan en la URL, así que recordarlos es
    // recordar su parte de la dirección. Se separan los parámetros que dicen
    // QUÉ PANTALLA es (y por tanto forman la llave) de los que la filtran.
    var FILTER_FORMS  = 'form.cmh-filterbar, form.cmh-filter-form, form.cmh-report-filters';
    var SCREEN_PARAMS = ['page', 'id', 'machine_id', 'company_id', 'city_id', 'branch_id', 'tech_id'];
    var IGNORED       = ['cmh_msg', 'cmh_warn', 'cmh_restored', 'paged', '_wpnonce', '_wp_http_referer', 'action', 'noheader'];

    function queryPairs() {
        var out = [], raw = window.location.search.replace(/^\?/, '');
        if (!raw) return out;
        raw.split('&').forEach(function (chunk) {
            if (!chunk) return;
            var i = chunk.indexOf('=');
            out.push(i < 0 ? [chunk, ''] : [chunk.slice(0, i), chunk.slice(i + 1)]);
        });
        return out;
    }
    function joinPairs(pairs) {
        return pairs.map(function (p) { return p[0] + '=' + p[1]; }).join('&');
    }
    function screenPairs() {
        return queryPairs().filter(function (p) { return SCREEN_PARAMS.indexOf(p[0]) >= 0; });
    }
    function filterKey()  { return 'cmh:filters:' + joinPairs(screenPairs()); }
    function filterQuery() {
        return joinPairs(queryPairs().filter(function (p) {
            return SCREEN_PARAMS.indexOf(p[0]) < 0 && IGNORED.indexOf(p[0]) < 0 && p[1] !== '';
        }));
    }
    function screenUrl(extra) {
        var parts = screenPairs().map(function (p) { return p[0] + '=' + p[1]; });
        if (extra) parts.push(extra);
        return window.location.pathname + '?' + parts.join('&');
    }
    function hasParam(name) {
        return queryPairs().some(function (p) { return p[0] === name; });
    }

    // Enviar el formulario en blanco significa «quiero verlo todo»: eso borra
    // lo recordado, o al recargar volveríamos a colar el filtro anterior.
    $(document).on('submit', FILTER_FORMS, function () {
        var vacio = true;
        $.each($(this).serializeArray(), function (i, f) {
            if (SCREEN_PARAMS.indexOf(f.name) >= 0 || IGNORED.indexOf(f.name) >= 0) return;
            if ($.trim(String(f.value)) !== '') vacio = false;
        });
        if (vacio) forget(filterKey());
    });

    $(document).on('click', '.cmh-clear-filters', function (e) {
        e.preventDefault();
        forget(filterKey());
        window.location.href = screenUrl('');
    });

    $(function () {
        // Pestaña recordada, solo si la URL no pide otra cosa.
        if (!window.location.hash) {
            var saved = recall(storageKey('tab'));
            if (saved) {
                var $tab = $('.cmh-tab[data-tab="' + saved + '"]');
                if ($tab.length) $tab.trigger('click');
            }
        }

        // Filtros recordados, solo en pantallas que de verdad filtran.
        if ($(FILTER_FORMS).length) {
            var actual = filterQuery(), guardado = recall(filterKey());

            if (actual) {
                remember(filterKey(), actual);
            } else if (guardado && !hasParam('cmh_restored')) {
                // replace() y no href: volver atrás no debe caer aquí otra vez.
                window.location.replace(screenUrl(guardado + '&cmh_restored=1'));
                return;
            }

            if (hasParam('cmh_restored')) {
                $(FILTER_FORMS).first().closest('.cmh-panel').before(
                    '<div class="cmh-restored-note">' +
                    '<span class="dashicons dashicons-filter"></span>' +
                    '<span>Se repusieron los filtros que tenías la última vez en esta pantalla.</span>' +
                    '<a href="#" class="cmh-clear-filters">Ver todo</a></div>'
                );
            }
        }

        // Las pestañas se quedan a la vista al bajar.
        $('.cmh-tabs-wrapper').addClass('cmh-sticky');
    });

})(jQuery);

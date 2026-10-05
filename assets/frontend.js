/**
 * CM Machine History — autocompletado en formularios Forminator
 *
 * v0.8.1 — al escribir el código de máquina se consulta la ficha y se rellenan
 *          marca, modelo, serial, contacto, horómetro, empresa y ciudad.
 * v2.0   — el mismo relleno se puede disparar desde la URL: al abrir el formato
 *          desde una tarea, el enlace trae ?cmh_machine=CODIGO y el formulario
 *          llega listo, sin que el técnico escriba nada.
 * v2.9   — SOLO se prellena lo configurado en «Máquinas → Formatos». Se quitó el
 *          relleno que adivinaba por el texto de la etiqueta («marca», «contacto»,
 *          «horómetro»…) y el del campo de contacto: venían de antes de que
 *          existiera esa pantalla y llenaban campos que nadie había pedido. Al
 *          escribir el código a mano, el servidor devuelve el mismo mapeo
 *          configurado que usa el prellenado desde la URL.
 */
(function ($) {
    'use strict';

    if (typeof CMHFront === 'undefined') return;

    var ajaxurl = CMHFront.ajaxurl;
    var configs = CMHFront.formConfigs || {};

    // Campo de máquina de cada formato → id del formato.
    var fieldMap = {};
    Object.keys(configs).forEach(function (formId) {
        var cfg = configs[formId];
        if (cfg.machine_field) {
            fieldMap[cfg.machine_field] = fieldMap[cfg.machine_field] || [];
            fieldMap[cfg.machine_field].push(formId);
        }
    });

    /** ¿Este <form> es el formato `formId` de Forminator? */
    function isForm($form, formId) {
        return $form.attr('id') === 'forminator-module-' + formId
            || String($form.data('form-id')) === String(formId)
            || $form.closest('#forminator-module-' + formId).length > 0;
    }

    /**
     * Escribe un mapa { slug: valor } dentro de $scope.
     *
     * No pisa lo que el usuario escribió. Sí reemplaza lo que pusimos nosotros
     * mismos: si se cambia el código de máquina, los datos de la anterior no
     * deben quedarse en el formulario.
     */
    function writeMap($scope, map) {
        var applied = false;
        Object.keys(map).forEach(function (slug) {
            var $f = $scope.find('[name="' + slug + '"]');
            if (!$f.length) return;

            var cur  = $f.val();
            var ours = $f.data('cmhPrefillValue');
            if (cur && cur !== ours) return;

            $f.data('cmhPrefillValue', String(map[slug])).val(map[slug]).trigger('change');
            applied = true;
        });
        return applied;
    }

    /** Aplica el prellenado de cada formato que esté en la página. */
    function applyPrefill(prefill, $onlyForm) {
        var applied = false;
        Object.keys(prefill || {}).forEach(function (formId) {
            var $scope;
            if ($onlyForm) {
                if (!isForm($onlyForm, formId)) return;
                $scope = $onlyForm;
            } else {
                $scope = $('#forminator-module-' + formId);
                if (!$scope.length) $scope = $(document);
            }
            if (writeMap($scope, prefill[formId])) applied = true;
        });
        return applied;
    }

    /** Pinta el aviso verde/rojo bajo el campo de máquina. */
    function showHint($hint, ok, machineOrCode) {
        if (ok) {
            var m = machineOrCode;
            $hint.css({ color: '#00a32a', background: '#e7f7ed' }).html(
                '<strong>✓ ' + esc(m.brand) + ' ' + esc(m.model) + '</strong> — ' +
                esc(m.company_name) + (m.city_name ? ' / ' + esc(m.city_name) : '') +
                (m.serial ? '<br><small>Serial: ' + esc(m.serial) + '</small>' : '')
            ).show();
        } else {
            $hint.css({ color: '#d63638', background: '#fdeaea' })
                .text('Máquina no encontrada: ' + machineOrCode).show();
        }
    }

    function esc(v) {
        return $('<div>').text(v == null ? '' : String(v)).html();
    }

    /**
     * Consulta la máquina, muestra el aviso y aplica el prellenado configurado
     * para el formato en el que se escribió el código.
     */
    function lookupAndFill(code, $form, $hint) {
        $.get(ajaxurl, { action: 'cmh_get_machine', code: code })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    if ($hint) showHint($hint, false, code);
                    return;
                }
                if ($hint) showHint($hint, true, resp.data);
                applyPrefill(resp.data.prefill || {}, $form);
            })
            .fail(function () { if ($hint) $hint.hide(); });
    }

    function makeHint($input) {
        return $('<div class="cmh-machine-hint"></div>').css({
            fontSize: '12px', margin: '4px 0 0', padding: '5px 10px',
            borderRadius: '4px', display: 'none', lineHeight: '1.5',
        }).insertAfter($input);
    }

    function attachAutocomplete($input) {
        if ($input.data('cmhBound')) return;
        $input.data('cmhBound', true);

        var $form = $input.closest('form');
        var $hint = makeHint($input);
        var timer;

        $input.on('input change', function () {
            clearTimeout(timer);
            var code = $.trim($(this).val()).toUpperCase();
            if (!code || code.length < 3) { $hint.hide(); return; }

            // Ya consultamos este código: no repetir la llamada. Cubre el caso del
            // prellenado desde la URL —que dispara 'change' para que la lógica
            // condicional de Forminator se entere— y también al salir del campo
            // sin haberlo editado.
            if ($input.data('cmhLastCode') === code) return;

            timer = setTimeout(function () {
                $input.data('cmhLastCode', code);
                lookupAndFill(code, $form, $hint);
            }, 600);
        });
    }

    // ─── v2.0 — Prellenado desde la URL ───────────────────────────────────────

    function queryParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    /**
     * Engancha el aviso al campo de máquina y aplica el prellenado que el
     * servidor ya resolvió (CMHFront.prefill). Devuelve true si encontró algo
     * del formulario en la página.
     */
    function prefillMachine(code) {
        var found = false;

        Object.keys(fieldMap).forEach(function (fieldName) {
            $('[name="' + fieldName + '"]').each(function () {
                var $input = $(this);
                found = true;
                if ($input.data('cmhMachinePrefilled')) return;
                $input.data('cmhMachinePrefilled', true);

                attachAutocomplete($input);
                // Se marca como ya consultado: el prellenado viene resuelto del
                // servidor y no hace falta pedir la misma máquina por AJAX.
                $input.data('cmhLastCode', code);

                // El código va siempre al campo de máquina: es a lo que vino el
                // enlace, y sin él el envío no se puede registrar.
                if (!$input.val()) {
                    $input.data('cmhPrefillValue', code).val(code).trigger('change');
                }
            });
        });

        if (applyPrefill(CMHFront.prefill || {})) found = true;

        // El aviso verde bajo el campo, para que el técnico confirme la máquina.
        if (found && CMHFront.machine) {
            Object.keys(fieldMap).forEach(function (fieldName) {
                $('[name="' + fieldName + '"]').each(function () {
                    var $hint = $(this).next('.cmh-machine-hint');
                    if ($hint.length && !$hint.is(':visible')) showHint($hint, true, CMHFront.machine);
                });
            });
        }
        return found;
    }

    /**
     * Forminator a veces pinta el formulario después del DOM ready (paginación,
     * lógica condicional, carga diferida), así que no basta con intentarlo una
     * vez: se reintenta unos segundos y se deja de insistir en cuanto aparece.
     */
    function prefillWhenReady(code) {
        if (prefillMachine(code)) return;

        var tries = 0;
        var timer = setInterval(function () {
            tries++;
            if (prefillMachine(code) || tries >= 25) clearInterval(timer);  // ~10 s
        }, 400);
    }

    $(function () {
        Object.keys(fieldMap).forEach(function (fieldName) {
            $('[name="' + fieldName + '"]').each(function () {
                attachAutocomplete($(this));
            });
        });

        var code = $.trim(queryParam('cmh_machine')).toUpperCase();
        if (code) prefillWhenReady(code);
    });

})(jQuery);
